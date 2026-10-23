<?php

namespace Tests\Unit;

use App\Programas\LaMaria\HistorialAdministrativo;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

class HistorialAdministrativoTest extends TestCase
{
    public function test_actual_month_is_separate_from_the_prior_price_window(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-06', 'AP', '992505', 100.0);
        $historial->registrar('PROSTATA', '2026-07', 'AP', '992505', 200.0);
        $historial->registrar('PROSTATA', '2026-08', 'AP', '992505', 300.0);
        $historial->registrar('PROSTATA', '2026-09', 'AP', '992505', 900.0);
        $historial->registrar('PROSTATA', '2026-09', 'AC', '890294', 50.0);

        $this->assertSame(200.0, $historial->precio('PROSTATA', '2026-09', 'AP', '992505')['mediana']);
        $this->assertSame(['registros' => 1, 'valor' => 900.0], $historial->valorDelMes('PROSTATA', '2026-09', 'AP', '992505'));
        $this->assertSame(['registros' => 2, 'valor' => 950.0], $historial->totalDelMes('PROSTATA', '2026-09'));
        $this->assertCount(2, $historial->codigosDelMes('PROSTATA', '2026-09'));
        $this->assertNull($historial->totalDelMes('CERVIX', '2026-09'));
    }

    public function test_autonomous_view_keeps_prior_prices_and_ignores_the_valued_month_report(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06' => 100.0, '2026-07' => 200.0, '2026-08' => 300.0, '2026-09' => 900.0] as $mes => $valor) {
            $historial->registrar('PROSTATA', $mes, 'AP', '906610', $valor, '', '', $mes.'-03', 'A');
        }

        $autonomo = $historial->sinInformeDelMes();
        $paciente = $historial->clavePaciente('A');

