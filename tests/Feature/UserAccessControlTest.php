<?php

namespace Tests\Feature;

use App\Models\AuthentikStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_loses_an_open_session_on_the_next_request(): void
    {
        $user = User::factory()->linkedToAuthentik()->create(['role' => 'admin', 'active' => false]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors(['email' => 'Tu usuario está inactivo. Contacta al administrador.']);

        $this->assertGuest();
    }

    public function test_deactivation_closes_only_the_sessions_of_that_user(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->linkedToAuthentik()->create(['active' => true, 'remember_token' => 'token-anterior']);
        $otherUser = User::factory()->linkedToAuthentik()->create(['active' => true]);
        DB::table('sessions')->insert([
            ['id' => 'sesion-desactivado', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'sesion-otro-usuario', 'user_id' => $otherUser->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);

        $this->actingAs(User::factory()->linkedToAuthentik()->create(['role' => 'admin']))
            ->patch(route('users.toggle-active', $user))
            ->assertRedirectToRoute('users.index');

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-desactivado']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-otro-usuario']);
        $this->assertNotSame('token-anterior', $user->fresh()->remember_token);
    }

    #[TestWith(['users.index'])]
    #[TestWith(['users.create'])]
    #[TestWith(['activity-logs.index'])]
    #[TestWith(['catalogos-referencia.index'])]
    #[TestWith(['referencias-manuales.index'])]
    public function test_auxiliar_cannot_open_administrative_pages(string $routeName): void
    {
        $auxiliar = User::factory()->provisionedInAuthentik()->create(['role' => 'user']);

        $this->actingAs($auxiliar)->get(route($routeName))->assertForbidden();
    }

    public function test_auxiliar_cannot_use_authentik_administrative_actions(): void
    {
        Http::preventStrayRequests();
        $auxiliar = User::factory()->provisionedInAuthentik()->create(['role' => 'user']);
        $otherUser = User::factory()->linkedToAuthentik()->create();

        $this->actingAs($auxiliar)->post(route('users.authentik.provision', $auxiliar))->assertForbidden();
        $this->actingAs($auxiliar)->post(route('users.authentik.activation-link', $auxiliar))->assertForbidden();
        $this->actingAs($auxiliar)->post(route('users.authentik.unlink', $otherUser))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_auxiliar_created_from_siruta_signs_in_with_the_cedula_identity_and_stays_out_of_admin_pages(): void
    {
        config([
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://auth.example.test',
            'services.authentik.api_base_url' => 'https://auth.example.test/api/v3',
            'services.authentik.api_token' => 'test-api-token',
            'services.authentik.client_id' => 'client-id',
            'services.authentik.client_secret' => 'client-secret',
            'services.authentik.redirect_uri' => 'https://app.example.test/auth/authentik/callback',
            'services.authentik.token_url' => 'https://auth.example.test/application/o/token/',
            'services.authentik.userinfo_url' => 'https://auth.example.test/application/o/userinfo/',
            'services.authentik.subject_mode' => 'hashed_user_id',
            'services.authentik.access_group' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            return match ($request->method().' '.$request->url()) {
                'GET https://auth.example.test/api/v3/core/users/?username=0098765432' => Http::response(['results' => []]),
                'POST https://auth.example.test/api/v3/core/users/' => Http::response([
                    'pk' => 501,
                    'uuid' => '6b1f8a52-3c1e-4d47-9b0a-2f5e7c9d1a33',
                    'uid' => 'uid-del-auxiliar',
                    'username' => '0098765432',
                    'type' => 'internal',
                    'is_active' => true,
                    'is_superuser' => false,
                    'attributes' => $request['attributes'],
                ], 201),
                'POST https://auth.example.test/application/o/token/' => Http::response(['access_token' => 'access-token']),
                'GET https://auth.example.test/application/o/userinfo/' => Http::response([
                    'sub' => 'uid-del-auxiliar',
                    'preferred_username' => '0098765432',
                    'groups' => ['authentik Admins'],
                ]),
                default => null,
            };
        });

        $this->actingAs(User::factory()->linkedToAuthentik()->create(['role' => 'admin']))
            ->post(route('users.store'), [
                'name' => 'Auxiliar Uno',
                'cedula' => '0098765432',
                'role' => 'user',
                'active' => '1',
            ])
            ->assertSessionHas('success');

        Auth::logout();

        $this->withSession([
            'authentik_oauth_state' => 'valid-state',
            'authentik_code_verifier' => 'valid-verifier',
        ])
            ->get(route('authentik.callback', ['state' => 'valid-state', 'code' => 'authorization-code']))
            ->assertRedirectToRoute('dashboard');

        $auxiliar = User::where('cedula', '0098765432')->sole();

        $this->assertAuthenticatedAs($auxiliar);
        $this->assertSame('user', $auxiliar->role);
        $this->assertSame(AuthentikStatus::Provisioned, $auxiliar->authentik_status);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('users.index'))->assertForbidden();
    }
}
