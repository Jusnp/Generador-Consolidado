<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthentikAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_displays_only_the_authentik_entry_point(): void
    {
        $this->configureAuthentik();

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Iniciar sesión')
            ->assertSeeText('Ingresar con Authentik')
            ->assertSee(route('authentik.redirect'), false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="password"', false);

        $this->assertFalse(Route::has('login.authenticate'));
    }

    public function test_login_page_does_not_restore_local_access_when_authentik_is_disabled(): void
    {
        config(['services.authentik.enabled' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Iniciar sesión')
            ->assertSeeText('Ingresar con Authentik')
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_redirect_to_authentik_uses_state_and_pkce(): void
    {
        $this->configureAuthentik();

        $response = $this->get(route('authentik.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('authentik_oauth_state');
        $response->assertSessionHas('authentik_code_verifier');

        $query = [];
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['code_challenge']);
    }

    public function test_callback_rejects_a_mismatched_oauth_state_without_contacting_authentik(): void
    {
        $this->configureAuthentik();
        Http::preventStrayRequests();

        $this->withSession([
            'authentik_oauth_state' => 'valid-state',
            'authentik_code_verifier' => 'valid-verifier',
        ])
            ->get(route('authentik.callback', [
                'state' => 'other-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'No fue posible validar la respuesta de Authentik. Intenta nuevamente.',
            ]);
    }

    public function test_callback_logs_in_active_matching_local_user_and_binds_authentik_subject(): void
    {
        $this->configureAuthentik();
        $user = User::factory()->create([
            'email' => 'persona@ejemplo.com',
            'active' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://auth.example.test/application/o/token/' => Http::response([
                'access_token' => 'access-token',
            ]),
            'https://auth.example.test/application/o/userinfo/' => Http::response([
                'sub' => 'authentik-subject-123',
                'email' => 'persona@ejemplo.com',
            ]),
        ]);

        $this->withSession([
            'authentik_oauth_state' => 'valid-state',
            'authentik_code_verifier' => 'valid-verifier',
        ])
            ->get(route('authentik.callback', [
                'state' => 'valid-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('authentik-subject-123', $user->fresh()->authentik_subject);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login_authentik',
            'description' => 'Usuario inició sesión correctamente mediante Authentik.',
        ]);
    }

    public function test_callback_refuses_authentik_identity_without_an_active_local_user(): void
    {
        $this->configureAuthentik();
        Http::preventStrayRequests();
        Http::fake([
            'https://auth.example.test/application/o/token/' => Http::response([
                'access_token' => 'access-token',
            ]),
            'https://auth.example.test/application/o/userinfo/' => Http::response([
                'sub' => 'unknown-subject',
                'email' => 'desconocido@ejemplo.com',
            ]),
        ]);

        $this->withSession([
            'authentik_oauth_state' => 'valid-state',
            'authentik_code_verifier' => 'valid-verifier',
        ])
            ->get(route('authentik.callback', [
                'state' => 'valid-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'Tu cuenta de Authentik no tiene un usuario activo autorizado en este sistema.',
            ]);

        $this->assertGuest();
    }

    public function test_callback_reconciles_a_changed_authentik_subject_by_trusted_email(): void
    {
        $this->configureAuthentik();
        $user = User::factory()->create([
            'email' => 'CarlosPrueba@hotmail.com',
            'authentik_subject' => 'old-subject',
            'active' => true,
        ]);

        Http::fake([
            'https://auth.example.test/application/o/token/' => Http::response([
                'access_token' => 'access-token',
            ]),
            'https://auth.example.test/application/o/userinfo/' => Http::response([
                'sub' => 'new-subject-from-provider',
                'email' => 'carlosprueba@hotmail.com',
            ]),
        ]);

        $this->withSession([
            'authentik_oauth_state' => 'valid-state',
            'authentik_code_verifier' => 'valid-verifier',
        ])
            ->get(route('authentik.callback', [
                'state' => 'valid-state',
                'code' => 'authorization-code',
            ]))
            ->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('new-subject-from-provider', $user->fresh()->authentik_subject);
    }

    private function configureAuthentik(): void
    {
        config([
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://auth.example.test',
            'services.authentik.client_id' => 'client-id',
            'services.authentik.client_secret' => 'client-secret',
            'services.authentik.redirect_uri' => 'https://app.example.test/auth/authentik/callback',
            'services.authentik.authorize_url' => 'https://auth.example.test/application/o/authorize/',
            'services.authentik.token_url' => 'https://auth.example.test/application/o/token/',
            'services.authentik.userinfo_url' => 'https://auth.example.test/application/o/userinfo/',
            'services.authentik.scopes' => 'openid profile email',
        ]);
    }
}
