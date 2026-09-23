<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitLabelController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $warehouses = $this->outletAccessService->warehousesFor($user);

        $warehouseId = (int) $request->integer('warehouse_id');
        if ($warehouseId > 0 && ! $warehouses->contains('id', $warehouseId)) {
            abort(403);
        }

        $query = PhysicalUnit::query()
            ->with([
                'product:id,sku,title,base_uom',
                'warehouse:id,code,name',
            ])
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->where('status', 'ACTIVE')
            ->orderByDesc('updated_at');

        if ($warehouseId > 0) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('unit_code', 'like', "%{$search}%")
                    ->orWhere('lot_code', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('sku', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%");
                    });
            });
        }

        $units = $query->limit(300)->get()->map(fn (PhysicalUnit $unit) => [
            'id' => $unit->id,
            'unit_code' => $unit->unit_code,
            'actual_weight' => $unit->actual_weight,
            'weight_uom' => $unit->weight_uom,
            'lot_code' => $unit->lot_code,
            'spoolman_spool_id' => $unit->spoolman_spool_id,
            'sku' => $unit->product?->sku,
            'product_title' => $unit->product?->title,
            'warehouse_id' => $unit->warehouse_id,
            'warehouse_name' => $unit->warehouse?->name,
            'barcode' => $unit->unit_code,
        ]);

        return Inertia::render('Dashboard/Labels/Index', [
            'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
            ])->values(),
            'currentWarehouseId' => $warehouseId ?: null,
            'search' => $search,
            'units' => $units,
            'products' => Product::query()
                ->where(function ($q) {
                    $q->where('domain', 'RM')
                        ->orWhere('tracks_physical_units', true)
                        ->orWhere('spoolman_enabled', true);
                })
                ->orderBy('sku')
                ->limit(100)
                ->get(['id', 'sku', 'title']),
        ]);
    }
}
