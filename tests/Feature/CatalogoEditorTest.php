<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edits_actual_note_with_audit_and_stale_write_protection(): void
    {
        $catalog = CatalogoReferencia::create(['programa_slug' => 'la-maria', 'tipo' => 'NOTA_PROSTATA', 'version' => '1', 'archivo_origen' => 'nota.xlsx', 'activo' => true]);
        $item = $catalog->items()->create(['codigo' => 'ABC', 'ruta' => 'PROSTATA', 'descripcion' => 'Original', 'tarifa_referencia' => 10, 'metadatos' => ['frecuencia_mensual' => 2]]);
        $payload = ['descripcion' => 'Actualizado', 'tarifa_referencia' => 25, 'revision' => hash('sha256', json_encode($item->fresh()->getAttributes()))];
        $url = route('catalogos-referencia.items.update', [$catalog, $item->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson($url, $payload)->assertOk();
        $this->assertSame(25.0, $item->fresh()->tarifa_referencia);
        $this->assertSame(['frecuencia_mensual' => 2], $item->fresh()->metadatos);
        $this->assertDatabaseHas('activity_logs', ['action' => 'nota_tecnica_editada']);
        $this->assertDatabaseCount('referencias_manuales', 0);
        $this->patchJson($url, $payload)->assertStatus(409);
        $this->patchJson($url, array_replace($payload, ['tarifa_referencia' => -1]))->assertUnprocessable();
        $this->get(route('catalogos-referencia.show', $catalog))->assertOk()->assertSee('Actualizado');
        $catalog->update(['activo' => false]);
        $this->patchJson($url, $payload)->assertStatus(409);
    }

    public function test_catalog_scope_cannot_edit_another_catalog_item(): void
    {
        $catalog = CatalogoReferencia::create(['programa_slug' => 'la-maria', 'tipo' => 'NOTA_CERVIX', 'version' => '1', 'archivo_origen' => 'nota.xlsx', 'activo' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson(route('catalogos-referencia.items.update', [$catalog, 999]), ['revision' => 'old', 'descripcion' => 'test'])->assertNotFound();
    }
}
