<?php

namespace App\Exports\Warehouse;

use App\Models\StockLedger;
use App\Services\Inventory\StockLedgerFilter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;

/** Stock movements with the same filters as the history page. */
class StockLedgerExport extends WarehouseExport implements FromQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $warehouseIds
     */
    public function __construct(
        private readonly array $filters,
        private readonly array $warehouseIds,
    ) {}

    public function query(): Builder
    {
        return StockLedgerFilter::apply(StockLedger::query(), $this->filters, $this->warehouseIds)
            ->with(['product:id,sku,title', 'warehouse:id,code,name', 'physicalUnit:id,unit_code,lot_code', 'creator:id,name'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id');
    }

    protected function group(): string
    {
        return 'ledger';
    }

    protected function columns(): array
    {
        return ['posted_at', 'warehouse_code', 'warehouse', 'sku', 'title', 'unit_code', 'lot', 'type', 'qty', 'uom', 'balance', 'reference', 'document', 'by', 'notes'];
    }

    /** @param  StockLedger  $ledger */
    public function map($ledger): array
    {
        return [
            $ledger->posted_at?->format('Y-m-d H:i'),
            $ledger->warehouse?->code,
            $ledger->warehouse?->name,
            $ledger->product?->sku,
            $ledger->product?->title,
            $ledger->physicalUnit?->unit_code,
            $ledger->physicalUnit?->lot_code,
            $this->label('types', $ledger->movement_type?->value),
            $this->number($ledger->qty),
            $ledger->uom,
            $this->number($ledger->qty_after),
            $this->label('references', $ledger->reference_type),
            $ledger->meta['document_number'] ?? null,
            $ledger->operator_name ?: $ledger->creator?->name,
            $ledger->notes,
        ];
    }
}
