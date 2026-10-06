<?php

namespace Tests\Unit;

use App\Programas\LaMaria\LaMariaPrograma;
use App\Services\SeguimientoRutaClassifier;
use PHPUnit\Framework\TestCase;

class LaMariaRouteFilterTest extends TestCase
{
    public function test_filters_profiles_to_selected_la_maria_execution(): void
    {
        $classifier = new SeguimientoRutaClassifier;
        $profiles = [
            ['rutaFinal' => 'PROSTATA', 'rutaCalculo' => 'PROSTATA', 'profileKey' => 'a'],
            ['rutaFinal' => 'CERVIX', 'rutaCalculo' => 'CERVIX', 'profileKey' => 'b'],
            ['rutaFinal' => 'DOBLE_RUTA_REVISAR', 'rutaCalculo' => 'PROSTATA', 'profileKey' => 'c'],
            ['rutaFinal' => 'DOBLE_RUTA_REVISAR', 'rutaCalculo' => 'CERVIX', 'profileKey' => 'd'],
            ['rutaFinal' => 'SIN_RUTA_DEFINIDA', 'rutaCalculo' => 'PROSTATA', 'profileKey' => 'e'],
            ['rutaFinal' => 'SIN_RUTA_DEFINIDA', 'rutaCalculo' => 'CERVIX', 'profileKey' => 'f'],
        ];

        $prostata = $classifier->profilesForExecutionRoute(
            $profiles,
            LaMariaPrograma::RUTA_PROSTATA
        );
        $cervix = $classifier->profilesForExecutionRoute(
            $profiles,
            LaMariaPrograma::RUTA_CERVIX
        );

        $this->assertSame(['a', 'c', 'e'], array_column($prostata, 'profileKey'));
        $this->assertSame(['b', 'd', 'f'], array_column($cervix, 'profileKey'));
    }
}
