<?php

namespace App\Programas\LaMaria;

use App\Exceptions\ConcurrenciaContinuidadException;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Almacén de continuidad hospitalaria en base de datos (tablas
 * hospital_segmentos y hospital_paquete_movimientos).
 *
 * Cada confirmación: (1) toma un bloqueo consultivo por ruta y paciente
 * (GET_LOCK en MySQL); (2) abre una transacción; (3) rechaza si la versión del
 * paciente cambió desde la lectura; (4) inserta versiones de segmentos y
 * movimientos. Los índices únicos identidad_vigente, bloque_vigente y
 * clave_idempotencia son la guarda final: un bloque no puede quedar reconocido
 * dos veces aunque falle todo lo demás. Nada se borra: un reverso anula
 * bloque_vigente del reconocimiento y registra su propio movimiento.
 */
class AlmacenContinuidadHospitalariaBaseDatos implements AlmacenContinuidadHospitalaria
{
    public function __construct(
        private ?string $conexion = null,
        private ?int $usuarioId = null,
        private string $programaSlug = LaMariaPrograma::SLUG,
        private int $esperaBloqueoSegundos = 10,
    ) {}

    public function version(string $ruta, string $paciente): int
    {
        [$tipo, $documento] = $this->documento($paciente);

        return $this->segmentosDe($ruta, $tipo, $documento)->count()
            + $this->movimientosDe($ruta)->where('tipo_documento', $tipo)->where('num_documento', $documento)->count();
    }

    public function segmentos(string $ruta, string $paciente): array
    {
        [$tipo, $documento] = $this->documento($paciente);

        return $this->segmentosDe($ruta, $tipo, $documento)->orderBy('id')->get()->map(static fn (object $fila): array => [
            'id' => (int) $fila->id,
            'identidad' => $fila->identidad,
            'version' => (int) $fila->version,
            'ruta' => $fila->ruta,
            'paciente' => $paciente,
            'prestador' => $fila->prestador,
            'tipo_documento' => $fila->tipo_documento,
            'documento' => $fila->num_documento,
            'factura' => $fila->num_factura,
            'consecutivo' => $fila->consecutivo_servicio,
            'inicio' => $fila->fecha_ingreso_rips,
            'fin' => $fila->fecha_egreso_rips,
            'destino' => $fila->condicion_destino,
            'autorizacion' => (string) $fila->num_autorizacion,
            'prestador_servicio' => (string) $fila->cod_prestador_servicio,
            'archivo' => (string) $fila->archivo_origen,
            'hash_contenido' => $fila->hash_contenido,
            'estado' => $fila->estado,
            'ejecucion' => $fila->ejecucion_origen,
        ])->all();
    }

    public function movimientos(string $ruta, ?string $paciente = null): array
    {
        $consulta = $this->movimientosDe($ruta)->orderBy('id');

        if ($paciente !== null) {
            [$tipo, $documento] = $this->documento($paciente);
            $consulta->where('tipo_documento', $tipo)->where('num_documento', $documento);
        }

        return $consulta->get()->map(static fn (object $fila): array => [
            'id' => (int) $fila->id,
            'ruta' => $fila->ruta,
            'paciente' => $fila->tipo_documento.'|'.$fila->num_documento,
            'bloque_inicio' => (new DateTimeImmutable($fila->bloque_inicio))->format('Y-m-d H:i'),
            'numero_bloque' => $fila->numero_bloque === null ? null : (int) $fila->numero_bloque,
            'mes_imputado' => $fila->mes_contable,
            'signo' => (int) $fila->signo,
            'valor' => (float) $fila->valor,
            'motivo' => $fila->motivo,
            'estado_episodio' => $fila->estado_episodio,
            'episodio_clave' => $fila->episodio_clave,
            'ejecucion' => $fila->ejecucion_origen,
            'clave_idempotencia' => $fila->clave_idempotencia,
            'bloque_vigente' => $fila->bloque_vigente,
        ])->all();
    }

    public function mesesCargados(string $ruta): array
    {
        $meses = [];

        foreach ($this->db()->table('hospital_segmentos')->where('programa_slug', $this->programaSlug)->where('ruta', $ruta)
            ->where('estado', 'VIGENTE')->get(['fecha_ingreso_rips', 'fecha_egreso_rips']) as $fila) {
            $meses[substr((string) $fila->fecha_ingreso_rips, 0, 7)] = true;
            $meses[substr((string) $fila->fecha_egreso_rips, 0, 7)] = true;
        }

        return array_keys($meses);
    }

