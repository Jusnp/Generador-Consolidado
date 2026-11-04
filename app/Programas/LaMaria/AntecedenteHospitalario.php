<?php

namespace App\Programas\LaMaria;

/**
 * Cierre hospitalario de una ruta y un período: estancias abiertas al cierre
 * (corte 08) con sus bloques ya reconocidos y la evidencia de las que no
 * continuaron. Es inmutable: una corrección es una versión nueva. La versión 0
 * es un cierre provisional, calculado en una carga y nunca guardado.
 *
 * Cada estancia: clavePaciente (HMAC), autorizacion, codPrestador, ingresoOriginal,
 * inicioTramo, finCorte, destinoFinal, cortes08, bloquesReconocidos,
 * bloquesPeriodo (número, inicio y período económico de cada bloque),
 * tramosOrigen (factura#consecutivo), estado (EstadoEstanciaHospitalaria) y
 * continuidad (motivo con que se unió o no).
 *
 * huellaSecreto identifica el secreto con que se seudonimizó al paciente sin
 * revelarlo: un cierre con otra huella no se puede comparar con la carga.
 * completitud dice cómo se estableció que el período estaba completo: hoy solo
 * CONFIRMADA_POR_OPERADOR (la lista de facturas no se contrasta con una fuente
 * independiente).
 */
final class AntecedenteHospitalario
{
    public const TIPO_ARRANQUE = 'ARRANQUE';

    public const TIPO_DEFINITIVO = 'DEFINITIVO';

    public const TIPO_PROVISIONAL = 'PROVISIONAL';

    public const COMPLETITUD_OPERADOR = 'CONFIRMADA_POR_OPERADOR';

    /**
     * @param  list<array<string, mixed>>  $estancias
     * @param  list<array{factura: string, archivo: string, sha256: string}>  $origen
     */
    public function __construct(
        public readonly string $ruta,
        public readonly string $periodo,
        public readonly int $version,
        public readonly string $tipo,
        public readonly array $estancias,
        public readonly array $origen,
        public readonly string $versionReglas,
        public readonly ?int $antecedenteId = null,
        public readonly ?string $antecedenteHash = null,
        public readonly ?string $antecedentePeriodo = null,
        public readonly ?int $antecedenteVersion = null,
        public readonly ?int $id = null,
        public readonly bool $vigente = true,
        public readonly ?int $programaEjecucionId = null,
        public readonly string $motivo = '',
        public readonly string $huellaSecreto = '',
        public readonly string $completitud = '',
    ) {}

    /**
     * Huella del contenido: estancias en orden canónico, antecedente y reglas.
     */
    public function hashContenido(): string
    {
        $estancias = $this->propias();
        usort($estancias, static fn (array $a, array $b): int => strcmp(self::identidad($a), self::identidad($b)));

        return hash('sha256', (string) json_encode([$estancias, $this->antecedenteHash, $this->versionReglas], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Estancias calculadas para el período, sin la evidencia de rectificación de versiones anteriores.
     *
     * @return list<array<string, mixed>>
     */
    public function propias(): array
    {
        return array_values(array_filter($this->estancias,
            static fn (array $estancia): bool => $estancia['estado'] !== EstadoEstanciaHospitalaria::Rectificada->value));
    }

    /**
     * Agrega como RECTIFICADA cada estancia de la versión anterior que esta versión ya no contiene igual.
     */
    public function rectificando(?self $anterior): self
    {
        if ($anterior === null) {
            return $this;
        }

        $actuales = array_flip(array_map(static fn (array $estancia): string => (string) json_encode($estancia), $this->propias()));
        $rectificadas = [];

        foreach ($anterior->propias() as $estancia) {
            if (! isset($actuales[(string) json_encode($estancia)])) {
                $rectificadas[] = [...$estancia, 'estado' => EstadoEstanciaHospitalaria::Rectificada->value, 'rectificaVersion' => $anterior->version];
            }
        }

        return $this->conEstancias([...$this->propias(), ...$rectificadas], $this->motivo);
    }

    /**
     * Huella de los JSON de origen del período, en orden de factura.
     */
    public function hashOrigen(): string
    {
        $origen = $this->origen;
        usort($origen, static fn (array $a, array $b): int => strcmp($a['factura'].$a['archivo'], $b['factura'].$b['archivo']));

        return hash('sha256', (string) json_encode($origen));
    }

    /**
     * Estancias abiertas al cierre: las únicas que una continuación del período siguiente puede unir.
     *
     * @return list<array<string, mixed>>
     */
    public function abiertas(): array
    {
        return array_values(array_filter($this->estancias,
            static fn (array $estancia): bool => EstadoEstanciaHospitalaria::from((string) $estancia['estado'])->abierta()));
    }

    /**
     * @param  array<string, mixed>  $estancia
     */
    public static function identidad(array $estancia): string
    {
        return $estancia['clavePaciente'].'|'.$estancia['autorizacion'].'|'.$estancia['ingresoOriginal'].'|'.$estancia['estado'];
    }

    /**
     * El cierre calculado, como cierre que se guarda: ARRANQUE (primer período autorizado) o DEFINITIVO.
     */
    public function comoCierre(string $tipo, string $motivo = '', string $completitud = self::COMPLETITUD_OPERADOR): self
    {
        return new self($this->ruta, $this->periodo, 0, $tipo, $this->estancias, $this->origen, $this->versionReglas,
            $this->antecedenteId, $this->antecedenteHash, $this->antecedentePeriodo, $this->antecedenteVersion,
            motivo: $motivo, huellaSecreto: $this->huellaSecreto, completitud: $completitud);
    }

    public function conVersion(int $version, ?int $id, bool $vigente = true, ?int $programaEjecucionId = null): self
    {
        return new self($this->ruta, $this->periodo, $version, $this->tipo, $this->estancias, $this->origen, $this->versionReglas,
            $this->antecedenteId, $this->antecedenteHash, $this->antecedentePeriodo, $this->antecedenteVersion, $id, $vigente,
            $programaEjecucionId ?? $this->programaEjecucionId, $this->motivo, $this->huellaSecreto, $this->completitud);
    }

    /**
     * @param  list<array<string, mixed>>  $estancias
     */
    public function conEstancias(array $estancias, string $motivo): self
    {
        return new self($this->ruta, $this->periodo, $this->version, $this->tipo, $estancias, $this->origen, $this->versionReglas,
            $this->antecedenteId, $this->antecedenteHash, $this->antecedentePeriodo, $this->antecedenteVersion, $this->id, $this->vigente,
            $this->programaEjecucionId, $motivo, $this->huellaSecreto, $this->completitud);
    }

    /**
     * Cómo se estableció que el período estaba completo, para la traza y el informe.
     */
    public function completitudLegible(): string
    {
        return match ($this->completitud) {
            self::COMPLETITUD_OPERADOR => 'completitud confirmada por el operador (sin fuente independiente)',
            default => 'completitud sin registrar',
        };
    }

    public function etiqueta(): string
    {
        return sprintf('%s %s v%d (%s)', $this->ruta, $this->periodo, $this->version, substr($this->hashContenido(), 0, 12));
    }
}
