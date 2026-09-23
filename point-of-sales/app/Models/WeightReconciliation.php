<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeightReconciliation extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'as_of',
        'ledger_expected_weight',
        'physical_sum_weight',
        'spoolman_sum_weight',
        'variance_weight',
        'dq_status',
        'resolution',
        'adjustment_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'ledger_expected_weight' => 'decimal:4',
            'physical_sum_weight' => 'decimal:4',
            'spoolman_sum_weight' => 'decimal:4',
            'variance_weight' => 'decimal:4',
            'adjustment_id' => 'integer',
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
}
