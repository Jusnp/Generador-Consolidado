<?php

namespace App\Http\Controllers;

use App\Models\AuthentikStatus;
use App\Models\User;
use App\Services\AuthentikProvisioningException;
use App\Services\AuthentikUserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Acciones administrativas sobre la identidad de Authentik de un usuario.
 * Ninguna modifica, desactiva ni cambia la contraseña de cuentas preexistentes.
 */
class UserAuthentikController extends Controller
{
    /**
     * Aprovisiona o reintenta de forma idempotente un aprovisionamiento incompleto.
     */
    public function provision(User $user, AuthentikUserProvisioner $authentikUserProvisioner): RedirectResponse
    {
        if (! $user->canRetryAuthentikProvisioning()) {
            return $this->backToUsers('error', 'Este usuario no requiere aprovisionamiento en Authentik.');
        }

        try {
            $user = $authentikUserProvisioner->provision($user);
        } catch (AuthentikProvisioningException $exception) {
            return $this->backToUsers('error', $exception->getMessage());
        }

        if ($user->authentik_status === AuthentikStatus::Provisioned) {
            return $this->backToUsers('success', 'Usuario aprovisionado en Authentik. Genera el enlace de activación para que defina su contraseña.');
        }

        return $this->backToUsers('error', "No se completó el aprovisionamiento: {$user->authentik_sync_error}");
    }

    /**
     * Vinculación administrativa de una cuenta que ya existía en Authentik con
     * la misma cédula. Exige escribir de nuevo la cédula como confirmación.
     */
    public function link(
        Request $request,
        User $user,
        AuthentikUserProvisioner $authentikUserProvisioner
    ): RedirectResponse {
        $validated = $request->validate([
            'cedula_confirmation' => ['required', 'string'],
        ], [
            'cedula_confirmation.required' => 'Escribe la cédula para confirmar la vinculación.',
        ]);

        if ($user->cedula === null || ! hash_equals($user->cedula, $validated['cedula_confirmation'])) {
            return $this->backToUsers('error', 'La cédula de confirmación no coincide; no se vinculó la cuenta.');
        }

        try {
            $authentikUserProvisioner->linkExisting($user);
        } catch (AuthentikProvisioningException $exception) {
            return $this->backToUsers('error', $exception->getMessage());
        }

        return $this->backToUsers(
            'success',
            'Cuenta existente de Authentik vinculada sin modificarla. Si la aplicación SIRUTA exige un grupo en Authentik, un administrador de Authentik debe asignarlo.'
        );
    }

    /**
     * Retira la vinculación local de una cuenta preexistente. Authentik no se
     * modifica.
     */
    public function unlink(User $user, AuthentikUserProvisioner $authentikUserProvisioner): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return $this->backToUsers('error', 'No puedes desvincular tu propia cuenta de Authentik.');
        }

        try {
            $authentikUserProvisioner->unlink($user);
        } catch (AuthentikProvisioningException $exception) {
            return $this->backToUsers('error', $exception->getMessage());
        }

        return $this->backToUsers('success', 'Vinculación retirada. La cuenta de Authentik no se modificó.');
    }

    /**
     * Muestra una sola vez el enlace temporal con el que la persona define su
     * contraseña en Authentik. El enlace no se guarda en sesión ni en registros.
     */
    public function activationLink(User $user, AuthentikUserProvisioner $authentikUserProvisioner): Response|RedirectResponse
    {
        try {
            $activationLink = $authentikUserProvisioner->activationLink($user);
        } catch (AuthentikProvisioningException $exception) {
            return $this->backToUsers('error', $exception->getMessage());
        }

        return response()
            ->view('users.index', [
                'users' => User::orderBy('name')->get(),
                'authentikConfigured' => true,
                'activationLink' => $activationLink,
                'activationUser' => $user,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function backToUsers(string $type, string $message): RedirectResponse
    {
        return redirect()
            ->route('users.index')
            ->with($type, $message);
    }
}
