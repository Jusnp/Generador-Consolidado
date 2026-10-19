<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ConciliacionRipsNota;
use PHPUnit\Framework\TestCase;

class ConciliacionRipsNotaTest extends TestCase
{
    public function test_same_amount_is_attributed_once_to_type_and_category_with_package_in_ah(): void
    {
        $conciliacion = new ConciliacionRipsNota('clave-privada-de-prueba');
        $conciliacion->registrar([
            'mesAtencion' => '2026-09', 'tipoServicio' => 'consultas', 'codigo' => '890701',
            'factura' => 'F1', 'casoId' => 'anonimo', 'fechaAtencion' => '2026-09-04 09:00',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 40195.61, 'fuenteTarifa' => 'NOTA_TECNICA',
        ], [
            'estado' => 'COSTO RESPALDADO', 'grupoNota' => 'URGENCIAS',
            'respaldado' => 40195.61, 'condicionadoIncluido' => 0,
            'referencialAdicional' => 0, 'condicionadoExterno' => 0,
        ]);
        $conciliacion->registrar([
            'mesAtencion' => '2026-09', 'tipoServicio' => 'otrosServicios', 'codigo' => 'DM00114',
            'factura' => 'F1', 'casoId' => 'anonimo', 'fechaAtencion' => '2026-09-04',
            'cantidadAplicada' => 2, 'decisionPaquete' => 'INCLUIDO_EN_PAQUETE',
        ], [
            'estado' => 'SERVICIO ABSORBIDO', 'grupoNota' => 'FUERA_DE_NOTA',
            'respaldado' => 0, 'condicionadoIncluido' => 0,
            'referencialAdicional' => 0, 'condicionadoExterno' => 0,
        ]);
        $conciliacion->registrar([
            'mesAtencion' => '2026-09', 'tipoServicio' => 'hospitalizacion', 'codigo' => '',
        ], [
            'estado' => 'NO SUMA POR REGLA', 'grupoNota' => 'HOSPITALIZACION',
            'respaldado' => 0, 'condicionadoIncluido' => 0,
            'referencialAdicional' => 0, 'condicionadoExterno' => 0,
        ]);
        $conciliacion->registrar([
            'mesAtencion' => '2026-09', 'tipoServicio' => 'urgencias', 'codigo' => '',
            'factura' => 'F1', 'casoId' => 'anonimo', 'fechaAtencion' => '2026-09-04 08:00',
            'servicioJson' => ['fechaEgreso' => '2026-09-04 11:00'],
        ], [
            'estado' => 'COSTO NO CALCULABLE', 'grupoNota' => 'URGENCIAS',
            'respaldado' => 0, 'condicionadoIncluido' => 0,
            'referencialAdicional' => 0, 'condicionadoExterno' => 0,
        ]);
        $conciliacion->atribuirPaquetes([['2026-09', 1, 6015314.53]]);

        $tipos = $conciliacion->filasTipos();
        $this->assertSame(['AC', 'AT', 'AU', 'AH'], array_column($tipos, 1));
        $this->assertSame(40195.61, $tipos[0][4]);
        $this->assertSame(1, $tipos[1][8]);
        $this->assertSame(6015314.53, $tipos[3][4]);
        $this->assertSame(1, $tipos[3][7]);

        $categories = $conciliacion->filasCategorias([]);
        $this->assertEqualsCanonicalizing(['URGENCIAS', 'DISPOSITIVOS_MEDICOS', 'HOSPITALIZACION'], array_column($categories, 1));
        $this->assertSame(4, array_sum(array_column($categories, 2)));
        $this->assertSame(40195.61, $categories[0][12]);

        $control = $conciliacion->filasControles([['PROSTATA', '2026-09', 3622024541, 6055510.14]]);
        $this->assertSame(0.0, $control[0][4]);
        $this->assertSame(0.0, $control[0][5]);
        $this->assertSame(0, $control[0][8]);
        $this->assertCount(3, $conciliacion->filasAtAu());
        $this->assertSame(40195.61, $conciliacion->filasEpisodiosUrgencias()[0][7]);
        $this->assertSame(['2026-09' => ['AC' => 40195.61]], $conciliacion->costoUrgenciasPorTipo());
    }

    public function test_shared_at_au_and_episode_rows_hide_documents_with_stable_protected_pseudonyms(): void
    {
        $documento = '106899999912345';
        $conciliacion = new ConciliacionRipsNota('clave-privada-de-prueba');
        foreach ([
            ['tipoServicio' => 'urgencias', 'codigo' => '', 'fechaAtencion' => '2026-09-04 08:00', 'servicioJson' => ['fechaEgreso' => '2026-09-04 11:00']],
            ['tipoServicio' => 'consultas', 'codigo' => '890701', 'fechaAtencion' => '2026-09-04 09:00'],
        ] as $registro) {
            $conciliacion->registrar([
                ...$registro, 'mesAtencion' => '2026-09', 'factura' => 'F1',
                'casoId' => $documento,
            ], ['estado' => 'COSTO RESPALDADO', 'grupoNota' => 'URGENCIAS', 'respaldado' => 40195.61]);
        }

        $atAu = $conciliacion->filasAtAu();
        $episodios = $conciliacion->filasEpisodiosUrgencias();
        $this->assertSame($atAu[0][2], $atAu[1][2]);
        $this->assertSame($atAu[0][2], $episodios[0][2]);
        $this->assertMatchesRegularExpression('/^LM-[a-f0-9]{20}$/', $atAu[0][2]);
        $this->assertStringNotContainsString($documento, json_encode([$atAu, $episodios], JSON_THROW_ON_ERROR));
        $this->assertSame(40195.61, $episodios[0][7]);
    }
}
