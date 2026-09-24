<?php

namespace App\Exports\Warehouse;

use App\Models\PhysicalUnit;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;

/** Physical units (rolls, spools, bundles) with their remaining qty. */
class PhysicalUnitsExport extends WarehouseExport implements FromQuery
{
    /**
     * @param  array<int, int>  $warehouseIds
     */
    public function __construct(
        private readonly array $warehouseIds,
        private readonly ?int $warehouseId = null,
        private readonly ?string $status = null,
    ) {}

    public function query(): Builder
    {
        return PhysicalUnit::query()
            ->with(['product:id,sku,title,base_uom,standard_weight_kg', 'warehouse:id,code,name'])
            ->whereIn('warehouse_id', $this->warehouseIds ?: [0])
            ->when($this->warehouseId, fn (Builder $q, $id) => $q->where('warehouse_id', $id))
            ->when($this->status, fn (Builder $q, $status) => $q->where('status', $status))
            ->orderBy('warehouse_id')
            ->orderBy('unit_code');
    }

    protected function group(): string
    {
        return 'units';
    }

    protected function columns(): array
    {
        return ['unit_code', 'sku', 'title', 'warehouse', 'lot', 'type', 'status', 'remaining', 'uom', 'weight_kg', 'spoolman_spool', 'opened_at', 'depleted_at'];
    }

    /** @param  PhysicalUnit  $unit */
    public function map($unit): array
    {
        $remaining = (float) $unit->actual_weight;
        $uom = strtoupper((string) ($unit->weight_uom ?: $unit->product?->base_uom));
        $weightKg = match (true) {
            in_array($uom, ['KG', 'KILOGRAM'], true) => $remaining,
            $uom === 'G' => $remaining / 1000,
            $unit->product?->standard_weight_kg !== null => $remaining * (float) $unit->product->standard_weight_kg,
            default => null,
        };

        return [
            $unit->unit_code,
            $unit->product?->sku,
            $unit->product?->title,
            $unit->warehouse?->code,
            $unit->lot_code,
            $unit->unit_type,
            $this->label('statuses', $unit->status),
            $this->number($remaining),
            $uom,
            $weightKg !== null ? $this->number($weightKg) : null,
            $unit->spoolman_spool_id,
            $unit->opened_at?->format('Y-m-d H:i'),
            $unit->depleted_at?->format('Y-m-d H:i'),
        ];
    }
}
