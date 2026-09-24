<?php

namespace App\Exports\Warehouse;

use App\Models\ProductWarehouse;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

/** One row per material per warehouse: on-hand (from the ledger in warehouse mode) and active units. */
class StockBalancesExport extends WarehouseExport implements FromCollection
{
    /**
     * @param  array<int, int>  $warehouseIds
     */
    public function __construct(
        private readonly array $warehouseIds,
        private readonly ?int $warehouseId = null,
    ) {}

    public function collection(): Collection
    {
        $ids = $this->warehouseId ? array_values(array_intersect($this->warehouseIds, [$this->warehouseId])) : $this->warehouseIds;
        $balances = StockBalances::forWarehouses($ids);

        return ProductWarehouse::query()
            ->with(['product:id,sku,title,base_uom,min_stock,category_id', 'product.category:id,name', 'warehouse:id,code,name,sort_order'])
            ->whereIn('warehouse_id', $ids ?: [0])
            ->get()
            ->filter(fn (ProductWarehouse $row) => $row->product && $row->warehouse)
            ->sortBy(fn (ProductWarehouse $row) => sprintf('%05d|%s', $row->warehouse->sort_order ?? 0, $row->product->sku))
            ->map(fn (ProductWarehouse $row) => [
                'row' => $row,
                'on_hand' => $balances->onHand($row->product_id, $row->warehouse_id, (float) $row->stock),
                'active_units' => $balances->activeUnits($row->product_id, $row->warehouse_id),
            ])
            ->values();
    }

    protected function group(): string
    {
        return 'balances';
    }

    protected function columns(): array
    {
        return ['warehouse_code', 'warehouse', 'sku', 'title', 'group', 'uom', 'on_hand', 'active_units', 'min_stock', 'below_min'];
    }

    /** @param  array{row: ProductWarehouse, on_hand: float, active_units: int}  $item */
    public function map($item): array
    {
        $row = $item['row'];
        $min = (float) ($row->min_stock ?? $row->product->min_stock ?? 0);

        return [
            $row->warehouse->code,
            $row->warehouse->name,
            $row->product->sku,
            $row->product->title,
            $row->product->category?->name,
            strtoupper((string) $row->product->base_uom),
            $this->number($item['on_hand']),
            $item['active_units'],
            $min ?: null,
            $min > 0 ? $this->yesNo($item['on_hand'] < $min) : null,
        ];
    }
}
