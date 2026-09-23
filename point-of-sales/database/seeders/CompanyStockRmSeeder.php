<?php

namespace Database\Seeders;

use App\Enums\StockCalcStrategy;
use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Import RM catalog + current stock from Stock RM.xlsx
 * (only "Stock ปัจจุบัน" / "จำนวนคงเหลือ", split by warehouse).
 */
class CompanyStockRmSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CompanyWarehouseSeeder::class);

        // Company stock data is kept out of the repo; generate it with data/extract_stock_rm.py.
        $path = database_path('seeders/data/stock-rm.json');
        $rows = File::exists($path) ? (json_decode(File::get($path), true) ?? []) : [];
        if ($rows === []) {
            $this->command?->error("Missing or empty {$path}");

            return;
        }

        $this->wipeOldInventory();

        $ledger = app(StockLedgerService::class);
        $userId = User::query()->where('email', 'admin@wms.local')->value('id');
        $warehouses = Warehouse::query()->get()->keyBy('code');
        $cuttable = 0;

        foreach ($rows as $row) {
            $warehouse = $warehouses->get($row['warehouse_code'] ?? '');
            if (! $warehouse) {
                $this->command?->warn('Skip unknown warehouse: '.($row['warehouse_code'] ?? '?'));

                continue;
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $uom = $this->uom($row['uom'] ?? null);
            $category = Category::query()->firstOrCreate(
                ['name' => $row['type'] ?: 'วัตถุดิบ (RM)'],
                ['image' => 'rm.png', 'description' => 'Raw materials']
            );

            $qty = max(0, (float) ($row['stock'] ?? 0));

            $product = Product::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => $category->id,
                    'image' => 'product.png',
                    'barcode' => $sku,
                    'title' => $this->title($row),
                    'description' => $this->description($row),
                    'buy_price' => 0,
                    'sell_price' => 0,
                    'stock' => (int) round($qty),
                    'domain' => 'RM',
                    'base_uom' => $uom['base'],
                    'secondary_uom' => $uom['base'] === 'ROLL' ? 'KG' : null,
                    'stock_calc_strategy' => $uom['strategy'],
                    'tracks_physical_units' => true,
                    'spoolman_enabled' => false,
                    'standard_weight_kg' => $row['weight_per_roll'] ?? null,
                ]
            );

            $product->warehouses()->syncWithoutDetaching([
                $warehouse->id => ['stock' => (int) round($qty)],
            ]);

            if ($qty > 0) {
                $unit = PhysicalUnit::query()->create([
                    'unit_code' => $sku,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'lot_code' => 'OPENING',
                    'unit_type' => $uom['unit_type'],
                    'status' => 'ACTIVE',
                    'nominal_qty' => $qty,
                    'nominal_weight' => $qty,
                    'actual_weight' => $qty,
                    'weight_uom' => $uom['base'],
                    'weight_source' => 'IMPORT',
                    'weight_verified_at' => now(),
                    'opened_at' => now(),
                    'meta' => [
                        'source' => 'stock_rm.xlsx',
                        'sheet' => $row['sheet'] ?? null,
                    ],
                ]);

                $ledger->post(
                    product: $product,
                    warehouseId: $warehouse->id,
                    movementType: StockLedgerMovement::Receive,
                    qtySigned: (string) $qty,
                    uom: $uom['base'],
                    physicalUnit: $unit,
                    referenceType: 'stock_rm_opening',
                    referenceId: $unit->id,
                    notes: 'Opening from Stock RM.xlsx — ready to cut',
                    meta: [
                        'source' => 'stock_rm.xlsx',
                        'sheet' => $row['sheet'] ?? null,
                        'warehouse_code' => $warehouse->code,
                    ],
                    userId: $userId,
                );
                $cuttable++;
            }

            $product->update([
                'stock' => (int) $product->warehouses()->sum('product_warehouse.stock'),
            ]);
        }

        $this->command?->info('Cleared leftover catalog/stock and imported '.count($rows).' SKUs ('.$cuttable.' ready to cut).');
    }

    private function wipeOldInventory(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'transaction_detail_batch_allocations',
            'spoolman_consumption_events',
            'weight_reconciliations',
            'product_spoolman_mappings',
            'stock_ledgers',
            'physical_units',
            'product_warehouse',
            'product_units',
            'product_batches',
            'composite_product_items',
            'product_notification_reads',
            'carts',
            'dine_order_items',
            'stock_transfer_items',
            'stock_opname_items',
            'stock_mutations',
            'sales_return_items',
            'purchase_order_items',
            'goods_receiving_items',
            'supplier_return_items',
            'price_list_items',
            'pricing_rule_buy_get_items',
            'pricing_rule_bundle_items',
            'transaction_details',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        if (Schema::hasTable('pricing_rules')) {
            if (Schema::hasColumn('pricing_rules', 'product_id')) {
                DB::table('pricing_rules')->update(['product_id' => null]);
            }
            if (Schema::hasColumn('pricing_rules', 'category_id')) {
                DB::table('pricing_rules')->update(['category_id' => null]);
            }
        }

        DB::table('products')->delete();
        DB::table('categories')->delete();

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function title(array $row): string
    {
        $skip = ['แข็ง', 'นิ่ม', 'ชุบ/ขาว', 'ดำ', 'เกลียว'];
        $name = $row['name'] ?? null;
        if ($name && in_array($name, $skip, true)) {
            $name = null;
        }

        $thickness = $row['thickness'] ?? null;
        if (in_array($thickness, [null, '', '—', '-'], true)) {
            $thickness = null;
        }

        $parts = array_values(array_unique(array_filter([
            $row['type'] ?? null,
            $name,
            $row['size'] ?? null,
            $thickness,
        ])));

        return $parts !== [] ? implode(' ', $parts) : (string) $row['sku'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function description(array $row): string
    {
        $bits = array_filter([
            $row['type'] ?? null,
            $row['name'] ?? null,
            $row['size'] ?? null,
            $row['characteristic'] ?? null,
            isset($row['weight_per_roll']) ? $row['weight_per_roll'].' กก./ม้วน' : null,
            $row['note'] ?? null,
        ]);

        return implode(' · ', $bits);
    }

    /**
     * @return array{base:string, strategy:StockCalcStrategy, track:bool, unit_type:string}
     */
    private function uom(?string $thai): array
    {
        return match ($thai) {
            'ม้วน' => [
                'base' => 'ROLL',
                'strategy' => StockCalcStrategy::QtyBased,
                'track' => true,
                'unit_type' => 'ROLL',
            ],
            'แผ่น' => [
                'base' => 'SHEET',
                'strategy' => StockCalcStrategy::SheetBased,
                'track' => true,
                'unit_type' => 'SHEET_PACK',
            ],
            default => [
                'base' => 'PCS',
                'strategy' => StockCalcStrategy::PieceBased,
                'track' => true,
                'unit_type' => 'OTHER',
            ],
        };
    }
}
