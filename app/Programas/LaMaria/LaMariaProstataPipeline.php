<?php

namespace App\Programas\LaMaria;

use App\Programas\Contracts\ProgramaPipeline;
use App\Services\CatalogoReferenciaImporter;

/**
 * Ejecución La María · Próstata (diagnósticos C61).
 * Pipeline propio: no reutilizar reglas de Cérvix ni de otros programas.
 */
class LaMariaProstataPipeline implements ProgramaPipeline
{
    public const SLUG = 'la-maria-prostata';

    public function entryRouteName(): ?string
    {
        return 'la-maria.prostata';
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function rutaCodigo(): ?string
    {
        return LaMariaPrograma::RUTA_PROSTATA;
    }

    public function ownedCatalogTypes(): array
    {
        return [
            CatalogoReferenciaImporter::TYPE_ANEXOS_2706,
            CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM,
            CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
            CatalogoReferenciaImporter::TYPE_NOTA_MEDICAMENTOS,
            CatalogoReferenciaImporter::TYPE_DISPENSACION_PROSTATA,
        ];
    }

    public function catalogOwnershipLabel(): string
    {
        return LaMariaPrograma::CATALOG_OWNERSHIP_LABEL;
    }
}
