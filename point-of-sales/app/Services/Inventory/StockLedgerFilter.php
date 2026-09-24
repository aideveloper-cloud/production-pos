<?php

namespace App\Services\Inventory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filters for stock ledger listings, shared by the history page and its export so a download
 * always contains exactly what the screen shows.
 */
final class StockLedgerFilter
{
    /**
     * @return array{warehouse_id: mixed, product_id: mixed, movement_type: mixed, date_from: mixed, date_to: mixed, search: string}
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'warehouse_id' => $request->input('warehouse_id'),
            'product_id' => $request->input('product_id'),
            'movement_type' => $request->input('movement_type'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => trim((string) $request->input('search', '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $allowedWarehouseIds
     */
    public static function apply(Builder $query, array $filters, array $allowedWarehouseIds): Builder
    {
        return $query
            ->whereIn('warehouse_id', $allowedWarehouseIds ?: [0])
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, $id) => $q->where('warehouse_id', (int) $id))
            ->when($filters['product_id'] ?? null, fn (Builder $q, $id) => $q->where('product_id', (int) $id))
            ->when($filters['movement_type'] ?? null, fn (Builder $q, $type) => $q->where('movement_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('posted_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('posted_at', '<=', $date))
            ->when(($filters['search'] ?? '') !== '', function (Builder $q) use ($filters) {
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
