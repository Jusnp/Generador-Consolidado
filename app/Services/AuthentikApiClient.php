<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operaciones de la API de Authentik que usa SIRUTA. Solo crea usuarios,
 * agrega miembros al grupo de acceso de SIRUTA y pide enlaces de recuperación:
 * no edita, desactiva ni cambia contraseñas de cuentas existentes.
 */
class AuthentikApiClient
{
    private const MAX_GROUP_DEPTH = 10;

    public function isConfigured(): bool
    {
        return filter_var(config('services.authentik.api_base_url'), FILTER_VALIDATE_URL) !== false
            && trim((string) config('services.authentik.api_token')) !== '';
    }

    /**
     * Busca una cuenta por username exacto.
     *
     * @return array<string, mixed>|null
     */
    public function findUserByUsername(string $username): ?array
    {
        $response = $this->send('buscar_usuario', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get('/core/users/', ['username' => $username]));

        $this->ensureSuccessful($response, 'consultar los usuarios');

        $results = $response->json('results');

        if (! is_array($results)) {
            throw new AuthentikProvisioningException('Authentik devolvió una respuesta inesperada al consultar los usuarios.');
        }

        foreach ($results as $account) {
            if (is_array($account) && ($account['username'] ?? null) === $username) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(int $pk): array
    {
        $response = $this->send('consultar_usuario', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get("/core/users/{$pk}/"));

        $this->ensureSuccessful($response, 'consultar la cuenta');

        return $this->accountFrom($response);
    }

    /**
     * Crea una cuenta. Devuelve null si el username ya existe en Authentik.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function createUser(array $payload): ?array
    {
        $response = $this->send('crear_usuario', fn (PendingRequest $client): Response => $client
            ->post('/core/users/', $payload));

        if ($response->status() === 400 && $response->json('username') !== null) {
            return null;
        }

        $this->ensureSuccessful($response, 'crear la cuenta');

        return $this->accountFrom($response);
    }

    /**
     * Agrega la cuenta al grupo de acceso de SIRUTA. Antes verifica que ni el
     * grupo ni sus grupos padre otorguen superusuario en Authentik.
     */
    public function addUserToAccessGroup(string $groupUuid, int $userPk): void
    {
        $this->assertGroupGrantsNoSuperuser($groupUuid);

        $response = $this->send('agregar_a_grupo', fn (PendingRequest $client): Response => $client
            ->post("/core/groups/{$groupUuid}/add_user/", ['pk' => $userPk]));

        $this->ensureSuccessful($response, 'asignar el grupo de acceso de SIRUTA');
    }

    /**
     * @return array{name: string, is_superuser: bool, inherits_superuser: bool}
     */
    public function accessGroupSummary(string $groupUuid): array
    {
        $group = $this->getGroup($groupUuid);

        try {
            $this->assertGroupGrantsNoSuperuser($groupUuid);
            $inheritsSuperuser = false;
        } catch (AuthentikProvisioningException) {
            $inheritsSuperuser = true;
        }

        return [
            'name' => (string) ($group['name'] ?? ''),
            'is_superuser' => ($group['is_superuser'] ?? true) !== false,
            'inherits_superuser' => $inheritsSuperuser,
        ];
    }

    /**
     * Enlace temporal del flujo de recuperación de la marca de Authentik, con el
     * que la persona define su propia contraseña.
     */
    public function recoveryLink(int $userPk): string
    {
        $response = $this->send('enlace_recuperacion', fn (PendingRequest $client): Response => $client
            ->post("/core/users/{$userPk}/recovery/"));

        if ($response->status() === 400 && str_contains(strtolower($response->body()), 'recovery flow')) {
            throw new AuthentikProvisioningException(
                'Authentik no tiene un flujo de recuperación aplicable en su marca (brand). Un administrador de Authentik debe asignarlo antes de generar enlaces de activación.'
            );
        }

        $this->ensureSuccessful($response, 'generar el enlace de activación');

        $link = $response->json('link');
        $baseUrl = rtrim((string) config('services.authentik.base_url'), '/').'/';

        if (! is_string($link) || ! str_starts_with($link, $baseUrl)) {
            throw new AuthentikProvisioningException('Authentik devolvió un enlace de activación no válido.');
        }

        return $link;
    }

    /**
     * Cuenta dueña del token de API (diagnóstico).
     *
     * @return array<string, mixed>
     */
    public function tokenOwner(): array
    {
        $response = $this->send('consultar_token', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get('/core/users/me/'));

        $this->ensureSuccessful($response, 'consultar la cuenta del token');

        $user = $response->json('user');

        return is_array($user) ? $user : [];
    }

    /**
     * Configuración pública de la marca que atiende las llamadas de SIRUTA (diagnóstico).
     *
     * @return array<string, mixed>
     */
    public function currentBrand(): array
    {
        $response = $this->send('consultar_marca', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get('/core/brands/current/'));

        $this->ensureSuccessful($response, 'consultar la marca');

        $brand = $response->json();

        return is_array($brand) ? $brand : [];
    }

    /**
     * Slugs de los flujos de recuperación existentes (diagnóstico).
     *
     * @return list<string>
     */
    public function recoveryFlowSlugs(): array
    {
        $response = $this->send('consultar_flujos', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get('/flows/instances/', ['designation' => 'recovery']));

        $this->ensureSuccessful($response, 'consultar los flujos');

        return collect($response->json('results') ?? [])
            ->pluck('slug')
            ->filter(fn (mixed $slug): bool => is_string($slug))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function getGroup(string $groupUuid): array
    {
        $response = $this->send('consultar_grupo', fn (PendingRequest $client): Response => $this->retryingReads($client)
            ->get("/core/groups/{$groupUuid}/", ['include_users' => 'false']));

        $this->ensureSuccessful($response, 'consultar el grupo de acceso de SIRUTA');

        return $this->accountFrom($response);
    }

    /**
     * Authentik hereda el superusuario de los grupos padre: se revisa toda la cadena.
     */
    private function assertGroupGrantsNoSuperuser(string $groupUuid): void
    {
        $currentUuid = $groupUuid;

        for ($depth = 0; $depth < self::MAX_GROUP_DEPTH; $depth++) {
            $group = $this->getGroup($currentUuid);

            if (($group['is_superuser'] ?? true) !== false) {
                throw new AuthentikProvisioningException(
                    'El grupo de acceso configurado otorga superusuario en Authentik; SIRUTA no asigna cuentas a ese grupo.'
                );
            }

            $parent = $group['parent'] ?? null;

            if (! is_string($parent) || $parent === '') {
                return;
            }

            $currentUuid = $parent;
        }

        throw new AuthentikProvisioningException('No fue posible verificar la cadena de grupos del grupo de acceso de SIRUTA.');
    }

    /**
     * @return array<string, mixed>
     */
    private function accountFrom(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new AuthentikProvisioningException('Authentik devolvió una respuesta inesperada.');
        }

        return $payload;
    }

    private function ensureSuccessful(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $hint = in_array($response->status(), [401, 403], true)
            ? ' Revisa los permisos del token de servicio de SIRUTA.'
            : '';

        throw new AuthentikProvisioningException(
            "Authentik no permitió {$action} (HTTP {$response->status()}).{$hint}"
        );
    }

    private function retryingReads(PendingRequest $client): PendingRequest
    {
        return $client->retry([300], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException, false);
    }

    /**
     * Las excepciones de conexión incluyen la URL, que puede llevar la cédula:
     * se registran solo con el nombre de la operación.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(string $operation, callable $request): Response
    {
        try {
            return $request($this->client());
        } catch (ConnectionException) {
            Log::warning('Authentik no respondió.', ['operacion' => $operation]);

            throw new AuthentikProvisioningException('Authentik no respondió. Intenta de nuevo en unos minutos.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.authentik.api_base_url'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('services.authentik.api_token'))
            ->connectTimeout(5)
            ->timeout(15);
    }
}
