<?php

namespace App\Programas\LaMaria;

use Closure;

/**
 * Valorización administrativa referencial: valora los servicios reales del mes
 * con precios administrativos históricos comparables (mismo código, unidad,
 * alcance y esquema), sin modificar el costo A. Cada línea queda en uno de
 * cinco estados; lo que no suma se separa en condicionado (estimación
 * condicionada), alternativa (otra unidad) o informativo. No es facturación.
 *
 * RN-01 conservación de A, AC y AH · RN-02/03 AP y quimioterapia según el
 * esquema real · RN-04 medicamentos oncológicos incluidos, de otro esquema o
 * de asociación desconocida · RN-05 demás AP con el histórico · RN-06 AT por
 * unidad comparable · RN-07 AU no aditivo · RN-08 radioterapia por registro y
 * por tratamiento, sin mezclar unidades.
 */
final class ValorizacionAdministrativa
{
    public const RESPALDADA = 'RESPALDADA POR TARIFA APLICABLE';

    public const ESTIMADA = 'ESTIMADA DESDE REFERENCIA HISTORICA';

    public const CONDICIONADA = 'CONDICIONADA POR UNIDAD O PRESENTACION';

    public const INCLUIDA = 'INCLUIDA EN OTRO COMPONENTE';

    public const SIN_VALOR = 'SIN VALORIZACION POSIBLE';

    public const NO_ADITIVO_CONDICIONADO = 'CONDICIONADO';

    public const NO_ADITIVO_ALTERNATIVA = 'ALTERNATIVA (OTRA UNIDAD)';

    public const NO_ADITIVO_INFORMATIVO = 'INFORMATIVO';

    public const GRUPO_INCLUIDO = '1 · INCLUIDO EN EL TRATAMIENTO INTEGRAL';

    public const GRUPO_OTRO_ESQUEMA = '2 · OTRO ESQUEMA (INDEPENDIENTE)';

    public const GRUPO_DESCONOCIDO = '3 · ASOCIACION DESCONOCIDA (CONDICIONADO)';

    public const GRUPO_SIN_INTEGRAL = 'SIN TRATAMIENTO INTEGRAL EN EL CASO-MES (INDEPENDIENTE)';

    public const SUBTIPO_RN14_AP = 'RN-14 · COBERTURA ECONOMICA AP NO DEMOSTRADA';

    public const SUBTIPO_RN14_MEZCLA = 'RN-14 · VALOR YA INCLUIDO EN LA MEZCLA DE ESQUEMAS DEL AP';

    public const NO_ADITIVO_COSTO_A_INCLUIDO = 'COSTO A DE MEDICAMENTOS INCLUIDOS EN TRATAMIENTO INTEGRAL (REEMPLAZADO POR EL PRECIO DEL ESQUEMA)';

    public const NO_ADITIVO_COSTO_A_RN14 = 'COSTO A DE MEDICAMENTOS ASOCIADOS A AP SIN COBERTURA DEMOSTRADA (RN-14)';

    public const SUBTIPO_DISPENSACION_MISMO_PERIODO = 'CONDICIONADO · PRECIO DE DISPENSACION DEL MISMO PACIENTE Y MES';

    /** Clasificación RN-14 de cada línea AM (hoja COBERTURA AM RN14). */
    public const COBERTURA_INCLUIDO_AP = 'INCLUIDO DEMOSTRADO EN AP';

    public const COBERTURA_INCLUIDO_AH = 'INCLUIDO DEMOSTRADO EN AH';

    public const COBERTURA_INCLUIDO_URGENCIA = 'INCLUIDO EN URGENCIA COBRADA';

    public const COBERTURA_INDEPENDIENTE = 'INDEPENDIENTE';

    public const COBERTURA_CONDICIONADO_AP = 'CONDICIONADO AP';

    public const COBERTURA_CONDICIONADO_AH = 'CONDICIONADO AH';

    public const COBERTURA_CONDICIONADO_PRECIO = 'CONDICIONADO PRECIO DEL MISMO PERIODO';

    public const COBERTURA_PENDIENTE = 'PENDIENTE SIN PRECIO O PRESENTACION';

