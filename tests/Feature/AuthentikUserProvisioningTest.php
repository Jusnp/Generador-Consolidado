<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthentikUserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_creation_provisions_the_account_in_authentik(): void
    {
        config([
            'services.authentik.api_base_url' => 'https://auth.example.test/api/v3',
            'services.authentik.api_token' => 'test-api-token',
        ]);

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response(['results' => []], 200);
            }

            if ($request->url() === 'https://auth.example.test/api/v3/core/users/') {
                return Http::response([
                    'pk' => 77,
                    'uuid' => 'authentik-user-uuid',
                ], 201);
            }

            return Http::response([], 204);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Carlos Prueba',
                'email' => 'carlosprueba@hotmail.com',
                'password' => 'NuevaClaveSegura123',
                'password_confirmation' => 'NuevaClaveSegura123',
                'role' => 'user',
                'active' => '1',
            ])
            ->assertRedirectToRoute('users.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'carlosprueba@hotmail.com',
            'authentik_subject' => 'authentik-user-uuid',
            'active' => true,
        ]);

        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://auth.example.test/api/v3/core/users/'
                && $request['username'] === 'carlosprueba@hotmail.com';
        });

        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://auth.example.test/api/v3/core/users/77/set_password/'
                && $request['password'] === 'NuevaClaveSegura123';
        });
    }

    public function test_editing_an_older_local_user_can_link_it_to_authentik(): void
    {
        config([
            'services.authentik.api_base_url' => 'https://auth.example.test/api/v3',
            'services.authentik.api_token' => 'test-api-token',
        ]);

        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response(['results' => []], 200);
            }

            if ($request->url() === 'https://auth.example.test/api/v3/core/users/') {
                return Http::response(['pk' => 88, 'uuid' => 'linked-user-uuid'], 201);
            }

            return Http::response([], 204);
        });

        $admin = User::factory()->create(['role' => 'admin']);
        $localUser = User::factory()->create([
            'name' => 'Carlos Prueba',
            'email' => 'carlosprueba@hotmail.com',
            'authentik_subject' => null,
            'role' => 'user',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('users.update', $localUser), [
                'name' => 'Carlos Prueba Editado',
                'email' => 'carlosprueba@hotmail.com',
                'password' => 'NuevaClaveSegura123',
                'password_confirmation' => 'NuevaClaveSegura123',
                'role' => 'user',
                'active' => '1',
            ])
            ->assertRedirectToRoute('users.index');

        $this->assertDatabaseHas('users', [
            'id' => $localUser->id,
            'authentik_subject' => 'linked-user-uuid',
            'name' => 'Carlos Prueba Editado',
        ]);
    }
}
