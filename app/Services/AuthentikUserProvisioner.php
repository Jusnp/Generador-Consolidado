<?php

namespace App\Services;

use App\Models\AuthentikStatus;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Crea y vincula las identidades de SIRUTA en el Authentik compartido.
 *
 * - La cédula es el username en Authentik; el rol existe solo en SIRUTA.
 * - El registro local se guarda antes de crear la cuenta, con una clave de
 *   aprovisionamiento que también queda en los atributos de la cuenta. Un
 *   reintento adopta solo la cuenta que lleva esa clave: nunca se apropia de
 *   cuentas ajenas ni deja cuentas sin registro local.
 * - Nunca edita, desactiva ni cambia la contraseña de cuentas existentes; la
 *   contraseña inicial la define la persona con un enlace de activación.
 */
class AuthentikUserProvisioner
{
    public function __construct(
        private AuthentikApiClient $authentikApiClient,
        private ActivityLogger $activityLogger,
    ) {}

    public function isConfigured(): bool
    {
        return $this->authentikApiClient->isConfigured();
    }

    /**
     * Crea la cuenta en Authentik o retoma un intento anterior. Es idempotente
     * y devuelve el usuario con su estado final (aprovisionado, conflicto o error).
     *
     * @throws AuthentikProvisioningException si otra operación tiene el usuario bloqueado.
     */
    public function provision(User $user): User
    {
        return $this->withUserLock($user, function () use ($user): User {
            $user->refresh();

            if (! $user->canRetryAuthentikProvisioning()) {
                return $user;
            }

            $user->authentik_provisioning_key ??= (string) Str::uuid();
            $user->authentik_status = AuthentikStatus::Pending;
            $user->save();

            $accessGroup = trim((string) config('services.authentik.access_group'));

            try {
                $account = $this->authentikApiClient->findUserByUsername($user->cedula)
                    ?? $this->createAccount($user);

                if (! $this->isOwnedBy($account, $user)) {
                    return $this->markConflict($user, $this->foreignAccountDescription($account));
                }

                if ($this->identityBelongsToAnotherUser($account, $user)) {
                    return $this->markConflict($user, 'La cuenta de Authentik ya está vinculada a otro usuario de SIRUTA.');
                }

                $this->storeIdentifiers($user, $account);

                if ($accessGroup !== '') {
                    $this->authentikApiClient->addUserToAccessGroup($accessGroup, $user->authentik_pk);
                }
            } catch (AuthentikProvisioningException $exception) {
                return $this->markFailed($user, $exception->getMessage());
            }

            $user->authentik_status = AuthentikStatus::Provisioned;
            $user->authentik_sync_error = null;
            $user->authentik_provisioned_at = now();
            $user->save();

            $this->audit('authentik_user_provisioned', 'Usuario aprovisionado en Authentik.', $user, [
                'access_group_assigned' => $accessGroup !== '',
            ]);

            return $user;
        });
    }

    /**
     * Vincula, por decisión expresa de un administrador, una cuenta que ya
     * existía en Authentik con la misma cédula. No modifica la cuenta.
     *
     * @throws AuthentikProvisioningException
     */
    public function linkExisting(User $user): User
    {
        return $this->withUserLock($user, function () use ($user): User {
            $user->refresh();

            if (! $user->canLinkExistingAuthentikAccount()) {
                throw new AuthentikProvisioningException('Este usuario no admite vincular una cuenta existente.');
            }

            $account = $this->authentikApiClient->findUserByUsername($user->cedula);

            if ($account === null) {
                throw new AuthentikProvisioningException('No existe una cuenta de Authentik con esa cédula.');
            }

            if ($this->isOwnedBy($account, $user)) {
                throw new AuthentikProvisioningException('SIRUTA creó esta cuenta para el usuario: usa «Reintentar aprovisionamiento».');
            }

            if (($account['is_superuser'] ?? true) !== false) {
                throw new AuthentikProvisioningException('La cuenta es superusuario de Authentik; SIRUTA no vincula cuentas con privilegios globales.');
            }

            if (($account['type'] ?? null) !== 'internal') {
                throw new AuthentikProvisioningException('Solo se pueden vincular cuentas internas de Authentik.');
            }

            if (($account['is_active'] ?? false) !== true) {
                throw new AuthentikProvisioningException('La cuenta de Authentik está inactiva.');
            }

            if ($this->identityBelongsToAnotherUser($account, $user)) {
                throw new AuthentikProvisioningException('La cuenta de Authentik ya está vinculada a otro usuario de SIRUTA.');
            }

            $this->storeIdentifiers($user, $account);

            $user->authentik_status = AuthentikStatus::Linked;
            $user->authentik_sync_error = null;
            $user->save();

            $this->audit('authentik_identity_linked', 'Administrador vinculó una cuenta existente de Authentik.', $user);

            return $user;
        });
    }

