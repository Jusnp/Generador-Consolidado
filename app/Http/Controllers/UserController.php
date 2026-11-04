<?php

namespace App\Http\Controllers;

use App\Models\AuthentikStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthentikProvisioningException;
use App\Services\AuthentikUserProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(AuthentikUserProvisioner $authentikUserProvisioner): View
    {
        return view('users.index', [
            'users' => User::orderBy('name')->get(),
            'authentikConfigured' => $authentikUserProvisioner->isConfigured(),
        ]);
    }

    public function create(): View
    {
        return view('users.create');
    }

    /**
     * Registra el usuario en SIRUTA y lo aprovisiona en Authentik con su cédula
     * como username. La confirmación solo se muestra si quedó en ambos sistemas.
     */
    public function store(
        Request $request,
        ActivityLogger $activityLogger,
        AuthentikUserProvisioner $authentikUserProvisioner
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'cedula' => ['required', 'string', 'regex:/^[0-9]{5,15}$/', 'unique:users,cedula'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'active' => ['required', 'boolean'],
        ], $this->validationMessages());

        if (! $authentikUserProvisioner->isConfigured()) {
            return back()
                ->withInput()
                ->withErrors(['cedula' => 'El aprovisionamiento en Authentik no está configurado; no se creó el usuario.']);
        }

        try {
            $user = User::create([
                'name' => $validated['name'],
                'cedula' => $validated['cedula'],
                'email' => $validated['email'] ?? null,
                // La contraseña vive en Authentik; la local queda inutilizable.
                'password' => Str::password(64),
                'role' => $validated['role'],
                'active' => (bool) $validated['active'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()
                ->withInput()
                ->withErrors(['cedula' => 'Esa cédula o ese correo ya están registrados.']);
        }

        $activityLogger->log(
            'user_created',
            'Administrador creó un nuevo usuario.',
            [
                'created_user_id' => $user->id,
                'created_user_name' => $user->name,
                'created_user_email' => $user->email,
                'cedula' => $user->maskedCedula(),
                'role' => $user->role,
                'active' => $user->active,
            ]
        );

        return $this->redirectAfterProvisioning($authentikUserProvisioner, $user);
    }

    public function edit(User $user): View
    {
        return view('users.edit', compact('user'));
    }

    /**
     * Actualiza solo los datos de SIRUTA. Authentik es compartido: el nombre,
     * el correo y la contraseña de la cuenta no se modifican desde aquí.
     */
    public function update(
        Request $request,
        User $user,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'cedula' => [
                Rule::requiredIf($user->cedula !== null),
                'nullable',
                'string',
                'regex:/^[0-9]{5,15}$/',
                Rule::unique('users', 'cedula')->ignore($user->id),
            ],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'active' => ['required', 'boolean'],
        ], $this->validationMessages());

        $cedula = $validated['cedula'] ?? null;

        if ($cedula !== $user->cedula && ! $user->canChangeCedula()) {
            return back()
                ->withInput()
                ->withErrors(['cedula' => 'La cédula no se puede cambiar: ya identifica una cuenta de Authentik.']);
        }

        if ($user->is(auth()->user())
            && ($validated['role'] !== $user->role || ! $validated['active'])) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'No puedes quitarte el rol de administrador ni desactivar tu propio usuario.']);
        }

        $oldData = [
            'name' => $user->name,
            'cedula' => $user->maskedCedula(),
            'email' => $user->email,
            'role' => $user->role,
            'active' => $user->active,
        ];

        if ($cedula !== $user->cedula) {
            $user->authentik_status = AuthentikStatus::Unlinked;
            $user->authentik_sync_error = null;
        }

        $user->name = $validated['name'];
        $user->cedula = $cedula;
        $user->email = $validated['email'] ?? null;
        $user->role = $validated['role'];
        $user->active = (bool) $validated['active'];
        $user->save();

        if ($oldData['active'] && ! $user->active) {
            $user->invalidateSessions();
        }

        $activityLogger->log(
            'user_updated',
            'Administrador actualizó un usuario.',
            [
                'updated_user_id' => $user->id,
                'updated_user_name' => $user->name,
                'old_data' => $oldData,
                'new_data' => [
                    'name' => $user->name,
                    'cedula' => $user->maskedCedula(),
                    'email' => $user->email,
                    'role' => $user->role,
                    'active' => $user->active,
                ],
            ]
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario actualizado correctamente en SIRUTA.');
    }

    /**
     * Activa o desactiva el acceso a SIRUTA. La cuenta de Authentik no se
     * desactiva porque puede usarse en otras aplicaciones.
     */
    public function toggleActive(
        User $user,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        if ($user->id === auth()->id()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'No puedes desactivar tu propio usuario.'
                );
        }

        $oldStatus = $user->active;

        $user->active = ! $user->active;
        $user->save();

        if (! $user->active) {
            $user->invalidateSessions();
        }

        $status = $user->active ? 'activado' : 'desactivado';

        $activityLogger->log(
            'user_status_changed',
            'Administrador cambió el estado de un usuario.',
            [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'old_active' => $oldStatus,
                'new_active' => $user->active,
                'status' => $status,
            ]
        );

        $message = $user->active
            ? 'Usuario activado correctamente en SIRUTA.'
            : 'Usuario desactivado en SIRUTA. Su cuenta de Authentik no se modificó.';

        return redirect()
            ->route('users.index')
            ->with('success', $message);
    }

    public function destroy(
        User $user,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        if ($user->id === auth()->id()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'No puedes eliminar tu propio usuario.'
                );
        }

        if (! $user->canBeDeleted()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'Este usuario tiene una cuenta creada por SIRUTA en Authentik: desactívalo en lugar de eliminarlo.'
                );
        }

        if ($user->role === 'admin') {
            $adminCount = User::where('role', 'admin')->count();

            if ($adminCount <= 1) {
                return redirect()
                    ->route('users.index')
                    ->with(
                        'error',
                        'No puedes eliminar al último administrador del sistema.'
                    );
            }
        }

        $deletedUser = [
            'id' => $user->id,
            'name' => $user->name,
            'cedula' => $user->maskedCedula(),
            'email' => $user->email,
            'role' => $user->role,
            'active' => $user->active,
        ];

        $user->delete();

        $activityLogger->log(
            'user_deleted',
            'Administrador eliminó un usuario.',
            [
                'deleted_user' => $deletedUser,
            ]
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario eliminado correctamente.');
    }

    private function redirectAfterProvisioning(
        AuthentikUserProvisioner $authentikUserProvisioner,
        User $user
    ): RedirectResponse {
        try {
            $user = $authentikUserProvisioner->provision($user);
        } catch (AuthentikProvisioningException $exception) {
            return redirect()
                ->route('users.index')
                ->with('error', "El usuario quedó registrado en SIRUTA, pero no en Authentik: {$exception->getMessage()}");
        }

        if ($user->authentik_status === AuthentikStatus::Provisioned) {
            return redirect()
                ->route('users.index')
                ->with('success', 'Usuario creado en SIRUTA y en Authentik. Genera el enlace de activación para que defina su contraseña.');
        }

        return redirect()
            ->route('users.index')
            ->with('error', "El usuario quedó registrado en SIRUTA, pero no en Authentik: {$user->authentik_sync_error}");
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'cedula.required' => 'El número de cédula es obligatorio.',
            'cedula.regex' => 'La cédula debe tener entre 5 y 15 dígitos, sin puntos ni espacios.',
            'cedula.unique' => 'Esa cédula ya está registrada en SIRUTA.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ese correo electrónico ya está registrado.',
            'role.required' => 'Debes seleccionar un rol.',
            'role.in' => 'El rol seleccionado no es válido.',
            'active.required' => 'Debes indicar el estado del usuario.',
            'active.boolean' => 'El estado seleccionado no es válido.',
        ];
    }
}
