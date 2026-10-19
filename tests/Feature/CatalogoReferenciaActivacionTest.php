<?php

namespace Tests\Feature;

use App\Exceptions\ActivacionCatalogoException;
use App\Models\ActivityLog;
use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Services\CatalogoReferenciaActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaActivacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // En MySQL solo contra la base independiente de pruebas (nunca la habitual).
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS) {
            $this->markTestSkipped('Las pruebas MySQL de activación solo corren en '.ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS.'.');
        }

        parent::setUp();
    }

    public function test_activation_leaves_a_single_active_version_per_type_and_audits_both_sets(): void
    {
        $old = $this->catalog('DISPENSACION_CERVIX', 'v1', true);
        $new = $this->catalog('DISPENSACION_CERVIX', 'v2', false);
        $otherRoute = $this->catalog('DISPENSACION_PROSTATA', 'v1', true);

        $log = app(CatalogoReferenciaActivator::class)->activar($new, [$old->id]);

        $this->assertSame([$new->id], $this->activeIds('DISPENSACION_CERVIX'));
        $this->assertTrue($otherRoute->fresh()->activo);
        $this->assertSame(CatalogoReferenciaActivator::ACCION_ACTIVACION, $log->action);
        $this->assertSame([$old->id], $log->metadata['activos_anteriores']);
        $this->assertSame([$new->id], $log->metadata['activos_nuevos']);
    }

    public function test_two_administrators_activating_different_versions_at_the_same_time_cannot_both_win(): void
    {
        $current = $this->catalog('NOTA_PROSTATA', 'actual', true);
        $first = $this->catalog('NOTA_PROSTATA', 'propuesta-1', false);
        $second = $this->catalog('NOTA_PROSTATA', 'propuesta-2', false);
        $seenByBoth = (string) $current->id;
        [$adminA, $adminB] = User::factory()->count(2)->create(['role' => 'admin']);

        $this->actingAs($adminA)
            ->post(route('catalogos-referencia.toggle-file', $first), ['activos_esperados' => $seenByBoth])
            ->assertSessionHasNoErrors();
        $this->actingAs($adminB)
            ->post(route('catalogos-referencia.toggle-file', $second), ['activos_esperados' => $seenByBoth])
            ->assertSessionHasErrors('catalogo');

        $this->assertSame([$first->id], $this->activeIds('NOTA_PROSTATA'));
        $this->assertSame(1, ActivityLog::query()->where('action', CatalogoReferenciaActivator::ACCION_ACTIVACION)->count());
    }

    public function test_rollback_restores_exactly_the_previous_set_of_active_versions(): void
    {
        $legacyA = $this->catalog('NOTA_CERVIX', 'legado-a', true);
        $legacyB = $this->catalog('NOTA_CERVIX', 'legado-b', true);
        $new = $this->catalog('NOTA_CERVIX', 'nueva', false);
        $activator = app(CatalogoReferenciaActivator::class);

        $activation = $activator->activar($new, [$legacyA->id, $legacyB->id]);
        $this->assertSame([$new->id], $this->activeIds('NOTA_CERVIX'));

        $this->artisan('catalogo:revertir-operacion', ['operacion' => $activation->id])->assertSuccessful();

        $this->assertSame([$legacyA->id, $legacyB->id], $this->activeIds('NOTA_CERVIX'));
        $this->expectException(ActivacionCatalogoException::class);
        $activator->revertir($activation);
    }

    public function test_rollback_is_refused_when_the_active_versions_changed_afterwards(): void
    {
        $v1 = $this->catalog('NOTA_PROSTATA', 'v1', true);
        $v2 = $this->catalog('NOTA_PROSTATA', 'v2', false);
        $v3 = $this->catalog('NOTA_PROSTATA', 'v3', false);
        $activator = app(CatalogoReferenciaActivator::class);
        $first = $activator->activar($v2, [$v1->id]);
        $activator->activar($v3, [$v2->id]);

        try {
            $activator->revertir($first);
            $this->fail('La reversión debía rechazarse.');
        } catch (ActivacionCatalogoException $exception) {
            $this->assertTrue($exception->esConflicto);
        }

        $this->assertSame([$v3->id], $this->activeIds('NOTA_PROSTATA'));
    }

    public function test_a_version_that_fails_verification_is_not_activated_and_nothing_changes(): void
    {
        $current = $this->catalog('DISPENSACION_CERVIX', 'actual', true);
        $broken = $this->catalog('DISPENSACION_CERVIX', 'fechas-corridas', false, [
            ['codigo' => '20021507-1', 'metadatos' => ['fecha_dispensacion' => '0162-06-01']],
        ]);
        $cumWithoutIum = $this->catalog('MEDICAMENTOS_CUM', 'sin-ium', false, [['codigo' => '20021507-1', 'metadatos' => ['principio_activo' => 'X']]]);
        $complement = $this->catalog('COMPLEMENTO_MEDICAMENTOS_PROSTATA', 'sin-evidencia', false, [
            ['codigo' => '20178975-1', 'metadatos' => ['estado_contractual' => 'VERIFICADO', 'evidencia_contractual' => '', 'vigencia_desde' => '']],
        ]);
        $empty = $this->catalog('NOTA_PROSTATA', 'vacia', false, []);
        $activator = app(CatalogoReferenciaActivator::class);

        foreach ([[$broken, 'FECHA_DISPENSACION_INVALIDA', [$current->id]], [$cumWithoutIum, 'CUM_SIN_IUM', []], [$complement, 'VERIFICADO_SIN_EVIDENCIA_O_VIGENCIA', []], [$empty, 'IMPORTACION_SIN_REGISTROS', []]] as [$catalog, $reason, $expected]) {
            try {
                $activator->activar($catalog, $expected);
                $this->fail('La activación debía rechazarse: '.$reason);
            } catch (ActivacionCatalogoException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame([$current->id], $this->activeIds('DISPENSACION_CERVIX'));
        $this->assertSame([], $this->activeIds('MEDICAMENTOS_CUM'));
        $this->assertSame(0, ActivityLog::query()->count());
    }

    public function test_the_screen_shows_inactive_uploads_with_their_validations_and_lets_the_admin_activate_and_revert(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $current = $this->catalog('NOTA_PROSTATA', 'vigente', true);

        $this->actingAs($admin)->post(route('catalogos-referencia.store'), [
            'tipo' => 'NOTA_PROSTATA', 'version' => 'oficial', 'archivo' => $this->noteWorkbook(),
        ])->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'INACTIVA'));
        $uploaded = CatalogoReferencia::query()->where('version', 'oficial')->firstOrFail();

        $this->actingAs($admin)->get(route('catalogos-referencia.index'))
            ->assertOk()
            ->assertSeeInOrder(['oficial', 'nota.xlsx', '2', 'VERIFICADO', 'INACTIVO', 'Activar archivo'])
            ->assertSee('SIN VERIFICACIÓN REGISTRADA')
            ->assertDontSee('Revertir última operación');

        $this->actingAs($admin)->post(route('catalogos-referencia.toggle-file', $uploaded), ['activos_esperados' => (string) $current->id])->assertSessionHasNoErrors();
        $operation = ActivityLog::query()->where('action', CatalogoReferenciaActivator::ACCION_ACTIVACION)->firstOrFail();

        $this->actingAs($admin)->get(route('catalogos-referencia.index'))->assertSee('Revertir última operación');
        $this->actingAs($admin)->post(route('catalogos-referencia.operaciones.revertir', $operation))->assertSessionHasNoErrors();

        $this->assertSame([$current->id], $this->activeIds('NOTA_PROSTATA'));
        $this->actingAs($admin)->get(route('catalogos-referencia.index'))->assertDontSee('Revertir última operación');
    }

    public function test_note_with_one_code_and_two_tariffs_is_not_activated(): void
    {
        $note = $this->catalog('NOTA_PROSTATA', 'duplicada', false, [
            ['codigo' => '890201', 'categoria' => 'SERVICIO_TECNICO', 'tarifa_referencia' => 1000],
            ['codigo' => '890201', 'categoria' => 'SERVICIO_TECNICO', 'tarifa_referencia' => 1200],
        ]);

        $this->expectExceptionMessage('CODIGO_CON_VARIAS_TARIFAS');
        app(CatalogoReferenciaActivator::class)->activar($note, []);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     */
    private function catalog(string $type, string $version, bool $active, ?array $items = null): CatalogoReferencia
    {
        $catalog = CatalogoReferencia::create([
            'programa_slug' => 'la-maria', 'tipo' => $type, 'version' => $version, 'archivo_origen' => $version.'.xlsx', 'activo' => $active,
        ]);
        $items ??= match ($type) {
            'DISPENSACION_CERVIX', 'DISPENSACION_PROSTATA' => [['codigo' => '20021507-1', 'metadatos' => ['fecha_dispensacion' => '2026-06-01']]],
            default => [['codigo' => '890201', 'categoria' => 'SERVICIO_TECNICO', 'tarifa_referencia' => 1000]],
        };

        foreach ($items as $item) {
            CatalogoReferenciaItem::create(['catalogo_referencia_id' => $catalog->id, 'ruta' => 'CERVIX', 'activo' => true, ...$item]);
        }

        return $catalog;
    }

    private function noteWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('servicios y tecnologías')->fromArray([
            ['AGRUPADOR', 'CUPS', 'Servicio', 'NOMBRE DEL CUPS', 'Tarifa'],
            ['QUIRÚRGICO', '601101', 'Procedimientos', 'BIOPSIA CERRADA DE PROSTATA', 250000],
            ['LABORATORIO CLÍNICO', '902210', 'Laboratorio Clínico', 'HEMOGRAMA IV', 19011.87],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'nota-');
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('nota.xlsx', $content, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * @return list<int>
     */
    private function activeIds(string $type): array
    {
        return CatalogoReferencia::query()->where('tipo', $type)->where('activo', true)->orderBy('id')->pluck('id')->all();
    }
}
