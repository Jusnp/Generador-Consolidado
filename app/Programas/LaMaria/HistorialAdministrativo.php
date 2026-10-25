<?php

namespace App\Programas\LaMaria;

use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

/**
 * Valores administrativos históricos por tipo RIPS y código, leídos de la hoja
 * «inf admin» (con CLASIFICACIÓN RIPS) o, en los informes nuevos, de la hoja de
 * servicios con GRUPO DEL SERVICIO (equivalencia verificada). Solo conserva mes,
 * tipo, código, descripción, medicamento del esquema y valor; documento y fecha
 * solo como clave seudónima en memoria (HMAC con semilla aleatoria por
 * instancia) para verificar la correspondencia de prestación con los RIPS.
 * Ningún dato personal sale del archivo.
 *
 * Cada fila administrativa es un registro con su valor; el informe no trae
 * cantidades, por eso el precio observado es un valor por registro.
 */
final class HistorialAdministrativo
{
    public const HOJA = 'inf admin';

    private const MESES_VENTANA = 3;

    /**
     * GRUPO DEL SERVICIO de los informes nuevos => tipo RIPS. Equivalencia verificada fila a fila contra
     * CLASIFICACIÓN RIPS del informe anterior (oct-2025 a ago-2026, ambas rutas: 100 % de las filas emparejadas).
     */
    private const GRUPOS_TIPO = [
        'CONSULTA EXTERNA' => 'AC', 'URGENCIAS' => 'AU', 'HOSPITALIZACION' => 'AH', 'TRANSPORTE Y ALBERGUE' => 'AT',
        'IMAGENES DIAGNOSTICAS' => 'AP', 'LABORATORIO CLINICO' => 'AP', 'PROCEDIMIENTOS DIAGNOSTICOS Y TERAPEUTICOS' => 'AP',
        'PROCEDIMIENTOS QUIRURGICOS' => 'AP', 'QUIMIOTERAPIA' => 'AP', 'RADIOTERAPIA' => 'AP',
    ];

    /** @var array<string, array<string, array<string, list<float>>>> ruta => tipo|codigo => mes => valores */
    private array $valores = [];

    /** @var array<string, array<string, array<string, array{registros: int, valor: float}>>> ruta => mes => tipo => totales */
    private array $totales = [];

    /** @var array<string, string> tipo|codigo => descripción administrativa */
    private array $descripciones = [];

    /** @var list<array{archivo: string, ruta: string, filas: int, meses: string, hoja: string, advertencias: list<string>}> */
    private array $fuentes = [];

    /** @var array<string, true> Huellas sha256 de los archivos ya leídos. */
    private array $huellas = [];

    /** @var array<string, array<string, array<string, array<string, list<float>>>>> ruta => tipo|codigo => mes => paciente|fecha => valores con valor */
    private array $servicios = [];

    /** @var array<string, array<string, array<string, array{porFecha: int, porPacienteMes: int}>>> */
    private array $unidadesConsolidadas = [];

    /** @var array<string, array<string, array<string, array<string, list<float>>>>> ruta => mes => paciente|fecha => tipo|codigo => valores */
    private array $serviciosPaciente = [];

    /** @var array<string, array<string, array<string, array<string, array<string, list<array{valor: float, medicamentos: list<string>}>>>>> ruta => tipo|codigo => mes => paciente => fecha => filas AP */
    private array $esquemasPaciente = [];

    /** Semilla de las claves seudónimas; vive solo en memoria. */
    private ?string $semilla = null;

    /** False en la vista autónoma: las filas del mes valorizado no se usan como conciliación (RN-17). */
    private bool $conciliaMes = true;

    /**
     * Medicamentos anotados por registro administrativo (columna MEDICAMENTO):
     * ruta => tipo|codigo => mes => [valor, tokens]. Evidencia del esquema.
     *
     * @var array<string, array<string, array<string, list<array{0: float, 1: list<string>, 2: string}>>>>
     */
    private array $composiciones = [];

    /** Palabras de presentación o fecha que no identifican un medicamento. */
    private const RUIDO = ['SOLU', 'INYE', 'TABL', 'JERI', 'PREL', 'PREC', 'POLV', 'LIOF', 'RECU', 'IMPL', 'SUBC', 'PARA',
        'INFU', 'RECO', 'VIAL', 'CAPS', 'ACET', 'ACID', 'DIME', 'ENER', 'FEBR', 'MARZ', 'ABRI', 'MAYO', 'JUNI', 'JULI', 'AGOS',
        'SEPT', 'OCTU', 'NOVI', 'DICI', 'CADA', 'CONT', 'FRAS', 'AMPO', 'CAJA', 'BLIS', 'ORAL', 'LITR'];

