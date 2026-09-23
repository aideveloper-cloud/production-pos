<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Company warehouses for WMS (RM/FG sites).
 */
class CompanyWarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'code' => 'WH-PHRANON',
                'name' => 'โกดังพระนอน',
                'type' => 'main',
                'sort_order' => 1,
            ],
            [
                'code' => 'WH-DECHA-MESH',
                'name' => 'โกดังเดชา / ผลิตตะแกรง',
                'type' => 'warehouse',
                'sort_order' => 2,
            ],
            [
                'code' => 'WH-DECHA-POST',
                'name' => 'โกดังเดชา / ผลิตเสา+ประตู',
                'type' => 'warehouse',
                'sort_order' => 3,
            ],
            [
                'code' => 'WH-CHOKDEE',
                'name' => 'โกดังโชคดี',
                'type' => 'warehouse',
                'sort_order' => 4,
            ],
        ];

        foreach ($warehouses as $row) {
            Warehouse::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }
}
