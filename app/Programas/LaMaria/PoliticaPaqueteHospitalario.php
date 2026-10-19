<?php

namespace App\Programas\LaMaria;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Política contractual de La María para la hospitalización, según la hoja "Hx"
 * de las notas técnicas de Próstata y Cérvix:
 *
 * "Costo ponderado de hospitalización: este valor es un costo ponderado
 * independiente de la complejidad de la hospitalización, e incluye la
 * hotelería, insumos, medicamentos, servicios y tecnologías que hayan sido
 * realizadas bajo el ámbito hospitalario, hasta por un máximo de 25 días de
 * estancia. Si se supera este número de días de hospitalización, se cargará a
 * la ejecución como un nuevo valor de hospitalización."
 *
 * Y el comentario de la hoja "servicios y tecnologías": toda hospitalización de
 * menos de 24 horas se considera una urgencia al valor pactado.
 *
 * La duración es el tiempo real transcurrido entre el ingreso y el egreso de la
 * estancia lógica (después de unir los cortes de facturación 08): menos de 24
 * horas es urgencia; desde 24 horas, un paquete por cada bloque de 600 horas
 * (25 días) iniciado, de modo que 25 días y un minuto ya generan el segundo.
 *
 * Las excepciones cobrables por fuera del paquete (cirugía, quimioterapia,
 * resonancia, gammagrafía y radioterapia) fueron definidas en las reuniones del
 * programa. Lo que no está definido queda como REGLA_NO_DEFINIDA: conserva su
 * cobro individual y se reporta para revisión.
 */
class PoliticaPaqueteHospitalario
{
    /**
     * Valor del paquete hospitalario de la hoja "Hx" ($6.015.314,531995301),
     * redondeado al centavo.
     */
    public const VALOR_PAQUETE = 6015314.53;

    public const DIAS_POR_PAQUETE = 25;

    public const HORAS_MINIMAS_HOSPITALIZACION = 24;

    public const SEGUNDOS_POR_BLOQUE = self::DIAS_POR_PAQUETE * 24 * 3600;

    public const SEGUNDOS_MINIMOS_HOSPITALIZACION = self::HORAS_MINIMAS_HOSPITALIZACION * 3600;

    /**
     * Agrupaciones de servicio de las notas técnicas (columnas "Servicio",
     * "AGRUPADOR" o "RECLASIFICACION") que el paquete hospitalario absorbe.
     *
     * @var list<string>
     */
    private const CATEGORIAS_INCLUIDAS = [
        'LABORATORIO CLINICO',
        'IMAGENES DIAGNOSTICAS',
        'CONSULTA EXTERNA',
        'CONSULTA ESPECIALIZADA',
        'URGENCIAS',
        'TERAPIAS',
        'PARAMEDICAS Y TERAPIAS',
        'PROCEDIMIENTOS DIAGNOSTICOS Y TERAPEUTICOS',
        'APOYO DX Y TERAPEUTICO',
    ];

    /**
     * CUPS de quimioterapia de las notas técnicas (hoja "quimio y Radio").
     *
     * @var list<string>
     */
    private const QUIMIOTERAPIA = ['992504', '992505', '992509', '992511'];

    /**
     * Transfusiones: sin regla definida en el contrato.
     *
     * @var list<string>
     */
    private const TRANSFUSIONES = ['911107', '912002'];

    /**
     * ATC de antineoplásicos (L01) y terapia endocrina (L02), por ejemplo la
     * Leuprorelina (L02AE02): sin regla definida en el contrato.
     *
     * @var list<string>
     */
    private const ATC_ONCOLOGICOS_HORMONALES = ['L01', 'L02'];

    public const FUENTE_CODIGO = 'REGLA POR CODIGO';

    public const FUENTE_TIPO = 'TIPO DE SERVICIO RIPS';

    public const FUENTE_SERVICIO = 'SERVICIO NOTA TECNICA';

    public const FUENTE_AGRUPADOR = 'AGRUPADOR NOTA TECNICA';

    public const FUENTE_FALLBACK = 'CLASIFICACION OFICIAL TEMPORAL';

    public const FUENTE_SIN_CATEGORIA = 'SIN CATEGORIA';

