<?php

use App\Enums\StockCalcStrategy;
use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use App\Services\StockMutationService;

$sku = 'RM-BOX-3X3-ZINC-1.5';
$title = 'เหล็กกล่อง 3" × 3" ขาวซิงค์ หนา 1.5 มม.';
$qty = 485;
$warehouse = Warehouse::query()->where('code', 'WH-DECHA-POST')->firstOrFail();
$userId = User::query()->where('email', 'admin@wms.local')->value('id');

if (Product::query()->where('sku', $sku)->exists()) {
    echo "already exists: {$sku}".PHP_EOL;

    return;
}

$category = Category::query()->firstOrCreate(
    ['name' => 'เหล็กกล่อง'],
    ['image' => 'rm.png', 'description' => 'Raw materials']
);

$product = Product::query()->create([
    'image' => 'product.png',
    'barcode' => $sku,
    'sku' => $sku,
    'title' => $title,
    'description' => $title,
    'category_id' => $category->id,
    'domain' => 'RM',
    'base_uom' => 'PCS',
    'stock_calc_strategy' => StockCalcStrategy::PieceBased,
    'tracks_physical_units' => true,
    'spoolman_enabled' => false,
    'standard_weight_kg' => null,
    'buy_price' => 0,
    'sell_price' => 0,
    'stock' => 0,
    'min_stock' => 0,
    'max_stock' => 0,
    'tax_type' => 'exclusive',
    'tax_rate' => 0,
    'is_composite' => false,
]);

$unit = app(InventoryPostingService::class)->receive(
    $product,
    $warehouse->id,
    $qty,
    StockLedgerMovement::Receive,
    new PostingContext('product_create', $product->id, 'เพิ่มรายการเข้าโกดัง', $userId),
    null,
    'OPENING',
);

if ($unit && ! PhysicalUnit::query()->where('unit_code', $sku)->whereKeyNot($unit->id)->exists()) {
    $unit->unit_code = $sku;
    $unit->lot_code = 'OPENING';
    $unit->unit_type = 'OTHER';
    $unit->save();
}

$product->refresh();
app(StockMutationService::class)->recordInitialStock($product, $userId, $warehouse->id);

echo "created {$sku} stock={$product->stock} warehouse={$warehouse->code} unit=".($unit?->unit_code ?? '-').PHP_EOL;
