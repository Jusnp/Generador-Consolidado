<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProgramaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_can_open_autoinmunes_and_la_maria(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('programas.show', 'autoinmunes'))
            ->assertRedirect(route('json-excel'));

        $this->actingAs($user)->get(route('json-excel'))->assertOk();
        $this->actingAs($user)->get(route('la-maria'))->assertOk();
        $this->actingAs($user)->get(route('la-maria.prostata'))->assertOk();
        $this->actingAs($user)->get(route('la-maria.cervix'))->assertOk();
    }

    #[TestWith(['salud-mental'])]
    #[TestWith(['coosalud'])]
    #[TestWith(['nueva-eps'])]
    public function test_user_role_gets_403_on_programas_reserved_for_admins(string $programa): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('programas.show', $programa))
            ->assertForbidden();
    }

    public function test_user_menu_lists_only_the_programas_it_can_open(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('programas.show', 'autoinmunes'))
            ->assertSee(route('programas.show', 'la-maria'))
            ->assertDontSee(route('programas.show', 'salud-mental'))
            ->assertDontSee(route('users.index'));
    }

    public function test_every_la_maria_route_is_checked_on_the_server(): void
    {
        config(['programas.la-maria.roles' => ['admin']]);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('programas.show', 'la-maria'))->assertForbidden();
        $this->actingAs($user)->get(route('la-maria'))->assertForbidden();
        $this->actingAs($user)->get(route('la-maria.prostata'))->assertForbidden();
        $this->actingAs($user)->get(route('la-maria.cervix'))->assertForbidden();
        $this->actingAs($user)->post(route('la-maria.prostata.export'))->assertForbidden();
        $this->actingAs($user)->post(route('la-maria.cervix.export'))->assertForbidden();
        $this->actingAs($user)->post(route('la-maria.consolidado.export'))->assertForbidden();
    }

    public function test_every_autoinmunes_route_is_checked_on_the_server(): void
    {
        config(['programas.autoinmunes.roles' => ['admin']]);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('programas.show', 'autoinmunes'))->assertForbidden();
        $this->actingAs($user)->get(route('json-excel'))->assertForbidden();
        $this->actingAs($user)->post(route('json-excel.convert'))->assertForbidden();
    }

    public function test_programa_without_roles_is_reserved_for_admins(): void
    {
        config(['programas.programa-nuevo' => ['nombre' => 'Programa nuevo', 'pipeline' => null]]);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('programas.show', 'programa-nuevo'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('programas.show', 'programa-nuevo'))
            ->assertOk()
            ->assertViewIs('programa-pendiente');
    }
}