    /**
     * Excepciones contractuales adicionales por tipo de servicio. Tienen
     * prioridad sobre la clasificación general.
     *
     * @param  array<string, list<string>>  $incluidosEnPaquete
     * @param  array<string, list<string>>  $cobrablesPorFuera
     */
    public function __construct(
        private array $incluidosEnPaquete = [],
        private array $cobrablesPorFuera = [],
    ) {
        foreach ($this->incluidosEnPaquete as $tipoServicio => $codigos) {
            $enAmbas = array_intersect($codigos, $this->cobrablesPorFuera[$tipoServicio] ?? []);

            if ($enAmbas !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Los códigos %s de %s no pueden estar incluidos en el paquete y ser cobrables por fuera.',
                    implode(', ', $enAmbas),
                    $tipoServicio
                ));
            }
        }
    }

    /**
     * Paquetes que genera una estancia según su duración real. Devuelve null si
     * las fechas no permiten calcularla.
     */
    public function paquetesPorEstancia(string $fechaInicio, string $fechaEgreso): ?int
    {
        $inicio = self::fecha($fechaInicio);
        $egreso = self::fecha($fechaEgreso);

        if ($inicio === null || $egreso === null || $egreso < $inicio) {
            return null;
        }

        return $this->paquetesPorDuracion($egreso->getTimestamp() - $inicio->getTimestamp());
    }

    /**
     * Ninguno por debajo de 24 horas (urgencia); desde 24 horas, uno por cada
     * bloque de hasta 600 horas: <= 600 h → 1, <= 1200 h → 2, <= 1800 h → 3.
     */
    public function paquetesPorDuracion(int $segundos): int
    {
        if ($segundos < self::SEGUNDOS_MINIMOS_HOSPITALIZACION) {
            return 0;
        }

        return intdiv($segundos - 1, self::SEGUNDOS_POR_BLOQUE) + 1;
    }

    /**
     * Decide el tratamiento de un servicio prestado durante una hospitalización
     * que genera paquete. Devuelve null para el registro de la hospitalización.
     */
    public function decidir(
        string $tipoServicio,
        string $codigo,
        string $descripcion = '',
        string $atc = '',
        string $servicio = '',
        string $agrupador = ''
    ): ?DecisionPaqueteHospitalario {
        return $this->clasificar($tipoServicio, $codigo, $descripcion, $atc, $servicio, $agrupador)['decision'];
    }

    /**
     * Las reglas por código definidas por el negocio tienen prioridad; luego la
     * agrupación de la nota técnica de la ruta (servicio, después agrupador) y,
     * solo si la nota activa no clasifica el código, la clasificación oficial
     * temporal.
     *
     * @return array{decision: ?DecisionPaqueteHospitalario, categoria: string, fuente: string}
     */
    public function clasificar(
        string $tipoServicio,
        string $codigo,
        string $descripcion = '',
        string $atc = '',
        string $servicio = '',
        string $agrupador = ''
    ): array {
        if ($tipoServicio === 'hospitalizacion') {
            return ['decision' => null, 'categoria' => '', 'fuente' => ''];
        }

        if (in_array($codigo, $this->incluidosEnPaquete[$tipoServicio] ?? [], true)) {
            return $this->resultado(DecisionPaqueteHospitalario::IncluidoEnPaquete, 'INCLUIDO POR REGLA CONTRACTUAL', self::FUENTE_CODIGO);
        }

        if (in_array($codigo, $this->cobrablesPorFuera[$tipoServicio] ?? [], true)) {
            return $this->resultado(DecisionPaqueteHospitalario::CobrablePorFuera, 'COBRABLE POR REGLA CONTRACTUAL', self::FUENTE_CODIGO);
        }

        $descripcion = self::normalizar($descripcion);

        if ($tipoServicio === 'medicamentos') {
            foreach (self::ATC_ONCOLOGICOS_HORMONALES as $prefijo) {
                if (str_starts_with(strtoupper($atc), $prefijo)) {
                    return $this->resultado(DecisionPaqueteHospitalario::ReglaNoDefinida, 'MEDICAMENTO ONCOLOGICO/HORMONAL ATC '.strtoupper($atc), self::FUENTE_CODIGO);
                }
            }

            return $this->resultado(DecisionPaqueteHospitalario::IncluidoEnPaquete, 'MEDICAMENTO', self::FUENTE_TIPO);
        }

        if (in_array($tipoServicio, ['otrosServicios', 'urgencias', 'consultas'], true)) {
            return $this->resultado(DecisionPaqueteHospitalario::IncluidoEnPaquete, match ($tipoServicio) {
                'otrosServicios' => 'INSUMO, DISPOSITIVO O ESTANCIA',
                'urgencias' => 'URGENCIA',
                default => 'CONSULTA',
            }, self::FUENTE_TIPO);
        }

        $porCodigo = match (true) {
            in_array($codigo, ClasificacionNotaTecnicaOficial::PET_TC, true)
                || str_contains($descripcion, 'POSITRONES') => [DecisionPaqueteHospitalario::ReglaNoDefinida, 'PET-TC'],
            in_array($codigo, self::TRANSFUSIONES, true) => [DecisionPaqueteHospitalario::ReglaNoDefinida, 'TRANSFUSION'],
            str_starts_with($codigo, '9228') => [DecisionPaqueteHospitalario::ReglaNoDefinida, 'TERAPIA CON RADIOISOTOPOS'],
            in_array($codigo, self::QUIMIOTERAPIA, true) => [DecisionPaqueteHospitalario::CobrablePorFuera, 'QUIMIOTERAPIA'],
            preg_match('/^922[46]\d{2}$/', $codigo) === 1 => [DecisionPaqueteHospitalario::CobrablePorFuera, 'RADIOTERAPIA'],
            in_array($codigo, ClasificacionNotaTecnicaOficial::GAMMAGRAFIAS, true)
                || preg_match('/GAMM?AGRAF/', $descripcion) === 1 => [DecisionPaqueteHospitalario::CobrablePorFuera, 'GAMMAGRAFIA'],
            str_starts_with($codigo, '883') && (
                in_array($codigo, ClasificacionNotaTecnicaOficial::RESONANCIAS, true)
                || str_contains($descripcion, 'RESONANCIA')
            ) => [DecisionPaqueteHospitalario::CobrablePorFuera, 'RESONANCIA'],
            default => null,
        };

        if ($porCodigo !== null) {
            return $this->resultado($porCodigo[0], $porCodigo[1], self::FUENTE_CODIGO);
        }

        foreach ([
            self::FUENTE_SERVICIO => [$servicio],
            self::FUENTE_AGRUPADOR => [$agrupador],
            self::FUENTE_FALLBACK => ClasificacionNotaTecnicaOficial::categorias($codigo),
        ] as $fuente => $categorias) {
            foreach ($categorias as $categoria) {
                $categoria = self::normalizar(str_replace('_', ' ', $categoria));

                if (str_starts_with($categoria, 'PROCEDIMIENTOS QUIRURGICOS') || str_starts_with($categoria, 'QUIRURGICO')) {
                    return $this->resultado(DecisionPaqueteHospitalario::CobrablePorFuera, 'PROCEDIMIENTO QUIRURGICO', $fuente);
                }

                if (in_array($categoria, self::CATEGORIAS_INCLUIDAS, true)) {
                    return $this->resultado(DecisionPaqueteHospitalario::IncluidoEnPaquete, $categoria, $fuente);
                }
            }
        }

        return $this->resultado(DecisionPaqueteHospitalario::ReglaNoDefinida, 'PROCEDIMIENTO SIN CATEGORIA EN LA NOTA TECNICA', self::FUENTE_SIN_CATEGORIA);
    }

    public static function fecha(string $valor): ?DateTimeImmutable
    {
        $valor = trim($valor);

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $formato) {
            $fecha = DateTimeImmutable::createFromFormat('!'.$formato, $valor);

            if ($fecha !== false) {
                return $fecha;
            }
        }

        return null;
    }

    private static function normalizar(string $valor): string
    {
        $valor = strtr(mb_strtoupper(trim($valor)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return (string) preg_replace('/\s+/', ' ', $valor);
    }

    /**
     * @return array{decision: DecisionPaqueteHospitalario, categoria: string, fuente: string}
     */
    private function resultado(DecisionPaqueteHospitalario $decision, string $categoria, string $fuente): array
    {
        return ['decision' => $decision, 'categoria' => $categoria, 'fuente' => $fuente];
    }
}