        $this->assertSame([true, ['AP|906610' => [900.0]]], [$historial->cubreMes('PROSTATA', '2026-09'), $historial->filasDelServicio('PROSTATA', '2026-09', $paciente, '2026-09-03')]);
        $this->assertSame([false, null, null], [$autonomo->cubreMes('PROSTATA', '2026-09'),
            $autonomo->filasDelServicio('PROSTATA', '2026-09', $paciente, '2026-09-03'), $autonomo->serviciosDelMes('PROSTATA', '2026-09', 'AP', '906610')]);
        $this->assertSame($historial->precio('PROSTATA', '2026-09', 'AP', '906610'), $autonomo->precio('PROSTATA', '2026-09', 'AP', '906610'));
        $this->assertSame($paciente, $autonomo->clavePaciente('A'));
    }

    public function test_type_reference_is_the_median_of_the_three_months_before_the_valued_month(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-05' => 999.0, '2026-06' => 100.0, '2026-07' => 300.0, '2026-08' => 200.0, '2026-09' => 5000.0] as $mes => $valor) {
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', $valor);
        }

        $referencia = $historial->referenciaTipo('PROSTATA', '2026-09', 'AP');

        $this->assertSame(['2026-06', '2026-07', '2026-08'], $referencia['meses']);
        $this->assertSame(200.0, $referencia['mediana']);
        $this->assertNull($historial->referenciaTipo('CERVIX', '2026-09', 'AP'));
    }

    public function test_code_price_uses_positive_values_and_falls_back_to_older_months_flagged(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-06', 'AP', '898221', 176743.55);
        $historial->registrar('PROSTATA', '2026-06', 'AP', '898221', 0.0);
        $historial->registrar('PROSTATA', '2026-07', 'AP', '898221', 176743.55);
        $historial->registrar('PROSTATA', '2026-08', 'AP', '898221', 176743.55);
        $historial->registrar('PROSTATA', '2026-02', 'AP', '405407', 5052320.0);
        $historial->registrar('PROSTATA', '2026-03', 'AP', '405407', 5052320.0);

        $reciente = $historial->precio('PROSTATA', '2026-09', 'AP', '898221');
        $antiguo = $historial->precio('PROSTATA', '2026-09', 'AP', '405407');

        $this->assertSame([3, 1, 176743.55, true], [$reciente['observaciones'], $reciente['ceros'], $reciente['mediana'], $reciente['ventanaReciente']]);
        $this->assertSame(1.0, $reciente['registrosMensuales']);
        $this->assertSame(['2026-02', '2026-03'], $antiguo['meses']);
        $this->assertFalse($antiguo['ventanaReciente']);
        $this->assertNull($historial->precio('PROSTATA', '2026-09', 'AT', 'DM00114'));
    }

    public function test_report_is_read_by_header_and_route_without_keeping_personal_data(): void
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inf-admin-'.uniqid().'.xlsx';
        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName('TD');
        $writer->addRow(Row::fromValues(['Resumen dinámico']));
        $writer->addNewSheetAndMakeItCurrent()->setName('inf admin ');
        $writer->addRow(Row::fromValues(['MES', 'TIPO DOCUMENTO', 'DOCUMENTO', 'PRIMER_NOMBRE', 'CUPS', 'DESCRIPCION DEL SERVICIO',
            'DIACODIGO', 'FECHA_PRESTACION_SERVICIO', 'GRUPO DEL SERVICIO', 'CLASIFICACIÓN RIPS ', 'MEDICAMENTO ', 'VALOR']));

        foreach ([['2026-06-10', 85000], ['2026-07-02', 85000], ['2026-08-20', 87000]] as [$fecha, $valor]) {
            $writer->addRow(Row::fromValues(['=UPPER(TEXT(H2))', 'CC', '70975586', 'PACIENTE', '890294', 'CONSULTA DE PRIMERA VEZ',
                'C61X', new DateTimeImmutable($fecha), 'Consulta externa', 'AC', '', $valor]));
        }

        $writer->close();

        $copia = $ruta.'.copia.xlsx';
        copy($ruta, $copia);

        try {
            // La misma fuente cargada dos veces se lee una sola vez.
            $historial = HistorialAdministrativo::desdeArchivos([$ruta => 'INFORME ADMIN PROSTATA PARA JUAN.xlsx', $copia => 'copia.xlsx']);
        } finally {
            @unlink($ruta);
            @unlink($copia);
        }

        $this->assertTrue($historial->tieneDatos('PROSTATA'));
        $this->assertSame(85000.0, $historial->precio('PROSTATA', '2026-09', 'AC', '890294')['mediana']);
        $this->assertSame(85000.0, $historial->referenciaTipo('PROSTATA', '2026-09', 'AC')['mediana']);
        $this->assertSame([['archivo' => 'INFORME ADMIN PROSTATA PARA JUAN.xlsx', 'ruta' => 'PROSTATA', 'filas' => 3, 'meses' => '2026-06 a 2026-08',
            'hoja' => 'inf admin', 'advertencias' => []]], $historial->fuentes());
        $this->assertStringNotContainsString('70975586', serialize($historial));
        $this->assertStringNotContainsString('PACIENTE', serialize($historial));
    }

    public function test_new_report_maps_the_service_group_from_its_largest_sheet_and_warns_about_unverifiable_rows(): void
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inf-admin-nuevo-'.uniqid().'.xlsx';
        $encabezado = ['MES', 'DOCUMENTO', 'PRIMER_NOMBRE', 'CUPS', 'DESCRIPCION DEL SERVICIO', 'DIACODIGO', 'FECHA_PRESTACION_SERVICIO',
            'GRUPO DEL SERVICIO', 'MEDICAMENTO', 'VALOR'];
        $writer = new Writer;
        $writer->openToFile($ruta);
        // Tabla dinámica con fechas antes de las hojas de servicios: no es un encabezado y no se lee.
        $writer->getCurrentSheet()->setName('TD');
        $writer->addRow(Row::fromValues(['Suma de VALOR', 'Etiquetas de columna']));
        $writer->addRow(Row::fromValues([new DateTimeImmutable('2026-06-01'), 85000]));
        $writer->addNewSheetAndMakeItCurrent()->setName('ESC 2');
        $writer->addRow(Row::fromValues(['ESCENARIO 2: SOLO APLICACIONES']));
        $writer->addRow(Row::fromValues($encabezado));
        $writer->addRow(Row::fromValues(['JUNIO', '70975586', 'PACIENTE', '890294', 'CONSULTA', 'C61X', '10/06/2026', 'Consulta externa', '', 99999]));
        $writer->addNewSheetAndMakeItCurrent()->setName('INFORME OCT - sept ESC 1');
        $writer->addRow(Row::fromValues($encabezado));

        foreach ([['890294', '10/06/2026', 'Consulta externa', 85000], ['906610', new DateTimeImmutable('2026-06-12'), 'Laboratorio Clínico', 57590],
            ['992511', '15/06/2026', 'QUIMIOTERAPIA', 32000000], ['931000', '16/06/2026', 'Terapias', 50000], ['890294', '20/06/2026', 'Consulta externa', '=J2*1']] as [$cups, $fecha, $grupo, $valor]) {
            $writer->addRow(Row::fromValues(['JUNIO', '70975586', 'PACIENTE', $cups, 'SERVICIO', 'C61X', $fecha, $grupo, $cups === '992511' ? 'PEMBROLIZUMAB' : '', $valor]));
        }

        $writer->close();

        // Excel guarda el resultado de cada fórmula: la de la última fila quedó en error (#REF!).
        $zip = new \ZipArchive;
        $zip->open($ruta);
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $zip->addFromString('xl/worksheets/sheet3.xml', (string) preg_replace('#(<c r="J\d+"[^>]*)><f>J2\*1</f>#', '$1 t="e"><f>J2*1</f><v>#REF!</v>', $hoja));
        $zip->close();

        try {
            $historial = HistorialAdministrativo::desdeArchivos([$ruta => 'Informe CaP nueva clasificación.xlsx']);
        } finally {
            @unlink($ruta);
        }

        [$fuente] = $historial->fuentes();
        $paciente = $historial->clavePaciente('70975586');

        // La hoja de servicios con más filas; GRUPO DEL SERVICIO se traduce al tipo RIPS verificado.
        $this->assertSame(['INFORME OCT - sept ESC 1', 'PROSTATA', 3, '2026-06 a 2026-06'], [$fuente['hoja'], $fuente['ruta'], $fuente['filas'], $fuente['meses']]);
        $this->assertSame(['Grupo «TERAPIAS» sin equivalencia RIPS verificada: 1 filas no usadas.', '1 filas con valor calculado por fórmula no verificable: no usadas.'],
            $fuente['advertencias']);
        $this->assertSame(85000.0, $historial->precio('PROSTATA', '2026-07', 'AC', '890294')['mediana']);
        $this->assertSame(['AP|906610' => [57590.0]], $historial->filasDelServicio('PROSTATA', '2026-06', $paciente, '2026-06-12'));
        $this->assertSame(['2026-06-15' => [['valor' => 32000000.0, 'medicamentos' => ['PEMB']]]], $historial->esquemasPacienteMes('PROSTATA', '2026-06', '992511', $paciente));
        $this->assertNull($historial->filasDelServicio('PROSTATA', '2026-07', $paciente, '2026-07-01'));
        $this->assertStringNotContainsString('70975586', serialize($historial));
    }

    public function test_inf_admin_keeps_priority_and_cached_formula_values_and_valid_dates_are_required(): void
    {
        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inf-admin-prioridad-'.uniqid().'.xlsx';
        $encabezado = ['DOCUMENTO', 'CUPS', 'DIACODIGO', 'FECHA_PRESTACION_SERVICIO', 'GRUPO DEL SERVICIO', 'CLASIFICACIÓN RIPS', 'VALOR'];
        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName('inf admin');
        $writer->addRow(Row::fromValues($encabezado));
        // VALOR por fórmula con resultado guardado; fechas imposibles y una fila de plantilla vacía con fórmula.
        $writer->addRow(Row::fromValues(['111', '890294', 'C61X', '10/06/2026', 'Consulta externa', 'AC', '=1*1']));
        $writer->addRow(Row::fromValues(['111', '890294', 'C61X', '06/15/2026', 'Consulta externa', 'AC', 85000]));
        $writer->addRow(Row::fromValues(['111', '890294', 'C61X', '2026-02-31', 'Consulta externa', 'AC', 85000]));
        $writer->addRow(Row::fromValues(['', '', '', '', '', '', '=1*2']));
        $writer->addNewSheetAndMakeItCurrent()->setName('INFORME COMPLETO');
        $writer->addRow(Row::fromValues($encabezado));

        foreach (range(1, 5) as $dia) {
            $writer->addRow(Row::fromValues(['111', '890294', 'C61X', $dia.'/07/2026', 'Consulta externa', 'AC', 99999]));
        }

        $writer->close();

        // Resultados que Excel guarda: 85000 en la primera fórmula y texto vacío en la de plantilla.
        $zip = new \ZipArchive;
        $zip->open($ruta);
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $hoja = str_replace('<f>1*1</f>', '<f>1*1</f><v>85000</v>', $hoja);
        $hoja = (string) preg_replace('#(<c r="G5"[^>]*)><f>1\*2</f>#', '$1 t="str"><f>1*2</f><v></v>', $hoja);
        $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
        $zip->close();

        try {
            $historial = HistorialAdministrativo::desdeArchivos([$ruta => 'informe.xlsx']);
        } finally {
            @unlink($ruta);
        }

        [$fuente] = $historial->fuentes();

        $this->assertSame(['inf admin', 1, '2026-06 a 2026-06', []], [$fuente['hoja'], $fuente['filas'], $fuente['meses'], $fuente['advertencias']]);
        $this->assertSame(['registros' => 1, 'valor' => 85000.0], $historial->totalDelMes('PROSTATA', '2026-06'));
    }
}
