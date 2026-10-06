<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AuthentikUserProvisioner
{
    public function isConfigured(): bool
    {
        return config('services.authentik.api_token') !== null
            && trim((string) config('services.authentik.api_token')) !== '';
    }

    /**
     * Creates or finds an Authentik account and assigns its password.
     *
     * @return array{pk:int, uuid:string}
     */
    public function provision(string $name, string $email, string $password): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('El aprovisionamiento de usuarios en Authentik no está configurado.');
        }

        $authentikUser = $this->findByUsername($email);

        if ($authentikUser === null) {
            $response = $this->client()->post('/core/users/', [
                'username' => $email,
                'name' => $name,
                'email' => $email,
                'is_active' => true,
                'type' => 'internal',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Authentik no permitió crear la cuenta.');
            }

            $authentikUser = $response->json();
        }

        $pk = (int) ($authentikUser['pk'] ?? 0);
        $uuid = (string) ($authentikUser['uuid'] ?? '');

        if ($pk <= 0 || $uuid === '') {
            throw new RuntimeException('Authentik devolvió una cuenta incompleta.');
        }

        $passwordResponse = $this->client()->post("/core/users/{$pk}/set_password/", [
            'password' => $password,
        ]);

        if (! $passwordResponse->successful()) {
            throw new RuntimeException('Authentik no permitió establecer la contraseña.');
        }

        return ['pk' => $pk, 'uuid' => $uuid];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByUsername(string $username): ?array
    {
        $response = $this->client()->get('/core/users/', [
            'username' => $username,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('No fue posible consultar los usuarios de Authentik.');
        }

        $results = $response->json('results');

        if (! is_array($results) || $results === []) {
            return null;
        }

        $user = $results[0] ?? null;

        return is_array($user) ? $user : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.authentik.api_base_url'), '/'))
            ->acceptJson()
            ->withToken((string) config('services.authentik.api_token'))
            ->timeout(15);
    }
}
