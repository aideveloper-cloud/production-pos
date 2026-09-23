<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpoolmanMapping extends Model
{
    protected $fillable = [
        'product_id',
        'spoolman_filament_id',
        'spoolman_vendor_name',
        'spoolman_material',
        'spoolman_color_hex',
        'weight_uom',
        'is_active',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'spoolman_filament_id' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
