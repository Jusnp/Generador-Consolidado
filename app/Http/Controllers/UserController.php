<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(
        Request $request,
        ActivityLogger $activityLogger
    ) {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'active' => ['required', 'boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ese correo electrónico ya está registrado.',
            'role.required' => 'Debes seleccionar un rol.',
            'role.in' => 'El rol seleccionado no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'active.required' => 'Debes indicar el estado del usuario.',
            'active.boolean' => 'El estado seleccionado no es válido.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'active' => (bool) $validated['active'],
        ]);

        $activityLogger->log(
            'user_created',
            'Administrador creó un nuevo usuario.',
            [
                'created_user_id' => $user->id,
                'created_user_name' => $user->name,
                'created_user_email' => $user->email,
                'role' => $user->role,
                'active' => $user->active,
            ]
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(
        Request $request,
        User $user,
        ActivityLogger $activityLogger
    ) {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'active' => ['required', 'boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ese correo electrónico ya está registrado.',
            'role.required' => 'Debes seleccionar un rol.',
            'role.in' => 'El rol seleccionado no es válido.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'active.required' => 'Debes indicar el estado del usuario.',
            'active.boolean' => 'El estado seleccionado no es válido.',
        ]);

        $oldData = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => $user->active,
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->active = (bool) $validated['active'];

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        $activityLogger->log(
            'user_updated',
            'Administrador actualizó un usuario.',
            [
                'updated_user_id' => $user->id,
                'updated_user_name' => $user->name,
                'old_data' => $oldData,
                'new_data' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'active' => $user->active,
                ],
                'password_changed' => !empty($validated['password']),
            ]
        );

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function toggleActive(
        User $user,
        ActivityLogger $activityLogger
    ) {
        if ($user->id === auth()->id()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'No puedes desactivar tu propio usuario.'
                );
        }

        $oldStatus = $user->active;

        $user->active = !$user->active;
        $user->save();

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
            ? 'Usuario activado correctamente.'
            : 'Usuario desactivado correctamente.';

        return redirect()
            ->route('users.index')
            ->with('success', $message);
    }

    public function destroy(
        User $user,
        ActivityLogger $activityLogger
    ) {
        if ($user->id === auth()->id()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'No puedes eliminar tu propio usuario.'
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
}