    /**
     * Retira la vinculación local de una cuenta preexistente. No toca Authentik.
     */
    public function unlink(User $user): User
    {
        if (! $user->canUnlinkAuthentikAccount()) {
            throw new AuthentikProvisioningException('Solo se pueden desvincular cuentas existentes vinculadas por un administrador.');
        }

        $user->authentik_subject = null;
        $user->authentik_pk = null;
        $user->authentik_uuid = null;
        $user->authentik_status = AuthentikStatus::Unlinked;
        $user->authentik_sync_error = null;
        $user->save();
        $user->invalidateSessions();

        $this->audit('authentik_identity_unlinked', 'Administrador desvinculó la cuenta de Authentik de un usuario.', $user);

        return $user;
    }

    /**
     * Enlace temporal para que la persona defina su contraseña. Solo para
     * cuentas creadas por SIRUTA que nunca han iniciado sesión en Authentik.
     *
     * @throws AuthentikProvisioningException
     */
    public function activationLink(User $user): string
    {
        if (! $user->canReceiveActivationLink()) {
            throw new AuthentikProvisioningException('Este usuario no admite enlace de activación.');
        }

        $account = $this->authentikApiClient->getUser($user->authentik_pk);

        if (! $this->isOwnedBy($account, $user)
            || ($account['is_superuser'] ?? true) !== false
            || $this->subjectFor($account) !== $user->authentik_subject) {
            throw new AuthentikProvisioningException('La cuenta de Authentik no corresponde a la que SIRUTA creó para este usuario.');
        }

        if (($account['last_login'] ?? null) !== null) {
            throw new AuthentikProvisioningException('La cuenta ya fue activada en Authentik; la persona debe usar la recuperación de contraseña de Authentik.');
        }

        $link = $this->authentikApiClient->recoveryLink($user->authentik_pk);

        $this->audit('authentik_activation_link_generated', 'Administrador generó un enlace de activación de Authentik.', $user);

        return $link;
    }

