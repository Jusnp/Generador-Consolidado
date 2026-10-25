<?php

namespace Tests\Feature;

use Tests\TestCase;

class ImportarHistoricoTarifarioLaMariaTest extends TestCase
{
    public function test_valid_private_snapshot_can_be_verified_without_creating_a_version(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'siruta-tarifa-');
        try {
            file_put_contents($path, json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
            $this->artisan('la-maria:importar-historico-tarifario', [
                'archivo' => $path, 'version' => 'prueba', '--verificar' => true,
            ])->expectsOutputToContain('1 referencias; no se modificó la base')->assertSuccessful();
        } finally {
            unlink($path);
        }
    }

    public function test_snapshot_with_patient_identifiers_is_rejected_before_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'siruta-tarifa-');
        try {
            $snapshot = $this->snapshot();
            $snapshot['items'][0]['documento'] = 'dato-no-permitido';
            file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
            $this->artisan('la-maria:importar-historico-tarifario', [
                'archivo' => $path, 'version' => 'prueba', '--verificar' => true,
            ])->expectsOutputToContain('clave de identificación personal')->assertFailed();
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return ['version' => 1, 'fuentes' => [['archivo' => 'Informe histórico', 'ruta' => 'PROSTATA']],
            'items' => [['ruta' => 'PROSTATA', 'tipo' => 'AC', 'codigo' => '890701', 'descripcion' => 'Urgencia',
                'valores' => ['2026-08' => [40195.61]], 'composiciones' => [], 'unidades' => []]]];
    }
}
