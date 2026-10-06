<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferenciasManualesRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_migration_rebuilds_the_same_schema_when_the_table_is_missing(): void
    {
        Schema::dropIfExists('referencias_manuales');
        $this->assertFalse(Schema::hasTable('referencias_manuales'));

        $migration = require database_path('migrations/2026_09_24_171000_repair_referencias_manuales_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('referencias_manuales'));

        $columns = collect(Schema::getColumns('referencias_manuales'))->keyBy('name');

        $this->assertTrue($columns->has('programa_slug'));
        $this->assertTrue($columns->has('ruta'));
        $this->assertTrue($columns->has('tipo'));
        $this->assertTrue($columns->has('codigo'));
        $this->assertTrue($columns->has('nombre'));
        $this->assertTrue($columns->has('nombre_normalizado'));
        $this->assertTrue($columns->has('tarifa'));
        $this->assertTrue($columns->has('activo'));
        $this->assertTrue($columns->has('created_by'));
        $this->assertTrue($columns->has('updated_by'));

        $this->assertTrue($columns['ruta']['nullable']);
        $this->assertTrue($columns['codigo']['nullable']);
        $this->assertFalse($columns['programa_slug']['nullable']);
        $this->assertFalse($columns['nombre']['nullable']);
        $this->assertFalse($columns['tarifa']['nullable']);

        $tarifaType = strtolower((string) $columns['tarifa']['type']);
        $this->assertTrue(
            str_contains($tarifaType, 'decimal') || str_contains($tarifaType, 'numeric'),
            'tarifa debe ser decimal/numeric'
        );

        if (array_key_exists('type_name', $columns['tarifa']) || isset($columns['tarifa']['type'])) {
            // SQLite may omit precision; MySQL/Postgres expose decimal(20,4).
            if (preg_match('/decimal\((\d+),\s*(\d+)\)/', $tarifaType, $matches) === 1) {
                $this->assertSame('20', $matches[1]);
                $this->assertSame('4', $matches[2]);
            }
        }

        $indexes = collect(Schema::getIndexes('referencias_manuales'));
        $indexColumns = $indexes->map(fn (array $index): string => implode('|', $index['columns']))->all();

        $this->assertContains('programa_slug|ruta|activo', $indexColumns);
        $this->assertTrue(
            $indexes->contains(
                fn (array $index): bool => $index['unique'] === true
                    && $index['columns'] === ['programa_slug', 'ruta', 'codigo']
            )
        );
        $this->assertTrue(
            $indexes->contains(
                fn (array $index): bool => $index['unique'] === true
                    && $index['columns'] === ['programa_slug', 'ruta', 'nombre_normalizado']
            )
        );

        $foreignKeys = collect(Schema::getForeignKeys('referencias_manuales'));
        $this->assertTrue(
            $foreignKeys->contains(
                fn (array $fk): bool => $fk['columns'] === ['created_by'] && $fk['foreign_table'] === 'users'
            )
        );
        $this->assertTrue(
            $foreignKeys->contains(
                fn (array $fk): bool => $fk['columns'] === ['updated_by'] && $fk['foreign_table'] === 'users'
            )
        );
    }

    public function test_repair_migration_does_not_alter_an_existing_table(): void
    {
        $this->assertTrue(Schema::hasTable('referencias_manuales'));

        $before = collect(Schema::getIndexes('referencias_manuales'))
            ->map(fn (array $index): string => implode('|', $index['columns']))
            ->sort()
            ->values()
            ->all();

        $migration = require database_path('migrations/2026_09_24_171000_repair_referencias_manuales_table.php');
        $migration->up();

        $after = collect(Schema::getIndexes('referencias_manuales'))
            ->map(fn (array $index): string => implode('|', $index['columns']))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($before, $after);
    }
}
