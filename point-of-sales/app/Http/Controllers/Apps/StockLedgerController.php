<?php

namespace App\Http\Controllers\Apps;

use App\Enums\StockLedgerMovement;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\Inventory\StockLedgerFilter;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock movement history read straight from stock_ledgers (the source of truth), so every
 * floor cut/receive, transfer, count, goods receiving and supplier return shows up here.
 */
class StockLedgerController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function index(Request $request): Response
    {
        $filters = StockLedgerFilter::fromRequest($request);

        $warehouses = $this->outletAccessService->warehousesFor($request->user());
        $query = StockLedgerFilter::apply(StockLedger::query(), $filters, $warehouses->pluck('id')->all());

        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(CASE WHEN qty > 0 THEN qty ELSE 0 END), 0) AS qty_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN qty < 0 THEN -qty ELSE 0 END), 0) AS qty_out')
            ->selectRaw('COUNT(*) AS rows_count')
            ->first();

        $ledgers = $query
            ->with([
                'product:id,sku,title,base_uom',
                'warehouse:id,code,name',
                'physicalUnit:id,unit_code,lot_code',
                'creator:id,name',
            ])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Dashboard/StockLedgers/Index', [
            'ledgers' => $ledgers,
            'totals' => [
                'qty_in' => (float) ($totals->qty_in ?? 0),
                'qty_out' => (float) ($totals->qty_out ?? 0),
                'rows' => (int) ($totals->rows_count ?? 0),
            ],
            'filters' => $filters,
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
            'products' => Product::query()->orderBy('sku')->get(['id', 'sku', 'title']),
            'movementTypes' => array_map(fn (StockLedgerMovement $type) => $type->value, StockLedgerMovement::cases()),
        ]);
    }
}
