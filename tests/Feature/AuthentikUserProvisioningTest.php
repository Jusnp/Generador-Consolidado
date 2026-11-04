<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuthentikStatus;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthentikUserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://auth.example.test/api/v3';

    private const CEDULA = '0012345678';

    private const ACCOUNT_UID = 'b5f0c6c6a3d1e9f7a4c2b8d0e6f1a3c5b7d9e1f3a5c7b9d1e3f5a7c9b1d3e5f7';

    private const ACCOUNT_UUID = '2f6c1f0e-8d55-4b71-9a43-6c0f2d8e9b17';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.authentik.base_url' => 'https://auth.example.test',
            'services.authentik.api_base_url' => self::API,
            'services.authentik.api_token' => 'test-api-token',
            'services.authentik.subject_mode' => 'hashed_user_id',
            'services.authentik.access_group' => null,
            'services.authentik.user_path' => 'users/siruta',
        ]);
    }

    public function test_admin_creates_the_user_in_siruta_and_authentik_with_the_cedula_as_username(): void
    {
        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => []]),
            'POST /core/users/' => fn (ClientRequest $request) => Http::response(
                $this->account(['attributes' => $request['attributes']]),
                201
            ),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Administrador Principal',
                'cedula' => self::CEDULA,
                'email' => '',
                'role' => 'admin',
                'active' => '1',
            ])
            ->assertRedirectToRoute('users.index')
            ->assertSessionHas('success', 'Usuario creado en SIRUTA y en Authentik. Genera el enlace de activación para que defina su contraseña.');

        $user = User::where('cedula', self::CEDULA)->sole();

        $this->assertSame(self::CEDULA, $user->cedula);
        $this->assertNull($user->email);
        $this->assertSame('admin', $user->role);
        $this->assertSame(AuthentikStatus::Provisioned, $user->authentik_status);
        $this->assertSame(self::ACCOUNT_UID, $user->authentik_subject);
        $this->assertSame(77, $user->authentik_pk);
        $this->assertSame(self::ACCOUNT_UUID, $user->authentik_uuid);

        Http::assertSent(function (ClientRequest $request) use ($user): bool {
            return $request->method() === 'POST'
                && $request->url() === self::API.'/core/users/'
                && $request->data() === [
                    'username' => self::CEDULA,
                    'name' => 'Administrador Principal',
                    'is_active' => true,
                    'type' => 'internal',
                    'attributes' => [
                        'siruta' => [
                            'provisioning_key' => $user->authentik_provisioning_key,
                            'local_user_id' => $user->id,
                        ],
                    ],
                    'path' => 'users/siruta',
                ];
        });
        $this->assertNoPasswordWasTouched();

        $this->assertDatabaseHas('activity_logs', ['action' => 'authentik_user_provisioned']);
        $this->assertSame('******5678', ActivityLog::where('action', 'authentik_user_provisioned')->sole()->metadata['cedula']);
        $this->assertFalse(
            ActivityLog::all()->contains(fn (ActivityLog $log): bool => str_contains((string) json_encode($log->metadata), self::CEDULA)),
            'La cédula completa no debe quedar en el registro de actividad.'
        );
    }

    public function test_cedula_already_registered_in_siruta_is_rejected_without_contacting_authentik(): void
    {
        Http::preventStrayRequests();
        User::factory()->create(['cedula' => self::CEDULA]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHasErrors(['cedula' => 'Esa cédula ya está registrada en SIRUTA.']);

        $this->assertDatabaseCount('users', 2);
        Http::assertNothingSent();
    }

    public function test_cedula_must_contain_only_digits(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload(['cedula' => '12.345.678']))
            ->assertSessionHasErrors(['cedula' => 'La cédula debe tener entre 5 y 15 dígitos, sin puntos ni espacios.']);

        Http::assertNothingSent();
    }

    public function test_user_is_not_created_when_the_authentik_api_is_not_configured(): void
    {
        Http::preventStrayRequests();
        config(['services.authentik.api_token' => null]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHasErrors(['cedula' => 'El aprovisionamiento en Authentik no está configurado; no se creó el usuario.']);

        $this->assertDatabaseMissing('users', ['cedula' => self::CEDULA]);
    }

    public function test_cedula_owned_by_another_application_is_reported_as_conflict_and_left_untouched(): void
    {
        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [
                $this->account(['pk' => 12, 'attributes' => ['sispam' => ['rol' => 'medico']]]),
            ]]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertRedirectToRoute('users.index')
            ->assertSessionMissing('success')
            ->assertSessionHas('error');

        $user = User::where('cedula', self::CEDULA)->sole();

        $this->assertSame(AuthentikStatus::Conflict, $user->authentik_status);
        $this->assertNull($user->authentik_subject);
        $this->assertStringContainsString('una cuenta que SIRUTA no creó', $user->authentik_sync_error);
        $this->assertOnlyReadsWereSent();
    }

    public function test_cedula_registered_in_authentik_between_lookup_and_creation_is_not_duplicated(): void
    {
        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => [$this->account(['pk' => 31])]]),
            'POST /core/users/' => Http::response(['username' => ['This field must be unique.']], 400),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHas('error');

        $this->assertSame(AuthentikStatus::Conflict, User::where('cedula', self::CEDULA)->sole()->authentik_status);
        Http::assertSentCount(3);
    }

    public function test_authentik_rejection_keeps_the_user_out_of_both_systems_until_a_retry(): void
    {
        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => []]),
            'POST /core/users/' => Http::response(['detail' => 'You do not have permission'], 403),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertRedirectToRoute('users.index')
            ->assertSessionMissing('success')
            ->assertSessionHas('error', 'El usuario quedó registrado en SIRUTA, pero no en Authentik: Authentik no permitió crear la cuenta (HTTP 403). Revisa los permisos del token de servicio de SIRUTA.');

        $user = User::where('cedula', self::CEDULA)->sole();

        $this->assertSame(AuthentikStatus::Failed, $user->authentik_status);
        $this->assertNull($user->authentik_subject);
        $this->assertNotNull($user->authentik_provisioning_key);
        $this->assertDatabaseHas('activity_logs', ['action' => 'authentik_provisioning_failed']);
    }

    public function test_retry_creates_the_account_when_the_failed_attempt_created_nothing(): void
    {
        $user = $this->failedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => []]),
            'POST /core/users/' => Http::response($this->account(['attributes' => $this->ownership($user)]), 201),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.provision', $user))
            ->assertSessionHas('success');

        $this->assertSame(AuthentikStatus::Provisioned, $user->fresh()->authentik_status);
        $this->assertNull($user->fresh()->authentik_sync_error);
    }

    public function test_retry_adopts_the_account_created_by_an_interrupted_attempt_without_duplicating_it(): void
    {
        $user = $this->failedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [
                $this->account(['attributes' => $this->ownership($user)]),
            ]]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.provision', $user))
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame(AuthentikStatus::Provisioned, $user->authentik_status);
        $this->assertSame(self::ACCOUNT_UID, $user->authentik_subject);
        $this->assertOnlyReadsWereSent();
    }

    public function test_connection_failure_is_recorded_without_logging_the_cedula(): void
    {
        Sleep::fake();
        Log::spy();
        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::failedConnection(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHas('error');

        $user = User::where('cedula', self::CEDULA)->sole();

        $this->assertSame(AuthentikStatus::Failed, $user->authentik_status);
        $this->assertSame('Authentik no respondió. Intenta de nuevo en unos minutos.', $user->authentik_sync_error);
        Log::shouldHaveReceived('warning')->with('Authentik no respondió.', ['operacion' => 'buscar_usuario']);
    }

    public function test_new_account_joins_the_siruta_access_group_when_it_grants_no_superuser(): void
    {
        config(['services.authentik.access_group' => 'siruta-group-uuid']);

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => []]),
            'POST /core/users/' => fn (ClientRequest $request) => Http::response($this->account(['attributes' => $request['attributes']]), 201),
            'GET /core/groups/siruta-group-uuid/?include_users=false' => Http::response(['name' => 'siruta-usuarios', 'is_superuser' => false, 'parent' => null]),
            'POST /core/groups/siruta-group-uuid/add_user/' => Http::response(null, 204),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHas('success');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::API.'/core/groups/siruta-group-uuid/add_user/'
            && $request->data() === ['pk' => 77]);
    }

    public function test_access_group_inheriting_superuser_is_never_assigned(): void
    {
        config(['services.authentik.access_group' => 'siruta-group-uuid']);

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => []]),
            'POST /core/users/' => fn (ClientRequest $request) => Http::response($this->account(['attributes' => $request['attributes']]), 201),
            'GET /core/groups/siruta-group-uuid/?include_users=false' => Http::response(['name' => 'siruta-usuarios', 'is_superuser' => false, 'parent' => 'admins-uuid']),
            'GET /core/groups/admins-uuid/?include_users=false' => Http::response(['name' => 'authentik Admins', 'is_superuser' => true, 'parent' => null]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.store'), $this->validPayload())
            ->assertSessionHas('error');

        $user = User::where('cedula', self::CEDULA)->sole();

        $this->assertSame(AuthentikStatus::Failed, $user->authentik_status);
        $this->assertFalse($user->authentik_status->allowsLogin());
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'add_user'));
    }

    #[TestWith(['user_uuid', self::ACCOUNT_UUID])]
    #[TestWith(['user_id', '77'])]
    #[TestWith(['user_username', self::CEDULA])]
    public function test_stored_subject_follows_the_oidc_subject_mode(string $subjectMode, string $expectedSubject): void
    {
        config(['services.authentik.subject_mode' => $subjectMode]);
        $user = $this->failedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [
                $this->account(['attributes' => $this->ownership($user)]),
            ]]),
        ]);

        $this->actingAs($this->admin())->post(route('users.authentik.provision', $user));

        $this->assertSame($expectedSubject, $user->fresh()->authentik_subject);
    }

    public function test_email_subject_mode_is_refused(): void
    {
        config(['services.authentik.subject_mode' => 'user_email']);
        $user = $this->failedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [
                $this->account(['attributes' => $this->ownership($user)]),
            ]]),
        ]);

        $this->actingAs($this->admin())->post(route('users.authentik.provision', $user));

        $this->assertSame(AuthentikStatus::Failed, $user->fresh()->authentik_status);
        $this->assertNull($user->fresh()->authentik_subject);
    }

    public function test_auxiliar_cannot_create_users_nor_grant_the_admin_role(): void
    {
        Http::preventStrayRequests();
        $auxiliar = User::factory()->create(['role' => 'user']);

        $this->actingAs($auxiliar)
            ->post(route('users.store'), $this->validPayload(['role' => 'admin']))
            ->assertForbidden();

        $this->actingAs($auxiliar)
            ->put(route('users.update', $auxiliar), ['name' => $auxiliar->name, 'role' => 'admin', 'active' => '1'])
            ->assertForbidden();

        $this->assertSame('user', $auxiliar->fresh()->role);
        $this->assertDatabaseMissing('users', ['cedula' => self::CEDULA]);
        Http::assertNothingSent();
    }

    public function test_guest_is_sent_to_login_from_user_administration(): void
    {
        $this->post(route('users.store'), $this->validPayload())->assertRedirectToRoute('login');
    }

    public function test_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), ['name' => $admin->name, 'role' => 'user', 'active' => '1'])
            ->assertSessionHasErrors(['role' => 'No puedes quitarte el rol de administrador ni desactivar tu propio usuario.']);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_editing_only_changes_siruta_and_keeps_the_provisioned_cedula(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->provisionedInAuthentik()->create(['cedula' => self::CEDULA, 'role' => 'user']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $user), [
                'name' => 'Nombre Corregido',
                'cedula' => '1234567890',
                'role' => 'user',
                'active' => '1',
            ])
            ->assertSessionHasErrors(['cedula' => 'La cédula no se puede cambiar: ya identifica una cuenta de Authentik.']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $user), [
                'name' => 'Nombre Corregido',
                'cedula' => self::CEDULA,
                'email' => 'nuevo@ejemplo.com',
                'role' => 'admin',
                'active' => '1',
            ])
            ->assertRedirectToRoute('users.index');

        $user->refresh();

        $this->assertSame(self::CEDULA, $user->cedula);
        $this->assertSame('Nombre Corregido', $user->name);
        $this->assertSame('admin', $user->role);
        Http::assertNothingSent();
    }

    public function test_deactivation_only_affects_siruta(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->provisionedInAuthentik()->create(['active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('users.toggle-active', $user))
            ->assertSessionHas('success', 'Usuario desactivado en SIRUTA. Su cuenta de Authentik no se modificó.');

        $this->assertFalse($user->fresh()->active);
        Http::assertNothingSent();
    }

    public function test_user_with_a_siruta_created_account_cannot_be_deleted(): void
    {
        $user = User::factory()->provisionedInAuthentik()->create();

        $this->actingAs($this->admin())
            ->delete(route('users.destroy', $user))
            ->assertSessionHas('error', 'Este usuario tiene una cuenta creada por SIRUTA en Authentik: desactívalo en lugar de eliminarlo.');

        $this->assertModelExists($user);
    }

    public function test_activation_link_is_shown_once_for_an_account_that_never_signed_in(): void
    {
        $user = $this->provisionedUser();
        $link = 'https://auth.example.test/if/flow/recuperacion/?flow_token=secret-token';

        $this->fakeAuthentikApi([
            'GET /core/users/77/' => Http::response($this->account(['attributes' => $this->ownership($user)])),
            'POST /core/users/77/recovery/' => Http::response(['link' => $link]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.activation-link', $user))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($link, false)
            ->assertSessionMissing('activationLink');

        $this->assertDatabaseHas('activity_logs', ['action' => 'authentik_activation_link_generated']);
        $this->assertFalse(
            ActivityLog::all()->contains(fn (ActivityLog $log): bool => str_contains((string) json_encode($log->metadata), 'secret-token')),
            'El enlace no debe quedar en el registro de actividad.'
        );
        $this->assertNoPasswordWasTouched();
    }

    public function test_activation_link_is_refused_once_the_account_is_in_use(): void
    {
        $user = $this->provisionedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/77/' => Http::response($this->account([
                'attributes' => $this->ownership($user),
                'last_login' => '2026-11-04T10:00:00Z',
            ])),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.activation-link', $user))
            ->assertSessionHas('error', 'La cuenta ya fue activada en Authentik; la persona debe usar la recuperación de contraseña de Authentik.');

        $this->assertOnlyReadsWereSent();
    }

    public function test_activation_link_is_never_requested_for_preexisting_accounts(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->linkedToAuthentik()->create(['authentik_pk' => 77]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.activation-link', $user))
            ->assertSessionHas('error', 'Este usuario no admite enlace de activación.');

        Http::assertNothingSent();
    }

    public function test_missing_recovery_flow_is_explained_to_the_admin(): void
    {
        $user = $this->provisionedUser();

        $this->fakeAuthentikApi([
            'GET /core/users/77/' => Http::response($this->account(['attributes' => $this->ownership($user)])),
            'POST /core/users/77/recovery/' => Http::response(['non_field_errors' => 'No recovery flow set.'], 400),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.activation-link', $user))
            ->assertSessionHas('error', 'Authentik no tiene un flujo de recuperación aplicable en su marca (brand). Un administrador de Authentik debe asignarlo antes de generar enlaces de activación.');
    }

    public function test_admin_links_an_existing_account_only_after_confirming_the_cedula(): void
    {
        $user = User::factory()->create(['cedula' => self::CEDULA, 'authentik_status' => AuthentikStatus::Conflict]);

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [$this->account(['pk' => 12])]]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.link', $user), ['cedula_confirmation' => '12345678'])
            ->assertSessionHas('error', 'La cédula de confirmación no coincide; no se vinculó la cuenta.');

        Http::assertNothingSent();

        $this->actingAs($this->admin())
            ->post(route('users.authentik.link', $user), ['cedula_confirmation' => self::CEDULA])
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame(AuthentikStatus::Linked, $user->authentik_status);
        $this->assertSame(self::ACCOUNT_UID, $user->authentik_subject);
        $this->assertSame(12, $user->authentik_pk);
        $this->assertOnlyReadsWereSent();
    }

    public function test_existing_superuser_account_is_never_linked(): void
    {
        $user = User::factory()->create(['cedula' => self::CEDULA, 'authentik_status' => AuthentikStatus::Conflict]);

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [
                $this->account(['pk' => 1, 'is_superuser' => true]),
            ]]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.link', $user), ['cedula_confirmation' => self::CEDULA])
            ->assertSessionHas('error', 'La cuenta es superusuario de Authentik; SIRUTA no vincula cuentas con privilegios globales.');

        $this->assertSame(AuthentikStatus::Conflict, $user->fresh()->authentik_status);
        $this->assertNull($user->fresh()->authentik_subject);
    }

    public function test_account_already_linked_to_another_user_is_never_linked_twice(): void
    {
        User::factory()->linkedToAuthentik()->create(['authentik_subject' => self::ACCOUNT_UID]);
        $user = User::factory()->create(['cedula' => self::CEDULA, 'authentik_status' => AuthentikStatus::Conflict]);

        $this->fakeAuthentikApi([
            'GET /core/users/?username='.self::CEDULA => Http::response(['results' => [$this->account(['pk' => 12])]]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('users.authentik.link', $user), ['cedula_confirmation' => self::CEDULA])
            ->assertSessionHas('error', 'La cuenta de Authentik ya está vinculada a otro usuario de SIRUTA.');

        $this->assertNull($user->fresh()->authentik_subject);
    }

    public function test_unlinking_removes_only_the_local_link(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->linkedToAuthentik()->create();

        $this->actingAs($this->admin())
            ->post(route('users.authentik.unlink', $user))
            ->assertSessionHas('success', 'Vinculación retirada. La cuenta de Authentik no se modificó.');

        $this->assertNull($user->fresh()->authentik_subject);
        $this->assertSame(AuthentikStatus::Unlinked, $user->fresh()->authentik_status);
        Http::assertNothingSent();
    }

    public function test_admin_cannot_unlink_their_own_account(): void
    {
        $admin = User::factory()->linkedToAuthentik()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('users.authentik.unlink', $admin))
            ->assertSessionHas('error', 'No puedes desvincular tu propia cuenta de Authentik.');

        $this->assertNotNull($admin->fresh()->authentik_subject);
    }

    public function test_user_list_shows_the_siruta_and_authentik_state_of_each_user(): void
    {
        User::factory()->create([
            'name' => 'Persona En Conflicto',
            'cedula' => self::CEDULA,
            'authentik_status' => AuthentikStatus::Conflict,
            'authentik_sync_error' => 'La cédula ya existe en Authentik en una cuenta que SIRUTA no creó.',
        ]);

        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSeeText(self::CEDULA)
            ->assertSeeText('Conflicto')
            ->assertSeeText('La cédula ya existe en Authentik en una cuenta que SIRUTA no creó.')
            ->assertSeeText('Vincular cuenta existente')
            ->assertSeeText('Reintentar aprovisionamiento');
    }

    public function test_forms_ask_for_the_cedula_and_never_for_a_password(): void
    {
        $user = User::factory()->provisionedInAuthentik()->create(['cedula' => self::CEDULA]);

        $this->actingAs($this->admin())
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('name="cedula"', false)
            ->assertDontSee('name="password"', false);

        $this->actingAs($this->admin())
            ->get(route('users.edit', $user))
            ->assertOk()
            ->assertSee('value="'.self::CEDULA.'"', false)
            ->assertSeeText('No se puede cambiar: ya identifica la cuenta de Authentik.')
            ->assertDontSee('name="password"', false);
    }

    private function admin(): User
    {
        return User::factory()->linkedToAuthentik()->create(['role' => 'admin']);
    }

    private function failedUser(): User
    {
        return User::factory()->create([
            'cedula' => self::CEDULA,
            'authentik_status' => AuthentikStatus::Failed,
            'authentik_provisioning_key' => (string) Str::uuid(),
        ]);
    }

    private function provisionedUser(): User
    {
        return User::factory()->provisionedInAuthentik()->create([
            'cedula' => self::CEDULA,
            'authentik_subject' => self::ACCOUNT_UID,
            'authentik_pk' => 77,
            'authentik_uuid' => self::ACCOUNT_UUID,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownership(User $user): array
    {
        return ['siruta' => ['provisioning_key' => $user->authentik_provisioning_key, 'local_user_id' => $user->id]];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Administrador Principal',
            'cedula' => self::CEDULA,
            'role' => 'user',
            'active' => '1',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function account(array $overrides = []): array
    {
        return [
            'pk' => 77,
            'uuid' => self::ACCOUNT_UUID,
            'uid' => self::ACCOUNT_UID,
            'username' => self::CEDULA,
            'name' => 'Administrador Principal',
            'type' => 'internal',
            'is_active' => true,
            'is_superuser' => false,
            'last_login' => null,
            'attributes' => [],
            ...$overrides,
        ];
    }

    /**
     * Responde cada "MÉTODO /ruta" de la API; cualquier otra solicitud falla la prueba.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeAuthentikApi(array $routes): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($routes) {
            $route = $request->method().' '.Str::after($request->url(), self::API);
            $response = $routes[$route] ?? null;

            return $response instanceof ResponseSequence || $response instanceof Closure
                ? $response($request)
                : $response;
        });
    }

    private function assertOnlyReadsWereSent(): void
    {
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() !== 'GET');
    }

    private function assertNoPasswordWasTouched(): void
    {
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'set_password')
            || array_key_exists('password', $request->data()));
    }
}
