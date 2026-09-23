<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalUnit extends Model
{
    protected $fillable = [
        'unit_code',
        'product_id',
        'warehouse_id',
        'location_id',
        'lot_code',
        'unit_type',
        'status',
        'nominal_qty',
        'nominal_weight',
        'actual_weight',
        'weight_uom',
        'weight_source',
        'weight_verified_at',
        'spoolman_spool_id',
        'opened_at',
        'depleted_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'nominal_qty' => 'decimal:4',
            'nominal_weight' => 'decimal:4',
            'actual_weight' => 'decimal:4',
            'spoolman_spool_id' => 'integer',
            'weight_verified_at' => 'datetime',
            'opened_at' => 'datetime',
            'depleted_at' => 'datetime',
            'meta' => 'array',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }
}
