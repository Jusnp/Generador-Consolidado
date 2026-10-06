<?php

namespace App\Programas\LaMaria;

use App\Programas\Contracts\ProgramaPipeline;
use App\Services\CatalogoReferenciaImporter;

/**
 * Ejecución La María · Cérvix (diagnósticos C53).
 * Pipeline propio: no reutilizar reglas de Próstata ni de otros programas.
 */
class LaMariaCervixPipeline implements ProgramaPipeline
{
    public const SLUG = 'la-maria-cervix';

    public function entryRouteName(): ?string
    {
        return 'la-maria.cervix';
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function rutaCodigo(): ?string
    {
        return LaMariaPrograma::RUTA_CERVIX;
    }

    public function ownedCatalogTypes(): array
    {
        return [
            CatalogoReferenciaImporter::TYPE_ANEXOS_2706,
            CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM,
            CatalogoReferenciaImporter::TYPE_NOTA_CERVIX,
            CatalogoReferenciaImporter::TYPE_NOTA_MEDICAMENTOS,
            CatalogoReferenciaImporter::TYPE_DISPENSACION_CERVIX,
        ];
    }

    public function catalogOwnershipLabel(): string
    {
        return LaMariaPrograma::CATALOG_OWNERSHIP_LABEL;
    }
}
