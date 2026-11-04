<?php

namespace App\Programas\Autoinmunes;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Services\CatalogoReferenciaImporter;

/**
 * Homologación de medicamentos por el registro CUM oficial (solo lectura).
 *
 * Un CUM de los RIPS sin tarifa se relaciona con otro CUM de la NT solo si el
 * registro de ambos tiene los mismos principios activos con la misma
 * concentración y la misma forma farmacéutica sólida oral (tableta o cápsula,
 * sin liberación modificada), y si todos los CUM NT con esa clave tienen una
 * única tarifa. No compara nombres comerciales ni descripciones libres: la
 * nota puede rotular un producto con el nombre ATC (p. ej. «ácido
 * micofenólico» para micofenolato de mofetilo). Sin registro verificable o con
 * tarifas distintas, el medicamento queda pendiente.
 *
 * Es una equivalencia técnica, no contractual: la nota técnica tarifa por CUM
 * y no define equivalencias, así que la tarifa del homólogo es solo referencia
 * y el precio queda condicionado.
 */
class HomologacionRegistroCum
{
    /** @var array<string, array{cums: string, tarifa: float}|null>|null clave de registro => homólogo NT (null si es ambiguo) */
    private ?array $indiceNt = null;

    /** @var array<string, ?string> CUM => clave de registro */
    private array $claves = [];

    private ?int $registroId = null;

    private bool $registroCargado = false;

    /**
     * @param  array<string, array{nombre_estandar: string, nt: string, tarifa: float}>  $catalogoNt  Catálogo NT activo por CUM.
     */
    public function __construct(private readonly array $catalogoNt) {}

    /**
     * Homólogo NT del CUM por registro oficial, o null si no hay equivalencia única demostrable.
     *
     * @return array{cums: string, tarifa: float, clave: string}|null
     */
    public function homologo(string $cum): ?array
    {
        $clave = $this->claveDe($cum);

        if ($clave === null) {
            return null;
        }

        $homologo = $this->indiceNt()[$clave] ?? null;

        return $homologo === null || $homologo['cums'] === $cum ? null : [...$homologo, 'clave' => $clave];
    }

    /** @return array<string, array{cums: string, tarifa: float}|null> */
    private function indiceNt(): array
    {
        if ($this->indiceNt !== null) {
            return $this->indiceNt;
        }

        $tarifas = [];

        foreach ($this->catalogoNt as $cums => $item) {
            if (in_array(mb_strtolower(trim($item['nt'])), ['si', 'sí'], true) && $item['tarifa'] > 0) {
                $tarifas[(string) $cums] = $item['tarifa'];
            }
        }

        ksort($tarifas, SORT_STRING);
        $this->cargarClaves(array_map('strval', array_keys($tarifas)));
        $indice = [];

        foreach ($tarifas as $cums => $tarifa) {
            $clave = $this->claves[(string) $cums] ?? null;

            if ($clave === null) {
                continue;
            }

            if (! array_key_exists($clave, $indice)) {
                $indice[$clave] = ['cums' => (string) $cums, 'tarifa' => $tarifa];
            } elseif ($indice[$clave] !== null && abs($indice[$clave]['tarifa'] - $tarifa) >= 0.0001) {
                // Con dos tarifas distintas para la misma clave no hay homólogo único.
                $indice[$clave] = null;
            }
        }

        return $this->indiceNt = $indice;
    }

    private function claveDe(string $cum): ?string
    {
        if (! array_key_exists($cum, $this->claves)) {
            $this->cargarClaves([$cum]);
        }

        return $this->claves[$cum] ?? null;
    }

    /**
     * Clave de registro por CUM: principios activos con concentración y unidad, y forma sólida oral.
     *
     * @param  list<string>  $cums
     */
    private function cargarClaves(array $cums): void
    {
        foreach ($cums as $cum) {
            $this->claves[$cum] = null;
        }

        $registroId = $this->registroId();

        if ($registroId === null || $cums === []) {
            return;
        }

        $porCum = [];

        foreach (array_chunk($cums, 500) as $lote) {
            CatalogoReferenciaItem::query()->where('catalogo_referencia_id', $registroId)->where('activo', true)->whereIn('codigo', $lote)
                ->get(['codigo', 'descripcion', 'metadatos'])
                ->each(function (CatalogoReferenciaItem $item) use (&$porCum): void {
                    $metadatos = is_array($item->metadatos) ? $item->metadatos : [];
                    $activo = self::normalizar(' '.($metadatos['principio_activo'] ?? $item->descripcion).' ');
                    $porCum[$item->codigo]['activos'][trim(str_replace(' DE ', ' ', ' '.$activo.' ')).' '.(float) ($metadatos['cantidad'] ?? 0)
                        .' '.self::unidad((string) ($metadatos['unidad_medida'] ?? ''))] = true;
                    $porCum[$item->codigo]['formas'][(string) self::forma((string) ($metadatos['forma_farmaceutica'] ?? ''))] = true;
                });
        }

        foreach ($porCum as $cum => $registro) {
            $formas = array_keys($registro['formas']);

            if (count($formas) !== 1 || $formas[0] === '') {
                continue;
            }

            $activos = array_keys($registro['activos']);
            sort($activos);
            $this->claves[(string) $cum] = implode(' + ', $activos).' | '.$formas[0];
        }
    }

    private function registroId(): ?int
    {
        if (! $this->registroCargado) {
            $this->registroCargado = true;
            $id = CatalogoReferencia::query()->where('tipo', CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM)
                ->where('activo', true)->orderByDesc('id')->value('id');
            $this->registroId = $id === null ? null : (int) $id;
        }

        return $this->registroId;
    }

    /**
     * Forma sólida oral de dosis unitaria; null para liberación modificada (incluida la cubierta entérica) y demás formas.
     */
    private static function forma(string $forma): ?string
    {
        $forma = self::normalizar($forma);

        if (preg_match('/LIBERACION|RETARD|GASTRO|ENTERIC|PROLONGAD|MODIFICAD|DISPERSABLE|EFERVESCENTE|MASTICABLE|SUBLINGUAL|BUCAL|VAGINAL/', $forma) === 1) {
            return null;
        }

        // El recubrimiento no cambia la dosis unitaria de la tableta.
        $forma = trim((string) preg_replace('/\b(RECUBIERTAS?|LACADAS?|CUBIERTA|CON PELICULA|PELICULA)\b/', '', $forma));

        return match (true) {
            str_starts_with($forma, 'TABLETA') || str_starts_with($forma, 'COMPRIMIDO') => 'TABLETA',
            $forma === 'CAPSULA' || $forma === 'CAPSULA DURA' => 'CAPSULA DURA',
            $forma === 'CAPSULA BLANDA' => 'CAPSULA BLANDA',
            default => null,
        };
    }

    /**
     * Unidad de concentración; «µ», «μ», «æ» (µ mal codificada) y «u» son el prefijo micro: µg = mcg, nunca g.
     */
    private static function unidad(string $unidad): string
    {
        return self::normalizar((string) preg_replace('/^\s*(µ|μ|æ|Æ|u)(?=(g|mol)\b)/iu', 'MC', $unidad));
    }

    private static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtoupper($texto), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9]/', ' ', $texto)));
    }
}
