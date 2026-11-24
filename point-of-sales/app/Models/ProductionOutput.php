<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finished quantity reported against an ERPNext Work Order. Good quantity is received into a WMS
 * warehouse (stock ledger reference_type production_output); scrap is recorded here only.
 */
class ProductionOutput extends Model
{
    public const REFERENCE_TYPE = 'production_output';

    protected $fillable = [
        'work_order',
        'item_code',
        'product_id',
        'warehouse_id',
        'good_qty',
        'scrap_qty',
        'scrap_reason',
        'uom',
        'user_id',
        'operator_name',
        'client_key',
        'stock_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'good_qty' => 'decimal:6',
            'scrap_qty' => 'decimal:6',
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
