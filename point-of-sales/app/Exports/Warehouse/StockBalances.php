<?php

namespace App\Exports\Warehouse;

use App\Models\PhysicalUnit;
use App\Models\StockLedger;
use App\Services\Inventory\InventoryPostingService;

/**
 * Bulk on-hand and unit counts for a set of warehouses (two queries instead of one per row).
 * Warehouse mode reads the ledger; retail mode keeps using the cached per-warehouse stock.
 */
final class StockBalances
{
    /**
     * @param  array<string, float>  $ledger
     * @param  array<string, int>  $units
     */
    private function __construct(private readonly array $ledger, private readonly array $units) {}

    /**
     * @param  array<int, int>  $warehouseIds
     */
    public static function forWarehouses(array $warehouseIds): self
    {
        $ledger = [];
        if (config('warehouse.is_warehouse')) {
            foreach (StockLedger::query()
                ->whereIn('warehouse_id', $warehouseIds ?: [0])
                ->selectRaw('product_id, warehouse_id, SUM(qty) AS qty')
                ->groupBy('product_id', 'warehouse_id')
                ->get() as $row) {
                $ledger["{$row->product_id}:{$row->warehouse_id}"] = (float) $row->qty;
            }
        }

        $units = [];
        foreach (PhysicalUnit::query()
            ->whereIn('warehouse_id', $warehouseIds ?: [0])
            ->where('status', InventoryPostingService::ACTIVE)
            ->selectRaw('product_id, warehouse_id, COUNT(*) AS n')
            ->groupBy('product_id', 'warehouse_id')
            ->get() as $row) {
            $units["{$row->product_id}:{$row->warehouse_id}"] = (int) $row->n;
        }

        return new self($ledger, $units);
    }

    public function onHand(int $productId, int $warehouseId, float $cached): float
    {
        return config('warehouse.is_warehouse') ? ($this->ledger["{$productId}:{$warehouseId}"] ?? 0.0) : $cached;
    }

    public function activeUnits(int $productId, int $warehouseId): int
    {
        return $this->units["{$productId}:{$warehouseId}"] ?? 0;
    }
}
