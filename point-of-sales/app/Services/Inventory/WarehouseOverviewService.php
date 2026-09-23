<?php

namespace App\Services\Inventory;

use App\Models\PhysicalUnit;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletAccessService;

class WarehouseOverviewService
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function warehouseKpis(User $user): array
    {
        $warehouses = $this->outletAccessService->warehousesFor($user);

        return $warehouses->map(function (Warehouse $warehouse) {
            $activeUnits = PhysicalUnit::query()
                ->where('warehouse_id', $warehouse->id)
                ->active()
                ->get(['id', 'product_id', 'actual_weight', 'status']);

            $weight = (float) $activeUnits->sum('actual_weight');
            $productIds = $activeUnits->pluck('product_id')->unique()->values();

            $ledgerQty = (float) StockLedger::query()
                ->where('warehouse_id', $warehouse->id)
                ->sum('qty');

            $todayMoves = StockLedger::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereDate('posted_at', now()->toDateString())
                ->count();

            return [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'active_units' => $activeUnits->count(),
                'sku_count' => $productIds->count(),
                'total_actual_weight' => number_format($weight, 4, '.', ''),
                'ledger_on_hand_qty' => number_format($ledgerQty, 4, '.', ''),
                'today_movements' => $todayMoves,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentMovements(User $user, int $limit = 80): array
    {
        $warehouseIds = $this->outletAccessService->warehousesFor($user)->pluck('id');

        return StockLedger::query()
            ->with(['product:id,sku,title', 'warehouse:id,code,name', 'physicalUnit:id,unit_code'])
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('posted_at')
            ->limit($limit)
            ->get()
            ->map(fn (StockLedger $row) => [
                'id' => $row->id,
                'movement_type' => $row->movement_type?->value ?? $row->movement_type,
                'qty' => $row->qty,
                'uom' => $row->uom,
                'weight_kg' => $row->weight_kg,
                'operator_name' => $row->operator_name ?: ($row->meta['operator_name'] ?? null),
                'posted_at' => optional($row->posted_at)?->toIso8601String(),
                'sku' => $row->product?->sku,
                'product_title' => $row->product?->title,
                'warehouse_code' => $row->warehouse?->code,
                'warehouse_name' => $row->warehouse?->name,
                'unit_code' => $row->physicalUnit?->unit_code,
                'notes' => $row->notes,
            ])
            ->all();
    }
}
