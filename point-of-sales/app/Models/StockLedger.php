<?php

namespace App\Models;

use App\Enums\StockLedgerMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedger extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'physical_unit_id',
        'movement_type',
        'qty',
        'uom',
        'qty_before',
        'qty_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
        'meta',
        'created_by',
        'operator_name',
        'weight_kg',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => StockLedgerMovement::class,
            'qty' => 'decimal:6',
            'qty_before' => 'decimal:6',
            'qty_after' => 'decimal:6',
            'weight_kg' => 'decimal:4',
            'reference_id' => 'integer',
            'meta' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function physicalUnit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
