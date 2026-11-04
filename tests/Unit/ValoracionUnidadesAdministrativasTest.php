<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ValoracionUnidadesAdministrativas;
use PHPUnit\Framework\TestCase;

class ValoracionUnidadesAdministrativasTest extends TestCase
{
    public function test_consultas_y_procedimientos_conservan_paquetes_y_esquemas_separados(): void
    {
        $lineas = [
            ['indice_rips' => 1, 'tipo' => 'AC', 'codigo' => '890201', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 25000, 'fuente' => 'NOTA_TECNICA']],
            ['indice_rips' => 2, 'tipo' => 'AP', 'codigo' => '992511', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 444690, 'fuente' => 'NOTA_TECNICA']],
            ['indice_rips' => 3, 'tipo' => 'AP', 'codigo' => '906610', 'paquete' => 'INCLUIDO_EN_PAQUETE', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 57590.016, 'fuente' => 'NOTA_TECNICA']],
            ['indice_rips' => 4, 'tipo' => 'AP', 'codigo' => '405407', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 0, 'fuente' => 'NINGUNA']],
        ];

        $resultado = (new ValoracionUnidadesAdministrativas)->calcularConsultasProcedimientos($lineas);

        $this->assertSame(46969000, $resultado['total_centavos']);
        $this->assertSame(5759002, $resultado['no_aditivo_centavos']);
        $this->assertSame(1, $resultado['pendientes']);
        $this->assertSame('BASE DE APLICACION; ESQUEMA INTEGRAL PENDIENTE', $resultado['detalle'][1]['estado_calculo']);
        $this->assertNull($resultado['detalle'][3]['importe_calculo_centavos']);
    }

    public function test_una_linea_no_puede_calcularse_dos_veces(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ValoracionUnidadesAdministrativas)->calcularConsultasProcedimientos([
            ['indice_rips' => 1], ['indice_rips' => 1],
        ]);
    }

    public function test_valora_cantidades_y_no_suma_medicamentos_en_paquetes(): void
    {
        $lineas = [
            ['tipo' => 'AT', 'codigo' => 'T34004', 'cantidad' => 194],
            ['tipo' => 'AM', 'cantidad' => 2, 'cobertura_medicamento' => ['clasificacion_motor' => 'INDEPENDIENTE', 'cum' => '123', 'precio_historico' => 12.5]],
            ['tipo' => 'AM', 'cantidad' => 10, 'cobertura_medicamento' => ['clasificacion_motor' => 'CONDICIONADO AP', 'cum' => '123', 'precio_historico' => 100]],
            ['tipo' => 'AM', 'cantidad' => 10, 'cobertura_medicamento' => ['clasificacion_motor' => 'INDEPENDIENTE', 'cum' => '', 'precio_historico' => 100]],
        ];
        $resultado = (new ValoracionUnidadesAdministrativas)->calcular($lineas, '304438.2243983406');
        $this->assertSame(5906104053, $resultado['total_centavos']);
        $this->assertNull($resultado['detalle'][2]['importe_centavos']);
        $this->assertNull($resultado['detalle'][3]['importe_centavos']);
    }

    public function test_expone_diferencia_entre_redondeo_por_linea_y_total(): void
    {
        $lineas = array_fill(0, 2, ['tipo' => 'AT', 'codigo' => 'T34004', 'cantidad' => 1]);
        $resultado = (new ValoracionUnidadesAdministrativas)->calcular($lineas, '0.004');
        $this->assertSame(1, $resultado['total_centavos']);
        $this->assertSame(0, $resultado['suma_lineas_centavos']);
        $this->assertSame(1, $resultado['ajuste_redondeo_centavos']);
    }
}