    /**
     * @param  array<string, string>  $archivos  Ruta temporal => nombre original.
     */
    public static function desdeArchivos(array $archivos): self
    {
        $historial = new self;

        foreach ($archivos as $ruta => $nombre) {
            $historial->cargarArchivo($ruta, $nombre);
        }

        return $historial;
    }

    /**
     * Lee un informe una sola vez (por su huella) y devuelve la ruta que
     * identificó por diagnóstico, o null si el mismo archivo ya estaba leído.
     */
    public function cargarArchivo(string $ruta, string $nombre): ?string
    {
        $huella = (string) hash_file('sha256', $ruta);

        if (isset($this->huellas[$huella])) {
            return null;
        }

        $this->huellas[$huella] = true;
        $this->leerArchivo($ruta, $nombre);

        return $this->fuentes[array_key_last($this->fuentes)]['ruta'];
    }

    public function registrar(string $ruta, string $mes, string $tipo, string $codigo, float $valor, string $descripcion = '', string $medicamento = '',
        string $fecha = '', string $documento = ''): void
    {
        $clave = $tipo.'|'.$codigo;
        $this->registrarServicio($ruta, $mes, $tipo, $codigo, $valor, $fecha, $this->clavePaciente($documento), $medicamento);

        if (trim($medicamento) !== '') {
            $this->composiciones[$ruta][$clave][$mes][] = [$valor, self::tokens($medicamento), mb_substr(mb_strtoupper(trim($medicamento)), 0, 80)];
        }
        $this->valores[$ruta][$clave][$mes][] = $valor;
        $this->totales[$ruta][$mes][$tipo] ??= ['registros' => 0, 'valor' => 0.0];
        $this->totales[$ruta][$mes][$tipo]['registros']++;
        $this->totales[$ruta][$mes][$tipo]['valor'] += $valor;

        if ($descripcion !== '') {
            $this->descripciones[$clave] ??= $descripcion;
        }
    }

    /** Clave seudónima del documento, comparable entre el informe y los RIPS de esta instancia. */
    public function clavePaciente(string $documento): string
    {
        $documento = ltrim(trim($documento), '0');
        $this->semilla ??= random_bytes(16);

        return $documento === '' ? '' : substr(hash_hmac('sha256', $documento, $this->semilla), 0, 20);
    }

    /**
     * Filas con valor del código en el mes por paciente y fecha, para la
     * correspondencia documentada con los RIPS. Null si el informe no cubre el mes.
     *
     * @return array<string, int>|null
     */
    public function serviciosDelMes(string $ruta, string $mes, string $tipo, string $codigo): ?array
    {
        return $this->cubreMes($ruta, $mes) ? array_map('count', $this->servicios[$ruta][$tipo.'|'.$codigo][$mes] ?? []) : null;
    }

    /** El informe administrativo trae filas del mes valorizado (fuente de conciliación del mismo mes). */
    public function cubreMes(string $ruta, string $mes): bool
    {
        return $this->conciliaMes && isset($this->totales[$ruta][$mes]);
    }

    /**
     * Vista autónoma del mismo historial: conserva precios, clases y unidades de los meses anteriores al
     * valorizado, pero no concilia con las filas del propio mes (cálculo con RN-01 a RN-16, sin RN-17).
     */
    public function sinInformeDelMes(): self
    {
        $this->semilla ??= random_bytes(16);
        $vista = clone $this;
        $vista->conciliaMes = false;

        return $vista;
    }

    /**
     * Filas con valor del mismo paciente y fecha en el informe del mes, por tipo|código.
     * Null si el informe no cubre el mes.
     *
     * @return array<string, list<float>>|null
     */
    public function filasDelServicio(string $ruta, string $mes, string $paciente, string $fecha): ?array
    {
        if (! $this->cubreMes($ruta, $mes)) {
            return null;
        }

        return $paciente === '' ? [] : ($this->serviciosPaciente[$ruta][$mes][$paciente.'|'.$fecha] ?? []);
    }

    /**
     * Filas AP con valor del código para el paciente en el mes del informe, por fecha, con los
     * medicamentos anotados (columna MEDICAMENTO). Null si el informe no cubre el mes.
     *
     * @return array<string, list<array{valor: float, medicamentos: list<string>}>>|null
     */
    public function esquemasPacienteMes(string $ruta, string $mes, string $codigo, string $paciente): ?array
    {
        if (! $this->cubreMes($ruta, $mes)) {
            return null;
        }

        return $paciente === '' ? [] : ($this->esquemasPaciente[$ruta]['AP|'.$codigo][$mes][$paciente] ?? []);
    }

