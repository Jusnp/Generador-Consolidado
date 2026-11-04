<?php

declare(strict_types=1);

namespace App\Programas\LaMaria;

use DateInterval;
use DateTimeImmutable;

/** Calcula el escenario observado: egreso y cruces de 25 días, sin acreditar cobro contractual. */
final class EscenarioHospitalarioAdministrativo
{
    /**
     * @param  list<array<string, mixed>>  $episodios
     * @return array{unidades: list<array<string, mixed>>, pendientes: list<array<string, mixed>>}
     */
    public function calcular(array $episodios, int $tarifaCentavos): array
    {
        $unidades = [];
        $pendientes = [];
        foreach ($episodios as $episodio) {
            $estado = $episodio['estado'] ?? '';
            $inicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr((string) ($episodio['inicio'] ?? ''), 0, 16));
            $fin = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr((string) ($episodio['fin'] ?? ''), 0, 16));
            if (! $inicio || ! $fin || $fin < $inicio || ($episodio['excluido'] ?? false)
                || in_array($estado, [ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA, ReconstructorEpisodiosHospitalarios::ESTADO_SIN_FECHAS], true)
                || ($inicio->format('d H:i') === '01 00:00' && ($episodio['continuidadesResueltas'] ?? 0) === 0)) {
                $pendientes[] = ['episodio' => $episodio['id'] ?? '', 'causa' => 'Continuidad, identidad o fechas por verificar'];

                continue;
            }
            for ($cruce = $inicio->add(new DateInterval('P25D')); $cruce < $fin; $cruce = $cruce->add(new DateInterval('P25D'))) {
                $unidades[] = ['episodio' => $episodio['id'], 'fecha' => $cruce->format('Y-m-d'), 'tipo' => 'CRUCE 25 DIAS', 'centavos' => $tarifaCentavos];
            }
            if ($estado === ReconstructorEpisodiosHospitalarios::ESTADO_CERRADO) {
                $unidades[] = ['episodio' => $episodio['id'], 'fecha' => $fin->format('Y-m-d'),
                    'tipo' => ($fin->getTimestamp() - $inicio->getTimestamp()) < 86400 ? 'EGRESO MENOR 24H' : 'EGRESO', 'centavos' => $tarifaCentavos];
            } else {
                $pendientes[] = ['episodio' => $episodio['id'], 'causa' => 'Egreso aún no documentado'];
            }
        }

        return compact('unidades', 'pendientes');
    }
}
