<?php

namespace App\Services\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyWarehouseDigest
{
    /**
     * Today's receive/issue totals and empty SKUs, split by RM and FG.
     *
     * @param  Collection<int, int>|array<int, int>  $warehouseIds
     * @return array{date: string, domains: array<string, array<string, mixed>>}
     */
    public function forWarehouses(Collection|array $warehouseIds): array
    {
        $ids = collect($warehouseIds)->map(fn ($id) => (int) $id)->filter()->values();
        $tz = 'Asia/Bangkok';
        $date = now($tz)->toDateString();
        $start = now($tz)->startOfDay()->utc();
        $end = now($tz)->endOfDay()->utc();

        $blank = [
            'RM' => $this->blankDomain(),
            'FG' => $this->blankDomain(),
        ];

        if ($ids->isEmpty()) {
            return ['date' => $date, 'domains' => $blank];
        }

        $moves = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->whereIn('stock_ledgers.warehouse_id', $ids)
            ->whereBetween('stock_ledgers.posted_at', [$start, $end])
            ->whereIn('products.domain', ['RM', 'FG'])
            ->whereIn('stock_ledgers.movement_type', [
                StockLedgerMovement::Receive->value,
                StockLedgerMovement::Issue->value,
                StockLedgerMovement::Consumption->value,
            ])
            ->groupBy('products.domain', 'stock_ledgers.movement_type')
            ->select([
                'products.domain',
                'stock_ledgers.movement_type',
                DB::raw('COUNT(*) as moves'),
                DB::raw('SUM(ABS(stock_ledgers.qty)) as qty'),
            ])
            ->get();

        foreach ($moves as $row) {
            $domain = (string) $row->domain;
            if (! isset($blank[$domain])) {
                continue;
            }
            $type = $row->movement_type instanceof StockLedgerMovement
                ? $row->movement_type
                : StockLedgerMovement::tryFrom((string) $row->movement_type);
            $issue = in_array($type, [StockLedgerMovement::Issue, StockLedgerMovement::Consumption], true);
            $prefix = $issue ? 'issue' : 'receive';
            $blank[$domain][$prefix.'_moves'] += (int) $row->moves;
            $blank[$domain][$prefix.'_qty'] += (float) $row->qty;
        }

        $empty = Product::query()
            ->select('products.domain', 'products.title', 'products.sku')
            ->selectRaw('SUM(product_warehouse.stock) as on_hand')
            ->join('product_warehouse', 'product_warehouse.product_id', '=', 'products.id')
            ->whereIn('product_warehouse.warehouse_id', $ids)
            ->whereIn('products.domain', ['RM', 'FG'])
            ->where('products.is_composite', false)
            ->groupBy('products.id', 'products.domain', 'products.title', 'products.sku')
            ->havingRaw('SUM(product_warehouse.stock) <= 0')
            ->orderBy('products.sku')
            ->get();

        foreach ($empty as $product) {
            $domain = (string) $product->domain;
            if (! isset($blank[$domain])) {
                continue;
            }
            $blank[$domain]['empty']++;
            if (count($blank[$domain]['empty_names']) < 8) {
                $blank[$domain]['empty_names'][] = $product->sku.' '.$product->title;
            }
        }

        foreach ($blank as $domain => $row) {
            $blank[$domain]['receive_qty'] = $this->qty($row['receive_qty']);
            $blank[$domain]['issue_qty'] = $this->qty($row['issue_qty']);
        }

        return ['date' => $date, 'domains' => $blank];
    }

    /**
     * @return array{receive_moves: int, receive_qty: float, issue_moves: int, issue_qty: float, empty: int, empty_names: array<int, string>}
     */
    private function blankDomain(): array
    {
        return [
            'receive_moves' => 0,
            'receive_qty' => 0,
            'issue_moves' => 0,
            'issue_qty' => 0,
            'empty' => 0,
            'empty_names' => [],
        ];
    }

    private function qty(float $value): float
    {
        return round($value, 2);
    }
}