    /**
     * Unidad histórica por paciente en la ventana: máximo de filas con valor del
     * código por paciente y fecha, y por paciente y mes.
     *
     * @return array{meses: list<string>, porFecha: int, porPacienteMes: int}|null
     */
    public function unidadPorPaciente(string $ruta, string $mes, string $tipo, string $codigo): ?array
    {
        $porMes = $this->servicios[$ruta][$tipo.'|'.$codigo] ?? [];
        $consolidadas = $this->unidadesConsolidadas[$ruta][$tipo.'|'.$codigo] ?? [];
        $meses = array_values(array_intersect($this->ventana($ruta, $mes), array_unique([...array_keys($porMes), ...array_keys($consolidadas)])));

        if ($meses === []) {
            return null;
        }

        $porFecha = 0;
        $porPacienteMes = 0;

        foreach ($meses as $m) {
            if (isset($consolidadas[$m])) {
                $porFecha = max($porFecha, $consolidadas[$m]['porFecha']);
                $porPacienteMes = max($porPacienteMes, $consolidadas[$m]['porPacienteMes']);
            }
            $pacientes = [];

            foreach ($porMes[$m] ?? [] as $servicio => $filas) {
                $porFecha = max($porFecha, count($filas));
                $paciente = (string) strstr($servicio, '|', true);
                $pacientes[$paciente] = ($pacientes[$paciente] ?? 0) + count($filas);
            }

            $porPacienteMes = max([$porPacienteMes, ...array_values($pacientes)]);
        }

        return ['meses' => $meses, 'porFecha' => $porFecha, 'porPacienteMes' => $porPacienteMes];
    }