    public const TIPOS_ORDEN = ['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'AN'];

    public const REFERENCIAS_GERENCIALES = ['PROSTATA' => 4000000000.0, 'CERVIX' => 2000000000.0];

    /** Códigos de transporte identificados tanto en RIPS como en el histórico administrativo. */
    private const TRANSPORTES_AT = ['T34004', 'T34003', '601T01', '601T02'];

    public const COSTO_A_HEADERS = ['MES', 'TIPO RIPS', 'REGISTROS', 'UNIDADES', 'COSTO A', 'CONTROL'];

    public const RESUMEN_SERVICIOS_HEADERS = [
        'TIPO RIPS', 'SERVICIO', 'REGISTROS', 'COSTO A', 'ESTIMACIÓN ADMINISTRATIVA', 'COSTO AU ATRIBUIDO', 'VALOR CONDICIONADO', 'ESTADO', 'UNIDADES', 'AU ADMIN ATRIBUIDO',
        'PENDIENTE',
    ];

    public const SERVICIOS = [
        'AC' => 'Consultas', 'AP' => 'Procedimientos', 'AT' => 'Otros servicios', 'AU' => 'Urgencias',
        'AM' => 'Medicamentos', 'AH' => 'Hospitalización', 'AN' => 'Recién nacidos',
    ];

    public const COMPARACION_HEADERS = [
        'MES', 'TIPO RIPS', 'REGISTROS', 'UNIDADES', 'COSTO A', 'ESTIMACION ADMINISTRATIVA CENTRAL', 'COMPONENTE CONDICIONADO',
        'ESTIMACION CON CONDICIONADOS', 'DIFERENCIA CENTRAL - A', 'REFERENCIA HISTORICA', 'DIFERENCIA CENTRAL - REFERENCIA',
        'IMPORTES INFORMATIVOS NO ADITIVOS', 'FUENTE', 'ESTADO',
    ];

    public const DETALLE_HEADERS = [
        'MES', 'TIPO RIPS', 'CODIGO', 'DESCRIPCION', 'ESTADO', 'SUMA EN ESTIMACION', 'SUBTIPO', 'REGISTROS', 'UNIDADES',
        'BASE DE UNIDAD', 'COSTO A', 'PRECIO HISTORICO MINIMO', 'PRECIO HISTORICO MEDIANA', 'PRECIO HISTORICO MAXIMO',
        'OBSERVACIONES HISTORICAS', 'MESES DE REFERENCIA', 'REGISTROS ADMINISTRATIVOS POR MES', 'VALOR ESTIMADO',
        'VALOR NO ADITIVO', 'FUENTE', 'MOTIVO', 'CLASE NO ADITIVO',
    ];

    public const TRATAMIENTOS_HEADERS = [
        'MES', 'CUPS', 'ESQUEMA (CLASE DE PRECIO)', 'METODO DE ASIGNACION', 'REGISTROS', 'COSTO A (APLICACION)', 'PRECIO UTILIZADO',
        'PRECIOS HISTORICOS DEL CUPS', 'VALOR CENTRAL', 'COMPONENTE CONDICIONADO', 'EVIDENCIA EN EL CASO-MES',
        'REGISTROS HISTORICOS DE LA CLASE', 'PARTICIPACION HISTORICA DE LA CLASE',
    ];

    public const MEDICAMENTOS_HEADERS = [
        'MES', 'GRUPO', 'MEDICAMENTO', 'CUM', 'REGISTROS', 'UNIDADES', 'COSTO A', 'VALOR CENTRAL', 'VALOR CONDICIONADO',
        'TRATAMIENTO INTEGRAL DEL CASO-MES', 'EVIDENCIA',
    ];

    public const COBERTURA_MEDICAMENTOS_HEADERS = [
        'MES', 'FACTURA', 'TIPO DOCUMENTO', 'DOCUMENTO PACIENTE', 'FECHA', 'CODIGO RIPS', 'CUM RESUELTO',
        'CANTIDAD RIPS', 'CONCENTRACION', 'UNIDAD', 'FORMA', 'UNIDAD MINIMA DISPENSA', 'CUPS AP',
        'INICIO EPISODIO AH', 'EGRESO EPISODIO AH', 'PAQUETES NETOS EPISODIO', 'CLASIFICACION RN-14',
        'PRECIO UNITARIO HISTORICO', 'VALOR POTENCIAL CONDICIONADO', 'FUENTE Y MOTIVO',
    ];

    public const DIFERENCIAS_HEADERS = [
        'MES', 'TIPO RIPS', 'CODIGO', 'DESCRIPCION', 'REGISTROS MES', 'REGISTROS ADMINISTRATIVOS POR MES (MEDIANA)',
        'PRECIO PROMEDIO HISTORICO POR REGISTRO', 'PRECIO PROMEDIO DEL MES POR REGISTRO', 'COSTO A', 'VALOR ADMINISTRATIVO ESTIMADO',
        'VALOR ADMINISTRATIVO MENSUAL (MEDIANA)', 'DIFERENCIA ESTIMADO - HISTORICO', 'EFECTO ACTIVIDAD',
        'EFECTO COMPOSICION, PRECIO O ESTADO', 'CAUSA PRINCIPAL',
        'VALOR ADMINISTRATIVO DEL MISMO MES', 'DIFERENCIA ESTIMADO - ADMINISTRATIVO DEL MISMO MES',
    ];

    public const PENDIENTES_HEADERS = [
        'MES', 'TIPO RIPS', 'CODIGO', 'DESCRIPCION', 'ESTADO', 'REGISTROS', 'UNIDADES', 'VALOR ESTIMADO REFERENCIAL',
        'VALOR NO ADITIVO', 'MOTIVO', 'INFORMACION FALTANTE PARA ACREDITAR EL COSTO',
    ];

    private const TIPOS = [
        'consultas' => 'AC', 'procedimientos' => 'AP', 'otrosServicios' => 'AT', 'urgencias' => 'AU',
        'medicamentos' => 'AM', 'hospitalizacion' => 'AH', 'recienNacidos' => 'AN',
    ];

    /** No son actividad distinta: ya están fuera del costo A. */
    private const EXCLUSIONES = ['DUPLICADO_EXACTO', 'FACTURA_REPETIDA_CON_DIFERENCIAS', 'NO_ASIGNABLE', 'DOBLE_RUTA', 'SIN_FECHA'];

    private const ABSORCIONES = ['INCLUIDO_EN_PAQUETE' => 'PAQUETE HOSPITALARIO AH', 'INCLUIDO_EN_URGENCIA' => 'URGENCIA COBRADA'];

    /** Precio administrativo por registro que supera la tarifa de aplicación en este factor: alcance integral. */
    private const FACTOR_INTEGRAL = 1.5;

    /** Razón aceptada entre registros administrativos por mes y registros/unidades RIPS. */
    private const RAZON_MINIMA = 0.5;

    private const RAZON_MAXIMA = 2.0;

    /** @var array<string, array{tarifa: float, tarifaMedicamento: float, esquema: string}> */
    private array $quimioterapia = [];

    /** @var array<string, float> Tarifa por tratamiento de radioterapia en la nota. */
    private array $radioterapia = [];

    /** @var array<string, array<string, mixed>> */
    private array $codigos = [];

    /** @var array<string, array<string, array{registros: int, unidades: float, costoA: int}>> */
    private array $tipos = [];

    /** @var array<string, array<string, mixed>> */
    private array $diferidos = [];

    /** @var array<string, array{registros: int, unidades: float}> mes|tipo|código => todas sus líneas no absorbidas del mes */
    private array $actividadCodigo = [];

    /** @var list<array<string, mixed>> */
    private array $integralesPendientes = [];

    /** @var array<string, array<string, mixed>> */
    private array $radioPendientes = [];

    /** @var array<string, array<string, array<string, bool>>> mes => caso => medicamentos (prefijos) */
    private array $medicamentosCaso = [];

    /** @var list<array<string, mixed>> */
    private array $oncologicos = [];

    /** @var array<string, array<string, list<array{codigo: string, composicion: list<string>, esquema: string}>>> */
    private array $integralesCaso = [];

    /** @var array<string, array<string, list<string>>> mes => paciente-mes => CUPS integrales */
    private array $integralesPacienteMes = [];

    /** @var array<string, array<string, mixed>> */
    private array $tratamientos = [];

    /** @var array<string, array<string, mixed>> */
    private array $medicamentos = [];

    /** @var array<int, array<string, mixed>> */
    private array $coberturaMedicamentos = [];

    /** @var array<string, int> */
    private array $atribucionUrgencias = [];

    /** @var array<string, int> mes => episodios AU */
    private array $episodiosUrgencias = [];

    /** @var array<string, array<string, float>> mes => tipo del componente => costo ya reconocido */
    private array $atribucionUrgenciasPorTipo = [];

    /** @var list<array{id: int, mes: string, caso: string, factura: string, fecha: string, egreso: string, tipo: string, codigo: string, cantidad: float}> */
    private array $componentesUrgencias = [];

    /** @var array<int, int> Importe aditivo administrativo exacto por línea RIPS. */
    private array $valoracionLinea = [];

    /** @var array<string, array<string, int>> mes => concepto => centavos */
    private array $noAditivos = [];

    /** @var array<string, list<string>> */
    private array $composicionOtros = [];

    private bool $cerrada = false;

    /**
     * @param  list<array<string, mixed>>  $notaTecnica  Conceptos de la nota de la ruta.
     * @param  Closure(string): list<array{mes: string, tarifa: float}>  $preciosMedicamento  Historial unitario por CUM.
     */
    public function __construct(
        private readonly string $ruta,
        private readonly HistorialAdministrativo $historial,
        array $notaTecnica,
        private readonly Closure $preciosMedicamento,
    ) {
        foreach ($notaTecnica as $concepto) {
            $tarifaMedicamento = (float) ($concepto['metadatos']['tarifa_medicamento'] ?? 0);

            if (($concepto['categoria'] ?? '') === 'ONCOLOGIA_QUIMIOTERAPIA' && $tarifaMedicamento > 0) {
                $this->quimioterapia[(string) $concepto['codigo']] = [
                    'tarifa' => (float) ($concepto['tarifaReferencia'] ?? 0),
                    'tarifaMedicamento' => $tarifaMedicamento,
                    'esquema' => (string) ($concepto['metadatos']['esquema'] ?? ''),
                ];
            }

            if (($concepto['categoria'] ?? '') === 'ONCOLOGIA_RADIOTERAPIA' && (float) ($concepto['tarifaReferencia'] ?? 0) > 0) {
                $this->radioterapia[(string) $concepto['codigo']] = (float) $concepto['tarifaReferencia'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $detalle  Línea del DETALLE RIPS.
     * @param  array<string, mixed>  $economico  Resultado de ResumenEconomico::registrar().
     */
    public function registrar(array $detalle, array $economico): void
    {
        $mes = (string) ($detalle['mesAtencion'] ?? '');
        $tipo = self::TIPOS[(string) ($detalle['tipoServicio'] ?? '')] ?? mb_strtoupper((string) ($detalle['tipoServicio'] ?? ''));
        $codigo = (string) ($detalle['codigo'] ?? '');
        $caso = (string) ($detalle['casoId'] ?? '');
        $casoFactura = $caso.'|'.(string) ($detalle['factura'] ?? '');
        $cantidad = is_numeric($detalle['cantidadAplicada'] ?? null)
            ? (float) $detalle['cantidadAplicada']
            : ($tipo === 'AM' ? 0.0 : 1.0);
        $costoA = ResumenEconomico::centavos($economico['respaldado'] ?? 0);
        $linea = [
            'mes' => $mes, 'tipo' => $tipo, 'codigo' => $codigo, 'cantidad' => $cantidad, 'costoA' => $costoA,
            'descripcion' => (string) (($detalle['descripcionOficial'] ?? '') ?: ($detalle['nombreServicio'] ?? '') ?: ($detalle['principioActivo'] ?? '')),
            'id' => count($this->componentesUrgencias),
        ];

        $this->componentesUrgencias[] = [
            'id' => $linea['id'], 'mes' => $mes, 'caso' => $caso, 'factura' => (string) ($detalle['factura'] ?? ''),
            'fecha' => (string) ($detalle['fechaAtencion'] ?? ''),
            'egreso' => (string) ($detalle['servicioJson']['fechaEgreso'] ?? ''),
            'tipo' => $tipo, 'codigo' => $codigo, 'cantidad' => $cantidad,
        ];

        $this->tipos[$mes][$tipo] ??= ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0];
        $this->tipos[$mes][$tipo]['registros']++;
        $this->tipos[$mes][$tipo]['unidades'] += $cantidad;
        $this->tipos[$mes][$tipo]['costoA'] += $costoA;

        $motivoNoSuma = (string) ($detalle['motivoNoSuma'] ?? '');

        if (in_array($motivoNoSuma, self::EXCLUSIONES, true)) {
            $this->agregar($linea, self::SIN_VALOR, false, 'EXCLUIDO DEL COSTO A', 'CLASIFICACION SIRUTA', 'EXCLUIDO DEL COSTO A: '.$motivoNoSuma);

            return;
        }

        $absorcion = self::ABSORCIONES[(string) ($detalle['decisionPaquete'] ?? '')] ?? null;

        if ($tipo === 'AH') {
            $this->agregar($linea, self::INCLUIDA, false, 'REGISTRO DE ESTANCIA', 'LIBRO DE PAQUETES',
                'La estancia se valora por paquetes de 25 días (control AH).');

            return;
        }

        if ($tipo === 'AU') {
            $this->agregar($linea, self::INCLUIDA, false, 'CONTENEDOR DE URGENCIA', 'ATRIBUCION AU',
                'Sus componentes ya se valoran como AC, AP o AM; la atribución AU es informativa y no aditiva.');

            return;
        }

        if ($tipo === 'AM') {
            // Evidencia del esquema del caso-mes: todos sus medicamentos, incluidos los absorbidos.
            foreach (self::medicamentosLinea($detalle) as $token) {
                $this->medicamentosCaso[$mes][$casoFactura][$token] = true;
            }

            $this->registrarMedicamento($linea, $detalle, $economico, $absorcion, $caso, $casoFactura);

            return;
        }

        $precioEstancia = $this->historial->precio($this->ruta, $mes, 'AH', $codigo);

        if ($precioEstancia !== null) {
            $this->agregar($linea, self::INCLUIDA, false, 'ESTANCIA (VALORADA COMO PAQUETE AH)', 'INFORME ADMINISTRATIVO (CLASIFICADO AH)',
                'El administrativo cobra este código como paquete hospitalario; SIRUTA ya cuenta el paquete por bloques de 25 días (control AH).',
                $precioEstancia, 0, ResumenEconomico::centavos($precioEstancia['mediana']), 'REGISTRO');
            $this->sumarNoAditivo($mes, 'AH SEGUN METODOLOGIA ADMINISTRATIVA (REGISTRO x PAQUETE)', ResumenEconomico::centavos($precioEstancia['mediana']));

            return;
        }

        if ($absorcion !== null) {
            $this->agregar($linea, self::INCLUIDA, false, 'INCLUIDO EN '.$absorcion, 'REGLA HOSPITALARIA SIRUTA',
                'Servicio absorbido; no se cobra por separado.');

            return;
        }

        $precio = $this->historial->precio($this->ruta, $mes, $tipo, $codigo);
        $quimio = $this->quimioterapia[$codigo] ?? null;

        if ($tipo === 'AP' && $quimio !== null && $precio !== null && $precio['mediana'] > $quimio['tarifa'] * self::FACTOR_INTEGRAL) {
            // RN-02/RN-03: el precio depende del esquema real; se decide al cerrar el mes.
            $this->integralesPendientes[] = ['linea' => $linea, 'caso' => $caso, 'casoFactura' => $casoFactura];

            return;
        }

        if (($detalle['tipoValorReferencia'] ?? '') === 'TARIFA_TRATAMIENTO_PENDIENTE') {
            $clave = $mes.'|'.$tipo.'|'.$codigo;
            $this->radioPendientes[$clave] ??= [...$linea, 'registros' => 0, 'unidades' => 0.0, 'casos' => [], 'precio' => $precio];
            $this->radioPendientes[$clave]['registros']++;
            $this->radioPendientes[$clave]['unidades'] += $cantidad;
            $this->radioPendientes[$clave]['casos'][$caso] = true;

            return;
        }

        // Actividad facturable del código en el mes (con o sin tarifa): base para comparar unidades con el historial.
        $this->actividadCodigo[$mes.'|'.$tipo.'|'.$codigo]['registros'] = ($this->actividadCodigo[$mes.'|'.$tipo.'|'.$codigo]['registros'] ?? 0) + 1;
        $this->actividadCodigo[$mes.'|'.$tipo.'|'.$codigo]['unidades'] = ($this->actividadCodigo[$mes.'|'.$tipo.'|'.$codigo]['unidades'] ?? 0.0) + $cantidad;

        if ($costoA > 0) {
            $this->agregar($linea, self::RESPALDADA, true, 'TARIFA APLICABLE', (string) ($detalle['fuenteTarifa'] ?? 'NOTA_TECNICA'),
                $precio !== null && abs($precio['mediana'] - (float) ($detalle['tarifaReferencia'] ?? 0)) >= 1.0
                    ? sprintf('Precio administrativo por registro distinto ($%s); se conserva la tarifa aplicable.', number_format($precio['mediana'], 2, ',', '.'))
                    : 'Tarifa aplicable igual al precio administrativo o sin historial distinto.',
                $precio, $costoA, 0, 'REGISTRO');

            return;
        }

        if ($tipo === 'AC') {
            $this->agregar($linea, $precio === null ? self::SIN_VALOR : self::ESTIMADA, false, 'CONTROL AC',
                $precio === null ? 'SIN FUENTE' : 'INFORME ADMINISTRATIVO (MEDIANA POR REGISTRO)',
                'AC se conserva igual al costo A; el valor histórico se muestra como referencia no aditiva.',
                $precio, 0, $precio === null ? 0 : ResumenEconomico::centavos($precio['mediana']), 'REGISTRO');

            if ($precio !== null) {
                $this->sumarNoAditivo($mes, 'AC SIN TARIFA CON PRECIO HISTORICO (CONTROL AC)', ResumenEconomico::centavos($precio['mediana']));
            }

            return;
        }

        // RN-05 / RN-06 sin tarifa aplicable: la unidad se decide con todas las líneas del código.
        $clave = $mes.'|'.$tipo.'|'.$codigo;
        $this->diferidos[$clave] ??= [...$linea, 'registros' => 0, 'unidades' => 0.0, 'maximo' => 0.0, 'costoA' => 0, 'condicionado' => 0];
        $this->diferidos[$clave]['registros']++;
        $this->diferidos[$clave]['unidades'] += $cantidad;
        $this->diferidos[$clave]['maximo'] = max($this->diferidos[$clave]['maximo'], $cantidad);
        $this->diferidos[$clave]['condicionado'] += ResumenEconomico::centavos(($economico['condicionadoIncluido'] ?? 0) + ($economico['referencialAdicional'] ?? 0));
    }

    /**
     * @param  list<array<int, mixed>>  $filasHospitalizacion  Filas de HOSPITALIZACION (mes, paquetes, costo...).
     */
    public function atribuirPaquetes(array $filasHospitalizacion): void
    {
        foreach ($filasHospitalizacion as $fila) {
            $mes = (string) ($fila[0] ?? '');

            if ($mes === '' || $mes === 'TOTAL') {
                continue;
            }

            $costo = ResumenEconomico::centavos($fila[2] ?? 0);
            $this->tipos[$mes]['AH'] ??= ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0];
            $this->tipos[$mes]['AH']['costoA'] += $costo;
            $this->agregar(['mes' => $mes, 'tipo' => 'AH', 'codigo' => 'PAQUETE_HOSPITALARIO_25D', 'cantidad' => (float) ($fila[1] ?? 0),
                'costoA' => $costo, 'descripcion' => 'Paquetes netos de 25 días'], self::RESPALDADA, true, 'CONTROL AH',
                'NOTA TECNICA / LIBRO DE PAQUETES', 'AH se conserva igual al costo A.', null, $costo, 0, 'PAQUETE', registros: 0);
        }
    }

    /**
     * @param  list<array<int, mixed>>  $episodios  Filas de ConciliacionRipsNota::filasEpisodiosUrgencias().
     * @param  array<string, array<string, float>>  $costoPorTipo  ConciliacionRipsNota::costoUrgenciasPorTipo().
     */
    public function atribuirUrgencias(array $episodios, array $costoPorTipo = []): void
    {
        foreach ($episodios as $episodio) {
            $mes = (string) ($episodio[0] ?? '');
            $this->atribucionUrgencias[$mes] = ($this->atribucionUrgencias[$mes] ?? 0) + ResumenEconomico::centavos($episodio[7] ?? 0);
            $this->episodiosUrgencias[$mes] = ($this->episodiosUrgencias[$mes] ?? 0) + 1;
        }

        $this->atribucionUrgenciasPorTipo = $costoPorTipo;
    }

    /**
     * Resumen económico por servicio del mes: costo A, estimación central,
     * atribución AU y valor condicionado. El total solo suma conceptos
     * aditivos: la atribución AU ya está dentro de AC/AP/AM y el condicionado
     * no entra en la estimación central.
     *
     * @return array{filas: list<list<mixed>>, totales: array{registros: int, costoA: float, estimado: float, atribuidoAu: float, condicionado: float}, urgencias: array{episodios: int, atribuido: float, porTipo: array<string, float>, referencia: float|null, meses: list<string>, cobroIndependiente: float}, conHistorial: bool, meses: list<string>}
     */
    public function resumenServicios(string $mes): array
    {
        $this->cerrar();
        $atribucionAdministrativa = $this->atribucionAdministrativaUrgencias($mes);
        $ventana = $this->historial->ventana($this->ruta, $mes);
        $episodios = $this->episodiosUrgencias[$mes] ?? 0;
        $atribuido = ($this->atribucionUrgencias[$mes] ?? 0) / 100.0;
        $cobroUrgencias = ($this->tipos[$mes]['AU']['costoA'] ?? 0) / 100.0;
        $porTipo = array_filter($this->atribucionUrgenciasPorTipo[$mes] ?? [], static fn (float $costo): bool => $costo > 0);
        uksort($porTipo, static fn (string $a, string $b): int => array_search($a, self::TIPOS_ORDEN, true) <=> array_search($b, self::TIPOS_ORDEN, true));
        $filas = [];
        $total = ['registros' => 0, 'costoA' => 0, 'estimado' => 0, 'condicionado' => 0];
        $pendientes = $this->registrosPendientesPorTipo($mes);
        $costoASinCobertura = $this->costoAMedicamentosGrupo($mes, self::GRUPO_DESCONOCIDO);

        foreach (self::TIPOS_ORDEN as $tipo) {
            if ($tipo === 'AN' && ! isset($this->tipos[$mes][$tipo])) {
                continue;
            }

            $datos = $this->tipos[$mes][$tipo] ?? ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0];
            $sumas = $this->totalesTipo($mes, $tipo);
            $estado = match ($tipo) {
                'AC' => 'COSTO A ACREDITADO; LA ESTIMACION CONSERVA EL COSTO A (CONTROL)',
                'AP' => 'ESTIMACION REFERENCIAL POR CUPS Y ESQUEMA INTEGRAL; MEDICAMENTOS DEL ESQUEMA INCLUIDOS UNA SOLA VEZ',
                'AT' => 'TRANSPORTE Y ALBERGUE CON PRECIO HISTORICO Y UNIDAD COMPATIBLE; DEMAS AT PENDIENTES SIN COBRO INDEPENDIENTE DEMOSTRADO',
                'AU' => 'ATRIBUIDO, NO ADITIVO: '.$episodios.' EPISODIOS; COSTO YA RECONOCIDO EN '
                    .(implode('/', array_keys($porTipo)) ?: 'LOS SERVICIOS ENLAZADOS')
                    .'; COBRO INDEPENDIENTE DEMOSTRADO $'.number_format($cobroUrgencias, 2, ',', '.'),
                'AM' => 'MEDICAMENTOS INDEPENDIENTES; INCLUIDOS CON COBERTURA DEMOSTRADA EN AP INTEGRAL (COSTO A NO SUMADO: $'
                    .number_format($this->medicamentosIncluidosIntegral($mes) / 100.0, 2, ',', '.').')'
                    .($costoASinCobertura > 0
                        ? '; ASOCIADOS A AP SIN COBERTURA DEMOSTRADA, CONDICIONADOS (COSTO A NO SUMADO: $'.number_format($costoASinCobertura / 100.0, 2, ',', '.').')'
                        : ''),
                'AH' => 'COSTO A ACREDITADO (PAQUETES DE 25 DIAS); LA ESTIMACION CONSERVA EL COSTO A (CONTROL)',
                default => 'ESTIMACION REFERENCIAL',
            };
            $filas[] = [$tipo, self::SERVICIOS[$tipo], $datos['registros'], $datos['costoA'] / 100.0, $sumas['estimado'] / 100.0,
                $tipo === 'AU' ? $atribuido : '', $sumas['condicionado'] / 100.0,
                ($ventana === [] ? 'SIN HISTORIAL ADMINISTRATIVO PARA EL MES · ' : '').$estado, round($datos['unidades'], 4),
                $tipo === 'AU' ? array_sum($atribucionAdministrativa) / 100.0 : '', $pendientes[$tipo] ?? 0];
            $total['registros'] += $datos['registros'];
            $total['costoA'] += $datos['costoA'];
            $total['estimado'] += $sumas['estimado'];
            $total['condicionado'] += $sumas['condicionado'];
        }

        $filas[] = ['TOTAL', 'Total aditivo', $total['registros'], $total['costoA'] / 100.0, $total['estimado'] / 100.0, $atribuido,
            $total['condicionado'] / 100.0, 'SOLO CONCEPTOS ADITIVOS; EL COSTO AU ATRIBUIDO YA ESTA EN EL COSTO A Y EL CONDICIONADO NO SE SUMA',
            round(array_sum(array_map(static fn (array $dato): float => $dato['unidades'], $this->tipos[$mes] ?? [])), 4),
            array_sum($atribucionAdministrativa) / 100.0, array_sum($pendientes)];
        $referencia = $this->historial->referenciaTipo($this->ruta, $mes, 'AU');

        return [
            'filas' => $filas,
            'totales' => ['registros' => $total['registros'], 'costoA' => $total['costoA'] / 100.0, 'estimado' => $total['estimado'] / 100.0,
                'atribuidoAu' => $atribuido, 'condicionado' => $total['condicionado'] / 100.0],
            'urgencias' => ['episodios' => $episodios, 'atribuido' => $atribuido, 'porTipo' => $porTipo,
                'referencia' => $referencia === null ? null : round($referencia['mediana'], 2), 'meses' => $ventana,
                'cobroIndependiente' => $cobroUrgencias, 'atribuidoAdministrativo' => array_sum($atribucionAdministrativa) / 100.0,
                'administrativoPorTipo' => array_map(static fn (int $centavos): float => $centavos / 100.0, $atribucionAdministrativa)],
            'conHistorial' => $ventana !== [],
            'meses' => $ventana,
        ];
    }

    /** @return array<string, int> Registros con importe pendiente, sin duplicar alternativas de cálculo. */
    private function registrosPendientesPorTipo(string $mes): array
    {
        $pendientes = [];

        foreach ($this->codigos as $codigo) {
            if ($codigo['mes'] !== $mes || $codigo['registros'] <= 0
                || ! in_array($codigo['estado'], [self::CONDICIONADA, self::SIN_VALOR], true)
                || str_starts_with($codigo['subtipo'], 'EXCLUIDO')
                || $codigo['claseNoAditivo'] === self::NO_ADITIVO_ALTERNATIVA) {
                continue;
            }

            $pendientes[$codigo['tipo']] = ($pendientes[$codigo['tipo']] ?? 0) + $codigo['registros'];
        }

        return $pendientes;
    }

    /**
     * Mayores cambios AP entre dos meses cargados, calculados únicamente desde
     * los CUPS que sumaron a la estimación central de cada mes.
     *
     * @return list<array{codigo: string, registrosAnterior: int, registrosActual: int, unidadesAnterior: float, unidadesActual: float, valorAnterior: float, valorActual: float, diferencia: float}>
     */
    public function principalesVariacionesCups(string $mesAnterior, string $mesActual, int $limite = 8): array
    {
        $this->cerrar();
        $porMes = [];

        foreach ($this->codigos as $codigo) {
            if ($codigo['tipo'] !== 'AP' || ! $codigo['suma'] || $codigo['registros'] <= 0
                || ! in_array($codigo['mes'], [$mesAnterior, $mesActual], true)) {
                continue;
            }

            $fila = &$porMes[$codigo['mes']][$codigo['codigo']];
            $fila ??= ['registros' => 0, 'unidades' => 0.0, 'valor' => 0];
            $fila['registros'] += $codigo['registros'];
            $fila['unidades'] += $codigo['unidades'];
            $fila['valor'] += $codigo['estimado'];
            unset($fila);
        }

        $codigos = array_unique([...array_keys($porMes[$mesAnterior] ?? []), ...array_keys($porMes[$mesActual] ?? [])]);
        $filas = [];

        foreach ($codigos as $codigo) {
            $anterior = $porMes[$mesAnterior][$codigo] ?? ['registros' => 0, 'unidades' => 0.0, 'valor' => 0];
            $actual = $porMes[$mesActual][$codigo] ?? ['registros' => 0, 'unidades' => 0.0, 'valor' => 0];
            $filas[] = ['codigo' => (string) $codigo, 'registrosAnterior' => $anterior['registros'],
                'registrosActual' => $actual['registros'], 'unidadesAnterior' => round($anterior['unidades'], 4),
                'unidadesActual' => round($actual['unidades'], 4), 'valorAnterior' => $anterior['valor'] / 100.0,
                'valorActual' => $actual['valor'] / 100.0, 'diferencia' => ($actual['valor'] - $anterior['valor']) / 100.0];
        }

        usort($filas, static fn (array $a, array $b): int => abs($b['diferencia']) <=> abs($a['diferencia']) ?: $a['codigo'] <=> $b['codigo']);

        return array_slice($filas, 0, max(0, $limite));
    }

    /**
     * Reclasificación informativa: cada centavo trasladado a AU se descuenta
     * del servicio de origen. Los códigos con varios precios/esquemas quedan
     * sin atribuir porque el agregado no identifica el precio de esa línea.
     *
     * @return list<array{tipo: string, original: float, trasladadoAu: float, gerencial: float}>
     */
    public function vistaGerencial(string $mes): array
    {
        $resumen = $this->resumenServicios($mes);
        $administrativa = $this->atribucionAdministrativaUrgencias($mes);
        $filas = [];

        foreach ($resumen['filas'] as $fila) {
            $tipo = (string) $fila[0];

            if ($tipo === 'TOTAL') {
                continue;
            }

            $original = ResumenEconomico::centavos($fila[4]);
            $trasladado = $tipo === 'AU' ? array_sum($administrativa) : -($administrativa[$tipo] ?? 0);
            $filas[] = ['tipo' => $tipo, 'original' => $original / 100.0,
                'trasladadoAu' => $trasladado / 100.0, 'gerencial' => ($original + $trasladado) / 100.0];
        }

        return $filas;
    }

    /** @return array<string, int> Centavos atribuibles por tipo de origen. */
    private function atribucionAdministrativaUrgencias(string $mes): array
    {
        $episodios = array_values(array_filter($this->componentesUrgencias,
            static fn (array $fila): bool => $fila['mes'] === $mes && $fila['tipo'] === 'AU'
                && $fila['fecha'] !== '' && $fila['egreso'] >= $fila['fecha']));
        $enlazados = [];
        $porTipo = [];

        foreach ($this->componentesUrgencias as $componente) {
            if ($componente['mes'] !== $mes || in_array($componente['tipo'], ['AU', 'AH'], true)) {
                continue;
            }

            $coincidencias = array_filter($episodios, static fn (array $episodio): bool => $episodio['caso'] === $componente['caso'] && $episodio['factura'] === $componente['factura']
                && $componente['fecha'] >= $episodio['fecha'] && $componente['fecha'] <= $episodio['egreso']);

            if (count($coincidencias) === 1) {
                if (isset($this->valoracionLinea[$componente['id']])) {
                    $porTipo[$componente['tipo']] = ($porTipo[$componente['tipo']] ?? 0) + $this->valoracionLinea[$componente['id']];

                    continue;
                }

                $clave = $componente['tipo'].'|'.$componente['codigo'];
                $enlazados[$clave]['registros'] = ($enlazados[$clave]['registros'] ?? 0) + 1;
                $enlazados[$clave]['unidades'] = ($enlazados[$clave]['unidades'] ?? 0.0) + $componente['cantidad'];
            }
        }

        $porCodigo = [];

        foreach ($this->codigos as $codigo) {
            if ($codigo['mes'] === $mes && $codigo['tipo'] !== 'AU' && $codigo['tipo'] !== 'AH') {
                $porCodigo[$codigo['tipo'].'|'.$codigo['codigo']][] = $codigo;
            }
        }

        foreach ($enlazados as $clave => $cantidad) {
            $candidatos = $porCodigo[$clave] ?? [];

            if (count($candidatos) !== 1 || ! $candidatos[0]['suma']) {
                continue;
            }

            $codigo = $candidatos[0];
            $base = $codigo['base'] === 'UNIDAD' ? 'unidades' : 'registros';
            $denominador = (float) $codigo[$base];

            if ($denominador <= 0) {
                continue;
            }

            $trasladado = min((int) $codigo['estimado'], (int) round($codigo['estimado'] * $cantidad[$base] / $denominador));
            $porTipo[$codigo['tipo']] = ($porTipo[$codigo['tipo']] ?? 0) + $trasladado;
        }

        return $porTipo;
    }

    /**
     * Costo A por tipo RIPS tal como lo calculó SIRUTA (sin cambios), con su
     * control contra el costo respaldado del mes.
     *
     * @param  array<string, float>  $costoAPorMes
     * @return list<array<int, mixed>>
     */
    public function filasCostoA(array $costoAPorMes): array
    {
        $filas = [];

        foreach ($this->meses() as $mes) {
            $total = ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0];

            foreach (self::TIPOS_ORDEN as $tipo) {
                if (! isset($this->tipos[$mes][$tipo])) {
                    continue;
                }

                $datos = $this->tipos[$mes][$tipo];
                $filas[] = [$mes, $tipo, $datos['registros'], round($datos['unidades'], 4), $datos['costoA'] / 100.0,
                    $tipo === 'AH' ? 'Paquetes de 25 días atribuidos al mes' : ''];
                $total['registros'] += $datos['registros'];
                $total['unidades'] += $datos['unidades'];
                $total['costoA'] += $datos['costoA'];
            }

            $diferencia = round($total['costoA'] / 100.0 - (float) ($costoAPorMes[$mes] ?? 0), 2);
            $filas[] = [$mes, 'TOTAL', $total['registros'], round($total['unidades'], 4), $total['costoA'] / 100.0,
                abs($diferencia) < 0.005 ? 'IGUAL AL COSTO RESPALDADO DE COSTOS Y BRECHAS' : 'DIFERENCIA CON COSTOS Y BRECHAS: '.number_format($diferencia, 2, ',', '.')];
        }

        return $filas;
    }

    /**
     * Resultado por servicio: costo A, estimación central, componente
     * condicionado y comparación con el histórico. Los importes informativos
     * y las alternativas de unidad no se suman a ningún total.
     *
     * @param  array<string, float>  $costoAPorMes  Costo A total por mes (COSTOS Y BRECHAS).
     * @return list<array<int, mixed>>
     */
    public function filasComparacion(array $costoAPorMes): array
    {
        $this->cerrar();
        $filas = [];

        foreach ($this->meses() as $mes) {
            $vacio = ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0, 'estimado' => 0, 'condicionado' => 0, 'informativo' => 0, 'referencia' => 0.0];
            $total = $apAm = $vacio;
            $conHistorial = $this->historial->ventana($this->ruta, $mes) !== [];

            foreach (self::TIPOS_ORDEN as $tipo) {
                $datos = $this->tipos[$mes][$tipo] ?? null;
                $referencia = $this->historial->referenciaTipo($this->ruta, $mes, $tipo);

                if ($datos === null && ($referencia === null || $referencia['mediana'] == 0.0)) {
                    continue;
                }

                $fila = $this->totalesTipo($mes, $tipo) + ($datos ?? ['registros' => 0, 'unidades' => 0.0, 'costoA' => 0]);
                $fila['informativo'] += $tipo === 'AU' ? ($this->atribucionUrgencias[$mes] ?? 0) : 0;
                $fila['referencia'] = $referencia['mediana'] ?? 0.0;
                $filas[] = $this->filaComparacion($mes, $tipo, $fila, $referencia === null ? null : $referencia['mediana'],
                    $this->fuenteTipo($tipo, $referencia), $this->estadoTipo($tipo, $mes));
                $total = $this->sumarFila($total, $fila);

                if (in_array($tipo, ['AP', 'AM'], true)) {
                    $apAm = $this->sumarFila($apAm, $fila);
                }
            }

            $filas[] = $this->filaComparacion($mes, 'AP + AM', $apAm, $conHistorial ? $apAm['referencia'] : null,
                'Comparable con el administrativo: su AP incluye los medicamentos del tratamiento integral y su AM está vacío.',
                'SUBTOTAL INFORMATIVO (NO SE SUMA AL TOTAL)');
            $controlA = round($total['costoA'] / 100.0 - (float) ($costoAPorMes[$mes] ?? $total['costoA'] / 100.0), 2);
            $filas[] = $this->filaComparacion($mes, 'TOTAL', $total, $conHistorial ? $total['referencia'] : null,
                'Referencia: suma de medianas mensuales por tipo. Control: costo A por tipo − costo A del mes = '.number_format($controlA, 2, ',', '.').'.',
                'ESTIMACION ADMINISTRATIVA REFERENCIAL; NO ES FACTURACION');

            if ($conHistorial) {
                $ilustrativa = ($this->tipos[$mes]['AC']['costoA'] ?? 0) / 100.0 + ($this->tipos[$mes]['AH']['costoA'] ?? 0) / 100.0
                    + ($this->historial->referenciaTipo($this->ruta, $mes, 'AP')['mediana'] ?? 0.0)
                    + ($this->historial->referenciaTipo($this->ruta, $mes, 'AT')['mediana'] ?? 0.0);
                $filas[] = [$mes, 'REFERENCIA ILUSTRATIVA', '', '', '', '', '', '', '', round($ilustrativa, 2),
                    round($total['estimado'] / 100.0 - $ilustrativa, 2), '',
                    'AC y AH del costo A del mes + medianas administrativas de AP y AT. Es una referencia estadística, no un objetivo.', 'NO ES RESULTADO DEMOSTRADO'];
            }

            foreach ($this->noAditivos[$mes] ?? [] as $concepto => $centavos) {
                $filas[] = [$mes, 'NO ADITIVO', '', '', '', '', '', '', '', '', '', $centavos / 100.0, $concepto, 'NO SE SUMA A NINGUN TOTAL'];
            }

            if (($this->atribucionUrgencias[$mes] ?? 0) > 0) {
                $filas[] = [$mes, 'NO ADITIVO', '', '', '', '', '', '', '', '', '', $this->atribucionUrgencias[$mes] / 100.0,
                    'COSTO YA RECONOCIDO EN AC/AP/AM Y ATRIBUIDO A EPISODIOS AU', 'NO SE SUMA A NINGUN TOTAL'];
            }
        }

        return $filas;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function filasDetalle(): array
    {
        $this->cerrar();
        $filas = [];

        foreach ($this->codigos as $codigo) {
            $precio = $codigo['precio'];
            $filas[] = [$codigo['mes'], $codigo['tipo'], $codigo['codigo'], $codigo['descripcion'], $codigo['estado'],
                $codigo['suma'] ? 'SI' : 'NO', $codigo['subtipo'], $codigo['registros'], round($codigo['unidades'], 4),
                $codigo['base'], $codigo['costoA'] / 100.0, $precio['minimo'] ?? '', $precio['mediana'] ?? '', $precio['maximo'] ?? '',
                $precio['observaciones'] ?? '', $precio === null ? '' : implode(', ', $precio['meses']).($precio['ventanaReciente'] ? '' : ' (fuera de la ventana reciente)'),
                $precio['registrosMensuales'] ?? '', $codigo['estimado'] / 100.0, $codigo['noAditivo'] / 100.0,
                $codigo['fuente'], implode(' | ', array_keys($codigo['motivos'])), $codigo['claseNoAditivo']];
        }

        usort($filas, static fn (array $a, array $b): int => [$a[0], array_search($a[1], self::TIPOS_ORDEN, true), -$a[17], -$a[18], $a[2]]
            <=> [$b[0], array_search($b[1], self::TIPOS_ORDEN, true), -$b[17], -$b[18], $b[2]]);

        return $filas;
    }

    /**
     * RN-11: conserva el universo de líneas AM y su unidad RIPS antes de
     * comparar cualquier conteo administrativo externo.
     *
     * @return list<array{grupo: string, registros: int, unidades: float, costoA: float, estimado: float}>
     */
    public function conciliacionMedicamentos(string $mes): array
    {
        $grupos = [];

        foreach ($this->filasDetalle() as $fila) {
            if ($fila[0] !== $mes || $fila[1] !== 'AM') {
                continue;
            }

            $grupo = match (true) {
                str_contains((string) $fila[6], 'PAQUETE HOSPITALARIO AH') => 'INCLUIDO_AH',
                str_starts_with((string) $fila[6], 'B ·') => 'INCLUIDO_AP',
                str_starts_with((string) $fila[6], 'RN-14 ·'), str_starts_with((string) $fila[6], 'ASOCIACION') => 'CONDICIONADO_AP',
                str_starts_with((string) $fila[6], 'INCLUIDO EN URGENCIA') => 'INCLUIDO_URGENCIA',
                $fila[6] === self::SUBTIPO_DISPENSACION_MISMO_PERIODO => 'CONDICIONADO_PRECIO_MISMO_PERIODO',
                str_starts_with((string) $fila[6], 'A ·') => 'INDEPENDIENTE_TARIFA_A',
                str_starts_with((string) $fila[6], 'C ·') => 'INDEPENDIENTE_HISTORICO_UNITARIO',
                default => 'SIN_VALOR_INDEPENDIENTE',
            };
            $grupos[$grupo] ??= ['grupo' => $grupo, 'registros' => 0, 'unidades' => 0.0, 'costoA' => 0.0, 'estimado' => 0.0];
            $grupos[$grupo]['registros'] += (int) $fila[7];
            $grupos[$grupo]['unidades'] += (float) $fila[8];
            $grupos[$grupo]['costoA'] += (float) $fila[10];
            $grupos[$grupo]['estimado'] += (float) $fila[17];
        }

        return array_values($grupos);
    }

    /**
     * RN-12: expone la base de cobro y la procedencia por código AC/AT sin
     * convertir una diferencia agregada en una tarifa nueva.
     *
     * @return list<array{tipo: string, codigo: string, registros: int, unidades: float, base: string, precioHistorico: float|null, estimado: float, noAditivo: float}>
     */
    public function conciliacionUnidades(string $mes): array
    {
        $filas = [];

        foreach ($this->filasDetalle() as $fila) {
            if ($fila[0] !== $mes || ! in_array($fila[1], ['AC', 'AT'], true)) {
                continue;
            }

            $filas[] = ['tipo' => (string) $fila[1], 'codigo' => (string) $fila[2], 'registros' => (int) $fila[7],
                'unidades' => (float) $fila[8], 'base' => (string) ($fila[9] ?? ''),
                'precioHistorico' => is_numeric($fila[12]) ? (float) $fila[12] : null,
                'estimado' => (float) $fila[17], 'noAditivo' => (float) $fila[18]];
        }

        return $filas;
    }

    /**
     * Líneas con valor condicionado o alternativo (estimación condicionada).
     *
     * @return list<array<int, mixed>>
     */
    public function filasCondicionadas(): array
    {
        return array_values(array_filter($this->filasDetalle(), static fn (array $fila): bool => in_array($fila[21],
            [self::NO_ADITIVO_CONDICIONADO, self::NO_ADITIVO_ALTERNATIVA], true) && $fila[18] > 0));
    }

    /**
     * Tratamientos integrales por CUPS y esquema (clase de precio histórica).
     *
     * @return list<array<int, mixed>>
     */
    public function filasTratamientos(): array
    {
        $this->cerrar();
        $filas = [];

        foreach ($this->tratamientos as $tratamiento) {
            $filas[] = [$tratamiento['mes'], $tratamiento['codigo'], $tratamiento['esquema'], $tratamiento['metodo'],
                $tratamiento['registros'], $tratamiento['costoA'] / 100.0, $tratamiento['precio'], $tratamiento['alternativos'],
                $tratamiento['valor'] / 100.0, $tratamiento['condicionado'] / 100.0, implode(' | ', array_slice(self::ordenadas(array_keys($tratamiento['evidencia'])), 0, 8)),
                $tratamiento['registrosClase'], $tratamiento['participacion']];
        }

        usort($filas, static fn (array $a, array $b): int => [$a[0], $a[1], -$a[8]] <=> [$b[0], $b[1], -$b[8]]);

        return $filas;
    }

    /**
     * Medicamentos oncológicos según su asociación con el tratamiento integral.
     *
     * @return list<array<int, mixed>>
     */
    public function filasMedicamentosAsociados(): array
    {
        $this->cerrar();
        $filas = [];

        foreach ($this->medicamentos as $medicamento) {
            $filas[] = [$medicamento['mes'], $medicamento['grupo'], $medicamento['nombre'], $medicamento['cum'], $medicamento['registros'],
                round($medicamento['unidades'], 4), $medicamento['costoA'] / 100.0, $medicamento['central'] / 100.0,
                $medicamento['condicionado'] / 100.0, implode(', ', array_keys($medicamento['integrales'])), $medicamento['evidencia']];
        }

        usort($filas, static fn (array $a, array $b): int => [$a[0], $a[1], -($a[6] + $a[7] + $a[8])] <=> [$b[0], $b[1], -($b[6] + $b[7] + $b[8])]);

        return $filas;
    }

    /**
     * Una fila por línea AM; los identificadores permanecen en el libro local controlado.
     *
     * @return list<array<int, mixed>>
     */
    public function filasCoberturaMedicamentos(): array
    {
        $this->cerrar();

        return array_values(array_map(static fn (array $linea): array => [
            $linea['mes'], $linea['factura'], $linea['tipoDocumento'], $linea['documento'], $linea['fecha'],
            $linea['codigo'], $linea['cum'], $linea['cantidad'], $linea['concentracion'], $linea['unidad'],
            $linea['forma'], $linea['unidadMinima'], $linea['ap'], $linea['inicioAh'], $linea['egresoAh'],
            $linea['paquetesAh'], $linea['clasificacion'], $linea['precio'], $linea['potencial'] / 100.0, $linea['fuente'],
        ], array_filter($this->coberturaMedicamentos, static fn (array $linea): bool => $linea['mes'] !== '')));
    }

    /**
     * Diferencias por código entre la estimación del mes y el historial,
     * separando el efecto de actividad del de composición o precio.
     *
     * @return list<array<int, mixed>>
     */
    public function filasDiferencias(): array
    {
        $this->cerrar();
        $porCodigo = [];

        foreach ($this->codigos as $codigo) {
            $clave = $codigo['mes'].'|'.$codigo['tipo'].'|'.$codigo['codigo'];
            $porCodigo[$clave] ??= ['mes' => $codigo['mes'], 'tipo' => $codigo['tipo'], 'codigo' => $codigo['codigo'],
                'descripcion' => $codigo['descripcion'], 'registros' => 0, 'costoA' => 0, 'estimado' => 0, 'estados' => []];
            $porCodigo[$clave]['registros'] += $codigo['registros'];
            $porCodigo[$clave]['costoA'] += $codigo['costoA'];
            $porCodigo[$clave]['estimado'] += $codigo['estimado'];
            $porCodigo[$clave]['estados'][$codigo['estado'].($codigo['subtipo'] === '' ? '' : ' / '.$codigo['subtipo'])] = true;
        }

        foreach ($this->meses() as $mes) {
            foreach ([...$this->historial->codigosEnVentana($this->ruta, $mes), ...$this->historial->codigosDelMes($this->ruta, $mes)] as ['tipo' => $tipo, 'codigo' => $codigo]) {
                $porCodigo[$mes.'|'.$tipo.'|'.$codigo] ??= ['mes' => $mes, 'tipo' => $tipo, 'codigo' => $codigo, 'descripcion' => '',
                    'registros' => 0, 'costoA' => 0, 'estimado' => 0, 'estados' => []];
            }
        }

        $filas = [];

        foreach ($porCodigo as $dato) {
            $precio = $this->historial->precio($this->ruta, $dato['mes'], $dato['tipo'], $dato['codigo']);
            $historico = $precio['valorMensual'] ?? 0.0;
            $estimado = $dato['estimado'] / 100.0;
            $actual = $this->historial->valorDelMes($this->ruta, $dato['mes'], $dato['tipo'], $dato['codigo']);
            $mesConInforme = $this->historial->totalDelMes($this->ruta, $dato['mes']) !== null;

            if ($precio === null && $actual === null && $estimado == 0.0 && $dato['costoA'] === 0) {
                continue;
            }

            // Precio por registro implícito en el valor y los registros mensuales históricos:
            // actividad + composición suman exactamente la diferencia.
            $promedio = $precio === null ? 0.0 : ((float) $precio['registrosMensuales'] > 0
                ? round($precio['valorMensual'] / $precio['registrosMensuales'], 2)
                : $precio['promedioRegistro']);
            $diferencia = round($estimado - $historico, 2);
            $actividad = $precio === null ? 0.0 : round(($dato['registros'] - $precio['registrosMensuales']) * $promedio, 2);
            $filas[] = [$dato['mes'], $dato['tipo'], $dato['codigo'], $dato['descripcion'] ?: ($precio['descripcion'] ?? ''),
                $dato['registros'], $precio['registrosMensuales'] ?? '', $precio === null ? '' : $promedio,
                $dato['registros'] > 0 ? round($estimado / $dato['registros'], 2) : '', $dato['costoA'] / 100.0, $estimado,
                $historico, $diferencia, $actividad, round($diferencia - $actividad, 2),
                $this->causa($dato, $precio, $diferencia, $actividad), $mesConInforme ? ($actual['valor'] ?? 0.0) : '',
                $mesConInforme ? round($estimado - ($actual['valor'] ?? 0.0), 2) : ''];
        }

        usort($filas, static fn (array $a, array $b): int => [$a[0], -abs($a[11])] <=> [$b[0], -abs($b[11])]);

        return $filas;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function filasPendientes(): array
    {
        $this->cerrar();
        $filas = [];

        foreach ($this->codigos as $codigo) {
            $referencial = $codigo['estado'] === self::ESTIMADA && $codigo['suma'];

            if (! $referencial && ! in_array($codigo['estado'], [self::CONDICIONADA, self::SIN_VALOR], true)) {
                continue;
            }

            if (str_starts_with($codigo['subtipo'], 'EXCLUIDO') || $codigo['claseNoAditivo'] === self::NO_ADITIVO_ALTERNATIVA) {
                continue;
            }

            $filas[] = [$codigo['mes'], $codigo['tipo'], $codigo['codigo'], $codigo['descripcion'], $codigo['estado'].($codigo['subtipo'] === '' ? '' : ' / '.$codigo['subtipo']),
                $codigo['registros'], round($codigo['unidades'], 4), $codigo['estimado'] / 100.0, $codigo['noAditivo'] / 100.0,
                implode(' | ', array_keys($codigo['motivos'])), $this->faltante($codigo)];
        }

        usort($filas, static fn (array $a, array $b): int => [$a[0], -($a[7] + $a[8]), -$a[5]] <=> [$b[0], -($b[7] + $b[8]), -$b[5]]);

        return $filas;
    }

    /**
     * @return list<array<int, string>>
     */
    public function filasReglas(): array
    {
        $filas = [
            ['RN-01', 'Conservación', 'El costo A, los catálogos, los JSON y las bases no cambian. AC y AH de la estimación son iguales al costo A. La estimación es un consolidado separado y no es facturación.'],
            ['RN-BASE', 'Cálculo', 'UNIDADES REALES DEL MES × PRECIO HISTÓRICO COMPARABLE, solo con correspondencia de código, unidad, presentación, alcance y esquema.'],
            ['RN-BASE', 'Ventana histórica', 'Tres meses más recientes anteriores al mes valorizado; precio = mediana de registros positivos. Sin datos recientes se usan los tres meses anteriores disponibles y se señala.'],
            ['RN-BASE', 'Estados y totales', 'Respaldada · Estimada · Condicionada · Incluida · Sin valorización. Estimación central = lo que suma. Componente condicionado = valores sin evidencia suficiente (no suma a la central). Alternativas de unidad e importes informativos no se suman a ningún total.'],
            ['RN-02', 'AP según esquema real', 'Cada CUPS integral se valora con la clase de precio histórica de su esquema: los precios del informe se agrupan por valor y por los medicamentos anotados (columna MEDICAMENTO). La asociación de medicamentos RIPS requiere paciente, mes y factura compartida; la coincidencia mensual sola queda condicionada. Si el histórico tiene un solo precio por CUPS, no se inventan tarifas distintas para sus medicamentos.'],
            ['RN-03', 'Asignación del esquema', 'Si el caso-mes tiene el medicamento distintivo de una clase (p. ej. pembrolizumab), se usa esa clase. Si tiene medicamentos del CUPS sin distintivo de otra clase, se usa la clase base. Sin evidencia: precio base en la central y la diferencia con la mezcla histórica como componente condicionado.'],
            ['RN-04', 'Medicamentos oncológicos', 'La composición histórica relaciona medicamento y CUPS integral; los de otro esquema se valoran como AM independiente. La relación con el mismo CUPS exige RN-14 para demostrar cobertura económica.'],
            ['RN-05', 'Demás procedimientos', 'Con tarifa aplicable: costo A. Sin tarifa o con regla pendiente: mediana administrativa por registro cuando la unidad es compatible.'],
            ['RN-06', 'AT', 'Solo transporte y albergue identificados por código y descripción pueden tener valoración AT independiente con precio histórico y unidad compatible. La base (registro o unidad) compara la mediana de filas administrativas de la ventana anterior con la actividad RIPS del código; fuera de 0,5 a 2 veces queda condicionada. Los demás AT sin tarifa acreditada permanecen pendientes y no suman aparte; los absorbidos en AH tampoco.'],
            ['RN-07', 'AU', 'No suma. Sus componentes ya están en AC/AP/AM; la atribución se muestra como informativa.'],
            ['RN-08', 'Radioterapia', 'Se conservan por separado: por registro (metodología administrativa) como componente condicionado y por tratamiento (un tratamiento por caso-mes × tarifa de la nota) como alternativa. No se mezclan unidades.'],
            ['RN-AM', 'A · B · C · D', 'A: costo A. B: incluido en tratamiento integral. C: cantidad × mediana del precio unitario de dispensación del mismo CUM en meses anteriores (sin tarifa contractual). Si el único precio es la dispensación del mismo paciente, CUM y mes, es el registro del propio evento: queda condicionado y no suma. D: sin presentación o cantidad: no se valoriza.'],
            ['RN-11', 'Conciliación de medicamentos', 'Una línea AM es un registro, no una unidad. Su cantidad RIPS se conserva por separado; solo la presentación CUM resuelta con precio unitario compatible admite cantidad × precio. Los medicamentos incluidos en AP integral o AH no vuelven a sumar en AM. Un conteo administrativo externo exige identificación de su filtro antes de compararlo.'],
            ['RN-12', 'Conciliación AC/AT por código y unidad', 'Se exponen registros, unidades, base de cobro y precio histórico por código. AC y AH conservan costo A; AT solo usa la unidad históricamente comparable. Un total administrativo agregado no crea una tarifa ni modifica el costo A.'],
            ['RN-14', 'Cobertura económica de medicamentos (toda ruta y mes)', 'Cada AM queda incluido demostrado en AP, incluido demostrado en AH, independiente, condicionado o pendiente. Factura y composición histórica del CUPS no acreditan por sí solas inclusión económica: la cobertura AP solo queda demostrada si el medicamento distingue la clase de precio histórica aplicada al integral de la misma factura (al menos la mitad de sus registros y no más del 10 % de las demás clases). Esto aplica a todo AM de la factura que figure en la composición del integral, aunque la nota técnica no lo nombre en el esquema. Con precio único del CUPS o sin distintivo, el posible valor se calcula con cantidad RIPS y precio unitario histórico del mismo CUM como condicionado no aditivo (en cero mientras no haya CUM y precio compatibles); si el integral se valoró con mezcla de varios esquemas, ese valor ya está en la mezcla condicionada y no se repite. Un integral del mismo paciente y mes en otra factura que contenga el medicamento lo deja condicionado. AH conserva la decisión de paquete y requiere un bloque neto en el episodio para acreditar su cobertura.'],
            ['RN-15', 'Valorización mensual automática', 'Cada ruta y mes se calculan exclusivamente desde sus RIPS con RN-01 a RN-14 y precios históricos comparables anteriores al mes. La referencia gerencial fija solo muestra una variación: nunca modifica cantidades, precios, inclusión, costo A ni estimación central. Los CUPS principales comparan actividad y valor aditivo entre meses cargados.'],
        ];

        foreach ($this->historial->fuentes() as $fuente) {
            $filas[] = ['FUENTE', $fuente['archivo'], sprintf('Hoja %s · programa %s · %s filas · meses %s. Solo se leyeron mes, tipo, código, descripción, diagnóstico, medicamento del esquema y valor.',
                HistorialAdministrativo::HOJA, $fuente['ruta'], number_format($fuente['filas'], 0, ',', '.'), $fuente['meses'])];
        }

        if (! $this->historial->tieneDatos($this->ruta)) {
            $filas[] = ['FUENTE', 'Informe administrativo', 'No se cargó historial administrativo para la ruta: AP, AT y AC sin tarifa quedan sin valorización histórica.'];
        }

        $filas[] = ['FUENTE', 'Dispensación administrativa', 'Catálogo activo de dispensación de la ruta (precio unitario por CUM y mes), solo como referencia RN-AM C.'];

        return $filas;
    }

    /**
     * Totales del mes: costo A, estimación central, condicionado e informativo.
     *
     * @return array<string, array{costoA: float, estimado: float, condicionado: float, informativo: float}>
     */
    public function totalesPorMes(): array
    {
        $this->cerrar();
        $totales = [];

        foreach ($this->meses() as $mes) {
            $suma = ['estimado' => 0, 'condicionado' => 0, 'informativo' => 0];

            foreach (self::TIPOS_ORDEN as $tipo) {
                foreach ($this->totalesTipo($mes, $tipo) as $campo => $valor) {
                    $suma[$campo] += $valor;
                }
            }

            $totales[$mes] = ['costoA' => array_sum(array_column($this->tipos[$mes] ?? [], 'costoA')) / 100.0,
                'estimado' => $suma['estimado'] / 100.0, 'condicionado' => $suma['condicionado'] / 100.0, 'informativo' => $suma['informativo'] / 100.0];
        }

        return $totales;
    }

    /**
     * @param  list<string>  $valores
     * @return list<string>
     */
    private static function ordenadas(array $valores): array
    {
        sort($valores);

        return $valores;
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @return list<string>
     */
    private static function medicamentosLinea(array $detalle): array
    {
        return HistorialAdministrativo::tokens((string) (($detalle['principioActivo'] ?? '') ?: ($detalle['nombreServicio'] ?? '') ?: ($detalle['descripcionOficial'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $linea
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $economico
     */
    private function registrarMedicamento(array $linea, array $detalle, array $economico, ?string $absorcion, string $caso, string $casoFactura): void
    {
        $this->coberturaMedicamentos[$linea['id']] = [
            'mes' => $linea['mes'], 'factura' => (string) ($detalle['factura'] ?? ''),
            'tipoDocumento' => (string) ($detalle['tipoDocumentoIdentificacion'] ?? ''),
            'documento' => (string) ($detalle['numDocumentoIdentificacion'] ?? ''),
            'fecha' => (string) ($detalle['fechaAtencion'] ?? ''), 'codigo' => $linea['codigo'],
            'cum' => (string) ($detalle['cumResuelto'] ?? ''), 'cantidad' => $linea['cantidad'],
            'concentracion' => (string) ($detalle['concentracionMedicamento'] ?? ''),
            'unidad' => (string) ($detalle['unidadMedida'] ?? ''), 'forma' => (string) ($detalle['formaFarmaceutica'] ?? ''),
            'unidadMinima' => (string) ($detalle['unidadMinDispensa'] ?? ''), 'ap' => '',
            'inicioAh' => (string) ($detalle['episodioInicio'] ?? ''), 'egresoAh' => (string) ($detalle['episodioEgreso'] ?? ''),
            'paquetesAh' => (int) ($detalle['episodioPaquetesSumados'] ?? 0), 'clasificacion' => self::COBERTURA_PENDIENTE,
            'precio' => 0.0, 'potencial' => 0, 'fuente' => 'Sin evaluación económica individual.',
        ];

        if ($absorcion !== null) {
            $cobertura = &$this->coberturaMedicamentos[$linea['id']];
            $cobertura['clasificacion'] = match (true) {
                $absorcion !== 'PAQUETE HOSPITALARIO AH' => self::COBERTURA_INCLUIDO_URGENCIA,
                $cobertura['paquetesAh'] > 0 => self::COBERTURA_INCLUIDO_AH,
                default => self::COBERTURA_CONDICIONADO_AH,
            };
            if ($cobertura['clasificacion'] === self::COBERTURA_CONDICIONADO_AH) {
                [$precioHistorico, $potencialHistorico] = $this->potencialHistoricoMedicamento($linea, $detalle);
                $cobertura['precio'] = (float) ($precioHistorico['mediana'] ?? 0);
                $cobertura['potencial'] = $potencialHistorico;
            }
            $cobertura['fuente'] = match ($cobertura['clasificacion']) {
                self::COBERTURA_INCLUIDO_AH => 'Regla de paquete hospitalario; episodio con bloque neto atribuido.',
                self::COBERTURA_INCLUIDO_URGENCIA => 'Urgencia de menos de 24 horas cobrada con su consulta de urgencias: el medicamento queda incluido en ese cobro.',
                default => 'Regla hospitalaria aplicada; falta acreditar bloque neto en este episodio. Potencial no aditivo solo con precio unitario del mismo CUM.',
            };
            $this->agregar($linea, self::INCLUIDA, false, 'INCLUIDO EN '.$absorcion, 'REGLA HOSPITALARIA SIRUTA', 'Medicamento absorbido; no se cobra por separado.');

            return;
        }

        $propio = $this->valoracionMedicamento($linea, $detalle, $economico);
        $cobertura = &$this->coberturaMedicamentos[$linea['id']];
        $cobertura['clasificacion'] = match (true) {
            $propio[1] => self::COBERTURA_INDEPENDIENTE,
            $propio[2] === self::SUBTIPO_DISPENSACION_MISMO_PERIODO => self::COBERTURA_CONDICIONADO_PRECIO,
            default => self::COBERTURA_PENDIENTE,
        };
        $cobertura['precio'] = (float) ($propio[5]['mediana'] ?? 0);
        $cobertura['potencial'] = $propio[1] ? 0 : $propio[7];
        $cobertura['fuente'] = $propio[3].'; '.$propio[4];

        // RN-14 evalúa todo AM no absorbido: sus prefijos también eligen la clase de precio del integral
        // de la misma factura, aunque la nota técnica no nombre el medicamento en el esquema.
        $this->oncologicos[] = ['linea' => $linea, 'propio' => $propio, 'caso' => $caso, 'casoFactura' => $casoFactura,
            'detalle' => $detalle, 'oncologico' => (string) ($detalle['esquemaOncologico'] ?? '') !== '',
            'tokens' => self::medicamentosLinea($detalle),
            'nombre' => (string) (($detalle['principioActivo'] ?? '') ?: ($detalle['nombreServicio'] ?? '')),
            'cum' => (string) (($detalle['cumResuelto'] ?? '') ?: ($detalle['cum'] ?? ''))];
    }

    /**
     * Estado propio del medicamento (A, C o D) antes de considerar el tratamiento integral.
     *
     * @param  array<string, mixed>  $linea
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $economico
     * @return array{0: string, 1: bool, 2: string, 3: string, 4: string, 5: array<string, mixed>|null, 6: int, 7: int, 8: string}
     */
    private function valoracionMedicamento(array $linea, array $detalle, array $economico): array
    {
        if ($linea['costoA'] > 0) {
            return [self::RESPALDADA, true, 'A · COSTO RECONOCIDO', (string) ($detalle['fuenteTarifa'] ?? ''), 'Medicamento con costo A.', null, $linea['costoA'], 0, 'UNIDAD'];
        }

        $resolucion = (string) ($detalle['resolucionMedicamento'] ?? '');
        $cum = (string) ($detalle['cumResuelto'] ?? '');

        if ($linea['cantidad'] <= 0) {
            return [self::CONDICIONADA, false, 'D · SIN CANTIDAD', 'RIPS', 'Cantidad del medicamento no informada o inválida.', null, 0, 0, 'UNIDAD'];
        }

        if (in_array($resolucion, ['AMBIGUO', 'CONFLICTO', 'MUESTRA_MEDICA'], true) || ($detalle['estadoTarifario'] ?? '') === 'REVISAR_PRESENTACION' || $cum === '') {
            return [self::CONDICIONADA, false, 'D · SIN PRESENTACION SUFICIENTE', 'CUM/IUM',
                'Presentación no determinada ('.($resolucion ?: 'sin CUM resuelto').'): no se usa el precio de otra presentación.', null, 0, 0, 'UNIDAD'];
        }

        $historial = $this->precioMedicamento($linea['mes'], $cum);

        if ($historial !== null) {
            return [self::ESTIMADA, true, 'C · PRECIO HISTORICO COMPATIBLE SIN TARIFA CONTRACTUAL', 'DISPENSACION ADMINISTRATIVA DEL CUM '.$cum,
                'Cantidad × mediana del precio unitario del mismo CUM.', $historial,
                0, ResumenEconomico::centavos($linea['cantidad'] * $historial['mediana']), 'UNIDAD'];
        }

        $condicionado = ResumenEconomico::centavos(($economico['condicionadoIncluido'] ?? 0) + ($economico['referencialAdicional'] ?? 0));
        $fuenteLinea = (string) (($detalle['fuenteEscenarioReferencial'] ?? '') ?: ($detalle['fuenteTarifa'] ?? ''));

        // El precio de dispensación del mismo paciente, CUM y mes es el registro administrativo
        // del propio evento, no una referencia histórica: no entra a la central.
        if ($condicionado > 0 && in_array(FuenteTarifa::Dispensacion->value, [$detalle['fuenteEscenarioReferencial'] ?? '', $detalle['fuenteTarifa'] ?? ''], true)) {
            return [self::CONDICIONADA, false, self::SUBTIPO_DISPENSACION_MISMO_PERIODO, 'DISPENSACION ADMINISTRATIVA DEL MISMO PACIENTE, CUM Y MES',
                'Sin precio del CUM en meses anteriores. El único precio es la dispensación administrativa del mismo paciente, CUM y mes de la línea: es el registro del propio evento, no una referencia histórica independiente.',
                null, 0, $condicionado, 'UNIDAD'];
        }

        if ($condicionado > 0) {
            return [self::ESTIMADA, true, 'C · TARIFA NO CONTRACTUAL DE LA LINEA', $fuenteLinea,
                'Sin historial del CUM; se usa la tarifa '.$fuenteLinea.' de la línea (escenario B), que no proviene del mismo evento.', null, 0, $condicionado, 'UNIDAD'];
        }

        return [self::SIN_VALOR, false, 'SIN PRECIO DEL CUM', 'SIN FUENTE', 'Sin tarifa contractual ni precio administrativo del CUM '.$cum.'.', null, 0, 0, 'UNIDAD'];
    }

    /**
     * @return array{meses: list<string>, observaciones: int, ceros: int, minimo: float, mediana: float, maximo: float, registrosMensuales: float, valorMensual: float, ventanaReciente: bool, descripcion: string}|null
     */
    private function precioMedicamento(string $mes, string $cum): ?array
    {
        $porMes = [];

        foreach (($this->preciosMedicamento)($cum) as $precio) {
            if ($precio['mes'] < $mes && $precio['tarifa'] > 0) {
                $porMes[$precio['mes']][] = $precio['tarifa'];
            }
        }

        if ($porMes === []) {
            return null;
        }

        ksort($porMes);
        $meses = array_slice(array_keys($porMes), -3);
        $valores = array_merge(...array_map(static fn (string $m): array => $porMes[$m], $meses));
        $limite = date('Y-m', (int) strtotime($mes.'-01 -3 months'));

        return ['meses' => $meses, 'observaciones' => count($valores), 'ceros' => 0, 'minimo' => min($valores),
            'mediana' => HistorialAdministrativo::mediana($valores), 'maximo' => max($valores),
            'registrosMensuales' => 0.0, 'valorMensual' => 0.0, 'ventanaReciente' => $meses[0] >= $limite, 'descripcion' => ''];
    }

    /**
     * @param  array<string, mixed>  $linea
     * @param  array<string, mixed>  $detalle
     * @return array{0: array<string, mixed>|null, 1: int}
     */
    private function potencialHistoricoMedicamento(array $linea, array $detalle): array
    {
        $cum = (string) ($detalle['cumResuelto'] ?? '');
        $resolucion = (string) ($detalle['resolucionMedicamento'] ?? '');

        if ($cum === '' || $linea['cantidad'] <= 0
            || in_array($resolucion, ['AMBIGUO', 'CONFLICTO', 'MUESTRA_MEDICA'], true)
            || ($detalle['estadoTarifario'] ?? '') === 'REVISAR_PRESENTACION') {
            return [null, 0];
        }

        $precio = $this->precioMedicamento($linea['mes'], $cum);

        return [$precio, $precio === null ? 0 : ResumenEconomico::centavos($linea['cantidad'] * $precio['mediana'])];
    }

    /**
     * Resuelve lo que depende del mes completo: unidad de cobro de AP/AT,
     * esquema de cada tratamiento integral, radioterapia y medicamentos
     * oncológicos asociados.
     */
    private function cerrar(): void
    {
        if ($this->cerrada) {
            return;
        }

        $this->cerrada = true;
        $this->cerrarDiferidos();
        $this->cerrarIntegrales();
        $this->cerrarRadioterapia();
        $this->cerrarOncologicos();
    }

    private function cerrarDiferidos(): void
    {
        foreach ($this->diferidos as $diferido) {
            $precio = $this->historial->precio($this->ruta, $diferido['mes'], $diferido['tipo'], $diferido['codigo']);
            $linea = [...$diferido, 'cantidad' => $diferido['unidades'], 'costoA' => 0];

            if ($precio === null) {
                $this->agregar($linea, self::SIN_VALOR, false, 'SIN TARIFA NI PRECIO HISTORICO', 'SIN FUENTE',
                    'Sin tarifa aplicable ni precio administrativo del código.', registros: $diferido['registros']);

                continue;
            }

            if ($diferido['tipo'] === 'AT' && ! $this->esTransporteOAlbergue($diferido, $precio)) {
                $this->agregar($linea, self::CONDICIONADA, false, 'INDEPENDENCIA AT NO DEMOSTRADA',
                    'INFORME ADMINISTRATIVO (REFERENCIA NO ADITIVA)',
                    'El código AT permanece en RIPS; no hay evidencia de cobro independiente frente a procedimientos o paquetes.',
                    $precio, 0, ResumenEconomico::centavos($diferido['registros'] * $precio['mediana']),
                    'NO VERIFICADA', registros: $diferido['registros']);

                continue;
            }

            [$base, $cantidadBase, $evidencia] = $this->baseUnidad($diferido, $precio);

            if ($base === null) {
                $this->agregar($linea, self::CONDICIONADA, false, 'UNIDAD NO COMPATIBLE', 'INFORME ADMINISTRATIVO (MEDIANA POR REGISTRO)', $evidencia,
                    $precio, 0, ResumenEconomico::centavos($diferido['registros'] * $precio['mediana']), 'NO VERIFICADA', registros: $diferido['registros']);

                continue;
            }

            $fuente = $base === 'UNIDAD'
                ? 'INFORME ADMINISTRATIVO (UNA FILA POR UNIDAD RIPS)'
                : 'INFORME ADMINISTRATIVO (MEDIANA POR REGISTRO)';
            $this->agregar($linea, self::ESTIMADA, true, $diferido['condicionado'] > 0 ? 'TARIFA CON REGLA PENDIENTE' : 'SIN TARIFA APLICABLE',
                $fuente, $evidencia, $precio, 0,
                ResumenEconomico::centavos($cantidadBase * $precio['mediana']), $base, registros: $diferido['registros']);
        }
    }

    /**
     * Los códigos de transporte están verificados en los RIPS y el histórico;
     * el código de albergue requiere además descripción coincidente.
     *
     * @param  array<string, mixed>  $diferido
     * @param  array<string, mixed>  $precio
     */
    private function esTransporteOAlbergue(array $diferido, array $precio): bool
    {
        if (in_array($diferido['codigo'], self::TRANSPORTES_AT, true)) {
            return true;
        }

        return $diferido['codigo'] === 'A'
            && preg_match('/ALBERGUE/u', mb_strtoupper((string) $diferido['descripcion'])) === 1
            && preg_match('/ALBERGUE/u', mb_strtoupper((string) $precio['descripcion'])) === 1;
    }

    /**
     * RN-02/RN-03: cada registro integral toma el precio histórico de su esquema.
     */
    private function cerrarIntegrales(): void
    {
        foreach ($this->integralesPendientes as ['linea' => $linea, 'caso' => $caso, 'casoFactura' => $casoFactura]) {
            $clases = $this->historial->clasesPrecio($this->ruta, $linea['mes'], 'AP', $linea['codigo']);
            $precio = $this->historial->precio($this->ruta, $linea['mes'], 'AP', $linea['codigo']);
            $medicamentos = array_keys($this->medicamentosCaso[$linea['mes']][$casoFactura] ?? []);
            $asignacion = $this->claseIntegral($clases, $medicamentos);
            [$clase, $metodo, $condicionado, $composicion, $evidencia] = $asignacion;
            $esquema = $this->etiquetaClase($clase, count($clases));
            $valor = ResumenEconomico::centavos($clase['precio']);
            $quimio = $this->quimioterapia[$linea['codigo']];

            $this->agregar($linea, self::ESTIMADA, true, 'TRATAMIENTO INTEGRAL · '.$esquema, 'INFORME ADMINISTRATIVO (PRECIO DEL ESQUEMA)',
                sprintf('Valor integral por registro del esquema (%s). Nota: aplicación $%s + medicamento promedio $%s. Los medicamentos del esquema no se suman de nuevo.',
                    $metodo, number_format($quimio['tarifa'], 2, ',', '.'), number_format($quimio['tarifaMedicamento'], 2, ',', '.')),
                $precio, $linea['costoA'], $valor, 'REGISTRO');

            if ($condicionado > 0) {
                $this->agregar([...$linea, 'cantidad' => 0.0, 'costoA' => 0], self::CONDICIONADA, false, 'MEZCLA HISTORICA DE ESQUEMAS',
                    'INFORME ADMINISTRATIVO (MEZCLA DE CLASES)', 'Sin evidencia del esquema en el caso-mes: diferencia entre la mezcla histórica del CUPS y el precio base.',
                    $precio, 0, $condicionado, 'REGISTRO', registros: 0);
            }

            // RN-14: la clase aplicada demuestra cobertura solo para sus medicamentos distintivos,
            // y solo si el CUPS tiene precios distintos por esquema y no se usó una mezcla.
            $this->integralesCaso[$linea['mes']][$casoFactura][] = ['codigo' => $linea['codigo'], 'composicion' => $composicion, 'esquema' => $esquema,
                'distintivos' => count($clases) > 1 && $condicionado === 0 ? array_map('strval', $clase['distintivos']) : [],
                'distintivosMezcla' => $condicionado > 0 ? ($asignacion[5] ?? []) : []];
            $this->integralesPacienteMes[$linea['mes']][$caso][] = $linea['codigo'];
            $clave = implode('|', [$linea['mes'], $linea['codigo'], $esquema, $metodo]);
            $this->tratamientos[$clave] ??= ['mes' => $linea['mes'], 'codigo' => $linea['codigo'], 'esquema' => $esquema, 'metodo' => $metodo,
                'registros' => 0, 'costoA' => 0, 'precio' => $clase['precio'], 'valor' => 0, 'condicionado' => 0, 'evidencia' => [],
                'alternativos' => implode(' | ', array_map(fn (array $otra): string => $this->etiquetaClase($otra, count($clases)).': $'.number_format($otra['precio'], 2, ',', '.')
                    .' ('.$otra['registros'].' reg.)', $clases)),
                'registrosClase' => $clase['registros'], 'participacion' => $clase['participacion']];
            $this->tratamientos[$clave]['registros']++;
            $this->tratamientos[$clave]['costoA'] += $linea['costoA'];
            $this->tratamientos[$clave]['valor'] += $valor;
            $this->tratamientos[$clave]['condicionado'] += $condicionado;
            $this->tratamientos[$clave]['evidencia'][$evidencia] = true;
        }
    }

    /**
     * @param  list<array{precio: float, registros: int, participacion: float, composicion: array<string, float>, distintivos: list<string>, ejemplo: string}>  $clases
     * @param  list<string>  $medicamentos  Prefijos de los medicamentos del caso-mes.
     * @return array{0: array<string, mixed>, 1: string, 2: int, 3: list<string>, 4: string, 5?: list<string>}
     */
    private function claseIntegral(array $clases, array $medicamentos): array
    {
        $evidencia = $medicamentos === [] ? 'SIN MEDICAMENTOS EN EL CASO-MES' : 'MEDICAMENTOS: '.implode('/', $medicamentos);
        $todos = array_values(array_unique(array_merge(...array_map(static fn (array $clase): array => array_map('strval', array_keys($clase['composicion'])), $clases))));

        if (count($clases) === 1) {
            return [$clases[0], 'PRECIO UNICO DEL CUPS', 0, $todos, $evidencia];
        }

        $candidatas = array_values(array_filter($clases, static fn (array $clase): bool => array_intersect($clase['distintivos'], $medicamentos) !== []));
        $bases = array_values(array_filter($clases, static fn (array $clase): bool => $clase['distintivos'] === []));
        usort($bases, static fn (array $a, array $b): int => $a['precio'] <=> $b['precio']);
        $base = $bases[0] ?? array_reduce($clases, static fn (?array $menor, array $clase): array => $menor === null || $clase['precio'] < $menor['precio'] ? $clase : $menor);

        if (count($candidatas) === 1) {
            return [$candidatas[0], 'MEDICAMENTO DISTINTIVO EN EL CASO-MES ('.implode('/', array_intersect($candidatas[0]['distintivos'], $medicamentos)).')',
                0, array_map('strval', array_keys($candidatas[0]['composicion'])), $evidencia];
        }

        $porComposicion = array_values(array_filter($clases, static fn (array $clase): bool => array_intersect(array_map('strval', array_keys($clase['composicion'])), $medicamentos) !== []));

        if ($candidatas === [] && count($porComposicion) === 1) {
            $comunes = array_intersect(array_map('strval', array_keys($porComposicion[0]['composicion'])), $medicamentos);

            return [$porComposicion[0], 'MEDICAMENTO SOLO EN LA COMPOSICION HISTORICA DE ESTA CLASE ('.implode('/', $comunes).')',
                0, array_map('strval', array_keys($porComposicion[0]['composicion'])), $evidencia];
        }

        if ($candidatas === [] && $bases !== [] && array_intersect($medicamentos, $todos) !== []) {
            return [$base, 'MEDICAMENTOS DEL CUPS SIN DISTINTIVO DE OTRO ESQUEMA', 0, array_map('strval', array_keys($base['composicion'])), $evidencia];
        }

        $mezcla = array_sum(array_map(static fn (array $clase): float => $clase['precio'] * $clase['participacion'], $clases));

        // Con varios esquemas posibles, el valor de sus medicamentos distintivos ya está en la mezcla condicionada.
        return [$base, $candidatas === [] ? 'SIN EVIDENCIA DEL ESQUEMA (BASE + MEZCLA CONDICIONADA)' : 'VARIOS ESQUEMAS POSIBLES (BASE + MEZCLA CONDICIONADA)',
            max(0, ResumenEconomico::centavos($mezcla - $base['precio'])), $todos, $evidencia,
            array_values(array_unique(array_merge([], ...array_map(static fn (array $clase): array => array_map('strval', $clase['distintivos']), $candidatas))))];
    }

    /**
     * @param  array<string, mixed>  $clase
     */
    private function etiquetaClase(array $clase, int $totalClases): string
    {
        if ($totalClases === 1) {
            return 'ESQUEMA UNICO DEL CUPS';
        }

        return $clase['distintivos'] === []
            ? 'ESQUEMA BASE (EJ. '.$clase['ejemplo'].')'
            : 'ESQUEMA CON '.implode('/', $clase['distintivos']).' (EJ. '.$clase['ejemplo'].')';
    }

    /**
     * RN-08: radioterapia por registro (metodología administrativa) y por tratamiento, sin mezclarlas.
     */
    private function cerrarRadioterapia(): void
    {
        foreach ($this->radioPendientes as $radio) {
            $precio = $radio['precio'];
            $porRegistro = $precio['mediana'] ?? ($this->radioterapia[$radio['codigo']] ?? 0.0);
            $porTratamiento = $this->radioterapia[$radio['codigo']] ?? $porRegistro;
            $casos = count($radio['casos']);
            $linea = [...$radio, 'cantidad' => $radio['unidades'], 'costoA' => 0];

            $this->agregar($linea, self::CONDICIONADA, false, 'RADIOTERAPIA · POR REGISTRO', $precio === null ? 'NOTA TECNICA' : 'INFORME ADMINISTRATIVO (MEDIANA POR REGISTRO)',
                'El administrativo valora cada registro como un tratamiento; el RIPS puede registrar sesiones. Equivalencia no demostrada.',
                $precio, 0, ResumenEconomico::centavos($radio['registros'] * $porRegistro), 'REGISTRO', registros: $radio['registros']);
            $this->agregar([...$linea, 'cantidad' => (float) $casos], self::CONDICIONADA, false, 'RADIOTERAPIA · POR TRATAMIENTO',
                'NOTA TECNICA (TARIFA POR TRATAMIENTO)', sprintf('Alternativa: un tratamiento por caso-mes (%d casos) × tarifa de la nota $%s. No se suma con la valoración por registro.',
                    $casos, number_format($porTratamiento, 2, ',', '.')),
                null, 0, ResumenEconomico::centavos($casos * $porTratamiento), 'TRATAMIENTO', registros: 0, claseNoAditivo: self::NO_ADITIVO_ALTERNATIVA);
        }
    }

    /**
     * RN-04 / RN-14: cada AM no absorbido frente al tratamiento integral del caso-mes. Los
     * oncológicos (según la nota técnica) y cualquier AM que figure en la composición del integral
     * de su misma factura se clasifican como incluidos con cobertura demostrada, de otro esquema,
     * de asociación incierta (condicionados) o según su propio precio.
     */
    private function cerrarOncologicos(): void
    {
        foreach ($this->oncologicos as $oncologico) {
            $linea = $oncologico['linea'];
            $integrales = $this->integralesCaso[$linea['mes']][$oncologico['casoFactura']] ?? [];
            [$estado, $suma, $subtipo, $fuente, $motivo, $precio, $costo, $valor, $base] = $oncologico['propio'];
            $valorPropio = $estado === self::RESPALDADA ? $costo : $valor;
            $condicionadoPropio = $suma ? 0 : $valor;
            $codigosIntegrales = array_column($integrales, 'codigo');
            $tokens = $oncologico['tokens'];
            $incluido = array_values(array_filter($integrales, static fn (array $integral): bool => array_intersect($tokens, $integral['composicion']) !== []));
            usort($incluido, static fn (array $a, array $b): int => $a['codigo'] <=> $b['codigo']);

            // Sin esquema oncológico en la nota y sin relación con el integral de su factura: su propio precio.
            if (! $oncologico['oncologico'] && $incluido === []) {
                $this->agregar($linea, ...$oncologico['propio']);

                continue;
            }

            if ($incluido !== []) {
                $demostrado = array_values(array_filter($incluido, static fn (array $integral): bool => array_intersect($tokens, $integral['distintivos']) !== []));
                $enMezcla = array_values(array_filter($incluido, static fn (array $integral): bool => array_intersect($tokens, $integral['distintivosMezcla']) !== []));

                if ($demostrado !== []) {
                    $distintivos = implode('/', array_intersect($tokens, $demostrado[0]['distintivos']));
                    $this->cobertura($linea, self::COBERTURA_INCLUIDO_AP, $demostrado[0]['codigo'], 0,
                        'El medicamento ('.$distintivos.') distingue la clase de precio histórica aplicada al AP integral '
                        .$demostrado[0]['codigo'].' de la misma factura ('.$demostrado[0]['esquema'].'): su valor ya está en ese precio.');
                    $this->agregar($linea, self::INCLUIDA, false, 'B · INCLUIDO EN TRATAMIENTO INTEGRAL '.$demostrado[0]['codigo'],
                        'INFORME ADMINISTRATIVO (CLASE DE PRECIO DEL ESQUEMA)',
                        'El medicamento distingue la clase de precio aplicada al integral de la misma factura (cobertura económica demostrada); no se suma de nuevo.');
                    $this->sumarNoAditivo($linea['mes'], self::NO_ADITIVO_COSTO_A_INCLUIDO, $linea['costoA']);
                    $this->registrarAsociacion($oncologico, self::GRUPO_INCLUIDO, 0, 0, $codigosIntegrales, $demostrado[0]['esquema']);

                    continue;
                }

                if ($enMezcla !== []) {
                    // Varios esquemas posibles: la mezcla condicionada del integral ya contiene el valor de este medicamento.
                    $this->cobertura($linea, self::COBERTURA_CONDICIONADO_AP, $enMezcla[0]['codigo'], 0,
                        'Distingue una de las clases mezcladas en el AP integral '.$enMezcla[0]['codigo'].' (varios esquemas posibles): su valor ya está en el componente condicionado de esa mezcla.');
                    $this->agregar($linea, self::CONDICIONADA, false, self::SUBTIPO_RN14_MEZCLA, 'INFORME ADMINISTRATIVO (MEZCLA DE CLASES)',
                        'La factura tiene medicamentos de varios esquemas del AP '.$enMezcla[0]['codigo'].'; la diferencia de la mezcla ya es condicionada y este medicamento no agrega otro potencial.',
                        null, $linea['costoA'], 0, 'UNIDAD');
                    $this->sumarNoAditivo($linea['mes'], self::NO_ADITIVO_COSTO_A_RN14, $linea['costoA']);
                    $this->registrarAsociacion($oncologico, self::GRUPO_DESCONOCIDO, 0, 0, $codigosIntegrales,
                        'Medicamento de una de las clases mezcladas del integral. '.$enMezcla[0]['esquema']);

                    continue;
                }

                $cum = (string) $oncologico['cum'];
                [$precioIndividual, $potencial] = $this->potencialHistoricoMedicamento($linea, $oncologico['detalle']);
                $this->cobertura($linea, self::COBERTURA_CONDICIONADO_AP, $incluido[0]['codigo'], $potencial, $precioIndividual === null
                    ? 'Composición histórica y factura compartida; cobertura AP sin prueba y sin precio unitario del CUM (potencial en cero hasta contar con precio).'
                    : 'Composición histórica y factura compartida; potencial = cantidad RIPS × mediana histórica unitaria del mismo CUM.',
                    (float) ($precioIndividual['mediana'] ?? 0));
                $this->agregar($linea, self::CONDICIONADA, false, self::SUBTIPO_RN14_AP,
                    $precioIndividual === null ? 'INFORME ADMINISTRATIVO (ASOCIACION DE ESQUEMA)' : 'DISPENSACION ADMINISTRATIVA DEL CUM '.$cum,
                    'La factura y la composición histórica asocian el medicamento al AP '.$incluido[0]['codigo'].', pero la clase de precio aplicada no lo distingue (precio único del CUPS o medicamento no distintivo): no demuestra que cubra esta presentación. El precio unitario del CUM, si existe, es potencial no aditivo.',
                    $precioIndividual, $linea['costoA'], $potencial, 'UNIDAD');
                $this->sumarNoAditivo($linea['mes'], self::NO_ADITIVO_COSTO_A_RN14, $linea['costoA']);
                $this->registrarAsociacion($oncologico, self::GRUPO_DESCONOCIDO, 0, $potencial, $codigosIntegrales,
                    'Coincidencia de factura y composición; cobertura económica individual no demostrada. '.$incluido[0]['esquema']);

                continue;
            }

            // Oncológico cuyo integral de la misma factura no lo incluye (o que no tiene integral en su factura).
            $otrasFacturas = $integrales === []
                ? ($this->integralesPacienteMes[$linea['mes']][$oncologico['caso']] ?? [])
                : array_column(array_filter($this->integralesOtrasFacturas($linea['mes'], $oncologico['caso'], $oncologico['casoFactura']),
                    static fn (array $integral): bool => array_intersect($tokens, $integral['composicion']) !== []), 'codigo');

            if ($otrasFacturas !== []) {
                $otrasFacturas = array_values(array_unique($otrasFacturas));
                $this->cobertura($linea, self::COBERTURA_CONDICIONADO_AP, implode(', ', $otrasFacturas), $valorPropio,
                    'AP del mismo paciente-mes en otra factura; asociación económica no demostrada.');
                $this->agregar($linea, self::CONDICIONADA, false, 'ASOCIACION SIN FACTURA COMPARTIDA', $fuente,
                    'Existe AP integral para el paciente-mes, pero en otra factura. La coincidencia mensual no demuestra inclusión ni cobro independiente.',
                    $precio, $costo, $valorPropio, $base);
                $this->registrarAsociacion($oncologico, self::GRUPO_DESCONOCIDO, 0, $valorPropio, $otrasFacturas,
                    'AP integral del mismo paciente-mes en otra factura; asociación pendiente.');

                continue;
            }

            if ($integrales === []) {
                $this->agregar($linea, $estado, $suma, $subtipo, $fuente, $motivo.' Oncológico sin AP integral en el caso-mes.', $precio, $costo, $valor, $base);
                $this->registrarAsociacion($oncologico, self::GRUPO_SIN_INTEGRAL, $suma ? $valorPropio : 0, $condicionadoPropio, [], 'Sin AP integral en el caso-mes.');

                continue;
            }

            if (array_intersect($tokens, $this->composicionOtrosCodigos($linea['mes'], $codigosIntegrales)) !== []) {
                // Independiente del integral del caso; su valor depende de su propio precio (o queda condicionado/pendiente).
                if ($suma) {
                    $this->cobertura($linea, self::COBERTURA_INDEPENDIENTE, implode(', ', $codigosIntegrales), 0, 'El medicamento figura solo en la composición histórica de otro CUPS; '.$fuente);
                }

                $this->agregar($linea, $estado, $suma, $subtipo, $fuente, $motivo.' Pertenece al esquema de otro CUPS (no figura en el integral del caso-mes): se suma como AM independiente.',
                    $precio, $costo, $valor, $base);
                $this->registrarAsociacion($oncologico, self::GRUPO_OTRO_ESQUEMA, $suma ? $valorPropio : 0, $condicionadoPropio, $codigosIntegrales,
                    'Figura en la composición histórica de otro CUPS de quimioterapia.');

                continue;
            }

            $this->cobertura($linea, self::COBERTURA_CONDICIONADO_AP, implode(', ', $codigosIntegrales), $valorPropio,
                'Sin composición histórica que demuestre inclusión ni independencia; '.$fuente);
            $this->agregar($linea, self::CONDICIONADA, false, 'ASOCIACION DESCONOCIDA CON EL TRATAMIENTO INTEGRAL', $fuente,
                'El caso-mes tiene AP integral ('.implode(', ', $codigosIntegrales).') pero el medicamento no figura en ninguna composición histórica: valor condicionado.',
                $precio, $costo, $valorPropio, $base);
            $this->registrarAsociacion($oncologico, self::GRUPO_DESCONOCIDO, 0, $valorPropio, $codigosIntegrales, 'Sin evidencia de pertenencia a un esquema.');
        }
    }

    /**
     * Clasificación RN-14 de la línea en COBERTURA AM RN14.
     *
     * @param  array<string, mixed>  $linea
     */
    private function cobertura(array $linea, string $clasificacion, string $ap, int $potencial, string $fuente, ?float $precio = null): void
    {
        $this->coberturaMedicamentos[$linea['id']]['clasificacion'] = $clasificacion;
        $this->coberturaMedicamentos[$linea['id']]['ap'] = $ap;
        $this->coberturaMedicamentos[$linea['id']]['potencial'] = $potencial;
        $this->coberturaMedicamentos[$linea['id']]['fuente'] = $fuente;

        if ($precio !== null) {
            $this->coberturaMedicamentos[$linea['id']]['precio'] = $precio;
        }
    }

    /**
     * Integrales del mismo paciente y mes facturados en otras facturas.
     *
     * @return list<array{codigo: string, composicion: list<string>, esquema: string, distintivos: list<string>, distintivosMezcla: list<string>}>
     */
    private function integralesOtrasFacturas(string $mes, string $caso, string $casoFactura): array
    {
        $integrales = [];

        foreach ($this->integralesCaso[$mes] ?? [] as $clave => $delCaso) {
            if ($clave !== $casoFactura && str_starts_with((string) $clave, $caso.'|')) {
                array_push($integrales, ...$delCaso);
            }
        }

        return $integrales;
    }

    /**
     * @param  array<string, mixed>  $oncologico
     * @param  list<string>  $integrales
     */
    private function registrarAsociacion(array $oncologico, string $grupo, int $central, int $condicionado, array $integrales, string $evidencia): void
    {
        $linea = $oncologico['linea'];
        $clave = implode('|', [$linea['mes'], $grupo, $oncologico['nombre'], $oncologico['cum']]);
        $this->medicamentos[$clave] ??= ['mes' => $linea['mes'], 'grupo' => $grupo, 'nombre' => $oncologico['nombre'], 'cum' => $oncologico['cum'],
            'registros' => 0, 'unidades' => 0.0, 'costoA' => 0, 'central' => 0, 'condicionado' => 0, 'integrales' => [], 'evidencia' => $evidencia];
        $this->medicamentos[$clave]['registros']++;
        $this->medicamentos[$clave]['unidades'] += $linea['cantidad'];
        $this->medicamentos[$clave]['costoA'] += $linea['costoA'];
        $this->medicamentos[$clave]['central'] += $central;
        $this->medicamentos[$clave]['condicionado'] += $condicionado;

        foreach ($integrales as $codigo) {
            $this->medicamentos[$clave]['integrales'][$codigo] = true;
        }
    }

    /**
     * Medicamentos que el histórico asocia a CUPS de quimioterapia distintos de los del caso-mes.
     *
     * @param  list<string>  $excluir
     * @return list<string>
     */
    private function composicionOtrosCodigos(string $mes, array $excluir): array
    {
        $tokens = [];

        foreach (array_keys($this->quimioterapia) as $codigo) {
            if (in_array((string) $codigo, $excluir, true)) {
                continue;
            }

            $clave = $mes.'|'.$codigo;
            $this->composicionOtros[$clave] ??= array_values(array_unique(array_merge([], ...array_map(
                static fn (array $clase): array => array_map('strval', array_keys($clase['composicion'])),
                $this->historial->clasesPrecio($this->ruta, $mes, 'AP', (string) $codigo)
            ))));
            $tokens = [...$tokens, ...$this->composicionOtros[$clave]];
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Base de unidad compatible entre el precio administrativo (por registro)
     * y la actividad RIPS del código en el mes.
     *
     * @param  array<string, mixed>  $diferido
     * @param  array<string, mixed>  $precio
     * @return array{0: string|null, 1: float, 2: string}
     */
    private function baseUnidad(array $diferido, array $precio): array
    {
        if ($diferido['maximo'] <= 1.0) {
            return ['REGISTRO = UNIDAD', (float) $diferido['registros'], 'Cada registro RIPS trae una unidad.'];
        }

        // Misma metodología para cualquier código, ruta y mes: la unidad que cobra cada fila
        // administrativa se deduce de la ventana histórica anterior al mes, nunca de constantes.
        // El historial cuenta todas las filas cobradas del código: se compara con todas sus líneas
        // RIPS del mes (con o sin tarifa) y solo se valoran las que no tienen tarifa.
        $administrativos = (float) $precio['registrosMensuales'];
        $actividad = $this->actividadCodigo[$diferido['mes'].'|'.$diferido['tipo'].'|'.$diferido['codigo']]
            ?? ['registros' => $diferido['registros'], 'unidades' => $diferido['unidades']];
        $mismoMes = $this->historial->valorDelMes($this->ruta, $diferido['mes'], $diferido['tipo'], $diferido['codigo']);
        $evidencia = sprintf('%s %s · ventana %s: registros administrativos por mes (mediana) %s frente a %d registros y %s unidades RIPS del código en %s (%d registros y %s unidades sin tarifa).%s',
            $this->ruta, $diferido['codigo'], implode(', ', $precio['meses']), number_format($administrativos, 1, ',', '.'), $actividad['registros'],
            number_format($actividad['unidades'], 1, ',', '.'), $diferido['mes'], $diferido['registros'], number_format($diferido['unidades'], 1, ',', '.'),
            $mismoMes === null ? '' : sprintf(' Control documental del mismo mes (no usado para valorar): %d filas administrativas.', $mismoMes['registros']));
        $candidatos = [];

        foreach (['REGISTRO' => [(float) $actividad['registros'], (float) $diferido['registros']], 'UNIDAD' => [(float) $actividad['unidades'], (float) $diferido['unidades']]] as $base => [$total, $cantidad]) {
            if ($total > 0 && $administrativos > 0) {
                $razon = $administrativos / $total;
                $candidatos[$base] = ['razon' => $razon, 'distancia' => abs(log($razon)), 'cantidad' => $cantidad];
            }
        }

        uasort($candidatos, static fn (array $a, array $b): int => $a['distancia'] <=> $b['distancia']);
        $mejor = array_key_first($candidatos);

        if ($mejor === null || $candidatos[$mejor]['razon'] < self::RAZON_MINIMA || $candidatos[$mejor]['razon'] > self::RAZON_MAXIMA) {
            return [null, 0.0, $evidencia.' Ninguna base queda entre 0,5 y 2 veces: unidad de cobro no verificable.'];
        }

        return [$mejor, $candidatos[$mejor]['cantidad'], $evidencia.' Base más cercana: '.mb_strtolower($mejor).'.'];
    }

    /**
     * @param  array<string, mixed>  $linea
     * @param  array<string, mixed>|null  $precio
     */
    private function agregar(
        array $linea,
        string $estado,
        bool $suma,
        string $subtipo,
        string $fuente,
        string $motivo,
        ?array $precio = null,
        int $costoA = -1,
        int $valor = 0,
        string $base = '',
        int $registros = 1,
        string $claseNoAditivo = '',
    ): void {
        $costoA = $costoA < 0 ? (int) $linea['costoA'] : $costoA;
        $claseNoAditivo = $suma ? '' : ($claseNoAditivo ?: ($estado === self::CONDICIONADA ? self::NO_ADITIVO_CONDICIONADO : self::NO_ADITIVO_INFORMATIVO));
        $clave = implode('|', [$linea['mes'], $linea['tipo'], $linea['codigo'], $estado, $suma ? 'S' : 'N', $subtipo, $base, $claseNoAditivo]);
        $this->codigos[$clave] ??= [
            'mes' => $linea['mes'], 'tipo' => $linea['tipo'], 'codigo' => $linea['codigo'], 'descripcion' => (string) $linea['descripcion'],
            'estado' => $estado, 'suma' => $suma, 'subtipo' => $subtipo, 'base' => $base, 'registros' => 0, 'unidades' => 0.0,
            'costoA' => 0, 'estimado' => 0, 'noAditivo' => 0, 'fuente' => $fuente, 'precio' => $precio, 'motivos' => [],
            'claseNoAditivo' => $claseNoAditivo,
        ];
        $codigo = &$this->codigos[$clave];
        $codigo['registros'] += $registros;
        $codigo['unidades'] += (float) $linea['cantidad'];
        $codigo['costoA'] += $costoA;
        // Lo respaldado se valora con su costo A; lo demás con el importe ya calculado para la línea o el código.
        $codigo[$suma ? 'estimado' : 'noAditivo'] += $suma && $estado === self::RESPALDADA ? $costoA : $valor;
        if ($suma && $registros === 1 && isset($linea['id'])) {
            $this->valoracionLinea[(int) $linea['id']] = ($this->valoracionLinea[(int) $linea['id']] ?? 0)
                + ($estado === self::RESPALDADA ? $costoA : $valor);
        }
        $codigo['precio'] ??= $precio;
        $codigo['motivos'][$motivo] = true;
        unset($codigo);
    }

    private function sumarNoAditivo(string $mes, string $concepto, int $centavos): void
    {
        if ($centavos > 0) {
            $this->noAditivos[$mes][$concepto] = ($this->noAditivos[$mes][$concepto] ?? 0) + $centavos;
        }
    }

    /**
     * @return array{estimado: int, condicionado: int, informativo: int}
     */
    private function totalesTipo(string $mes, string $tipo): array
    {
        $suma = ['estimado' => 0, 'condicionado' => 0, 'informativo' => 0];

        foreach ($this->codigos as $codigo) {
            if ($codigo['mes'] !== $mes || $codigo['tipo'] !== $tipo) {
                continue;
            }

            $suma['estimado'] += $codigo['estimado'];
            $suma['condicionado'] += $codigo['claseNoAditivo'] === self::NO_ADITIVO_CONDICIONADO ? $codigo['noAditivo'] : 0;
            $suma['informativo'] += $codigo['claseNoAditivo'] === self::NO_ADITIVO_INFORMATIVO ? $codigo['noAditivo'] : 0;
        }

        return $suma;
    }

    /**
     * @param  array<string, int|float>  $acumulado
     * @param  array<string, int|float>  $fila
     * @return array<string, int|float>
     */
    private function sumarFila(array $acumulado, array $fila): array
    {
        foreach ($acumulado as $campo => $valor) {
            $acumulado[$campo] = $valor + ($fila[$campo] ?? 0);
        }

        return $acumulado;
    }

    /**
     * @param  array<string, int|float>  $fila
     * @return list<mixed>
     */
    private function filaComparacion(string $mes, string $tipo, array $fila, ?float $referencia, string $fuente, string $estado): array
    {
        $central = $fila['estimado'] / 100.0;

        return [$mes, $tipo, $fila['registros'], round($fila['unidades'], 4), $fila['costoA'] / 100.0, $central,
            $fila['condicionado'] / 100.0, round(($fila['estimado'] + $fila['condicionado']) / 100.0, 2),
            round(($fila['estimado'] - $fila['costoA']) / 100.0, 2), $referencia === null ? 'SIN HISTORIAL' : round($referencia, 2),
            $referencia === null ? '' : round($central - $referencia, 2), $fila['informativo'] / 100.0, $fuente, $estado];
    }

    /**
     * @return list<string>
     */
    private function meses(): array
    {
        $meses = array_values(array_filter(array_keys($this->tipos), static fn (string $mes): bool => $mes !== ''));
        sort($meses);

        return $meses;
    }

    /**
     * @param  array<string, mixed>|null  $referencia
     */
    private function fuenteTipo(string $tipo, ?array $referencia): string
    {
        $historico = $referencia === null ? 'sin historial administrativo' : 'mediana administrativa '.implode(', ', $referencia['meses']);

        return match ($tipo) {
            'AC' => 'A (control) · '.$historico,
            'AP' => 'Tarifa aplicable, tratamiento integral según esquema real y mediana administrativa por código · '.$historico,
            'AT' => 'Mediana administrativa por código con unidad compatible · '.$historico,
            'AU' => 'Atribución no aditiva · '.$historico,
            'AM' => 'AM independiente (A + precio histórico de dispensación por CUM); el incluido en AP integral no suma · el administrativo no registra AM aparte',
            'AH' => 'Paquetes de 25 días (control) · '.$historico,
            default => $historico,
        };
    }

    /** Costo A (centavos) de los medicamentos que reemplaza el tratamiento integral del mes. */
    private function medicamentosIncluidosIntegral(string $mes): int
    {
        return $this->costoAMedicamentosGrupo($mes, self::GRUPO_INCLUIDO);
    }

    /** Costo A (centavos) de los medicamentos oncológicos del mes clasificados en un grupo de asociación. */
    private function costoAMedicamentosGrupo(string $mes, string $grupo): int
    {
        $costo = 0;

        foreach ($this->medicamentos as $medicamento) {
            $costo += $medicamento['mes'] === $mes && $medicamento['grupo'] === $grupo ? $medicamento['costoA'] : 0;
        }

        return $costo;
    }

    private function estadoTipo(string $tipo, string $mes): string
    {
        if ($tipo === 'AM') {
            return 'AM INDEPENDIENTE; AM INCLUIDO EN AP INTEGRAL (COSTO A NO SUMADO): $'.number_format($this->medicamentosIncluidosIntegral($mes) / 100.0, 2, ',', '.');
        }

        return match ($tipo) {
            'AC', 'AH' => 'CONTROL: IGUAL AL COSTO A',
            'AU' => 'NO ADITIVO',
            default => 'ESTIMACION REFERENCIAL',
        };
    }

    /**
     * @param  array<string, mixed>  $dato
     * @param  array<string, mixed>|null  $precio
     */
    private function causa(array $dato, ?array $precio, float $diferencia, float $actividad): string
    {
        $estados = implode(' + ', array_keys($dato['estados']));
        $sentido = static fn (float $efecto): string => $efecto < 0 ? 'MENOR' : 'MAYOR';

        return match (true) {
            abs($diferencia) < 0.01 => 'SIN DIFERENCIA',
            $dato['tipo'] === 'AH' || str_contains($estados, 'PAQUETE AH') => 'PAQUETES: METODOLOGIA DISTINTA (REGISTRO DE INTERNACION FRENTE A BLOQUES DE 25 DIAS)',
            $dato['tipo'] === 'AU' => 'METODOLOGIA: LA CONSULTA DE URGENCIAS SE VALORA EN AC',
            $dato['registros'] === 0 => 'MENOR ACTIVIDAD: SIN REGISTROS EN EL MES',
            str_contains($estados, 'TRATAMIENTO INTEGRAL') => $sentido($actividad).' ACTIVIDAD ('.number_format($actividad, 0, ',', '.').') Y COMPOSICION DE ESQUEMAS ('
                .number_format($diferencia - $actividad, 0, ',', '.').')',
            str_contains($estados, 'RADIOTERAPIA') => 'RADIOTERAPIA CONDICIONADA POR UNIDAD (NO SUMA A LA CENTRAL)',
            str_contains($estados, self::INCLUIDA) && ! str_contains($estados, self::ESTIMADA) && ! str_contains($estados, self::RESPALDADA) => 'INCLUIDO EN PAQUETE U OTRO COMPONENTE',
            str_contains($estados, self::CONDICIONADA) && ! str_contains($estados, self::ESTIMADA) && ! str_contains($estados, self::RESPALDADA) => 'UNIDAD O PRESENTACION NO COMPATIBLE',
            str_contains($estados, self::SIN_VALOR) && ! str_contains($estados, self::ESTIMADA) && ! str_contains($estados, self::RESPALDADA) => 'REGISTROS SIN TARIFA NI PRECIO HISTORICO',
            $precio === null => 'DATOS INSUFICIENTES: SIN PRECIO HISTORICO',
            abs($actividad) >= abs($diferencia - $actividad) => $sentido($actividad).' ACTIVIDAD',
            default => 'PRECIO, ALCANCE O LINEAS NO VALORADAS',
        };
    }

    /**
     * @param  array<string, mixed>  $codigo
     */
    private function faltante(array $codigo): string
    {
        return match (true) {
            str_starts_with($codigo['subtipo'], 'RADIOTERAPIA') => 'Unidad contractual (tratamiento, sesión o fracción) y tarifa vigente.',
            in_array($codigo['subtipo'], ['MEZCLA HISTORICA DE ESQUEMAS', self::SUBTIPO_RN14_MEZCLA], true) => 'Esquema real (medicamentos) de cada registro de quimioterapia.',
            str_starts_with($codigo['subtipo'], 'ASOCIACION DESCONOCIDA') => 'Asociación del medicamento con el tratamiento (esquema y CUPS) del caso-mes.',
            $codigo['subtipo'] === self::SUBTIPO_RN14_AP => 'Prueba de que el precio del AP integral cubre esta presentación, o de que el medicamento se cobra aparte.',
            $codigo['subtipo'] === self::SUBTIPO_DISPENSACION_MISMO_PERIODO => 'Precio del CUM en meses anteriores o tarifa contractual; la dispensación del mismo evento no es referencia histórica.',
            $codigo['subtipo'] === 'UNIDAD NO COMPATIBLE' => 'Unidad de cobro histórica del código (por registro o por unidad) y tarifa contractual.',
            $codigo['subtipo'] === 'INDEPENDENCIA AT NO DEMOSTRADA' => 'Evidencia de cobro independiente del AT frente a procedimientos y paquetes, y unidad contractual.',
            str_starts_with($codigo['subtipo'], 'D ·') => 'CUM o presentación inequívoca y cantidad dispensada.',
            str_starts_with($codigo['subtipo'], 'C ·') => 'Tarifa contractual vigente del CUM; el precio de dispensación solo es referencia.',
            str_starts_with($codigo['subtipo'], 'TRATAMIENTO INTEGRAL') => 'Acuerdo de tarifa integral por esquema y número de tratamientos completos.',
            $codigo['estado'] === self::SIN_VALOR => 'Tarifa contractual o precio administrativo histórico del código.',
            default => 'Soporte contractual de la tarifa y del alcance del precio administrativo.',
        };
    }
}
