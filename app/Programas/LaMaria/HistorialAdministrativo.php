<?php

namespace App\Programas\LaMaria;

use DateTimeImmutable;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Valores administrativos históricos por tipo RIPS y código, leídos de la hoja
 * «inf admin» de los informes administrativos de La María. Solo conserva mes,
 * tipo, código, descripción, medicamento del esquema y valor: ningún dato
 * personal sale del archivo.
 *
 * Cada fila administrativa es un registro con su valor; el informe no trae
 * cantidades, por eso el precio observado es un valor por registro.
 */
final class HistorialAdministrativo
{
    public const HOJA = 'inf admin';

    private const MESES_VENTANA = 3;

    /** @var array<string, array<string, array<string, list<float>>>> ruta => tipo|codigo => mes => valores */
    private array $valores = [];

    /** @var array<string, array<string, array<string, array{registros: int, valor: float}>>> ruta => mes => tipo => totales */
    private array $totales = [];

    /** @var array<string, string> tipo|codigo => descripción administrativa */
    private array $descripciones = [];

    /** @var list<array{archivo: string, ruta: string, filas: int, meses: string}> */
    private array $fuentes = [];

    /** @var array<string, true> Huellas sha256 de los archivos ya leídos. */
    private array $huellas = [];

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

    public function registrar(string $ruta, string $mes, string $tipo, string $codigo, float $valor, string $descripcion = '', string $medicamento = ''): void
    {
        $clave = $tipo.'|'.$codigo;

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
     * @return list<array{archivo: string, ruta: string, filas: int, meses: string}>
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

    private function leerArchivo(string $ruta, string $nombre): void
    {
        $reader = new Reader;
        $reader->open($ruta);
        $filas = [];
        $diagnosticos = ['PROSTATA' => 0, 'CERVIX' => 0];

        try {
            foreach ($reader->getSheetIterator() as $hoja) {
                if (mb_strtolower(trim($hoja->getName())) !== self::HOJA) {
                    continue;
                }

                $columnas = null;
                $leidas = 0;

                foreach ($hoja->getRowIterator() as $fila) {
                    $valores = $fila->toArray();

                    if ($columnas === null) {
                        $columnas = $leidas++ < 5 ? self::columnas($valores) : [];

                        continue;
                    }

                    if ($columnas === []) {
                        break;
                    }

                    $mes = self::mes($valores[$columnas['fecha']] ?? null);
                    $tipo = mb_strtoupper(trim((string) ($valores[$columnas['tipo']] ?? '')));
                    $codigo = trim((string) ($valores[$columnas['codigo']] ?? ''));

                    if ($mes === '' || $tipo === '' || $codigo === '') {
                        continue;
                    }

                    $diagnostico = mb_strtoupper(trim((string) ($columnas['diagnostico'] === null ? '' : ($valores[$columnas['diagnostico']] ?? ''))));
                    $diagnosticos['PROSTATA'] += str_starts_with($diagnostico, 'C61') ? 1 : 0;
                    $diagnosticos['CERVIX'] += str_starts_with($diagnostico, 'C53') ? 1 : 0;
                    $filas[] = [$mes, $tipo, $codigo, self::numero($valores[$columnas['valor']] ?? 0),
                        $columnas['descripcion'] === null ? '' : trim((string) ($valores[$columnas['descripcion']] ?? '')),
                        $columnas['medicamento'] === null ? '' : trim((string) ($valores[$columnas['medicamento']] ?? ''))];
                }
            }
        } finally {
            $reader->close();
        }

        // Cada informe pertenece a un programa: se asigna por el diagnóstico predominante.
        $rutaPrograma = $diagnosticos['PROSTATA'] === $diagnosticos['CERVIX']
            ? ''
            : ($diagnosticos['PROSTATA'] > $diagnosticos['CERVIX'] ? 'PROSTATA' : 'CERVIX');
        $meses = [];

        foreach ($filas as [$mes, $tipo, $codigo, $valor, $descripcion, $medicamento]) {
            $this->registrar($rutaPrograma, $mes, $tipo, $codigo, $valor, $descripcion, $medicamento);
            $meses[$mes] = true;
        }

        ksort($meses);
        $this->fuentes[] = ['archivo' => $nombre, 'ruta' => $rutaPrograma === '' ? 'NO IDENTIFICADA' : $rutaPrograma,
            'filas' => count($filas), 'meses' => $meses === [] ? '' : array_key_first($meses).' a '.array_key_last($meses)];
    }

    /**
     * Ubica las columnas por su encabezado; los informes de cada ruta las
     * tienen en posiciones distintas.
     *
     * @param  array<int, mixed>  $encabezado
     * @return array{codigo: int, tipo: int, valor: int, fecha: int, descripcion: int|null, diagnostico: int|null, medicamento: int|null}|null
     */
    private static function columnas(array $encabezado): ?array
    {
        $normalizado = array_map(static fn (mixed $valor): string => mb_strtoupper(trim((string) $valor)), $encabezado);
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
            'valor' => $buscar('VALOR'),
            'fecha' => $buscar('FECHA_PRESTACION'),
            'descripcion' => $buscar('DESCRIPCION DEL SERVICIO'),
            'diagnostico' => $buscar('DIACODIGO'),
            'medicamento' => $buscar('MEDICAMENTO'),
        ];

        return in_array(null, [$columnas['codigo'], $columnas['tipo'], $columnas['valor'], $columnas['fecha']], true) ? null : $columnas;
    }

    private static function mes(mixed $fecha): string
    {
        // Fecha serial de Excel cuando la celda no conserva el formato de fecha.
        if ((is_int($fecha) || is_float($fecha)) && $fecha > 20000 && $fecha < 80000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) $fecha.' days')->format('Y-m');
        }

        $texto = $fecha instanceof DateTimeInterface ? $fecha->format('Y-m') : substr(trim((string) $fecha), 0, 7);

        return preg_match('/^\d{4}-\d{2}$/', $texto) === 1 ? $texto : '';
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
