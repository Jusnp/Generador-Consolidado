<?php

namespace App\Programas\LaMaria;

use App\Models\AhAntecedente;
use App\Models\AhEstanciaAbierta;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Historial hospitalario en las tablas ah_antecedentes y ah_estancias_abiertas.
 *
 * Guardar: (1) bloquea la versión vigente del período; (2) si el contenido y el
 * origen son iguales, la devuelve sin escribir; (3) si no, la marca REEMPLAZADO,
 * libera clave_vigente e inserta la versión siguiente con sus estancias. Los
 * índices únicos clave_vigente y (programa, ruta, período, versión) impiden dos
 * versiones vigentes aunque dos cierres corran a la vez. Un bloqueo, interbloqueo
 * o choque de índice deshace el intento completo y se reintenta tras una pausa
 * (entonces ve la versión del otro cierre: la devuelve si es igual o la
 * reemplaza); si persiste, pide volver a ejecutar. Nunca hay escrituras parciales.
 */
class HistorialHospitalarioBaseDatos implements HistorialHospitalario
{
    private const INTENTOS = 3;

    public function __construct(
        private string $programaSlug = LaMariaPrograma::SLUG,
        private ?string $conexion = null,
    ) {}

    public function vigente(string $ruta, string $periodo): ?AntecedenteHospitalario
    {
        $fila = $this->consulta()->where('clave_vigente', $this->claveVigente($ruta, $periodo))->first();

        return $fila === null ? null : $this->antecedente($fila);
    }

    public function version(string $ruta, string $periodo, int $version): ?AntecedenteHospitalario
    {
        $fila = $this->consulta()->where('ruta', $ruta)->where('periodo', $periodo)->where('version', $version)->first();

        return $fila === null ? null : $this->antecedente($fila);
    }

    public function versiones(string $ruta, string $periodo): array
    {
        return $this->consulta()->where('ruta', $ruta)->where('periodo', $periodo)->orderBy('version')->get()
            ->map(fn (AhAntecedente $fila): AntecedenteHospitalario => $this->antecedente($fila))->values()->all();
    }

    public function guardar(AntecedenteHospitalario $cierre, ?int $usuarioId = null): AntecedenteHospitalario
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::connection($this->conexion)->transaction(fn (): AntecedenteHospitalario => $this->guardarVersion($cierre, $usuarioId));
            } catch (QueryException $exception) {
                if (! $exception instanceof UniqueConstraintViolationException && ! (new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                if ($intento >= self::INTENTOS) {
                    throw new RuntimeException('Otro cierre del mismo período se registró al mismo tiempo; vuelva a ejecutar el cierre.', 0, $exception);
                }

                usleep(200_000 * $intento);
            }
        }
    }

    private function guardarVersion(AntecedenteHospitalario $cierre, ?int $usuarioId): AntecedenteHospitalario
    {
        $clave = $this->claveVigente($cierre->ruta, $cierre->periodo);
        $actual = $this->consulta()->where('clave_vigente', $clave)->lockForUpdate()->first();

        if ($actual !== null && $actual->hash_contenido === $cierre->hashContenido() && $actual->hash_origen === $cierre->hashOrigen()) {
            return $this->antecedente($actual);
        }

        $anterior = $actual === null ? null : $this->antecedente($actual);
        $version = (int) $this->consulta()->where('ruta', $cierre->ruta)->where('periodo', $cierre->periodo)->max('version') + 1;
        $actual?->update(['estado' => 'REEMPLAZADO', 'clave_vigente' => null]);
        $nuevo = $cierre->rectificando($anterior);
        $fila = AhAntecedente::on($this->conexion)->create([
            'programa_slug' => $this->programaSlug, 'ruta' => $cierre->ruta, 'periodo' => $cierre->periodo, 'version' => $version,
            'tipo' => $cierre->tipo, 'estado' => 'VIGENTE', 'clave_vigente' => $clave,
            'hash_contenido' => $cierre->hashContenido(), 'hash_origen' => $cierre->hashOrigen(), 'origen' => $cierre->origen,
            'version_reglas' => $cierre->versionReglas, 'huella_secreto' => $cierre->huellaSecreto,
            'antecedente_id' => $cierre->antecedenteId, 'antecedente_hash' => $cierre->antecedenteHash,
            'reemplaza_id' => $actual?->id, 'motivo' => $cierre->motivo === '' ? null : $cierre->motivo, 'completitud' => $cierre->completitud,
            'programa_ejecucion_id' => $cierre->programaEjecucionId, 'created_by' => $usuarioId,
        ]);

        foreach ($nuevo->estancias as $estancia) {
            AhEstanciaAbierta::on($this->conexion)->create([
                'antecedente_id' => $fila->id, 'clave_paciente' => $estancia['clavePaciente'], 'autorizacion' => $estancia['autorizacion'],
                'cod_prestador' => $estancia['codPrestador'], 'ingreso_original' => $estancia['ingresoOriginal'],
                'inicio_tramo' => $estancia['inicioTramo'], 'fin_corte' => $estancia['finCorte'], 'destino_final' => $estancia['destinoFinal'],
                'cortes_08' => $estancia['cortes08'], 'bloques_reconocidos' => $estancia['bloquesReconocidos'],
                'bloques_periodo' => $estancia['bloquesPeriodo'], 'tramos_origen' => $estancia['tramosOrigen'],
                'estado' => $estancia['estado'], 'continuidad' => $estancia['continuidad'], 'rectifica_version' => $estancia['rectificaVersion'] ?? null,
            ]);
        }

        return $this->antecedente($fila->fresh());
    }

    private function claveVigente(string $ruta, string $periodo): string
    {
        return $this->programaSlug.'|'.$ruta.'|'.$periodo;
    }

    /**
     * @return Builder<AhAntecedente>
     */
    private function consulta()
    {
        return AhAntecedente::on($this->conexion)->where('programa_slug', $this->programaSlug);
    }

    private function antecedente(AhAntecedente $fila): AntecedenteHospitalario
    {
        $previo = $fila->antecedente_id === null ? null : AhAntecedente::on($this->conexion)->find($fila->antecedente_id);

        return new AntecedenteHospitalario(
            $fila->ruta, $fila->periodo, $fila->version, $fila->tipo,
            $fila->estancias()->orderBy('id')->get()->map(static fn (AhEstanciaAbierta $estancia): array => array_filter([
                'clavePaciente' => $estancia->clave_paciente, 'autorizacion' => $estancia->autorizacion, 'codPrestador' => $estancia->cod_prestador,
                'ingresoOriginal' => $estancia->ingreso_original, 'inicioTramo' => $estancia->inicio_tramo, 'finCorte' => $estancia->fin_corte,
                'destinoFinal' => $estancia->destino_final, 'cortes08' => $estancia->cortes_08, 'bloquesReconocidos' => $estancia->bloques_reconocidos,
                'bloquesPeriodo' => $estancia->bloques_periodo, 'tramosOrigen' => $estancia->tramos_origen, 'estado' => $estancia->estado,
                'continuidad' => $estancia->continuidad, 'rectificaVersion' => $estancia->rectifica_version,
            ], static fn (mixed $valor, string $campo): bool => $campo !== 'rectificaVersion' || $valor !== null, ARRAY_FILTER_USE_BOTH))->values()->all(),
            $fila->origen, $fila->version_reglas, $fila->antecedente_id, $fila->antecedente_hash, $previo?->periodo, $previo?->version,
            $fila->id, $fila->estado === 'VIGENTE', $fila->programa_ejecucion_id, (string) $fila->motivo, (string) $fila->huella_secreto, (string) $fila->completitud,
        );
    }
}
