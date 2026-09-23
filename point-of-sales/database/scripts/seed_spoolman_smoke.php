<?php

use App\Enums\StockCalcStrategy;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Warehouse;

$warehouse = Warehouse::query()->firstOrCreate(
    ['code' => 'WH-01'],
    [
        'name' => 'Warehouse 01',
        'type' => 'warehouse',
        'is_active' => true,
        'sort_order' => 0,
    ]
);

$category = Category::query()->firstOrCreate(
    ['name' => 'Filament'],
    [
        'image' => 'cat.png',
        'description' => 'RM filament',
    ]
);

$product = Product::query()->updateOrCreate(
    ['sku' => 'RM-PLA-BLACK'],
    [
        'category_id' => $category->id,
        'image' => 'product.png',
        'barcode' => 'RM-PLA-BLACK',
        'title' => 'PLA Black',
        'description' => 'Filament PLA Black',
        'buy_price' => 0,
        'sell_price' => 0,
        'stock' => 0,
        'domain' => 'RM',
        'base_uom' => 'KG',
        'stock_calc_strategy' => StockCalcStrategy::SpoolWeightBased,
        'tracks_physical_units' => true,
        'spoolman_enabled' => true,
    ]
);

$unit = PhysicalUnit::query()->updateOrCreate(
    ['unit_code' => 'SP-000123'],
    [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'unit_type' => 'SPOOL',
        'status' => 'ACTIVE',
        'actual_weight' => 12.5,
        'weight_uom' => 'KG',
        'weight_source' => 'SPOOLMAN',
        'spoolman_spool_id' => 123,
    ]
);

echo "seeded warehouse={$warehouse->id} product={$product->id} unit={$unit->id}\n";
