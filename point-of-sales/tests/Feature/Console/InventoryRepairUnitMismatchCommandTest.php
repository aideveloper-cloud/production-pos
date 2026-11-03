<?php

namespace Tests\Feature\Console;

use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryRepairUnitMismatchCommandTest extends TestCase
{
    use RefreshDatabase;

    private PhysicalUnit $pipeUnit;

    private Product $wire;

    private StockLedger $misposted;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warehouse.mode' => 'warehouse', 'warehouse.is_warehouse' => true]);

        $user = User::create(['name' => 'Admin', 'email' => 'repair@test.local', 'password' => Hash::make('password')]);
        $warehouse = Warehouse::create(['code' => 'WH-PHRANON', 'name' => 'พระนอน', 'type' => 'main', 'is_active' => true, 'sort_order' => 1]);
        $category = Category::create(['image' => 'c.png', 'name' => 'RM', 'description' => 'RM']);
        $make = fn (string $sku, string $uom) => Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => $sku, 'sku' => $sku, 'title' => $sku,
            'description' => 'RM', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'RM', 'base_uom' => $uom, 'tracks_physical_units' => true,
        ]);
        $pipe = $make('RM-PIPE-114-32', 'PCS');
        $this->wire = $make('RM-W25-S', 'ROLL');

        $posting = app(InventoryPostingService::class);
        $posting->receive($pipe, $warehouse->id, 2, StockLedgerMovement::Receive, new PostingContext('stock_rm_opening', null, 'opening', $user->id), null, 'OPENING');
        $this->pipeUnit = PhysicalUnit::query()->where('product_id', $pipe->id)->sole();

        // What the old receive did with unit_code=PU-…: ledger names the wire, qty lands on the pipe's unit.
        $this->pipeUnit->update(['actual_weight' => 27, 'weight_uom' => 'ROLL']);
        $this->misposted = StockLedger::create([
            'product_id' => $this->wire->id, 'warehouse_id' => $warehouse->id, 'physical_unit_id' => $this->pipeUnit->id,
            'movement_type' => StockLedgerMovement::Receive, 'qty' => 25, 'uom' => 'ROLL', 'qty_before' => 0, 'qty_after' => 25,
            'reference_type' => 'warehouse_floor_receive', 'reference_id' => $this->pipeUnit->id, 'created_by' => $user->id,
            'operator_name' => 'test', 'posted_at' => now(),
        ]);
        $this->wire->warehouses()->attach($warehouse->id, ['stock' => 0]);
        $posting->refreshCache($this->wire, $warehouse->id, 25);
    }

    #[Test]
    public function dry_run_lists_the_row_and_changes_nothing(): void
    {
        $this->artisan('inventory:repair-unit-mismatch')
            ->expectsOutputToContain('FIX')
            ->expectsOutputToContain('Dry run: 1 row(s)')
            ->assertSuccessful();

        $this->assertEquals(27, $this->pipeUnit->fresh()->actual_weight);
        $this->assertSame($this->pipeUnit->id, $this->misposted->fresh()->physical_unit_id);
    }

    #[Test]
    public function apply_moves_the_qty_onto_a_new_unit_of_the_received_product(): void
    {
        $this->artisan('inventory:repair-unit-mismatch', ['--apply' => true])->assertSuccessful();

        $pipeUnit = $this->pipeUnit->fresh();
        $this->assertEquals(2, $pipeUnit->actual_weight);
        $this->assertSame('PCS', $pipeUnit->weight_uom);
        $this->assertSame('ACTIVE', $pipeUnit->status);

        $wireUnit = PhysicalUnit::query()->where('product_id', $this->wire->id)->sole();
        $this->assertEquals(25, $wireUnit->actual_weight);
        $this->assertSame('ROLL', $wireUnit->unit_type);
        $this->assertSame($wireUnit->id, $this->misposted->fresh()->physical_unit_id);
        $this->assertSame($this->pipeUnit->unit_code, $this->misposted->fresh()->meta['repaired_from_unit']);
        $this->assertEquals(25, StockLedger::query()->where('product_id', $this->wire->id)->sum('qty'), 'ledger qty untouched');

        $this->artisan('inventory:ledger-check')->assertSuccessful();
        $this->artisan('inventory:repair-unit-mismatch')->expectsOutputToContain('No ledger rows')->assertSuccessful();
    }

    #[Test]
    public function a_unit_that_was_already_cut_below_the_qty_is_left_for_a_manual_fix(): void
    {
        $this->pipeUnit->update(['actual_weight' => 10]);

        $this->artisan('inventory:repair-unit-mismatch', ['--apply' => true])
            ->expectsOutputToContain('SKIP')
            ->assertSuccessful();

        $this->assertEquals(10, $this->pipeUnit->fresh()->actual_weight);
        $this->assertSame(0, PhysicalUnit::query()->where('product_id', $this->wire->id)->count());
    }
}
