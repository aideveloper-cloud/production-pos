<?php

namespace App\Services\Spoolman;

use InvalidArgumentException;

class WeightUnitConverter
{
    /** Multipliers to convert a unit into grams. */
    private const TO_GRAMS = [
        'G' => 1.0,
        'GRAM' => 1.0,
        'GRAMS' => 1.0,
        'KG' => 1000.0,
        'KILOGRAM' => 1000.0,
        'KILOGRAMS' => 1000.0,
        'MG' => 0.001,
    ];

    /**
     * Convert mass units used by Spoolman (typically g) into WMS base UOM.
     */
    public function convert(float $qty, string $fromUom, string $toUom): float
    {
        $from = strtoupper(trim($fromUom));
        $to = strtoupper(trim($toUom));

        if ($from === $to) {
            return $qty;
        }

        $fromFactor = self::TO_GRAMS[$from] ?? null;
        $toFactor = self::TO_GRAMS[$to] ?? null;

        if ($fromFactor === null) {
            throw new InvalidArgumentException("Unsupported source UOM: {$fromUom}");
        }

        if ($toFactor === null) {
            throw new InvalidArgumentException("Unsupported target UOM: {$toUom}");
        }

        return $qty * $fromFactor / $toFactor;
    }
}
