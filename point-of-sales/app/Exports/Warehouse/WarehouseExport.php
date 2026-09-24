<?php

namespace App\Exports\Warehouse;

use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Common behaviour for the warehouse exports: translated headings, auto-sized columns, and a
 * UTF-8 BOM on CSV so Excel opens Thai text correctly. Strict null comparison keeps 0 as 0 (not an
 * empty cell).
 */
abstract class WarehouseExport implements ShouldAutoSize, WithCustomCsvSettings, WithHeadings, WithMapping, WithStrictNullComparison
{
    /** Group in lang/{locale}/exports.php holding this export's headings. */
    abstract protected function group(): string;

    /** @return array<int, string> heading keys, in column order */
    abstract protected function columns(): array;

    public function headings(): array
    {
        return array_map(fn (string $key) => __("exports.{$this->group()}.{$key}"), $this->columns());
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }

    protected function yesNo(bool $value): string
    {
        return __($value ? 'exports.yes' : 'exports.no');
    }

    protected function label(string $group, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $translated = __("exports.{$group}.{$key}");

        return $translated === "exports.{$group}.{$key}" ? $key : $translated;
    }

    protected function number(mixed $value): float
    {
        return round((float) $value, 4);
    }
}
