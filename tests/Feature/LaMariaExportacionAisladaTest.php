<?php

namespace Tests\Feature;

use App\Http\Controllers\SeguimientoRutaController;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Mockery;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class LaMariaExportacionAisladaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'programas.la-maria.libro_paquetes' => 'POR_LOTE', 'programas.la-maria.historial_hospitalario.modo' => 'DESACTIVADO',
            'programas.la-maria.informes_administrativos' => ['requerido' => false]]);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::statement('CREATE TABLE catalogo_referencias (id INTEGER PRIMARY KEY, programa_slug TEXT, contrato_id INTEGER, tipo TEXT, version TEXT, archivo_origen TEXT, fecha_referencia TEXT, activo INTEGER, imported_by INTEGER, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE catalogo_referencia_items (id INTEGER PRIMARY KEY, catalogo_referencia_id INTEGER, codigo TEXT, hoja TEXT, ruta TEXT, categoria TEXT, descripcion TEXT, tarifa_referencia REAL, activo INTEGER DEFAULT 1, metadatos TEXT, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE referencias_manuales (id INTEGER PRIMARY KEY, programa_slug TEXT, ruta TEXT, tipo TEXT, codigo TEXT, nombre TEXT, tarifa REAL, activo INTEGER)');
        DB::statement('CREATE TABLE programa_ejecuciones (id INTEGER PRIMARY KEY, programa_slug TEXT, contrato_id INTEGER, user_id INTEGER, periodo TEXT, batch_id TEXT, archivo_salida TEXT, catalogos_aplicados TEXT, archivos_procesados INTEGER, created_at TEXT, updated_at TEXT)');
        DB::table('catalogo_referencias')->insert([
            ['id' => 1, 'programa_slug' => 'la-maria', 'tipo' => 'ANEXOS_2706', 'version' => 'aislada', 'archivo_origen' => 'anexos-prueba.xlsx', 'activo' => 1],
            ['id' => 2, 'programa_slug' => 'la-maria', 'tipo' => 'NOTA_PROSTATA', 'version' => 'aislada', 'archivo_origen' => 'nota-prueba.xlsx', 'activo' => 1],
        ]);
        DB::table('catalogo_referencia_items')->insert([
            ['catalogo_referencia_id' => 1, 'codigo' => '890201', 'ruta' => null, 'categoria' => 'ANEXO_2', 'descripcion' => 'Consulta', 'tarifa_referencia' => null, 'activo' => 1],
            ['catalogo_referencia_id' => 2, 'codigo' => '890201', 'ruta' => 'PROSTATA', 'categoria' => 'SERVICIO_TECNICO', 'descripcion' => 'Consulta', 'tarifa_referencia' => 5000, 'activo' => 1],
        ]);
    }

    public function test_controlador_exporta_con_y_sin_fuente_sin_cambiar_el_central(): void
    {
        $sinInforme = $this->exportar(false);
        $conInforme = $this->exportar(true);
        $nombres = ['CONCILIACION ADMIN', 'DETALLE POR CODIGO', 'QUIMIOTERAPIA', 'PENDIENTES ADMIN', 'FUENTES Y CONTROL'];
        foreach ($nombres as $nombre) {
            $this->assertArrayHasKey($nombre, $sinInforme);
            $this->assertArrayHasKey($nombre, $conInforme);
        }
        $this->assertSame($sinInforme['SEGUIMIENTO MENSUAL'], $conInforme['SEGUIMIENTO MENSUAL']);
        $this->assertSame('NO DISPONIBLE: INFORME INDIVIDUAL DEL PERIODO', $sinInforme['CONCILIACION ADMIN'][1][8]);
        $ac = array_values(array_filter($conInforme['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AC'))[0];
        $this->assertSame(5000, $ac[3]);
        $this->assertSame(5000, $ac[4]);
        $this->assertSame(0, $ac[7]);
        $this->assertGreaterThan(1, count($conInforme['SEGUIMIENTO MENSUAL']));
        $this->assertSame(2, DB::table('programa_ejecuciones')->count());
    }

    public function test_lote_documental_desde_controlador_con_catalogos_en_memoria(): void
    {
        if (getenv('LA_MARIA_LOTE_AISLADO') !== '1') {
            $this->markTestSkipped('Requiere ejecutar explícitamente el lote documental local aislado.');
        }
        $directorioInformes = getenv('LA_MARIA_INFORMES_PRUEBA_DIR');
        if (! is_string($directorioInformes) || $directorioInformes === '') {
            $this->markTestSkipped('Configure LA_MARIA_INFORMES_PRUEBA_DIR con los informes privados de prueba.');
        }
        ini_set('memory_limit', '4G');
        set_time_limit(0);
        DB::table('catalogo_referencia_items')->delete();
        DB::table('catalogo_referencias')->delete();
        $activos = json_decode((string) file_get_contents(base_path('outputs/auditoria-la-maria-codex-20261008/active_catalogs.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($activos as $catalogo) {
            if (in_array($catalogo['tipo'], ['ANEXOS_2706', 'NOTA_MEDICAMENTOS'], true)) {
                $this->cargarCatalogo($catalogo['tipo'], $catalogo['version'], basename($catalogo['archivo_origen']), $catalogo['items']);
            }
        }
        unset($activos);
        $manifest = json_decode((string) file_get_contents(base_path('outputs/cierre-documental-la-maria-20261008/CANDIDATAS_MANIFEST.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['MEDICAMENTOS_CUM', 'DISPENSACION_PROSTATA', 'DISPENSACION_CERVIX', 'NOTA_PROSTATA', 'NOTA_CERVIX'] as $tipo) {
            $entrada = $manifest[$tipo];
            $this->assertSame($entrada['source_sha256'], hash_file('sha256', $entrada['archivo_origen']));
            $path = base_path('outputs/cierre-documental-la-maria-20261008/'.$tipo.'_CANDIDATA.jsonl');
            $items = (static function () use ($path): \Generator {
                $file = new \SplFileObject($path);
                foreach ($file as $linea) {
                    if (trim($linea) !== '') {
                        yield json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            })();
            $this->cargarCatalogo($tipo, $entrada['version'], basename($entrada['archivo_origen']), $items);
        }
        $archivos = glob(base_path('outputs/auditoria-forense-20261028/copias-json/FEHM*.json'));
        $this->assertCount(16, $archivos);
        $rips = array_map(static fn (string $path): UploadedFile => new UploadedFile($path, basename($path), 'application/json', null, true), $archivos);
        $informes = glob(rtrim($directorioInformes, '/\\').'/*.xlsx');
        $this->assertCount(2, $informes);
        $uploads = array_map(static fn (string $path): UploadedFile => new UploadedFile($path, basename($path), null, null, true), $informes);
        $request = Request::create('/la-maria/consolidado/export', 'POST', [], [], ['archivos' => $rips, 'informes_administrativos' => $uploads]);
        $request->setLaravelSession(new Store('lote_aislado', new ArraySessionHandler(60)));
        $request->setUserResolver(static fn () => null);
        $logger = Mockery::mock(ActivityLogger::class);
        $logger->shouldReceive('log')->once()->andReturn(new ActivityLog);
        $response = (new SeguimientoRutaController)->exportConsolidated($request, new SeguimientoRutaClassifier, $logger);
        $archivo = $response->getFile()->getPathname();
        if (getenv('LA_MARIA_LOTE_SALIDA')) {
            copy($archivo, getenv('LA_MARIA_LOTE_SALIDA'));
        }
        $reader = new Reader;
        $reader->open($archivo);
        $resumen = [];
        $candidatas = 0;
        $solapamientos = [];
        $hojas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            $nombre = $hoja->getName();
            $hojas[] = $nombre;
            foreach ($hoja->getRowIterator() as $fila) {
                $celdas = $fila->toArray();
                if ($nombre === 'FUENTES Y CONTROL' && ($celdas[3] ?? '') === '2026-09' && str_starts_with((string) ($celdas[6] ?? ''), 'RIPS=')) {
                    $resumen[$celdas[2]] = $celdas[4];
                }
                if ($nombre === 'QUIMIOTERAPIA' && ($celdas[0] ?? '') === 'PROSTATA' && ($celdas[1] ?? '') === '2026-09'
                    && in_array((string) ($celdas[5] ?? ''), ['992505', '992511'], true) && ($celdas[9] ?? '') === 'CANDIDATA MENSUAL; NO CONFIRMADA') {
                    $candidatas++;
                }
                if ($nombre === 'PENDIENTES ADMIN' && ($celdas[1] ?? '') === '2026-09' && str_contains((string) ($celdas[5] ?? ''), 'POSIBLE SOLAPAMIENTO AH')) {
                    $solapamientos[$celdas[0]] = ($solapamientos[$celdas[0]] ?? 0) + 1;
                }
            }
        }
        $reader->close();
        unlink($archivo);
        $this->assertSame(['PROSTATA' => 11360, 'CERVIX' => 6954], $resumen);
        $this->assertSame(190, $candidatas);
        $this->assertSame(['PROSTATA' => 54, 'CERVIX' => 60], $solapamientos);
        foreach (['SEGUIMIENTO MENSUAL', 'PUENTE ECONOMICO', 'CONCILIACION ADMIN', 'DETALLE POR CODIGO', 'QUIMIOTERAPIA', 'PENDIENTES ADMIN', 'FUENTES Y CONTROL'] as $nombre) {
            $this->assertContains($nombre, $hojas);
        }
    }

    /** @param iterable<array<string, mixed>> $items */
    private function cargarCatalogo(string $tipo, string $version, string $archivo, iterable $items): void
    {
        $id = DB::table('catalogo_referencias')->insertGetId(['programa_slug' => 'la-maria', 'tipo' => $tipo, 'version' => $version, 'archivo_origen' => $archivo, 'activo' => 1]);
        $lote = [];
        foreach ($items as $item) {
            $metadata = $item['metadatos'] ?? null;
            $lote[] = ['catalogo_referencia_id' => $id, 'codigo' => (string) ($item['codigo'] ?? ''), 'hoja' => $item['hoja'] ?? '',
                'ruta' => $item['ruta'] ?? null, 'categoria' => $item['categoria'] ?? '', 'descripcion' => $item['descripcion'] ?? '',
                'tarifa_referencia' => $item['tarifa_referencia'] ?? null, 'activo' => (int) ($item['activo'] ?? true),
                'metadatos' => is_array($metadata) ? json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $metadata];
            if (count($lote) === 100) {
                DB::table('catalogo_referencia_items')->insert($lote);
                $lote = [];
            }
        }
        if ($lote !== []) {
            DB::table('catalogo_referencia_items')->insert($lote);
        }
    }

    /** @return array<string, list<list<mixed>>> */
    private function exportar(bool $conInforme): array
    {
        $rips = ['numFactura' => 'FAC-001', 'usuarios' => [[
            'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456', 'codSexo' => 'M',
            'fechaNacimiento' => '1980-05-12', 'servicios' => ['consultas' => [[
                'codPrestador' => '9000000102', 'codConsulta' => '890201', 'codDiagnosticoPrincipal' => 'C61X',
                'fechaInicioAtencion' => '2026-09-01 09:00', 'consecutivo' => 1,
            ]]],
        ]]];
        $json = UploadedFile::fake()->createWithContent('rips.json', json_encode($rips, JSON_THROW_ON_ERROR));
        $files = ['archivos' => [$json]];
        $administrativo = null;
        if ($conInforme) {
            $archivo = tempnam(sys_get_temp_dir(), 'administrativo_');
            $writer = new Writer;
            $writer->openToFile($archivo);
            $writer->getCurrentSheet()->setName('inf admin');
            $writer->addRow(Row::fromValues(['DOCUMENTO', 'CUPS', 'DIACODIGO', 'FECHA_PRESTACION', 'CLASIFICACIÓN', 'VALOR']));
            $writer->addRow(Row::fromValues(['123456', '890201', 'C61X', '2026-09-01', 'AC', 5000]));
            $writer->close();
            $administrativo = new UploadedFile($archivo, 'administrativo.xlsx', null, null, true);
            $files['informes_administrativos'] = [$administrativo];
        }
        $request = Request::create('/la-maria/consolidado/export', 'POST', [], [], $files);
        $request->setLaravelSession(new Store('aislada', new ArraySessionHandler(60)));
        $request->setUserResolver(static fn () => null);
        $logger = Mockery::mock(ActivityLogger::class);
        $logger->shouldReceive('log')->once()->andReturn(new ActivityLog);
        $response = (new SeguimientoRutaController)->exportConsolidated($request, new SeguimientoRutaClassifier, $logger);
        $archivoSalida = $response->getFile()->getPathname();
        if ($conInforme && getenv('LA_MARIA_PRUEBA_SALIDA')) {
            copy($archivoSalida, getenv('LA_MARIA_PRUEBA_SALIDA'));
        }
        $reader = new Reader;
        $reader->open($archivoSalida);
        $hojas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $hojas[$hoja->getName()][] = $fila->toArray();
            }
        }
        $reader->close();
        unlink($archivoSalida);
        if ($administrativo !== null) {
            unlink($administrativo->getPathname());
        }

        return $hojas;
    }
}
