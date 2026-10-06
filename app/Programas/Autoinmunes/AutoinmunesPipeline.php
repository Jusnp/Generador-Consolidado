<?php

namespace App\Programas\Autoinmunes;

use App\Programas\Contracts\ProgramaPipeline;
use App\Services\CatalogoConsolidadoImporter;

/**
 * Pipeline de ejecución del programa Autoinmunes (consolidado RIPS JSON → Excel).
 *
 * Las reglas tarifarias y de conversión viven en JsonExcelController y
 * CatalogoConsolidadoImporter; este pipeline declara la frontera del programa.
 * No compartir este procesamiento con otros programas.
 */
class AutoinmunesPipeline implements ProgramaPipeline
{
    public const SLUG = 'autoinmunes';

    public function entryRouteName(): ?string
    {
        return 'json-excel';
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function rutaCodigo(): ?string
    {
        return null;
    }

    public function ownedCatalogTypes(): array
    {
        return array_keys(CatalogoConsolidadoImporter::types());
    }

    public function catalogOwnershipLabel(): string
    {
        return 'Autoinmunes · tarifarios contractuales';
    }
}
