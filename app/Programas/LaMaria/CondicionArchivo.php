<?php

namespace App\Programas\LaMaria;

/**
 * Identidad de un archivo JSON frente a los demás archivos del mismo cargue.
 */
enum CondicionArchivo: string
{
    case Unico = 'UNICO';
    case ArchivoDuplicadoExcluido = 'ARCHIVO_DUPLICADO_EXCLUIDO';
    case FacturaRepetidaConDiferencias = 'FACTURA_REPETIDA_CON_DIFERENCIAS';
}
