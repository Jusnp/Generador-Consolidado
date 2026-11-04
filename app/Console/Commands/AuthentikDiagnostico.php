<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuthentikApiClient;
use App\Services\AuthentikProvisioningException;
use Illuminate\Console\Command;

/**
 * Diagnóstico de solo lectura de la integración con Authentik: solo envía
 * consultas GET y nunca imprime tokens, enlaces ni cédulas completas.
 */
class AuthentikDiagnostico extends Command
{
    protected $signature = 'authentik:diagnostico
                            {--cedula=* : Cédula que se verificará en Authentik (se puede repetir)}';

    protected $description = 'Verifica, sin modificar Authentik, el token de servicio, la activación y si una cédula colisiona';

    public function handle(AuthentikApiClient $authentikApiClient): int
    {
        $this->line('Configuración');
        $this->line('  Acceso OIDC habilitado: '.$this->yesNo((bool) config('services.authentik.enabled')));
        $this->line('  API de aprovisionamiento configurada: '.$this->yesNo($authentikApiClient->isConfigured()));
        $this->line('  Modo de subject OIDC: '.config('services.authentik.subject_mode'));

        if (! $authentikApiClient->isConfigured()) {
            $this->error('Falta AUTHENTIK_BASE_URL o AUTHENTIK_API_TOKEN.');

            return self::FAILURE;
        }

        $this->section('Token de servicio', function () use ($authentikApiClient): void {
            $owner = $authentikApiClient->tokenOwner();

            $this->line('  Cuenta: '.($owner['username'] ?? 'desconocida'));
            $this->line('  Superusuario: '.$this->yesNo(($owner['is_superuser'] ?? true) !== false));

            if (($owner['is_superuser'] ?? true) !== false) {
                $this->warn('  Usa una cuenta de servicio sin superusuario y con permisos mínimos.');
            }
        });

        $this->section('Activación (flujo de recuperación de la marca)', function () use ($authentikApiClient): void {
            $recoveryFlow = $authentikApiClient->currentBrand()['flow_recovery'] ?? null;

            $this->line('  Flujo asignado: '.(is_string($recoveryFlow) ? $recoveryFlow : 'ninguno'));

            if (! is_string($recoveryFlow)) {
                $this->warn('  Sin flujo de recuperación, Authentik no genera enlaces de activación.');
            }

            $slugs = $authentikApiClient->recoveryFlowSlugs();
            $this->line('  Flujos de recuperación existentes: '.($slugs === [] ? 'ninguno' : implode(', ', $slugs)));
        });

        $accessGroup = trim((string) config('services.authentik.access_group'));

        $this->section('Grupo de acceso de SIRUTA', function () use ($authentikApiClient, $accessGroup): void {
            if ($accessGroup === '') {
                $this->line('  No configurado (AUTHENTIK_ACCESS_GROUP).');

                return;
            }

            $group = $authentikApiClient->accessGroupSummary($accessGroup);

            $this->line('  Nombre: '.$group['name']);
            $this->line('  Otorga superusuario (directo o heredado): '.$this->yesNo($group['is_superuser'] || $group['inherits_superuser']));
        });

        foreach ($this->option('cedula') as $cedula) {
            $this->section('Cédula '.User::maskCedula($cedula), function () use ($authentikApiClient, $cedula): void {
                $localUser = User::query()->where('cedula', $cedula)->first();
                $this->line('  En SIRUTA: '.($localUser ? "usuario #{$localUser->id} ({$localUser->authentik_status->label()})" : 'no registrada'));

                $account = $authentikApiClient->findUserByUsername($cedula);

                if ($account === null) {
                    $this->info('  En Authentik: no existe; no hay colisión.');

                    return;
                }

                $createdBySiruta = is_string($account['attributes']['siruta']['provisioning_key'] ?? null);
                $linkedUser = User::query()->where('authentik_pk', (int) ($account['pk'] ?? 0))->first();

                $this->warn('  En Authentik: ya existe.');
                $this->line('  Tipo: '.($account['type'] ?? 'desconocido'));
                $this->line('  Activa: '.$this->yesNo(($account['is_active'] ?? false) === true));
                $this->line('  Superusuario: '.$this->yesNo(($account['is_superuser'] ?? true) !== false));
                $this->line('  Creada por SIRUTA: '.$this->yesNo($createdBySiruta));
                $this->line('  Ha iniciado sesión en Authentik: '.$this->yesNo(($account['last_login'] ?? null) !== null));
                $this->line('  Vinculada en SIRUTA: '.($linkedUser ? "usuario #{$linkedUser->id}" : 'no'));
            });
        }

        return self::SUCCESS;
    }

    private function section(string $title, callable $callback): void
    {
        $this->newLine();
        $this->line($title);

        try {
            $callback();
        } catch (AuthentikProvisioningException $exception) {
            $this->error('  '.$exception->getMessage());
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'sí' : 'no';
    }
}
