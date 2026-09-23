<?php

namespace Tests\Unit\Spoolman;

use App\Services\Spoolman\WeightUnitConverter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeightUnitConverterTest extends TestCase
{
    #[Test]
    public function it_converts_grams_to_kilograms(): void
    {
        $converter = new WeightUnitConverter;

        $this->assertSame(0.4, $converter->convert(400, 'g', 'KG'));
        $this->assertSame(400.0, $converter->convert(0.4, 'KG', 'g'));
        $this->assertSame(12.5, $converter->convert(12.5, 'KG', 'kg'));
    }
}