    /**
     * Referencia sin identificadores de pacientes para el catálogo versionado.
     * Conserva observaciones, composición de esquemas y máximos de unidad.
     *
     * @return array{version: int, fuentes: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function exportarConsolidado(): array
    {
        $items = [];

        foreach ($this->valores as $ruta => $codigos) {
            foreach ($codigos as $clave => $porMes) {
                [$tipo, $codigo] = explode('|', $clave, 2);
                $unidades = $this->unidadesConsolidadas[$ruta][$clave] ?? [];

                foreach ($this->servicios[$ruta][$clave] ?? [] as $mes => $servicios) {
                    $porFecha = 0;
                    $porPaciente = [];

                    foreach ($servicios as $servicio => $filas) {
                        $porFecha = max($porFecha, count($filas));
                        $paciente = (string) strstr($servicio, '|', true);
                        $porPaciente[$paciente] = ($porPaciente[$paciente] ?? 0) + count($filas);
                    }

                    $unidades[$mes] = ['porFecha' => $porFecha, 'porPacienteMes' => max(0, ...array_values($porPaciente))];
                }

                $items[] = [
                    'ruta' => $ruta,
                    'tipo' => $tipo,
                    'codigo' => $codigo,
                    'descripcion' => $this->descripciones[$clave] ?? '',
                    'valores' => $porMes,
                    'composiciones' => $this->composiciones[$ruta][$clave] ?? [],
                    'unidades' => $unidades,
                ];
            }
        }

        return ['version' => 1, 'fuentes' => $this->fuentes, 'items' => $items];
    }

    /**
     * @param  array{version: int, fuentes: list<array<string, mixed>>, items: list<array<string, mixed>>}  $consolidado
     */
    public static function desdeConsolidado(array $consolidado): self
    {
        if (($consolidado['version'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('Versión de referencia tarifaria no compatible.');
        }

        $historial = new self;
        $historial->fuentes = $consolidado['fuentes'];
        $historial->conciliaMes = false;

        foreach ($consolidado['items'] as $item) {
            $ruta = (string) $item['ruta'];
            $clave = $item['tipo'].'|'.$item['codigo'];
            $historial->valores[$ruta][$clave] = $item['valores'];
            $historial->composiciones[$ruta][$clave] = $item['composiciones'];
            $historial->unidadesConsolidadas[$ruta][$clave] = $item['unidades'];
            $historial->descripciones[$clave] = (string) $item['descripcion'];

            foreach ($item['valores'] as $mes => $valores) {
                $historial->totales[$ruta][$mes][$item['tipo']] ??= ['registros' => 0, 'valor' => 0.0];
                $historial->totales[$ruta][$mes][$item['tipo']]['registros'] += count($valores);
                $historial->totales[$ruta][$mes][$item['tipo']]['valor'] += array_sum($valores);
            }
        }

        return $historial;
    }

    public function tieneDatos(string $ruta): bool
    {
        return ($this->totales[$ruta] ?? []) !== [];
    }

    /**
     * Meses de referencia: los tres más recientes anteriores al mes valorizado.
     *
     * @return list<string>
     */
    public function ventana(string $ruta, string $mes): array
    {
        $meses = array_values(array_filter(array_keys($this->totales[$ruta] ?? []), static fn (string $m): bool => $m < $mes));
        sort($meses);

        return array_slice($meses, -self::MESES_VENTANA);
    }

    /**
     * Mediana de los totales mensuales del tipo RIPS en la ventana.
     *
     * @return array{meses: list<string>, valores: list<float>, mediana: float, registros: float}|null
     */
    public function referenciaTipo(string $ruta, string $mes, string $tipo): ?array
    {
        $meses = $this->ventana($ruta, $mes);

        if ($meses === []) {
            return null;
        }

        $valores = array_map(fn (string $m): float => round($this->totales[$ruta][$m][$tipo]['valor'] ?? 0.0, 2), $meses);
        $registros = array_map(fn (string $m): float => (float) ($this->totales[$ruta][$m][$tipo]['registros'] ?? 0), $meses);

        return ['meses' => $meses, 'valores' => $valores, 'mediana' => self::mediana($valores), 'registros' => self::mediana($registros)];
    }

    /**
     * Precio por registro del código: mediana robusta de valores positivos en la
     * ventana. Si el código no aparece en la ventana, usa sus tres meses
     * anteriores más recientes y lo señala.
     *
     * @return array{meses: list<string>, observaciones: int, ceros: int, minimo: float, mediana: float, maximo: float, registrosMensuales: float, valorMensual: float, promedioRegistro: float, ventanaReciente: bool, descripcion: string}|null
     */
    public function precio(string $ruta, string $mes, string $tipo, string $codigo): ?array
    {
        $porMes = $this->valores[$ruta][$tipo.'|'.$codigo] ?? [];
        $ventana = $this->ventana($ruta, $mes);
        $meses = array_values(array_intersect($ventana, array_keys($porMes)));
        $reciente = true;

        if (array_filter($meses, static fn (string $m): bool => array_filter($porMes[$m], static fn (float $v): bool => $v > 0) !== []) === []) {
            $anteriores = array_values(array_filter(array_keys($porMes), static fn (string $m): bool => $m < $mes
                && array_filter($porMes[$m], static fn (float $v): bool => $v > 0) !== []));
            sort($anteriores);
            $meses = array_slice($anteriores, -self::MESES_VENTANA);
            $reciente = false;
        }

        if ($meses === []) {
            return null;
        }

        $todos = array_merge(...array_map(static fn (string $m): array => $porMes[$m], $meses));
        $positivos = array_values(array_filter($todos, static fn (float $v): bool => $v > 0));

        if ($positivos === []) {
            return null;
        }

        $mesesVentana = $reciente ? $ventana : $meses;

        return [
            'meses' => $meses,
            'observaciones' => count($positivos),
            'ceros' => count($todos) - count($positivos),
            'minimo' => min($positivos),
            'mediana' => self::mediana($positivos),
            'maximo' => max($positivos),
            'registrosMensuales' => self::mediana(array_map(static fn (string $m): float => (float) count($porMes[$m] ?? []), $mesesVentana)),
            'valorMensual' => round(self::mediana(array_map(static fn (string $m): float => array_sum($porMes[$m] ?? []), $mesesVentana)), 2),
            'promedioRegistro' => round(array_sum($todos) / count($todos), 2),
            'ventanaReciente' => $reciente,
            'descripcion' => $this->descripciones[$tipo.'|'.$codigo] ?? '',
        ];
    }

    /**
     * Clases de precio del código según el esquema anotado en cada registro
     * (columna MEDICAMENTO): un mismo CUPS puede valorarse distinto según su
     * composición. Usa los mismos meses que precio(). Un medicamento es
     * distintivo de una clase si aparece en al menos la mitad de sus registros
     * y en no más del 10 % de los registros de las demás clases.
     *
     * @return list<array{precio: float, registros: int, participacion: float, composicion: array<string, float>, distintivos: list<string>, ejemplo: string}>
     */
    public function clasesPrecio(string $ruta, string $mes, string $tipo, string $codigo): array
    {
        $precio = $this->precio($ruta, $mes, $tipo, $codigo);

        if ($precio === null) {
            return [];
        }

        $filas = array_values(array_filter(
            array_merge(...array_map(fn (string $m): array => $this->composiciones[$ruta][$tipo.'|'.$codigo][$m] ?? [], $precio['meses'])),
            static fn (array $fila): bool => $fila[0] > 0
        ));

        if ($filas === []) {
            return [['precio' => $precio['mediana'], 'registros' => $precio['observaciones'], 'participacion' => 1.0,
                'composicion' => [], 'distintivos' => [], 'ejemplo' => '']];
        }

        $clases = [];

        foreach ($filas as [$valor, $tokens, $texto]) {
            $clave = (string) round($valor);
            $clases[$clave] ??= ['valores' => [], 'tokens' => [], 'textos' => []];
            $clases[$clave]['valores'][] = $valor;
            $clases[$clave]['textos'][$texto] = ($clases[$clave]['textos'][$texto] ?? 0) + 1;

            foreach (array_unique($tokens) as $token) {
                $clases[$clave]['tokens'][$token] = ($clases[$clave]['tokens'][$token] ?? 0) + 1;
            }
        }

        $total = count($filas);
        $resultado = [];

        foreach ($clases as $clave => $clase) {
            $registros = count($clase['valores']);
            $otros = $total - $registros;
            $composicion = array_map(static fn (int $conteo): float => round($conteo / $registros, 4), $clase['tokens']);
            $distintivos = [];

            foreach ($composicion as $token => $participacion) {
                $enOtras = 0;

                foreach ($clases as $otraClave => $otra) {
                    $enOtras += $otraClave === $clave ? 0 : ($otra['tokens'][$token] ?? 0);
                }

                if ($otros > 0 && $participacion >= 0.5 && $enOtras / $otros <= 0.1) {
                    $distintivos[] = (string) $token;
                }
            }

            arsort($clase['textos']);
            $resultado[] = ['precio' => self::mediana($clase['valores']), 'registros' => $registros,
                'participacion' => round($registros / $total, 4), 'composicion' => $composicion,
                'distintivos' => $distintivos, 'ejemplo' => (string) array_key_first($clase['textos'])];
        }

        usort($resultado, static fn (array $a, array $b): int => $b['registros'] <=> $a['registros']);

        return $resultado;
    }

    /**
     * Medicamentos de un texto (columna MEDICAMENTO o principio activo) como
     * prefijos de cuatro letras, para tolerar abreviaturas (PEMBRO, PACLI).
     *
     * @return list<string>
     */
    public static function tokens(string $texto): array
    {
        $ascii = strtr(mb_strtoupper($texto), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        preg_match_all('/[A-Z]{4,}/', $ascii, $palabras);
        $tokens = [];

        foreach ($palabras[0] as $palabra) {
            $token = substr($palabra, 0, 4);

            if (! in_array($token, self::RUIDO, true)) {
                $tokens[$token] = true;
            }
        }

        return array_map('strval', array_keys($tokens));
    }

    /**
     * Códigos con historial en la ventana del mes, para mostrar también los
     * que no tuvieron actividad en el mes valorizado.
     *
     * @return list<array{tipo: string, codigo: string}>
     */
    public function codigosEnVentana(string $ruta, string $mes): array
    {
        $ventana = $this->ventana($ruta, $mes);
        $codigos = [];

        foreach ($this->valores[$ruta] ?? [] as $clave => $porMes) {
            if (array_intersect($ventana, array_keys($porMes)) !== []) {
                [$tipo, $codigo] = explode('|', $clave, 2);
                $codigos[] = ['tipo' => $tipo, 'codigo' => $codigo];
            }
        }

        return $codigos;
    }

    /** @return list<array{tipo: string, codigo: string}> */
    public function codigosDelMes(string $ruta, string $mes): array
    {
        $codigos = [];

        foreach ($this->valores[$ruta] ?? [] as $clave => $porMes) {
            if (! isset($porMes[$mes])) {
                continue;
            }

            [$tipo, $codigo] = explode('|', $clave, 2);
            $codigos[] = ['tipo' => $tipo, 'codigo' => $codigo];
        }

        return $codigos;
    }

    /** @return array{registros: int, valor: float}|null */
    public function valorDelMes(string $ruta, string $mes, string $tipo, string $codigo): ?array
    {
        $valores = $this->valores[$ruta][$tipo.'|'.$codigo][$mes] ?? null;

        return $valores === null ? null : ['registros' => count($valores), 'valor' => round(array_sum($valores), 2)];
    }

    /** @return array{registros: int, valor: float}|null */
    public function totalDelMes(string $ruta, string $mes): ?array
    {
        $tipos = $this->totales[$ruta][$mes] ?? null;

        return $tipos === null ? null : ['registros' => array_sum(array_column($tipos, 'registros')),
            'valor' => round(array_sum(array_column($tipos, 'valor')), 2)];
    }

    /**
     * @return list<array{archivo: string, ruta: string, filas: int, meses: string, hoja: string, advertencias: list<string>}>
     */
    public function fuentes(): array
    {
        return $this->fuentes;
    }

    /**
     * @param  list<float>  $valores
     */
    public static function mediana(array $valores): float
    {
        if ($valores === []) {
            return 0.0;
        }

        sort($valores);
        $n = count($valores);

        return $n % 2 === 1 ? (float) $valores[intdiv($n, 2)] : ($valores[$n / 2 - 1] + $valores[$n / 2]) / 2;
    }

    private function registrarServicio(string $ruta, string $mes, string $tipo, string $codigo, float $valor, string $fecha, string $paciente,
        string $medicamento = ''): void
    {
        if ($valor > 0 && $paciente !== '' && $fecha !== '') {
            $this->servicios[$ruta][$tipo.'|'.$codigo][$mes][$paciente.'|'.$fecha][] = $valor;
            $this->serviciosPaciente[$ruta][$mes][$paciente.'|'.$fecha][$tipo.'|'.$codigo][] = $valor;

            if ($tipo === 'AP') {
                $this->esquemasPaciente[$ruta][$tipo.'|'.$codigo][$mes][$paciente][$fecha][] = ['valor' => $valor, 'medicamentos' => self::tokens($medicamento)];
            }
        }
    }

    private function leerArchivo(string $ruta, string $nombre): void
    {
        $filasDeclaradas = self::filasDeclaradas($ruta);
        $reader = new Reader;
        $reader->open($ruta);
        $elegida = null;
        $leida = null;

        try {
            // La hoja «inf admin» conserva la prioridad; en los informes nuevos se usa la hoja de servicios con más filas.
            foreach ($reader->getSheetIterator() as $hoja) {
                $nombreHoja = trim($hoja->getName());
                $orden = [mb_strtolower($nombreHoja) === self::HOJA, $filasDeclaradas[$nombreHoja] ?? 0];

                if (($elegida === null || $orden > $elegida['orden']) && self::encabezado($hoja) !== null) {
                    $elegida = ['indice' => $hoja->getIndex(), 'nombre' => $nombreHoja, 'orden' => $orden];
                }
            }

            foreach ($elegida === null ? [] : $reader->getSheetIterator() as $hoja) {
                if ($hoja->getIndex() === $elegida['indice']) {
                    $leida = $this->leerHoja($hoja);

                    break;
                }
            }
        } finally {
            $reader->close();
        }

        $leida ??= ['filas' => [], 'diagnosticos' => ['PROSTATA' => 0, 'CERVIX' => 0], 'sinGrupo' => [], 'noVerificables' => 0];
        $diagnosticos = $leida['diagnosticos'];

        // Cada informe pertenece a un programa: se asigna por el diagnóstico predominante.
        $rutaPrograma = $diagnosticos['PROSTATA'] === $diagnosticos['CERVIX']
            ? ''
            : ($diagnosticos['PROSTATA'] > $diagnosticos['CERVIX'] ? 'PROSTATA' : 'CERVIX');
        $meses = [];

        foreach ($leida['filas'] as [$mes, $tipo, $codigo, $valor, $descripcion, $medicamento, $fecha, $paciente]) {
            $this->registrarServicio($rutaPrograma, $mes, $tipo, $codigo, $valor, $fecha, $paciente, $medicamento);
            $this->registrar($rutaPrograma, $mes, $tipo, $codigo, $valor, $descripcion, $medicamento);
            $meses[$mes] = true;
        }

        $advertencias = [];

        foreach ($leida['sinGrupo'] as $grupo => $filas) {
            $advertencias[] = sprintf('Grupo «%s» sin equivalencia RIPS verificada: %d filas no usadas.', $grupo, $filas);
        }

        if ($leida['noVerificables'] > 0) {
            $advertencias[] = sprintf('%d filas con valor calculado por fórmula no verificable: no usadas.', $leida['noVerificables']);
        }

        ksort($meses);
        $this->fuentes[] = ['archivo' => $nombre, 'ruta' => $rutaPrograma === '' ? 'NO IDENTIFICADA' : $rutaPrograma,
            'filas' => count($leida['filas']), 'meses' => $meses === [] ? '' : array_key_first($meses).' a '.array_key_last($meses),
            'hoja' => $elegida['nombre'] ?? '', 'advertencias' => $advertencias];
    }

    /**
     * Columnas de la hoja si una de sus cinco primeras filas es el encabezado de un informe de servicios.
     *
     * @return array{codigo: int, tipo: int|null, grupo: int|null, valor: int, fecha: int, descripcion: int|null, diagnostico: int|null, medicamento: int|null, documento: int|null}|null
     */
    private static function encabezado(SheetInterface $hoja): ?array
    {
        $leidas = 0;

        try {
            foreach ($hoja->getRowIterator() as $fila) {
                $columnas = self::columnas(self::valoresFila($fila->getCells()));

                if ($columnas !== null || ++$leidas >= 5) {
                    return $columnas;
                }
            }
        } catch (OpenSpoutException) {
            // Una hoja auxiliar ilegible (tabla dinámica, formato inválido) no es de servicios ni impide leer las demás.
        }

        return null;
    }

    /**
     * Valores de la fila; las columnas calculadas usan el resultado guardado de la fórmula.
     *
     * @param  list<Cell>  $celdas
     * @return list<mixed>
     */
    private static function valoresFila(array $celdas): array
    {
        return array_map(static fn (Cell $celda): mixed => $celda instanceof FormulaCell ? $celda->getComputedValue() : $celda->getValue(), $celdas);
    }

    /**
     * Filas declaradas por hoja en la dimensión del libro (sin leer los datos).
     *
     * @return array<string, int>
     */
    private static function filasDeclaradas(string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            return [];
        }

        $libro = (string) $zip->getFromName('xl/workbook.xml');
        $relaciones = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        preg_match_all('/<sheet\b[^>]*\bname="([^"]*)"[^>]*\br:id="([^"]*)"/', $libro, $hojas, PREG_SET_ORDER);
        $filas = [];

        foreach ($hojas as [, $nombre, $relacion]) {
            if (preg_match('/<Relationship\b(?=[^>]*\bId="'.preg_quote($relacion, '/').'")[^>]*\bTarget="([^"]*)"/', $relaciones, $destino) !== 1) {
                continue;
            }

            $archivo = str_starts_with($destino[1], '/') ? ltrim($destino[1], '/') : 'xl/'.$destino[1];
            $flujo = $zip->getStream($archivo);
            $inicio = '';

            if ($flujo !== false) {
                $inicio = (string) fread($flujo, 4096);
                fclose($flujo);
            }

            if (preg_match('/<dimension ref="[A-Z]*\d+(?::[A-Z]*(\d+))?"/', $inicio, $dimension) === 1) {
                $filas[trim(html_entity_decode($nombre, ENT_QUOTES | ENT_XML1))] = (int) ($dimension[1] ?? 1);
            }
        }

        $zip->close();

        return $filas;
    }

    /**
     * Filas de la hoja de servicios administrativos elegida.
     *
     * @return array{filas: list<array{0: string, 1: string, 2: string, 3: float, 4: string, 5: string, 6: string, 7: string}>, diagnosticos: array{PROSTATA: int, CERVIX: int}, sinGrupo: array<string, int>, noVerificables: int}
     */
    private function leerHoja(SheetInterface $hoja): array
    {
        $columnas = null;
        $leidas = 0;
        $resultado = ['filas' => [], 'diagnosticos' => ['PROSTATA' => 0, 'CERVIX' => 0], 'sinGrupo' => [], 'noVerificables' => 0];

        foreach ($hoja->getRowIterator() as $fila) {
            $celdas = $fila->getCells();
            $valores = self::valoresFila($celdas);

            if ($columnas === null) {
                $columnas = $leidas++ < 5 ? self::columnas($valores) : [];

                continue;
            }

            if ($columnas === []) {
                break;
            }

            $mes = self::mes($valores[$columnas['fecha']] ?? null);
            $codigo = trim((string) ($valores[$columnas['codigo']] ?? ''));
            $tipo = $columnas['tipo'] !== null
                ? mb_strtoupper(trim((string) ($valores[$columnas['tipo']] ?? '')))
                : (self::GRUPOS_TIPO[self::normalizar((string) ($valores[$columnas['grupo']] ?? ''))] ?? '');

            if ($tipo === '' && $columnas['tipo'] === null && $mes !== '' && $codigo !== '') {
                $grupo = self::normalizar((string) ($valores[$columnas['grupo']] ?? ''));
                $resultado['sinGrupo'][$grupo] = ($resultado['sinGrupo'][$grupo] ?? 0) + 1;
            }

            if ($mes === '' || $tipo === '' || $codigo === '') {
                continue;
            }

            // Un error o un texto como resultado de la fórmula de VALOR no es verificable.
            $celdaValor = $celdas[$columnas['valor']] ?? null;

            if ($celdaValor instanceof FormulaCell && ! is_numeric($celdaValor->getComputedValue())) {
                $resultado['noVerificables']++;

                continue;
            }

            $diagnostico = mb_strtoupper(trim((string) ($columnas['diagnostico'] === null ? '' : ($valores[$columnas['diagnostico']] ?? ''))));
            $resultado['diagnosticos']['PROSTATA'] += str_starts_with($diagnostico, 'C61') ? 1 : 0;
            $resultado['diagnosticos']['CERVIX'] += str_starts_with($diagnostico, 'C53') ? 1 : 0;
            $resultado['filas'][] = [$mes, $tipo, $codigo, self::numero($valores[$columnas['valor']] ?? 0),
                $columnas['descripcion'] === null ? '' : trim((string) ($valores[$columnas['descripcion']] ?? '')),
                $columnas['medicamento'] === null ? '' : trim((string) ($valores[$columnas['medicamento']] ?? '')),
                self::dia($valores[$columnas['fecha']] ?? null),
                $columnas['documento'] === null ? '' : $this->clavePaciente((string) ($valores[$columnas['documento']] ?? ''))];
        }

        return $resultado;
    }

    private static function normalizar(string $texto): string
    {
        return (string) preg_replace('/\s+/', ' ', strtr(mb_strtoupper(trim($texto)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']));
    }

    /**
     * Ubica las columnas por su encabezado; los informes de cada ruta las
     * tienen en posiciones distintas.
     *
     * @param  array<int, mixed>  $encabezado
     * @return array{codigo: int, tipo: int|null, grupo: int|null, valor: int, fecha: int, descripcion: int|null, diagnostico: int|null, medicamento: int|null, documento: int|null}|null
     */
    private static function columnas(array $encabezado): ?array
    {
        $normalizado = array_map(static fn (mixed $valor): string => is_scalar($valor) ? mb_strtoupper(trim((string) $valor)) : '', $encabezado);
        $buscar = static function (string $prefijo) use ($normalizado): ?int {
            foreach ($normalizado as $indice => $titulo) {
                if (str_starts_with($titulo, $prefijo)) {
                    return $indice;
                }
            }

            return null;
        };
        $columnas = [
            'codigo' => $buscar('CUPS'),
            'tipo' => $buscar('CLASIFICACI'),
            'grupo' => $buscar('GRUPO DEL SERVICIO'),
            'valor' => $buscar('VALOR'),
            'fecha' => $buscar('FECHA_PRESTACION'),
            'descripcion' => $buscar('DESCRIPCION DEL SERVICIO'),
            'diagnostico' => $buscar('DIACODIGO'),
            'medicamento' => $buscar('MEDICAMENTO'),
            'documento' => $buscar('DOCUMENTO'),
        ];

        return in_array(null, [$columnas['codigo'], $columnas['valor'], $columnas['fecha']], true) || ($columnas['tipo'] === null && $columnas['grupo'] === null)
            ? null : $columnas;
    }

    private static function mes(mixed $fecha): string
    {
        $dia = self::dia($fecha);

        return $dia === '' ? '' : substr($dia, 0, 7);
    }

    private static function dia(mixed $fecha): string
    {
        // Fecha serial de Excel cuando la celda no conserva el formato de fecha.
        if ((is_int($fecha) || is_float($fecha)) && $fecha > 20000 && $fecha < 80000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $fecha.' days')->format('Y-m-d');
        }

        $texto = $fecha instanceof DateTimeInterface ? $fecha->format('Y-m-d') : trim((string) $fecha);

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $texto, $partes) === 1) {
            return checkdate((int) $partes[2], (int) $partes[1], (int) $partes[3]) ? sprintf('%04d-%02d-%02d', $partes[3], $partes[2], $partes[1]) : '';
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $partes) === 1 && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? substr($texto, 0, 10) : '';
    }

    private static function numero(mixed $valor): float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = str_replace(['$', ' ', ','], '', trim((string) $valor));

        return is_numeric($texto) ? (float) $texto : 0.0;
    }
}
