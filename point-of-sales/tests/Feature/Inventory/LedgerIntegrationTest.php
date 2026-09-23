<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\PurchaseOrder;
use App\Models\StockLedger;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceivingService;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use App\Services\PurchaseOrderService;
use App\Services\StockTransferService;
use App\Services\SupplierReturnService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Warehouse-mode stock documents all go through the ledger and keep units, cache and ledger equal.
 */
class LedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $phranon;

    private Warehouse $chokdee;

    private Category $category;

    private InventoryPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warehouse.mode' => 'warehouse', 'warehouse.is_warehouse' => true]);

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-ledger@test.local',
            'password' => Hash::make('password'),
        ]);
        $this->admin->markEmailAsVerified();
        $this->admin->syncRoles([$role->name]);
        $this->admin->syncPermissions(Permission::all());
        $this->actingAs($this->admin);

        $this->phranon = Warehouse::create(['code' => 'WH-PHRANON', 'name' => 'พระนอน', 'type' => 'main', 'is_active' => true, 'sort_order' => 1]);
        $this->chokdee = Warehouse::create(['code' => 'WH-CHOKDEE', 'name' => 'โชคดี', 'type' => 'main', 'is_active' => true, 'sort_order' => 2]);
        $this->category = Category::create(['image' => 'c.png', 'name' => 'RM', 'description' => 'RM']);
        $this->posting = app(InventoryPostingService::class);
    }

    #[Test]
    public function receiving_a_po_posts_to_the_ledger_on_a_new_lotted_unit(): void
    {
        $product = $this->rm('RM-W18-H');
        $order = $this->orderedPo($product, 10);

        $receiving = app(GoodsReceivingService::class)->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'qty_received' => 6,
        ]], null, $this->admin->id);

        $unit = PhysicalUnit::query()->where('product_id', $product->id)->sole();
        $this->assertSame($receiving->document_number, $unit->lot_code);
        $this->assertEquals(6, (float) $unit->actual_weight);
        $this->assertLedger($product, $this->phranon, StockLedgerMovement::Receive, 6, 'goods_receiving');
        $this->assertSame(6, $this->cached($product, $this->phranon));
        $this->assertInvariants();
    }

    #[Test]
    public function transferring_a_whole_unit_moves_that_unit(): void
    {
        $product = $this->rm('RM-PIPE-1');
        $unit = $this->open($product, $this->phranon, 5);

        $transfer = $this->sendTransfer($product, 5);
        $this->assertSame(InventoryPostingService::IN_TRANSIT, $unit->fresh()->status);
        $this->assertSame(0, $this->cached($product, $this->phranon));
        $this->assertInvariants();

        app(StockTransferService::class)->receive($transfer, $this->admin->id);

        $unit->refresh();
        $this->assertSame($this->chokdee->id, $unit->warehouse_id);
        $this->assertSame(InventoryPostingService::ACTIVE, $unit->status);
        $this->assertSame(1, PhysicalUnit::query()->where('product_id', $product->id)->count(), 'label + spool of the moved unit stay valid');
        $this->assertSame(5, $this->cached($product, $this->chokdee));
        $this->assertLedger($product, $this->chokdee, StockLedgerMovement::TransferIn, 5, 'stock_transfer');
        $this->assertInvariants();
    }

    #[Test]
    public function partial_transfer_splits_a_unit_and_cancelling_merges_it_back(): void
    {
        $product = $this->rm('RM-PIPE-2');
        $unit = $this->open($product, $this->phranon, 10);

        $transfer = $this->sendTransfer($product, 4);
        $this->assertEquals(6, (float) $unit->fresh()->actual_weight);
        $part = PhysicalUnit::query()->where('status', InventoryPostingService::IN_TRANSIT)->sole();
        $this->assertEquals(4, (float) $part->actual_weight);
        $this->assertSame($unit->id, $part->meta['split_from_unit_id']);
        $this->assertInvariants();

        app(StockTransferService::class)->cancel($transfer, $this->admin->id);

        $this->assertEquals(10, (float) $unit->fresh()->actual_weight);
        $this->assertNull(PhysicalUnit::find($part->id), 'the split-off part is merged back, not left behind');
        $this->assertSame(10, $this->cached($product, $this->phranon));
        $this->assertEquals(10, (float) StockLedger::query()->where('product_id', $product->id)->sum('qty'));
        $this->assertInvariants();
    }

    #[Test]
    public function partial_transfer_arrives_as_the_split_unit(): void
    {
        $product = $this->rm('RM-PIPE-3');
        $this->open($product, $this->phranon, 10);

        $transfer = $this->sendTransfer($product, 3);
        app(StockTransferService::class)->receive($transfer, $this->admin->id);

        $this->assertSame(7, $this->cached($product, $this->phranon));
        $this->assertSame(3, $this->cached($product, $this->chokdee));
        $this->assertEquals(3, (float) PhysicalUnit::query()->where('warehouse_id', $this->chokdee->id)->sole()->actual_weight);
        $this->assertInvariants();
    }

    #[Test]
    public function supplier_return_takes_from_the_receiving_lot_first_and_cannot_complete_twice(): void
    {
        $product = $this->rm('RM-SHEET-1');
        $opening = $this->open($product, $this->phranon, 5);
        $order = $this->orderedPo($product, 4);
        $receiving = app(GoodsReceivingService::class)->receive($order, [[
            'purchase_order_item_id' => $order->items->first()->id,
            'qty_received' => 4,
        ]], null, $this->admin->id);
        $grUnit = PhysicalUnit::query()->where('lot_code', $receiving->document_number)->sole();

        $service = app(SupplierReturnService::class);
        $return = $service->createReturn(
            ['warehouse_id' => $this->phranon->id, 'goods_receiving_id' => $receiving->id],
            [[
                'product_id' => $product->id,
                'qty_returned' => 3,
                'goods_receiving_item_id' => $receiving->items()->first()->id,
            ]],
            $this->admin->id,
        );
        $service->complete($return, $this->admin->id);

        $this->assertEquals(1, (float) $grUnit->fresh()->actual_weight, 'returned from the GR lot');
        $this->assertEquals(5, (float) $opening->fresh()->actual_weight, 'older opening stock untouched');
        $this->assertSame(6, $this->cached($product, $this->phranon));

        $this->expectException(ValidationException::class);
        try {
            $service->complete($return, $this->admin->id);
        } finally {
            $this->assertSame(6, $this->cached($product, $this->phranon), 'second complete must not deduct again');
            $this->assertInvariants();
        }
    }

    #[Test]
    public function supplier_return_cannot_exceed_what_is_on_hand(): void
    {
        $product = $this->rm('RM-SHEET-2');
        $this->open($product, $this->phranon, 2);
        $service = app(SupplierReturnService::class);
        $return = $service->createReturn(
            ['warehouse_id' => $this->phranon->id],
            [['product_id' => $product->id, 'qty_returned' => 5]],
            $this->admin->id,
        );

        try {
            $service->complete($return, $this->admin->id);
            $this->fail('Expected a validation error');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(2, $this->cached($product, $this->phranon));
        $this->assertSame('draft', $return->fresh()->status);
        $this->assertInvariants();
    }

    #[Test]
    public function stock_count_posts_only_the_difference(): void
    {
        $lower = $this->rm('RM-COUNT-1');
        $higher = $this->rm('RM-COUNT-2');
        $this->open($lower, $this->phranon, 10);
        $this->open($higher, $this->phranon, 3);

        $opname = StockOpname::create(['code' => 'SO-TEST-1', 'warehouse_id' => $this->phranon->id, 'status' => 'draft', 'created_by' => $this->admin->id]);
        StockOpnameItem::create(['stock_opname_id' => $opname->id, 'product_id' => $lower->id, 'system_stock' => 10, 'physical_stock' => 7, 'difference' => -3, 'adjustment_reason' => 'เสียหาย']);
        StockOpnameItem::create(['stock_opname_id' => $opname->id, 'product_id' => $higher->id, 'system_stock' => 3, 'physical_stock' => 5, 'difference' => 2, 'adjustment_reason' => 'พบเพิ่ม']);

        $this->post(route('stock-opnames.finalize', $opname))->assertRedirect();

        $this->assertSame(7, $this->cached($lower, $this->phranon));
        $this->assertSame(5, $this->cached($higher, $this->phranon));
        $this->assertLedger($lower, $this->phranon, StockLedgerMovement::Count, -3, 'stock_opname');
        $this->assertLedger($higher, $this->phranon, StockLedgerMovement::Count, 2, 'stock_opname');
        $this->assertSame('SO-TEST-1', PhysicalUnit::query()->where('product_id', $higher->id)->latest('id')->first()->lot_code);
        $this->assertInvariants();
    }

    #[Test]
    public function opening_stock_on_product_create_is_posted_once(): void
    {
        Storage::fake('public');

        $this->post(route('products.store'), [
            'image' => UploadedFile::fake()->image('p.png'),
            'barcode' => 'RM-NEW-1',
            'sku' => 'RM-NEW-1',
            'title' => 'วัตถุดิบใหม่',
            'description' => 'ทดสอบ',
            'category_id' => $this->category->id,
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => 12,
            'warehouse_id' => $this->phranon->id,
        ])->assertRedirect(route('products.index'));

        $product = Product::query()->where('sku', 'RM-NEW-1')->sole();
        $this->assertSame(12, (int) $product->stock, 'not counted twice');
        $this->assertSame(12, $this->cached($product, $this->phranon));
        $this->assertLedger($product, $this->phranon, StockLedgerMovement::Receive, 12, 'product_create');
        $this->assertInvariants();
    }

    #[Test]
    public function ledger_page_lists_movements_with_totals(): void
    {
        $product = $this->rm('RM-PAGE-1');
        $this->open($product, $this->phranon, 8);
        $this->posting->issue($product, $this->phranon->id, 3, StockLedgerMovement::Issue, new PostingContext('supplier_return', 1, 'x', $this->admin->id));

        $this->get(route('stock-ledgers.index', ['warehouse_id' => $this->phranon->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/StockLedgers/Index')
                ->where('totals.rows', 2)
                ->where('totals.qty_in', 8)
                ->where('totals.qty_out', 3)
                ->has('ledgers.data', 2));

        $this->get(route('stock-ledgers.index', ['movement_type' => 'ISSUE']))
            ->assertInertia(fn ($page) => $page->where('totals.rows', 1));
    }

    private function rm(string $sku): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'image' => 'p.png',
            'barcode' => $sku,
            'sku' => $sku,
            'title' => $sku,
            'description' => $sku,
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => 0,
            'domain' => 'RM',
            'base_uom' => 'PCS',
            'tracks_physical_units' => true,
            'spoolman_enabled' => false,
        ]);
    }

    private function open(Product $product, Warehouse $warehouse, float $qty): PhysicalUnit
    {
        return $this->posting->receive($product, $warehouse->id, $qty, StockLedgerMovement::Receive,
            new PostingContext('stock_rm_opening', null, 'opening', $this->admin->id), null, 'OPENING');
    }

    private function orderedPo(Product $product, int $qty): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'ผู้จำหน่าย', 'phone' => '0800000000', 'email' => 's'.$product->id.'@test.local']);
        $service = app(PurchaseOrderService::class);
        $order = $service->createOrder(
            data: ['supplier_id' => $supplier->id, 'warehouse_id' => $this->phranon->id],
            items: [['product_id' => $product->id, 'qty_ordered' => $qty, 'unit_price' => 100]],
            userId: $this->admin->id,
        );
        $service->placeOrder($order);

        return $order->fresh('items');
    }

    private function sendTransfer(Product $product, int $qty): StockTransfer
    {
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft(
            ['source_warehouse_id' => $this->phranon->id, 'destination_warehouse_id' => $this->chokdee->id],
            [['product_id' => $product->id, 'qty' => $qty]],
            $this->admin->id,
        );
        $service->send($transfer, $this->admin->id);

        return $transfer->fresh();
    }

    private function cached(Product $product, Warehouse $warehouse): int
    {
        return (int) ProductWarehouse::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');
    }

    private function assertLedger(Product $product, Warehouse $warehouse, StockLedgerMovement $type, float $qty, string $referenceType): void
    {
        $this->assertEquals($qty, (float) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('movement_type', $type)
            ->where('reference_type', $referenceType)
            ->sum('qty'));
    }

    private function assertInvariants(): void
    {
        $this->artisan('inventory:ledger-check')->assertSuccessful();
    }
}
