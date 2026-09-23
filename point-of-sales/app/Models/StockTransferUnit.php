<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferUnit extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'stock_transfer_item_id',
        'physical_unit_id',
        'source_unit_id',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class, 'physical_unit_id');
    }

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class, 'source_unit_id');
    }
}
