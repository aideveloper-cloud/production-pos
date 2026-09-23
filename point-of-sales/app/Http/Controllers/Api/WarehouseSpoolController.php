<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponder;
use App\Models\PhysicalUnit;
use App\Models\Warehouse;
use App\Services\Inventory\StockCalculationService;
use App\Services\OutletAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseSpoolController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly StockCalculationService $stockCalculationService,
    ) {}

    /**
     * GET /api/v1/warehouses/{warehouse}/spools
     */
    public function index(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $status = $request->string('status')->toString();
        $sku = $request->string('sku')->toString();

        $spools = PhysicalUnit::query()
            ->with(['product:id,sku,title,base_uom,stock_calc_strategy'])
            ->where('warehouse_id', $warehouse->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($sku, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('sku', $sku)))
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->through(fn (PhysicalUnit $unit) => [
                'id' => $unit->id,
                'unit_code' => $unit->unit_code,
                'sku' => $unit->product?->sku,
                'product_title' => $unit->product?->title,
                'status' => $unit->status,
                'unit_type' => $unit->unit_type,
                'actual_weight' => $unit->actual_weight,
                'weight_uom' => $unit->weight_uom,
                'weight_source' => $unit->weight_source,
                'spoolman_spool_id' => $unit->spoolman_spool_id,
                'lot_code' => $unit->lot_code,
            ]);

        return $this->paginated($spools);
    }

    /**
     * GET /api/v1/warehouses/{warehouse}/spools/{unitCode}
     */
    public function show(Request $request, Warehouse $warehouse, string $unitCode): JsonResponse
    {
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $unit = PhysicalUnit::query()
            ->with('product')
            ->where('warehouse_id', $warehouse->id)
            ->where('unit_code', $unitCode)
            ->firstOrFail();

        return $this->ok([
            'unit' => $unit,
            'stock_summary' => $this->stockCalculationService->summarize($unit->product, $warehouse->id),
        ]);
    }
}
