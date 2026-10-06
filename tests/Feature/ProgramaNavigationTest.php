<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramaNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_programa_request_redirects_to_login(): void
    {
        $this->get(route('programas.show', 'autoinmunes'))
            ->assertRedirect(route('login'));
    }

    public function test_autoinmunes_redirects_to_its_own_execution_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('programas.show', 'autoinmunes'))
            ->assertRedirect(route('json-excel'));
    }

    public function test_la_maria_shows_hub_to_choose_prostata_or_cervix(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('programas.show', 'la-maria'))
            ->assertOk()
            ->assertViewIs('programa-la-maria')
            ->assertSeeText('La María')
            ->assertSeeText('Próstata')
            ->assertSeeText('Cérvix')
            ->assertSee(route('la-maria.prostata'))
            ->assertSee(route('la-maria.cervix'));
    }

    public function test_pending_programa_renders_pending_view_without_sharing_other_pipelines(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('programas.show', 'salud-mental'))
            ->assertOk()
            ->assertViewIs('programa-pendiente')
            ->assertSee('Salud Mental')
            ->assertSee('pipeline de ejecución independiente')
            ->assertDontSee(route('json-excel', absolute: false));
    }

    public function test_unknown_programa_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('programas.show', 'programa-inexistente'))
            ->assertNotFound();
    }

    public function test_dashboard_menu_lists_single_la_maria_and_renamed_programas(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Usa el menú de la izquierda')
            ->assertDontSee('Abrir ejecución')
            ->assertSee('Autoinmunes')
            ->assertSeeText('La María')
            ->assertDontSee('La María · Próstata')
            ->assertDontSee('La María · Cérvix')
            ->assertSee(route('programas.show', 'la-maria'))
            ->assertDontSee('Amarilla')
            ->assertSee('Salud Mental')
            ->assertSee('Coosalud')
            ->assertDontSee('Posalud')
            ->assertSee('Nueva EPS')
            ->assertDontSee('Contratantes');
    }

    public function test_nueva_eps_and_coosalud_are_pending_programas(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('programas.show', 'nueva-eps'))
            ->assertOk()
            ->assertViewIs('programa-pendiente')
            ->assertSee('Nueva EPS');

        $this->actingAs($user)
            ->get(route('programas.show', 'coosalud'))
            ->assertOk()
            ->assertViewIs('programa-pendiente')
            ->assertSee('Coosalud');
    }
}
