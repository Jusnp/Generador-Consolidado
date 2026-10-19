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
        $this->assertSame([['archivo' => 'INFORME ADMIN PROSTATA PARA JUAN.xlsx', 'ruta' => 'PROSTATA', 'filas' => 3, 'meses' => '2026-06 a 2026-08']], $historial->fuentes());
        $this->assertStringNotContainsString('70975586', serialize($historial));
        $this->assertStringNotContainsString('PACIENTE', serialize($historial));
    }
}
