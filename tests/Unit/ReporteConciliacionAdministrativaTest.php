<?php

namespace Tests\Unit;

use App\Programas\LaMaria\HistorialAdministrativo;
use App\Programas\LaMaria\ReporteConciliacionAdministrativa;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

class ReporteConciliacionAdministrativaTest extends TestCase
{
    public function test_diferencias_por_codigo_incluyen_saldos_no_comparables_sin_valor_cero(): void
    {
        $base = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'paciente' => 'anon', 'fecha' => '2026-09-02'];
        $admin = [[...$base, 'codigo' => 'A', 'centavos' => 10000, 'comparable' => true, 'fuente' => 'fila A'],
            [...$base, 'codigo' => 'B', 'centavos' => 5000, 'comparable' => true, 'fuente' => 'fila B']];
        $rips = [[...$base, 'codigo' => 'A', 'cantidad' => 1, 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 120, 'fuente' => 'NOTA_TECNICA']],
            [...$base, 'codigo' => 'C', 'cantidad' => 1, 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 20, 'fuente' => 'NOTA_TECNICA']]];

        $hojas = $this->exportar($admin, $rips);
        $porCodigo = [];
        foreach (array_slice($hojas['DETALLE POR CODIGO'], 1) as $fila) {
            $porCodigo[$fila[3]] = $fila;
        }
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(-20, $porCodigo['A'][10]);
        $this->assertSame(-20, $porCodigo['A'][13]);
        $this->assertSame('', $porCodigo['B'][9]);
        $this->assertSame(50, $porCodigo['B'][14]);
        $this->assertSame(-20, $porCodigo['C'][14]);
        $this->assertSame($ap[7], $porCodigo['A'][10] + $porCodigo['B'][10] + $porCodigo['C'][10]);
    }

    public function test_rn05_no_suma_repeticion_identica_y_conserva_linea_distinta(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '601101', 100, '', '', '2026-08-01', 'otra');
        $reporte = new ReporteConciliacionAdministrativa;
        $base = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'codigo' => '601101',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 100, 'fuenteTarifa' => 'NOTA_TECNICA',
            'servicioJson' => ['codProcedimiento' => '601101', 'fechaInicioAtencion' => '2026-09-02 08:00', 'consecutivo' => 1]];
        $segunda = $base;
        $segunda['servicioJson']['consecutivo'] = 2;
        $distinta = $base;
        $distinta['servicioJson']['codPrestador'] = 'OTRO';
        $rips = $this->normalizarConFirmas($reporte, $historial, [$base, $segunda, $distinta]);
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '601101', 'paciente' => $historial->clavePaciente('123456'),
            'fecha' => '2026-09-02', 'centavos' => 10000, 'comparable' => true, 'fuente' => 'informe!fila 1']];

        $hojas = $this->exportar($admin, $rips, $historial);
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(200, $ap[4]);
        $this->assertSame(100, $ap[9]);
        $this->assertSame('RN-05 REPETICION IDENTICA NO ADITIVA; UNIDAD HISTORICA', $hojas['PENDIENTES ADMIN'][1][5]);
        $this->assertSame(300, $hojas['DETALLE POR CODIGO'][1][17]);
        $this->assertSame(100, $hojas['DETALLE POR CODIGO'][1][18]);
    }

    public function test_rn05_conserva_dos_lineas_identicas_si_dos_unidades_estan_documentadas(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '623001', 100, '', '', '2026-08-01', 'otra');
        $reporte = new ReporteConciliacionAdministrativa;
        $base = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'codigo' => '623001',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 100, 'fuenteTarifa' => 'NOTA_TECNICA',
            'servicioJson' => ['codProcedimiento' => '623001', 'consecutivo' => 1]];
        $segunda = $base;
        $segunda['servicioJson']['consecutivo'] = 2;
        $rips = $this->normalizarConFirmas($reporte, $historial, [$base, $segunda]);
        $fila = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '623001', 'paciente' => $historial->clavePaciente('123456'),
            'fecha' => '2026-09-02', 'centavos' => 10000, 'comparable' => true];
        $admin = [[...$fila, 'fuente' => 'fila 1'], [...$fila, 'fuente' => 'fila 2']];

        $hojas = $this->exportar($admin, $rips, $historial);
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(200, $ap[4]);
        $this->assertSame(0, $ap[9]);
    }

    public function test_rn05_no_descarta_una_linea_sin_unidad_historica(): void
    {
        $historial = new HistorialAdministrativo;
        $reporte = new ReporteConciliacionAdministrativa;
        $base = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'codigo' => '623001',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 100, 'fuenteTarifa' => 'NOTA_TECNICA',
            'servicioJson' => ['codProcedimiento' => '623001', 'consecutivo' => 1]];
        $segunda = $base;
        $segunda['servicioJson']['consecutivo'] = 2;
        $rips = $this->normalizarConFirmas($reporte, $historial, [$base, $segunda]);
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '623001', 'paciente' => $historial->clavePaciente('123456'),
            'fecha' => '2026-09-02', 'centavos' => 10000, 'comparable' => true, 'fuente' => 'fila 1']];

        $hojas = $this->exportar($admin, $rips, $historial);
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(200, $ap[4]);
        $this->assertSame(0, $ap[9]);
    }

    public function test_rn05_no_descarta_lineas_con_campos_anidados_distintos(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '601101', 100, '', '', '2026-08-01', 'otra');
        $reporte = new ReporteConciliacionAdministrativa;
        $base = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'codigo' => '601101',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 100, 'fuenteTarifa' => 'NOTA_TECNICA',
            'servicioJson' => ['codProcedimiento' => '601101', 'datosAdicionales' => ['lado' => 'derecho']]];
        $distinta = $base;
        $distinta['servicioJson']['datosAdicionales']['lado'] = 'izquierdo';
        $rips = $this->normalizarConFirmas($reporte, $historial, [$base, $distinta]);
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '601101', 'paciente' => $historial->clavePaciente('123456'),
            'fecha' => '2026-09-02', 'centavos' => 10000, 'comparable' => true, 'fuente' => 'fila 1']];

        $hojas = $this->exportar($admin, $rips, $historial);
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(200, $ap[4]);
        $this->assertSame(0, $ap[9]);
    }

    public function test_firma_ambigua_no_se_asigna_a_ninguna_linea(): void
    {
        $historial = new HistorialAdministrativo;
        $reporte = new ReporteConciliacionAdministrativa;
        $servicio = ['codProcedimiento' => '601101', 'fechaInicioAtencion' => '2026-09-02 08:00', 'consecutivo' => 1];
        $detalle = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'archivoOrigen' => 'prueba.json',
            'codigo' => '601101', 'consecutivoRips' => '1', 'cantidadAplicada' => 1, 'servicioJson' => $servicio];
        $linea = $reporte->normalizar('PROSTATA', $detalle, $historial);
        $archivo = tempnam(sys_get_temp_dir(), 'rips_');
        file_put_contents($archivo, json_encode(['usuarios' => [['tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'servicios' => ['procedimientos' => [$servicio, $servicio]]]]], JSON_THROW_ON_ERROR));
        try {
            $rips = $reporte->incorporarFirmasCompletas([$linea, $linea], [['path' => $archivo, 'fileName' => 'prueba.json']], $historial);
        } finally {
            unlink($archivo);
        }

        $this->assertNull($rips[0]['firma_repeticion']);
        $this->assertNull($rips[1]['firma_repeticion']);
    }

    public function test_libro_del_lote_real_reconstruye_subtotales_y_controles(): void
    {
        $archivo = getenv('LA_MARIA_LIBRO_VALIDACION');
        if (! is_string($archivo) || ! is_file($archivo)) {
            $this->markTestSkipped('Requiere el libro del lote aislado previamente generado.');
        }
        $reader = new Reader;
        $reader->open($archivo);
        $categorias = [];
        $porCodigo = [];
        $brechasCategoria = [];
        $brechasCodigo = [];
        $reducciones = [];
        $administrativosUsados = [];
        $rn08 = [];
        $rips = [];
        $candidatas = 0;
        $solapamientos = [];
        $pendientes405407 = 0;
        $repeticiones = [];
        $indicesRepetidos = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            $nombre = $hoja->getName();
            foreach ($hoja->getRowIterator() as $fila) {
                $r = $fila->toArray();
                if ($nombre === 'CONCILIACION ADMIN' && ($r[1] ?? '') === '2026-09' && is_numeric($r[4] ?? null)) {
                    $categorias[$r[0].'|'.$r[2]] = (int) round($r[4] * 100);
                }
                if ($nombre === 'CONCILIACION ADMIN' && ($r[1] ?? '') === '2026-09' && is_numeric($r[7] ?? null)) {
                    $brechasCategoria[$r[0].'|'.$r[2]] = (int) round($r[7] * 100);
                }
                if ($nombre === 'DETALLE POR CODIGO' && ($r[1] ?? '') === '2026-09' && is_numeric($r[9] ?? null)) {
                    $clave = $r[0].'|'.$r[2];
                    $porCodigo[$clave] = ($porCodigo[$clave] ?? 0) + (int) round($r[9] * 100);
                }
                if ($nombre === 'DETALLE POR CODIGO' && ($r[1] ?? '') === '2026-09' && is_numeric($r[10] ?? null)) {
                    $clave = $r[0].'|'.$r[2];
                    $brecha = (int) round($r[10] * 100);
                    $brechasCodigo[$clave] = ($brechasCodigo[$clave] ?? 0) + $brecha;
                    $partes = 0;
                    foreach ([12, 13, 14] as $columna) {
                        if (is_numeric($r[$columna] ?? null)) {
                            $partes += (int) round($r[$columna] * 100);
                        }
                    }
                    $this->assertSame($brecha, $partes, 'La diferencia por código no está descompuesta.');
                }
                if ($nombre === 'DETALLE POR CODIGO' && ($r[1] ?? '') === '2026-09' && is_numeric($r[17] ?? null)) {
                    $antes = (int) round($r[17] * 100);
                    $reduccion = (int) round(($r[18] ?? 0) * 100);
                    $this->assertSame($antes - $reduccion, (int) round($r[9] * 100), 'El antes y después RN-05 no concilia.');
                    $reducciones[$r[0]] = ($reducciones[$r[0]] ?? 0) + $reduccion;
                }
                if ($nombre === 'QUIMIOTERAPIA' && ($r[1] ?? '') === '2026-09') {
                    if (($r[10] ?? '') !== '') {
                        $this->assertArrayNotHasKey($r[10], $administrativosUsados, 'Una fila administrativa se reutilizó.');
                        $administrativosUsados[$r[10]] = true;
                    }
                    if (($r[0] ?? '') === 'PROSTATA' && in_array((string) ($r[5] ?? ''), ['992505', '992511'], true) && ($r[9] ?? '') === 'CANDIDATA MENSUAL; NO CONFIRMADA') {
                        $candidatas++;
                    }
                }
                if ($nombre === 'PENDIENTES ADMIN' && ($r[1] ?? '') === '2026-09') {
                    if (str_contains((string) ($r[5] ?? ''), 'POSIBLE SOLAPAMIENTO AH')) {
                        $solapamientos[$r[0]] = ($solapamientos[$r[0]] ?? 0) + 1;
                    }
                    if (($r[0] ?? '') === 'PROSTATA' && (string) ($r[3] ?? '') === '405407' && ($r[5] ?? '') === 'NO DETERMINABLE: TARIFA O CANTIDAD') {
                        $pendientes405407++;
                    }
                    if (($r[5] ?? '') === 'RN-08 REFERENCIA POR REGISTRO; UNIDAD CONTRACTUAL PENDIENTE') {
                        $rn08[$r[0]]['cantidad'] = ($rn08[$r[0]]['cantidad'] ?? 0) + 1;
                        $rn08[$r[0]]['centavos'] = ($rn08[$r[0]]['centavos'] ?? 0) + (int) round($r[6] * 100);
                    }
                    if (str_starts_with((string) ($r[5] ?? ''), 'RN-05 REPETICION IDENTICA NO ADITIVA')) {
                        $clave = $r[0].'|'.$r[1].'|'.$r[4];
                        $this->assertArrayNotHasKey($clave, $indicesRepetidos, 'Una línea repetida se utilizó dos veces.');
                        $indicesRepetidos[$clave] = true;
                        $claveCodigo = $r[0].'|'.$r[3];
                        $repeticiones[$claveCodigo] = ($repeticiones[$claveCodigo] ?? 0) + 1;
                    }
                }
                if ($nombre === 'FUENTES Y CONTROL' && ($r[3] ?? '') === '2026-09' && str_starts_with((string) ($r[6] ?? ''), 'RIPS=')) {
                    $rips[$r[2]] = (int) $r[4];
                }
            }
        }
        $reader->close();

        ksort($categorias);
        ksort($porCodigo);
        ksort($brechasCategoria);
        ksort($brechasCodigo);
        $this->assertSame($categorias, $porCodigo);
        $this->assertSame($brechasCategoria, $brechasCodigo);
        $this->assertSame(['PROSTATA' => 11360, 'CERVIX' => 6954], $rips);
        $this->assertSame(190, $candidatas);
        $this->assertSame(['PROSTATA' => 54, 'CERVIX' => 60], $solapamientos);
        $this->assertSame(24, $pendientes405407);
        $this->assertSame(75, $repeticiones['PROSTATA|601101']);
        $this->assertSame(23, $repeticiones['PROSTATA|623001']);
        $reduccionesEsperadas = getenv('LA_MARIA_EXCEPCION_AH') === '1'
            ? ['PROSTATA' => 12782055706, 'CERVIX' => 1344466821]
            : ['PROSTATA' => 12538325706, 'CERVIX' => 634705821];
        $this->assertSame($reduccionesEsperadas, $reducciones);
        $this->assertSame(['PROSTATA' => ['cantidad' => 1, 'centavos' => 885297600], 'CERVIX' => ['cantidad' => 13, 'centavos' => 9691879880]], $rn08);
    }

    public function test_normaliza_segmentos_del_controlador_sin_clave_interna_del_clasificador(): void
    {
        $historial = new HistorialAdministrativo;
        $detalle = ['tipoServicio' => 'hospitalizacion', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 08:00', 'factura' => 'F', 'archivoOrigen' => 'rips.json',
            'servicioJson' => ['fechaEgreso' => '2026-09-03 09:00', 'condicionDestinoUsuarioEgreso' => '01']];
        $reporte = new ReporteConciliacionAdministrativa;

        $primero = $reporte->normalizar('PROSTATA', $detalle, $historial);
        $segundo = $reporte->normalizar('PROSTATA', $detalle, $historial);

        $this->assertFalse($primero['hospital_segmento']['excluido']);
        $this->assertSame('CC|'.$historial->clavePaciente('123456'), $primero['hospital_segmento']['paciente']);
        $this->assertNotSame($primero['hospital_segmento']['clave'], $segundo['hospital_segmento']['clave']);
        $this->assertStringNotContainsString('123456', serialize($primero));
    }

    public function test_radioterapia_usa_solo_una_fila_unica_de_la_misma_fecha_como_referencia_condicionada(): void
    {
        $base = ['ruta' => 'CERVIX', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '922444'];
        $admin = [
            [...$base, 'paciente' => 'a', 'fecha' => '2026-09-01', 'centavos' => 50000, 'comparable' => true, 'fuente' => 'hoja!fila 1'],
            [...$base, 'paciente' => 'b', 'fecha' => '2026-09-01', 'centavos' => 60000, 'comparable' => true, 'fuente' => 'hoja!fila 2'],
        ];
        $sinTarifa = ['cantidad' => 1, 'paquete' => '', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 0, 'fuente' => 'NINGUNA']];
        $rips = [
            [...$base, ...$sinTarifa, 'paciente' => 'a', 'fecha' => '2026-09-01'],
            [...$base, ...$sinTarifa, 'paciente' => 'b', 'fecha' => '2026-09-02'],
        ];

        $hojas = $this->exportar($admin, $rips);
        $ap = array_values(array_filter($hojas['CONCILIACION ADMIN'], static fn (array $r): bool => ($r[2] ?? '') === 'AP'))[0];

        $this->assertSame(500, $ap[4]);
        $this->assertSame(500, $ap[5]);
        $this->assertSame(1, $ap[6]);
        $this->assertSame('RN-08 REFERENCIA POR REGISTRO; UNIDAD CONTRACTUAL PENDIENTE', $hojas['PENDIENTES ADMIN'][1][5]);
    }

    public function test_lectura_opcional_conserva_fila_y_huella_sin_documento(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'admin_');
        $writer = new Writer;
        $writer->openToFile($archivo);
        $writer->getCurrentSheet()->setName('inf admin');
        $writer->addRow(Row::fromValues(['DOCUMENTO', 'CUPS', 'DIACODIGO', 'FECHA_PRESTACION', 'CLASIFICACIÓN', 'VALOR']));
        $writer->addRow(Row::fromValues(['', '', '', '', '', '']));
        $writer->addRow(Row::fromValues(['123456', '992511', 'C61', '2026-09-02', 'AP', 1000]));
        $writer->addNewSheetAndMakeItCurrent()->setName('ESCENARIOS');
        $writer->addRow(Row::fromValues(['Escenario 1']));
        $writer->addRow(Row::fromValues(['', 'NT mes', 'SEPTIEMBRE']));
        $writer->addRow(Row::fromValues(['Medicamentos', '', 250.25]));
        $writer->close();
        $huella = hash_file('sha256', $archivo);
        $historial = new HistorialAdministrativo;
        $historial->habilitarFilasIndividuales();
        $historial->cargarArchivo($archivo, 'informe.xlsx');
        unlink($archivo);

        $fila = $historial->filasIndividuales()[0];
        $this->assertSame('informe.xlsx!inf admin!fila 3', $fila['fuente']);
        $this->assertSame($huella, $fila['sha256']);
        $this->assertSame($historial->clavePaciente('123456'), $fila['paciente']);
        $this->assertStringNotContainsString('123456', serialize($fila));
        $agregado = $historial->filasIndividuales()[1];
        $this->assertSame('informe.xlsx!ESCENARIOS!C3', $agregado['fuente']);
        $this->assertSame(25025, $agregado['centavos']);
        $this->assertFalse($agregado['comparable']);
    }

    public function test_sin_informe_se_muestran_cinco_hojas_y_no_un_valor_cero(): void
    {
        $rips = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AC', 'codigo' => '890201', 'paciente' => '', 'fecha' => '2026-09-01']];
        $hojas = $this->exportar([], $rips);

        $this->assertCount(5, $hojas);
        $this->assertSame('NO DISPONIBLE: INFORME INDIVIDUAL DEL PERIODO', $hojas['CONCILIACION ADMIN'][1][8]);
        $this->assertSame('', $hojas['CONCILIACION ADMIN'][1][4]);
    }

    public function test_candidata_es_provisional_y_el_solapamiento_no_se_suma(): void
    {
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'centavos' => 100000, 'comparable' => true, 'fuente' => 'archivo!hoja!fila 2']];
        $rips = [
            ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'anon', 'fecha' => '2026-09-03', 'factura' => 'F', 'cantidad' => 1, 'paquete' => '', 'episodio_posible' => false, 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 10, 'fuente' => 'NOTA_TECNICA']],
            ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AC', 'codigo' => '890201', 'paciente' => 'anon', 'fecha' => '2026-09-03', 'factura' => 'F', 'cantidad' => 1, 'paquete' => '', 'episodio_posible' => true, 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 20, 'fuente' => 'NOTA_TECNICA']],
        ];

        $hojas = $this->exportar($admin, $rips);

        $this->assertSame('CANDIDATA MENSUAL; NO CONFIRMADA', $hojas['QUIMIOTERAPIA'][1][9]);
        $this->assertSame(1000, $hojas['QUIMIOTERAPIA'][1][8]);
        $this->assertSame('POSIBLE SOLAPAMIENTO AH', $hojas['PENDIENTES ADMIN'][1][5]);
    }

    public function test_el_paquete_ah_no_suma_la_prestacion_solapada(): void
    {
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AC', 'codigo' => '890201', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'centavos' => 2000, 'comparable' => true, 'fuente' => 'hoja!fila 2']];
        $segmento = ['clave' => 'F|1', 'paciente' => 'anon', 'inicio' => '2026-09-02 08:00', 'fin' => '2026-09-03 09:00', 'destino' => '01', 'ruta' => 'PROSTATA'];
        $rips = [
            ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AH', 'codigo' => '', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'fecha_hora' => '2026-09-02 08:00', 'hospital_segmento' => $segmento],
            ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AC', 'codigo' => '890201', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'fecha_hora' => '2026-09-02 10:00', 'paquete' => '', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 20, 'fuente' => 'NOTA_TECNICA']],
        ];

        $hojas = $this->exportar($admin, $rips);
        $porTipo = [];
        foreach (array_slice($hojas['CONCILIACION ADMIN'], 1) as $fila) {
            $porTipo[$fila[2]] = $fila;
        }

        $this->assertSame('', $porTipo['AC'][4]);
        $this->assertSame(6015314.53, $porTipo['AH'][4]);
        $this->assertSame('POSIBLE SOLAPAMIENTO AH', $hojas['PENDIENTES ADMIN'][2][5]);
    }

    public function test_excepcion_hospitalaria_solo_se_aplica_al_mismo_episodio_cerrado(): void
    {
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '605101', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'centavos' => 2000, 'comparable' => true, 'fuente' => 'nota']];
        $segmento = ['clave' => 'F|1', 'paciente' => 'anon', 'inicio' => '2026-09-02 08:00', 'fin' => '2026-09-03 09:00', 'destino' => '01', 'ruta' => 'PROSTATA'];
        $hospital = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AH', 'codigo' => '', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'fecha_hora' => '2026-09-02 08:00', 'hospital_segmento' => $segmento];
        $procedimiento = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '605101', 'paciente' => 'anon', 'fecha' => '2026-09-02', 'fecha_hora' => '2026-09-02 10:00', 'paquete' => 'COBRABLE_POR_FUERA', 'episodio_inicio_motor' => $segmento['inicio'], 'episodio_fin_motor' => $segmento['fin'], 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 20, 'fuente' => 'NOTA_TECNICA']];

        $resuelto = $this->exportar($admin, [$hospital, $procedimiento]);
        $detalle = array_values(array_filter($resuelto['DETALLE POR CODIGO'], static fn (array $fila): bool => ($fila[3] ?? '') === '605101'))[0];
        $this->assertSame(20, $detalle[9]);
        $pendientes = array_values(array_filter($resuelto['PENDIENTES ADMIN'], static fn (array $fila): bool => str_contains((string) ($fila[5] ?? ''), 'SOLAPAMIENTO AH RESUELTO')));
        $this->assertCount(1, $pendientes);
        $this->assertSame('CONDICIONADO', $pendientes[0][9]);

        $procedimiento['episodio_fin_motor'] = '2026-09-04 09:00';
        $ambiguo = $this->exportar($admin, [$hospital, $procedimiento]);
        $detalle = array_values(array_filter($ambiguo['DETALLE POR CODIGO'], static fn (array $fila): bool => ($fila[3] ?? '') === '605101'))[0];
        $this->assertSame('', $detalle[9]);

        $segmento['fin'] = '2026-09-02 18:00';
        $hospital['hospital_segmento'] = $segmento;
        $procedimiento['episodio_fin_motor'] = $segmento['fin'];
        $urgencia = $this->exportar($admin, [$hospital, $procedimiento]);
        $detalle = array_values(array_filter($urgencia['DETALLE POR CODIGO'], static fn (array $fila): bool => ($fila[3] ?? '') === '605101'))[0];
        $this->assertSame('', $detalle[9]);
    }

    public function test_excepcion_hospitalaria_no_reincorpora_repeticion_rn05(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '623001', 100, '', '', '2026-08-01', 'otra');
        $reporte = new ReporteConciliacionAdministrativa;
        $base = ['tipoServicio' => 'procedimientos', 'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'mesAtencion' => '2026-09', 'fechaAtencion' => '2026-09-02 10:00', 'factura' => 'F', 'codigo' => '623001',
            'cantidadAplicada' => 1, 'tarifaReferencia' => 100, 'fuenteTarifa' => 'NOTA_TECNICA', 'decisionPaquete' => 'COBRABLE_POR_FUERA',
            'episodioInicio' => '2026-09-02 08:00', 'episodioEgreso' => '2026-09-03 09:00',
            'servicioJson' => ['codProcedimiento' => '623001', 'consecutivo' => 1]];
        $segunda = $base;
        $segunda['servicioJson']['consecutivo'] = 2;
        $rips = $this->normalizarConFirmas($reporte, $historial, [$base, $segunda]);
        $paciente = $historial->clavePaciente('123456');
        $segmento = ['clave' => 'F|1', 'paciente' => 'CC|'.$paciente, 'inicio' => $base['episodioInicio'], 'fin' => $base['episodioEgreso'], 'destino' => '01', 'ruta' => 'PROSTATA'];
        $rips[] = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AH', 'codigo' => '', 'paciente' => $paciente, 'fecha' => '2026-09-02', 'fecha_hora' => $segmento['inicio'], 'hospital_segmento' => $segmento];
        $admin = [['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '623001', 'paciente' => $paciente, 'fecha' => '2026-09-02', 'centavos' => 10000, 'comparable' => true, 'fuente' => 'fila']];

        $hojas = $this->exportar($admin, $rips, $historial);
        $detalle = array_values(array_filter($hojas['DETALLE POR CODIGO'], static fn (array $fila): bool => ($fila[3] ?? '') === '623001'))[0];
        $this->assertSame(100, $detalle[9]);
        $this->assertSame(200, $detalle[17]);
        $this->assertSame(100, $detalle[18]);
    }

    public function test_no_atribuye_cobertura_de_medicamento_a_lineas_ambiguas(): void
    {
        $historial = new HistorialAdministrativo;
        $paciente = $historial->clavePaciente('123');
        $rips = [['ruta' => 'PROSTATA', 'tipo' => 'AM', 'factura' => 'F', 'paciente' => $paciente, 'fecha_hora' => '2026-09-01 09:00', 'codigo' => 'MED']];
        $cobertura = ['2026-09', 'F', 'CC', '123', '2026-09-01 09:00', 'MED', 'CUM', 1, '', '', '', '', '', '', '', '', 'INDEPENDIENTE', 100, 0, 'FUENTE'];
        $reporte = new ReporteConciliacionAdministrativa;

        $unica = $reporte->incorporarCobertura($rips, [$cobertura], $historial, 'PROSTATA');
        $ambigua = $reporte->incorporarCobertura([...$rips, ...$rips], [$cobertura], $historial, 'PROSTATA');

        $this->assertSame('CUM', $unica[0]['cobertura_medicamento']['cum']);
        $this->assertArrayNotHasKey('cobertura_medicamento', $ambigua[0]);
        $this->assertArrayNotHasKey('cobertura_medicamento', $ambigua[1]);
    }

    /** @param list<array<string, mixed>> $detalles
     * @return list<array<string, mixed>>
     */
    private function normalizarConFirmas(ReporteConciliacionAdministrativa $reporte, HistorialAdministrativo $historial, array $detalles): array
    {
        $archivo = tempnam(sys_get_temp_dir(), 'rips_');
        $servicios = [];
        $rips = [];
        foreach ($detalles as $i => $detalle) {
            $detalle['archivoOrigen'] = 'prueba.json';
            $detalle['consecutivoRips'] = (string) ($i + 1);
            $detalle['servicioJson']['consecutivo'] = $i + 1;
            $detalle['servicioJson']['fechaInicioAtencion'] = $detalle['fechaAtencion'];
            $servicios[] = $detalle['servicioJson'];
            $rips[] = $reporte->normalizar('PROSTATA', $detalle, $historial);
        }
        file_put_contents($archivo, json_encode(['usuarios' => [['tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => '123456',
            'servicios' => ['procedimientos' => $servicios]]]], JSON_THROW_ON_ERROR));
        try {
            return $reporte->incorporarFirmasCompletas($rips, [['path' => $archivo, 'fileName' => 'prueba.json']], $historial);
        } finally {
            unlink($archivo);
        }
    }

    /** @return array<string, list<list<mixed>>> */
    private function exportar(array $admin, array $rips, ?HistorialAdministrativo $historial = null): array
    {
        $archivo = tempnam(sys_get_temp_dir(), 'conciliacion_');
        $writer = new Writer;
        $writer->openToFile($archivo);
        $writer->getCurrentSheet()->setName('CENTRAL');
        (new ReporteConciliacionAdministrativa)->escribir($writer, $admin, $rips, [], [], [], $historial);
        $writer->close();
        $reader = new Reader;
        $reader->open($archivo);
        $hojas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            if ($hoja->getName() === 'CENTRAL') {
                continue;
            }
            foreach ($hoja->getRowIterator() as $fila) {
                $hojas[$hoja->getName()][] = $fila->toArray();
            }
            $hojas[$hoja->getName()] ??= [];
        }
        $reader->close();
        unlink($archivo);

        return $hojas;
    }
}
