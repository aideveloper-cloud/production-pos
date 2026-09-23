<?php

namespace App\Http\Controllers\Apps;

use App\Enums\StockLedgerMovement;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\OutletAccessService;
use Illuminate\Database\Eloquent\Builder;
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
        $filters = [
            'warehouse_id' => $request->input('warehouse_id'),
            'product_id' => $request->input('product_id'),
            'movement_type' => $request->input('movement_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => trim((string) $request->input('search', '')),
        ];

        $warehouses = $this->outletAccessService->warehousesFor($request->user());
        $query = $this->filtered(StockLedger::query(), $filters, $warehouses->pluck('id')->all());

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

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $allowedWarehouseIds
     */
    private function filtered(Builder $query, array $filters, array $allowedWarehouseIds): Builder
    {
        return $query
            ->whereIn('warehouse_id', $allowedWarehouseIds ?: [0])
            ->when($filters['warehouse_id'], fn (Builder $q, $id) => $q->where('warehouse_id', (int) $id))
            ->when($filters['product_id'], fn (Builder $q, $id) => $q->where('product_id', (int) $id))
            ->when($filters['movement_type'], fn (Builder $q, $type) => $q->where('movement_type', $type))
            ->when($filters['date_from'], fn (Builder $q, $date) => $q->whereDate('posted_at', '>=', $date))
            ->when($filters['date_to'], fn (Builder $q, $date) => $q->whereDate('posted_at', '<=', $date))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(function (Builder $w) use ($term) {
                    $w->where('operator_name', 'like', $term)
                        ->orWhere('notes', 'like', $term)
                        ->orWhere('meta->document_number', 'like', $term)
                        ->orWhereHas('physicalUnit', fn (Builder $u) => $u->where('unit_code', 'like', $term)->orWhere('lot_code', 'like', $term))
                        ->orWhereHas('product', fn (Builder $p) => $p->where('sku', 'like', $term)->orWhere('title', 'like', $term));
                });
            });
    }
}
