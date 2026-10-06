<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.authentik.enabled' => false]);
    }

    public function test_login_page_shows_local_form_when_authentik_is_disabled(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Iniciar sesión')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSeeText('Correo electrónico')
            ->assertSeeText('Contraseña')
            ->assertSee(route('login.authenticate'), false)
            ->assertDontSeeText('Ingresar con Authentik');
    }

    public function test_valid_local_credentials_allow_access(): void
    {
        $user = User::factory()->create([
            'email' => 'persona@ejemplo.com',
            'password' => 'password',
            'active' => true,
        ]);

        $this->post(route('login.authenticate'), [
            'email' => 'persona@ejemplo.com',
            'password' => 'password',
        ])->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'description' => 'Usuario inició sesión correctamente.',
        ]);
    }

    public function test_inactive_local_user_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'inactivo@ejemplo.com',
            'password' => 'password',
            'active' => false,
        ]);

        $this->from(route('login'))
            ->post(route('login.authenticate'), [
                'email' => 'inactivo@ejemplo.com',
                'password' => 'password',
            ])
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'Tu usuario está inactivo. Contacta al administrador.',
            ]);

        $this->assertGuest();
    }

    public function test_invalid_local_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'persona@ejemplo.com',
            'password' => 'password',
            'active' => true,
        ]);

        $this->from(route('login'))
            ->post(route('login.authenticate'), [
                'email' => 'persona@ejemplo.com',
                'password' => 'incorrecta',
            ])
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'Las credenciales no son correctas.',
            ]);

        $this->assertGuest();
    }

    public function test_authentik_entry_is_rejected_when_disabled(): void
    {
        $this->get(route('authentik.redirect'))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'El acceso con Authentik no está habilitado.',
            ]);

        $this->get(route('authentik.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors([
                'email' => 'El acceso con Authentik no está habilitado.',
            ]);
    }

    public function test_logout_continues_working_when_authentik_is_disabled(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
        ]);
    }
}
