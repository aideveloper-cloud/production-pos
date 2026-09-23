<?php

namespace Database\Seeders;

use App\Enums\StockCalcStrategy;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Starter RM/FG catalog for warehouse mode.
 */
class CompanyProductSeeder extends Seeder
{
    public function run(): void
    {
        $rm = Category::query()->firstOrCreate(
            ['name' => 'วัตถุดิบ (RM)'],
            ['image' => 'rm.png', 'description' => 'Raw materials']
        );

        $fg = Category::query()->firstOrCreate(
            ['name' => 'สินค้าสำเร็จรูป (FG)'],
            ['image' => 'fg.png', 'description' => 'Finished goods']
        );

        $items = [
            // Filament / spool-tracked
            [
                'sku' => 'RM-PLA-BLACK',
                'barcode' => 'RM-PLA-BLACK',
                'title' => 'Filament PLA Black',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'KG',
                'secondary_uom' => 'SPOOL',
                'stock_calc_strategy' => StockCalcStrategy::SpoolWeightBased,
                'tracks_physical_units' => true,
                'spoolman_enabled' => true,
                'standard_weight_kg' => 1,
            ],
            [
                'sku' => 'RM-PETG-GREY',
                'barcode' => 'RM-PETG-GREY',
                'title' => 'Filament PETG Grey',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'KG',
                'secondary_uom' => 'SPOOL',
                'stock_calc_strategy' => StockCalcStrategy::SpoolWeightBased,
                'tracks_physical_units' => true,
                'spoolman_enabled' => true,
                'standard_weight_kg' => 1,
            ],
            // Wire / roll — qty + actual weight
            [
                'sku' => 'RM-W40-W',
                'barcode' => 'RM-W40-W',
                'title' => 'ลวด W40-W',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'KG',
                'secondary_uom' => 'ROLL',
                'stock_calc_strategy' => StockCalcStrategy::QtyBased,
                'tracks_physical_units' => true,
                'spoolman_enabled' => false,
                'standard_weight_kg' => 100,
            ],
            [
                'sku' => 'RM-B70',
                'barcode' => 'RM-B70',
                'title' => 'ลวด B70',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'KG',
                'secondary_uom' => 'ROLL',
                'stock_calc_strategy' => StockCalcStrategy::ActualWeightBased,
                'tracks_physical_units' => true,
                'spoolman_enabled' => false,
                'standard_weight_kg' => 1000,
            ],
            // Mesh production (โกดังเดชาตะแกรง)
            [
                'sku' => 'RM-WIRE-MESH',
                'barcode' => 'RM-WIRE-MESH',
                'title' => 'ลวดสำหรับตะแกรง',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'KG',
                'secondary_uom' => 'ROLL',
                'stock_calc_strategy' => StockCalcStrategy::ActualWeightBased,
                'tracks_physical_units' => true,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
            [
                'sku' => 'FG-MESH-STD',
                'barcode' => 'FG-MESH-STD',
                'title' => 'ตะแกรงสำเร็จรูป',
                'category_id' => $fg->id,
                'domain' => 'FG',
                'base_uom' => 'PCS',
                'secondary_uom' => null,
                'stock_calc_strategy' => StockCalcStrategy::PieceBased,
                'tracks_physical_units' => false,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
            // Post + gate (โกดังเดชาเสา+ประตู)
            [
                'sku' => 'RM-PIPE',
                'barcode' => 'RM-PIPE',
                'title' => 'ท่อ/โปรไฟล์สำหรับเสา',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'PCS',
                'secondary_uom' => null,
                'stock_calc_strategy' => StockCalcStrategy::PieceBased,
                'tracks_physical_units' => false,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
            [
                'sku' => 'RM-PLATE',
                'barcode' => 'RM-PLATE',
                'title' => 'แผ่นเหล็ก',
                'category_id' => $rm->id,
                'domain' => 'RM',
                'base_uom' => 'SHEET',
                'secondary_uom' => null,
                'stock_calc_strategy' => StockCalcStrategy::SheetBased,
                'tracks_physical_units' => false,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
            [
                'sku' => 'FG-POST',
                'barcode' => 'FG-POST',
                'title' => 'เสาสำเร็จรูป',
                'category_id' => $fg->id,
                'domain' => 'FG',
                'base_uom' => 'PCS',
                'secondary_uom' => null,
                'stock_calc_strategy' => StockCalcStrategy::PieceBased,
                'tracks_physical_units' => false,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
            [
                'sku' => 'FG-GATE',
                'barcode' => 'FG-GATE',
                'title' => 'ประตูสำเร็จรูป',
                'category_id' => $fg->id,
                'domain' => 'FG',
                'base_uom' => 'PCS',
                'secondary_uom' => null,
                'stock_calc_strategy' => StockCalcStrategy::PieceBased,
                'tracks_physical_units' => false,
                'spoolman_enabled' => false,
                'standard_weight_kg' => null,
            ],
        ];

        foreach ($items as $item) {
            Product::query()->updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'category_id' => $item['category_id'],
                    'image' => 'product.png',
                    'barcode' => $item['barcode'],
                    'title' => $item['title'],
                    'description' => $item['title'],
                    'buy_price' => 0,
                    'sell_price' => 0,
                    'stock' => 0,
                    'domain' => $item['domain'],
                    'base_uom' => $item['base_uom'],
                    'secondary_uom' => $item['secondary_uom'],
                    'stock_calc_strategy' => $item['stock_calc_strategy'],
                    'tracks_physical_units' => $item['tracks_physical_units'],
                    'spoolman_enabled' => $item['spoolman_enabled'],
                    'standard_weight_kg' => $item['standard_weight_kg'],
                ]
            );
        }
    }
}