    /**
     * Subject OIDC que Authentik enviará para la cuenta, según el modo
     * configurado en el proveedor de SIRUTA.
     *
     * @param  array<string, mixed>  $account
     */
    public function subjectFor(array $account): string
    {
        $subject = match (config('services.authentik.subject_mode')) {
            'hashed_user_id' => $account['uid'] ?? null,
            'user_id' => $account['pk'] ?? null,
            'user_uuid' => $account['uuid'] ?? null,
            'user_username' => $account['username'] ?? null,
            default => throw new AuthentikProvisioningException('El modo de subject OIDC configurado no permite una vinculación segura.'),
        };

        if ((! is_string($subject) && ! is_int($subject)) || (string) $subject === '') {
            throw new AuthentikProvisioningException('Authentik no devolvió el identificador de la cuenta.');
        }

        return (string) $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function createAccount(User $user): array
    {
        $payload = [
            'username' => $user->cedula,
            'name' => $user->name,
            'is_active' => true,
            'type' => 'internal',
            'attributes' => [
                'siruta' => [
                    'provisioning_key' => $user->authentik_provisioning_key,
                    'local_user_id' => $user->id,
                ],
            ],
        ];

        if ($user->email !== null) {
            $payload['email'] = $user->email;
        }

        $path = trim((string) config('services.authentik.user_path'));

        if ($path !== '') {
            $payload['path'] = $path;
        }

        $account = $this->authentikApiClient->createUser($payload);

        if ($account !== null) {
            return $account;
        }

        // La cédula se registró en Authentik entre la consulta y la creación.
        return $this->authentikApiClient->findUserByUsername($user->cedula)
            ?? throw new AuthentikProvisioningException('Authentik rechazó la cédula, pero no fue posible consultar la cuenta existente.');
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function isOwnedBy(array $account, User $user): bool
    {
        $provisioningKey = $account['attributes']['siruta']['provisioning_key'] ?? null;

        return ($account['username'] ?? null) === $user->cedula
            && is_string($provisioningKey)
            && is_string($user->authentik_provisioning_key)
            && hash_equals($user->authentik_provisioning_key, $provisioningKey);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function identityBelongsToAnotherUser(array $account, User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->getKey())
            ->where(function ($query) use ($account): void {
                $query->where('authentik_subject', $this->subjectFor($account))
                    ->orWhere('authentik_pk', (int) ($account['pk'] ?? 0))
                    ->orWhere('authentik_uuid', (string) ($account['uuid'] ?? ''));
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function storeIdentifiers(User $user, array $account): void
    {
        $pk = (int) ($account['pk'] ?? 0);
        $uuid = $account['uuid'] ?? null;

        if ($pk <= 0 || ! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw new AuthentikProvisioningException('Authentik devolvió una cuenta incompleta.');
        }

        if (($account['is_superuser'] ?? true) !== false) {
            throw new AuthentikProvisioningException('La cuenta de Authentik tiene privilegios de superusuario; SIRUTA no la vincula.');
        }

        $user->authentik_subject = $this->subjectFor($account);
        $user->authentik_pk = $pk;
        $user->authentik_uuid = $uuid;
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function foreignAccountDescription(array $account): string
    {
        $type = is_string($account['type'] ?? null) ? $account['type'] : 'desconocido';
        $active = ($account['is_active'] ?? false) === true ? 'activa' : 'inactiva';
        $superuser = ($account['is_superuser'] ?? true) !== false ? ', superusuario' : '';

        return "La cédula ya existe en Authentik en una cuenta que SIRUTA no creó (tipo {$type}, {$active}{$superuser}). "
            .'No se modificó; requiere vinculación administrativa.';
    }

    private function markConflict(User $user, string $reason): User
    {
        $user->authentik_status = AuthentikStatus::Conflict;
        $user->authentik_sync_error = $reason;
        $user->save();

        $this->audit('authentik_provisioning_conflict', 'Conflicto de identidad al aprovisionar en Authentik.', $user, [
            'reason' => $reason,
        ]);

        return $user;
    }

    private function markFailed(User $user, string $reason): User
    {
        $user->authentik_status = AuthentikStatus::Failed;
        $user->authentik_sync_error = $reason;
        $user->save();

        $this->audit('authentik_provisioning_failed', 'Falló el aprovisionamiento en Authentik.', $user, [
            'reason' => $reason,
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(string $action, string $description, User $user, array $metadata = []): void
    {
        $this->activityLogger->log($action, $description, [
            'target_user_id' => $user->id,
            'target_user_name' => $user->name,
            'cedula' => $user->maskedCedula(),
            'authentik_pk' => $user->authentik_pk,
            ...$metadata,
        ]);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withUserLock(User $user, Closure $callback): mixed
    {
        $lock = Cache::lock('authentik-provisioning:'.$user->getKey(), 60);

        if (! $lock->get()) {
            throw new AuthentikProvisioningException('Ya hay una operación de Authentik en curso para este usuario. Intenta de nuevo en un momento.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
