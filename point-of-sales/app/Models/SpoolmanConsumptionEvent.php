<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpoolmanConsumptionEvent extends Model
{
    protected $fillable = [
        'event_id',
        'spoolman_spool_id',
        'physical_unit_id',
        'product_id',
        'warehouse_id',
        'qty_consumed',
        'qty_uom',
        'weight_before',
        'weight_after',
        'occurred_at',
        'payload',
        'process_status',
        'stock_ledger_id',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'spoolman_spool_id' => 'integer',
            'qty_consumed' => 'decimal:6',
            'weight_before' => 'decimal:4',
            'weight_after' => 'decimal:4',
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function physicalUnit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockLedger(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class);
    }
}
