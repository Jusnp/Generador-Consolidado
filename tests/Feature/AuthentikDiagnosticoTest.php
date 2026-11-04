<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthentikDiagnosticoTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostic_only_reads_authentik_and_masks_the_cedula(): void
    {
        config([
            'services.authentik.base_url' => 'https://auth.example.test',
            'services.authentik.api_base_url' => 'https://auth.example.test/api/v3',
            'services.authentik.api_token' => 'test-api-token',
            'services.authentik.access_group' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://auth.example.test/api/v3/core/users/me/' => Http::response([
                'user' => ['username' => 'cuenta-servicio', 'is_superuser' => true],
            ]),
            'https://auth.example.test/api/v3/core/brands/current/' => Http::response([
                'flow_authentication' => 'default-authentication-flow',
            ]),
            'https://auth.example.test/api/v3/flows/instances/?designation=recovery' => Http::response([
                'results' => [],
            ]),
            'https://auth.example.test/api/v3/core/users/?username=1098765432' => Http::response([
                'results' => [],
            ]),
        ]);

        $this->artisan('authentik:diagnostico', ['--cedula' => ['1098765432']])
            ->expectsOutputToContain('Superusuario: sí')
            ->expectsOutputToContain('Flujo asignado: ninguno')
            ->expectsOutputToContain('Cédula ******5432')
            ->expectsOutputToContain('En Authentik: no existe; no hay colisión.')
            ->doesntExpectOutputToContain('1098765432')
            ->assertSuccessful();

        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() !== 'GET');
    }
}
