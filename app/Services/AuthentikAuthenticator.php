<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AuthentikAuthenticator
{
    public function isConfigured(): bool
    {
        $clientId = config('services.authentik.client_id');
        $clientSecret = config('services.authentik.client_secret');

        return config('services.authentik.enabled')
            && filter_var(config('services.authentik.base_url'), FILTER_VALIDATE_URL) !== false
            && is_string($clientId)
            && $clientId !== ''
            && is_string($clientSecret)
            && $clientSecret !== ''
            && filter_var(config('services.authentik.redirect_uri'), FILTER_VALIDATE_URL) !== false;
    }

    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        return config('services.authentik.authorize_url').'?'.http_build_query([
            'client_id' => config('services.authentik.client_id'),
            'redirect_uri' => config('services.authentik.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('services.authentik.scopes'),
            'state' => $state,
            'code_challenge' => $this->codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     */
    public function claimsForAuthorizationCode(string $code, string $codeVerifier): array
    {
        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                (string) config('services.authentik.client_id'),
                (string) config('services.authentik.client_secret')
            )
            ->timeout(10)
            ->post(config('services.authentik.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.authentik.redirect_uri'),
                'code_verifier' => $codeVerifier,
            ]);

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('No fue posible validar el código de autenticación.');
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Authentik no entregó un token de acceso válido.');
        }

        $userInfoResponse = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(10)
            ->get(config('services.authentik.userinfo_url'));

        if (! $userInfoResponse->successful() || ! is_array($userInfoResponse->json())) {
            throw new RuntimeException('No fue posible consultar la identidad en Authentik.');
        }

        return $userInfoResponse->json();
    }

    /**
     * Authentik never creates a local user automatically. An administrator must
     * already have created and activated the matching local account.
     *
     * @param  array<string, mixed>  $claims
     */
    public function activeUserForClaims(array $claims): ?User
    {
        $subject = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        $email = is_string($claims['email'] ?? null) ? $claims['email'] : '';

        if ($subject === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return DB::transaction(function () use ($subject, $email): ?User {
            $user = User::query()
                ->where('authentik_subject', $subject)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $user = User::query()
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    return null;
                }

                $user->authentik_subject = $subject;
                $user->save();
            }

            return $user->active ? $user : null;
        });
    }

    private function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
