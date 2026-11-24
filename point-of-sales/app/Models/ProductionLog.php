<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone working on an ERPNext Work Order at the production POS (start → stop). */
class ProductionLog extends Model
{
    protected $fillable = [
        'work_order',
        'warehouse_id',
        'user_id',
        'operator_name',
        'started_at',
        'ended_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