    public function confirmar(string $ruta, string $paciente, int $versionLeida, array $escrituras): void
    {
        $this->antesDeConfirmar();
        [$tipo, $documento] = $this->documento($paciente);
        $bloqueo = 'lmc:'.sha1($this->programaSlug.'|'.$ruta.'|'.$paciente);
        $this->bloquear($bloqueo);

        try {
            $this->db()->transaction(function () use ($ruta, $paciente, $tipo, $documento, $versionLeida, $escrituras): void {
                if ($this->version($ruta, $paciente) !== $versionLeida) {
                    throw new ConcurrenciaContinuidadException('Otra carga modificó la continuidad de este paciente.');
                }

                $ahora = now();
                $ids = [];

                foreach ($escrituras['reemplazados'] as $clave) {
                    [$identidad, $version] = $this->claveSegmento($clave);
                    $this->db()->table('hospital_segmentos')->where('identidad', $identidad)->where('version', $version)
                        ->update(['estado' => 'REEMPLAZADO', 'identidad_vigente' => null, 'updated_at' => $ahora]);
                }

                foreach ($escrituras['segmentos'] as $segmento) {
                    $reemplaza = $segmento['reemplaza'] === null ? null : $this->idSegmento($segmento['reemplaza']);
                    $ids[$segmento['identidad'].'#'.$segmento['version']] = $this->db()->table('hospital_segmentos')->insertGetId([
                        'programa_slug' => $this->programaSlug,
                        'ruta' => $ruta,
                        'prestador' => $segmento['prestador'],
                        'num_factura' => $segmento['factura'],
                        'tipo_documento' => $tipo,
                        'num_documento' => $documento,
                        'consecutivo_servicio' => $segmento['consecutivo'],
                        'identidad' => $segmento['identidad'],
                        'version' => $segmento['version'],
                        'identidad_vigente' => $segmento['estado'] === 'VIGENTE' ? $segmento['identidad'] : null,
                        'fecha_ingreso' => PoliticaPaqueteHospitalario::fecha($segmento['inicio']),
                        'fecha_egreso' => PoliticaPaqueteHospitalario::fecha($segmento['fin']),
                        'fecha_corte' => $segmento['fecha_corte'] === null ? null : PoliticaPaqueteHospitalario::fecha($segmento['fecha_corte']),
                        'fecha_ingreso_rips' => $segmento['inicio'],
                        'fecha_egreso_rips' => $segmento['fin'],
                        'condicion_destino' => $segmento['destino'],
                        'num_autorizacion' => ($segmento['autorizacion'] ?? '') ?: null,
                        'cod_prestador_servicio' => ($segmento['prestador_servicio'] ?? '') ?: null,
                        'hash_contenido' => $segmento['hash_contenido'],
                        'estado' => $segmento['estado'],
                        'reemplaza_segmento_id' => $reemplaza,
                        'archivo_origen' => $segmento['archivo'] ?: null,
                        'ejecucion_origen' => $segmento['ejecucion'],
                        'programa_ejecucion_id' => $segmento['programa_ejecucion_id'] ?? null,
                        'created_by' => $this->usuarioId,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                }

                foreach ($escrituras['movimientos'] as $movimiento) {
                    $this->insertarMovimiento($ruta, $tipo, $documento, $movimiento, $ids, $ahora);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrenciaContinuidadException('Restricción de unicidad: '.$exception->getMessage(), 0, $exception);
        } finally {
            $this->liberar($bloqueo);
        }
    }

    /**
     * @param  array<string, mixed>  $movimiento
     * @param  array<string, int>  $ids
     */
    protected function insertarMovimiento(string $ruta, string $tipo, string $documento, array $movimiento, array $ids, mixed $ahora): void
    {
        $bloque = (new DateTimeImmutable($movimiento['bloque_inicio']))->format('Y-m-d H:i');
        $claveBloque = implode('|', [$this->programaSlug, $ruta, $tipo, $documento, $bloque]);
        $ordinal = $this->movimientosDe($ruta)->where('tipo_documento', $tipo)->where('num_documento', $documento)
            ->where('bloque_inicio', $bloque.':00')->count() + 1;
        $revertido = null;

        if ($movimiento['signo'] < 0) {
            $revertido = $this->db()->table('hospital_paquete_movimientos')->where('bloque_vigente', $claveBloque)->value('id');

            if ($revertido === null) {
                throw new ConcurrenciaContinuidadException('No hay reconocimiento vigente que revertir para '.$claveBloque.'.');
            }

            $this->db()->table('hospital_paquete_movimientos')->where('id', $revertido)->update(['bloque_vigente' => null, 'updated_at' => $ahora]);
        }

        $ancla = $movimiento['segmento_ancla'] ?? null;

        $this->db()->table('hospital_paquete_movimientos')->insert([
            'programa_slug' => $this->programaSlug,
            'ruta' => $ruta,
            'tipo_documento' => $tipo,
            'num_documento' => $documento,
            'episodio_clave' => $movimiento['episodio_clave'] ?? null,
            'segmento_ancla_id' => $ancla === null ? null : ($ids[$ancla] ?? $this->idSegmento($ancla)),
            'segmentos' => json_encode(array_values(array_filter(array_map(
                fn (string $clave): ?int => $ids[$clave] ?? $this->idSegmento($clave),
                $movimiento['segmentos'] ?? []
            )))),
            'bloque_inicio' => $bloque.':00',
            'numero_bloque' => $movimiento['numero_bloque'],
            'mes_contable' => $movimiento['mes_imputado'],
            'signo' => $movimiento['signo'],
            'valor' => round((float) $movimiento['valor'], 2),
            'tarifa_paquete' => round((float) ($movimiento['tarifa_paquete'] ?? PoliticaPaqueteHospitalario::VALOR_PAQUETE), 2),
            'motivo' => $movimiento['motivo'],
            'estado_episodio' => $movimiento['estado_episodio'] ?: null,
            'movimiento_revertido_id' => $revertido,
            'bloque_vigente' => $movimiento['signo'] > 0 ? $claveBloque : null,
            'clave_idempotencia' => $claveBloque.'|'.$ordinal,
            'ejecucion_origen' => $movimiento['ejecucion'],
            'programa_ejecucion_id' => $movimiento['programa_ejecucion_id'] ?? null,
            'created_by' => $this->usuarioId,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    /**
     * Punto de extensión para simular otra carga que confirma primero.
     */
    protected function antesDeConfirmar(): void {}

    protected function db(): ConnectionInterface
    {
        return DB::connection($this->conexion);
    }

    private function bloquear(string $bloqueo): void
    {
        if ($this->db()->getDriverName() !== 'mysql') {
            return;
        }

        if ((int) $this->db()->scalar('SELECT GET_LOCK(?, ?)', [$bloqueo, $this->esperaBloqueoSegundos]) !== 1) {
            throw new ConcurrenciaContinuidadException('Otra carga mantiene ocupada la continuidad de este paciente.');
        }
    }

    private function liberar(string $bloqueo): void
    {
        if ($this->db()->getDriverName() === 'mysql') {
            $this->db()->scalar('SELECT RELEASE_LOCK(?)', [$bloqueo]);
        }
    }

    private function idSegmento(string $clave): ?int
    {
        [$identidad, $version] = $this->claveSegmento($clave);
        $id = $this->db()->table('hospital_segmentos')->where('identidad', $identidad)->where('version', $version)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function claveSegmento(string $clave): array
    {
        $posicion = (int) strrpos($clave, '#');

        return [substr($clave, 0, $posicion), (int) substr($clave, $posicion + 1)];
    }

    private function segmentosDe(string $ruta, string $tipo, string $documento): Builder
    {
        return $this->db()->table('hospital_segmentos')->where('programa_slug', $this->programaSlug)->where('ruta', $ruta)
            ->where('tipo_documento', $tipo)->where('num_documento', $documento);
    }

    private function movimientosDe(string $ruta): Builder
    {
        return $this->db()->table('hospital_paquete_movimientos')->where('programa_slug', $this->programaSlug)->where('ruta', $ruta);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function documento(string $paciente): array
    {
        [$tipo, $documento] = array_pad(explode('|', $paciente, 2), 2, '');

        return [$tipo, $documento];
    }
}
