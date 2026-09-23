<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the stock invariants InventoryPostingService maintains:
 *   1. product_warehouse.stock == ledger balance
 *   2. products.stock == sum of product_warehouse.stock
 *   3. tracked products: ACTIVE unit weight == ledger balance
 *   4. tracked products: every ledger row points at a unit
 */
class InventoryLedgerCheckCommand extends Command
{
    protected $signature = 'inventory:ledger-check
        {--fix-cache : Rewrite product_warehouse.stock and products.stock from the ledger (never touches the ledger)}';

    protected $description = 'Check that cached balances and physical units agree with the stock ledger';

    public function handle(): int
    {
        $ledger = DB::table('stock_ledgers')
            ->select('product_id', 'warehouse_id', DB::raw('SUM(qty) AS qty'))
            ->groupBy('product_id', 'warehouse_id');

        $cacheDrift = DB::table('product_warehouse as pw')
            ->leftJoinSub($ledger, 'l', fn ($j) => $j->on('l.product_id', '=', 'pw.product_id')->on('l.warehouse_id', '=', 'pw.warehouse_id'))
            ->whereRaw('ABS(pw.stock - ROUND(COALESCE(l.qty, 0))) > 0')
            ->get(['pw.product_id', 'pw.warehouse_id', 'pw.stock', DB::raw('COALESCE(l.qty, 0) AS ledger_qty')]);

        $orphanLedger = DB::query()->fromSub($ledger, 'l')
            ->leftJoin('product_warehouse as pw', fn ($j) => $j->on('pw.product_id', '=', 'l.product_id')->on('pw.warehouse_id', '=', 'l.warehouse_id'))
            ->whereNull('pw.id')
            ->whereRaw('ABS(l.qty) > 0.000001')
            ->get(['l.product_id', 'l.warehouse_id', 'l.qty']);

        $totalDrift = DB::table('products as p')
            ->leftJoinSub(
                DB::table('product_warehouse')->select('product_id', DB::raw('SUM(stock) AS total'))->groupBy('product_id'),
                'x',
                'x.product_id',
                '=',
                'p.id'
            )
            ->whereRaw('p.stock <> COALESCE(x.total, 0)')
            ->count();

        $unitDrift = DB::query()->fromSub($ledger, 'l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where(fn ($q) => $q->where('p.tracks_physical_units', true)->orWhere('p.spoolman_enabled', true))
            ->leftJoinSub(
                DB::table('physical_units')->where('status', InventoryPostingService::ACTIVE)
                    ->select('product_id', 'warehouse_id', DB::raw('SUM(actual_weight) AS qty'))
                    ->groupBy('product_id', 'warehouse_id'),
                'u',
                fn ($j) => $j->on('u.product_id', '=', 'l.product_id')->on('u.warehouse_id', '=', 'l.warehouse_id')
            )
            ->whereRaw('ABS(l.qty - COALESCE(u.qty, 0)) > 0.0001')
            ->get(['p.sku', 'l.warehouse_id', 'l.qty', DB::raw('COALESCE(u.qty, 0) AS unit_qty')]);

        $unlinkedRows = DB::table('stock_ledgers as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where(fn ($q) => $q->where('p.tracks_physical_units', true)->orWhere('p.spoolman_enabled', true))
            ->whereNull('l.physical_unit_id')
            ->count();

        $inTransit = DB::table('physical_units')->where('status', InventoryPostingService::IN_TRANSIT)->count();

        $this->table(['Check', 'Problems'], [
            ['1. product_warehouse.stock = ledger', $cacheDrift->count() + $orphanLedger->count()],
            ['2. products.stock = Σ warehouses', $totalDrift],
            ['3. ACTIVE units = ledger (tracked)', $unitDrift->count()],
            ['4. tracked ledger rows without unit', $unlinkedRows],
        ]);
        $this->line("Units in transit: {$inTransit}");

        foreach ($cacheDrift->take(20) as $row) {
            $this->warn("  cache  product #{$row->product_id} wh #{$row->warehouse_id}: stock {$row->stock} vs ledger {$row->ledger_qty}");
        }
        foreach ($orphanLedger->take(20) as $row) {
            $this->warn("  cache  product #{$row->product_id} wh #{$row->warehouse_id}: no product_warehouse row, ledger {$row->qty}");
        }
        foreach ($unitDrift->take(20) as $row) {
            $this->warn("  units  {$row->sku} wh #{$row->warehouse_id}: ledger {$row->qty} vs ACTIVE units {$row->unit_qty}");
        }

        $problems = $cacheDrift->count() + $orphanLedger->count() + $totalDrift + $unitDrift->count() + $unlinkedRows;

        if ($this->option('fix-cache') && ($cacheDrift->isNotEmpty() || $orphanLedger->isNotEmpty() || $totalDrift > 0)) {
            DB::transaction(function () use ($cacheDrift, $orphanLedger) {
                foreach ($cacheDrift->concat($orphanLedger) as $row) {
                    $qty = (float) ($row->ledger_qty ?? $row->qty);
                    ProductWarehouse::query()->updateOrCreate(
                        ['product_id' => $row->product_id, 'warehouse_id' => $row->warehouse_id],
                        ['stock' => max(0, (int) round($qty))]
                    );
                }
                Product::query()->each(fn (Product $p) => $p->update([
                    'stock' => (int) ProductWarehouse::query()->where('product_id', $p->id)->sum('stock'),
                ]));
            });
            $this->info('Cache rewritten from the ledger. Unit problems (3, 4) need a stock count or a manual fix.');
        }

        if ($problems === 0) {
            $this->info('Stock ledger, cached balances and physical units agree.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
