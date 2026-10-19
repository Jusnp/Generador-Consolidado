<?php

namespace App\Programas\LaMaria;

use App\Exceptions\ConcurrenciaContinuidadException;
use Closure;

/**
 * Implementación en memoria del almacén de continuidad, con las mismas reglas
 * que exigirá la base de datos (confirmación optimista por paciente y un solo
 * reconocimiento vigente por bloque). Sirve para simular cargas sin escribir
 * en la base y para las pruebas.
 */
class AlmacenContinuidadHospitalariaEnMemoria implements AlmacenContinuidadHospitalaria
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $segmentos = [];

    /** @var list<array<string, mixed>> */
    private array $movimientos = [];

    /** @var array<string, int> */
    private array $versiones = [];

    /** @var array<string, true> bloques con reconocimiento vigente (equivale al índice único) */
    private array $bloquesVigentes = [];

    /**
     * Se ejecuta una vez justo antes de la siguiente confirmación; permite
     * simular otra carga que confirma primero.
     */
    public ?Closure $antesDeConfirmar = null;

    public function version(string $ruta, string $paciente): int
    {
        return $this->versiones[$ruta.'|'.$paciente] ?? 0;
    }

    public function segmentos(string $ruta, string $paciente): array
    {
        return $this->segmentos[$ruta.'|'.$paciente] ?? [];
    }

    public function movimientos(string $ruta, ?string $paciente = null): array
    {
        return array_values(array_filter(
            $this->movimientos,
            static fn (array $movimiento): bool => $movimiento['ruta'] === $ruta && ($paciente === null || $movimiento['paciente'] === $paciente)
        ));
    }

    public function mesesCargados(string $ruta): array
    {
        $meses = [];

        foreach ($this->segmentos as $clave => $segmentos) {
            if (! str_starts_with($clave, $ruta.'|')) {
                continue;
            }

            foreach ($segmentos as $segmento) {
                if ($segmento['estado'] === 'VIGENTE') {
                    $meses[substr((string) $segmento['inicio'], 0, 7)] = true;
                    $meses[substr((string) $segmento['fin'], 0, 7)] = true;
                }
            }
        }

        return array_keys($meses);
    }

    public function confirmar(string $ruta, string $paciente, int $versionLeida, array $escrituras): void
    {
        if ($this->antesDeConfirmar !== null) {
            $otraCarga = $this->antesDeConfirmar;
            $this->antesDeConfirmar = null;
            $otraCarga();
        }

        $clave = $ruta.'|'.$paciente;

        if ($this->version($ruta, $paciente) !== $versionLeida) {
            throw new ConcurrenciaContinuidadException('Otra carga modificó la continuidad de este paciente.');
        }

        $vigentes = $this->bloquesVigentes;

        foreach ($escrituras['movimientos'] as $movimiento) {
            $bloque = $movimiento['ruta'].'|'.$movimiento['paciente'].'|'.$movimiento['bloque_inicio'];

            if ($movimiento['signo'] > 0 && isset($vigentes[$bloque])) {
                throw new ConcurrenciaContinuidadException('El bloque '.$bloque.' ya tiene un reconocimiento vigente.');
            }

            if ($movimiento['signo'] > 0) {
                $vigentes[$bloque] = true;
            } else {
                unset($vigentes[$bloque]);
            }
        }

        foreach ($this->segmentos[$clave] ?? [] as $indice => $segmento) {
            if (in_array($segmento['identidad'].'#'.$segmento['version'], $escrituras['reemplazados'], true)) {
                $this->segmentos[$clave][$indice]['estado'] = 'REEMPLAZADO';
            }
        }

        foreach ($escrituras['segmentos'] as $segmento) {
            $this->segmentos[$clave][] = $segmento;
        }

        foreach ($escrituras['movimientos'] as $movimiento) {
            $this->movimientos[] = [...$movimiento, 'id' => count($this->movimientos) + 1];
        }

        $this->bloquesVigentes = $vigentes;
        $this->versiones[$clave] = $versionLeida + 1;
    }
}
