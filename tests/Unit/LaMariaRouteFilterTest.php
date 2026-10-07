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
            ['ruta' => 'PROSTATA', 'origenRuta' => 'DIAGNOSTICO', 'profileKey' => 'a'],
            ['ruta' => 'CERVIX', 'origenRuta' => 'DIAGNOSTICO', 'profileKey' => 'b'],
            ['ruta' => 'NO_ASIGNABLE', 'origenRuta' => 'NINGUNO', 'profileKey' => 'c'],
            ['ruta' => 'NO_ASIGNABLE', 'origenRuta' => 'NINGUNO', 'profileKey' => 'd'],
            ['ruta' => 'PROSTATA', 'origenRuta' => 'SEXO', 'profileKey' => 'e'],
            ['ruta' => 'CERVIX', 'origenRuta' => 'SEXO', 'profileKey' => 'f'],
        ];

        $prostata = $classifier->profilesForExecutionRoute(
            $profiles,
            LaMariaPrograma::RUTA_PROSTATA
        );
        $cervix = $classifier->profilesForExecutionRoute(
            $profiles,
            LaMariaPrograma::RUTA_CERVIX
        );

        $this->assertSame(['a', 'e'], array_column($prostata, 'profileKey'));
        $this->assertSame(['b', 'f'], array_column($cervix, 'profileKey'));
    }
}
