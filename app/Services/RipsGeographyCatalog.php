<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class RipsGeographyCatalog
{
    /**
     * ISO 3166-1 numeric codes observed in the RIPS processed by this project.
     * Unknown codes remain unchanged so that the report never invents a country.
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAMES = [
        '050' => 'BANGLADÉS',
        '170' => 'COLOMBIA',
        '862' => 'VENEZUELA',
    ];

    /** @var array<string, string>|null */
    private ?array $municipalityNames = null;

    public function countryName(string $code): string
    {
        $normalizedCode = str_pad(trim($code), 3, '0', STR_PAD_LEFT);

        if ($normalizedCode === '000') {
            return $code;
        }

        return self::COUNTRY_NAMES[$normalizedCode] ?? $code;
    }

    public function municipalityName(string $code): string
    {
        $normalizedCode = str_pad(trim($code), 5, '0', STR_PAD_LEFT);

        if ($normalizedCode === '00000') {
            return $code;
        }

        return $this->municipalityNames()[$normalizedCode] ?? $code;
    }

    /**
     * @return array<string, string>
     */
    private function municipalityNames(): array
    {
        if ($this->municipalityNames !== null) {
            return $this->municipalityNames;
        }

        $catalogPath = resource_path('data/divipola-municipios-2025.json');
        $contents = file_get_contents($catalogPath);

        if ($contents === false) {
            throw new RuntimeException('No fue posible leer el catálogo DIVIPOLA de municipios.');
        }

        try {
            $municipalityNames = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('El catálogo DIVIPOLA de municipios no contiene JSON válido.', 0, $exception);
        }

        if (! is_array($municipalityNames)) {
            throw new RuntimeException('El catálogo DIVIPOLA de municipios tiene una estructura inválida.');
        }

        return $this->municipalityNames = $municipalityNames;
    }
}
