<?php

namespace App\Exports\Warehouse;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

/** Material master with the on-hand qty of every warehouse the user can see as extra columns. */
class MaterialsExport extends WarehouseExport implements FromCollection
{
    /** @var Collection<int, Warehouse> */
    private Collection $warehouses;

    private StockBalances $balances;

    /** @var array<string, float> */
    private array $cached = [];

    /**
     * @param  array<int, int>  $warehouseIds
     */
    public function __construct(array $warehouseIds)
    {
        $this->warehouses = Warehouse::query()->whereIn('id', $warehouseIds ?: [0])->orderBy('sort_order')->orderBy('code')->get(['id', 'code']);
        $this->balances = StockBalances::forWarehouses($this->warehouses->pluck('id')->all());
        foreach (ProductWarehouse::query()->whereIn('warehouse_id', $this->warehouses->pluck('id'))->get(['product_id', 'warehouse_id', 'stock']) as $row) {
            $this->cached["{$row->product_id}:{$row->warehouse_id}"] = (float) $row->stock;
        }
    }

    public function collection(): Collection
    {
        return Product::query()->with('category:id,name')->orderBy('sku')->get();
    }

    protected function group(): string
    {
        return 'materials';
    }

    protected function columns(): array
    {
        return ['sku', 'barcode', 'title', 'group', 'domain', 'uom', 'tracks_units', 'spoolman', 'standard_weight_kg', 'min_stock', 'max_stock', 'total'];
    }

    public function headings(): array
    {
        return [...parent::headings(), ...$this->warehouses->pluck('code')->all()];
    }

    /** @param  Product  $product */
    public function map($product): array
    {
        $perWarehouse = $this->warehouses->map(fn (Warehouse $w) => $this->number($this->balances->onHand(
            $product->id,
            $w->id,
            $this->cached["{$product->id}:{$w->id}"] ?? 0.0,
        )))->all();

        return [
            $product->sku,
            $product->barcode,
            $product->title,
            $product->category?->name,
            $product->domain,
            strtoupper((string) $product->base_uom),
            $this->yesNo((bool) $product->tracks_physical_units),
            $this->yesNo((bool) $product->spoolman_enabled),
            $product->standard_weight_kg !== null ? $this->number($product->standard_weight_kg) : null,
            (int) ($product->min_stock ?? 0),
            (int) ($product->max_stock ?? 0),
            $this->number(array_sum($perWarehouse)),
            ...$perWarehouse,
        ];
    }
}
