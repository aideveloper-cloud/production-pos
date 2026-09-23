<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockCalcStrategy;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarehouseFloorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warehouse.mode' => 'warehouse', 'warehouse.is_warehouse' => true]);

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin-floor@test.local',
            'password' => Hash::make('password'),
        ]);
        $this->user->markEmailAsVerified();
        $this->user->syncRoles([$role->name]);
        $this->user->syncPermissions(Permission::all());

        $this->warehouse = Warehouse::create([
            'code' => 'WH-PHRANON',
            'name' => 'โกดังพระนอน',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $category = Category::create([
            'image' => 'c.png',
            'name' => 'RM',
            'description' => 'RM',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'image' => 'p.png',
            'barcode' => 'RM-TEST-1',
            'sku' => 'RM-TEST-1',
            'title' => 'Test RM',
            'description' => 'Test',
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => 0,
            'domain' => 'RM',
            'base_uom' => 'KG',
            'stock_calc_strategy' => StockCalcStrategy::ActualWeightBased,
            'tracks_physical_units' => true,
            'spoolman_enabled' => false,
        ]);
    }

    #[Test]
    public function receive_and_cut_post_to_stock_ledger(): void
    {
        $this->actingAs($this->user);

        $this->post(route('warehouse-floor.receive'), [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 10,
            'uom' => 'KG',
            'create_unit' => true,
            'unit_code' => 'PU-TEST-01',
            'operator_name' => 'สมชาย',
        ])->assertRedirect();

        $unit = PhysicalUnit::query()->where('unit_code', 'PU-TEST-01')->first();
        $this->assertNotNull($unit);
        $this->assertSame('10.0000', number_format((float) $unit->actual_weight, 4, '.', ''));
        $this->assertSame(1, StockLedger::count());

        $this->post(route('warehouse-floor.cut'), [
            'warehouse_id' => $this->warehouse->id,
            'physical_unit_id' => $unit->id,
            'qty' => 2.5,
            'uom' => 'KG',
            'operator_name' => 'สมชาย',
        ])->assertRedirect();

        $cut = StockLedger::query()->where('movement_type', 'ISSUE')->first();
        $this->assertSame('สมชาย', $cut?->operator_name);

        $this->assertSame('7.5000', number_format((float) $unit->fresh()->actual_weight, 4, '.', ''));
        $this->assertSame(2, StockLedger::count());
    }

    #[Test]
    public function cut_with_unconvertible_uom_is_a_validation_error(): void
    {
        $this->actingAs($this->user);
        $unit = $this->makeUnit('PU-UOM-01', 10);

        $this->post(route('warehouse-floor.cut'), [
            'physical_unit_id' => $unit->id,
            'qty' => 1,
            'uom' => 'ROLL',
            'operator_name' => 'สมชาย',
        ])->assertSessionHasErrors('uom');

        $this->assertSame('10.0000', number_format((float) $unit->fresh()->actual_weight, 4, '.', ''));
        $this->assertSame(0, StockLedger::count());
    }

    #[Test]
    public function new_unit_codes_skip_codes_already_taken_after_a_delete(): void
    {
        $this->actingAs($this->user);
        $first = $this->makeUnit('PU-000001', 1);
        $this->makeUnit('PU-000002', 1);
        $first->delete();

        // count()+1 = 2 is taken by PU-000002; the next code must skip it instead of hitting the unique index.
        $this->post(route('warehouse-floor.receive'), [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 3,
            'create_unit' => true,
            'operator_name' => 'สมชาย',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(PhysicalUnit::query()->where('unit_code', 'PU-000003')->exists());
    }

    #[Test]
    public function pin_locked_device_cannot_look_up_units_of_another_warehouse(): void
    {
        $other = Warehouse::create([
            'code' => 'WH-CHOKDEE',
            'name' => 'โกดังโชคดี',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $foreign = $this->makeUnit('PU-OTHER-01', 5, $other);

        $device = User::create([
            'name' => 'POS device',
            'email' => 'device-floor@test.local',
            'password' => Hash::make('password'),
        ]);
        $device->markEmailAsVerified();
        $device->givePermissionTo('stock-mutations-access');

        $this->actingAs($device)
            ->withSession(['pos.warehouse_id' => $this->warehouse->id])
            ->postJson(route('pos.lookup'), [
                'code' => $foreign->unit_code,
                'warehouse_id' => $other->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function retail_transaction_route_is_blocked_in_warehouse_mode(): void
    {
        $this->actingAs($this->user);

        $this->get('/dashboard/transactions')->assertNotFound();
    }

    private function makeUnit(string $code, float $weight, ?Warehouse $warehouse = null): PhysicalUnit
    {
        return PhysicalUnit::create([
            'unit_code' => $code,
            'product_id' => $this->product->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'unit_type' => 'ROLL',
            'status' => 'ACTIVE',
            'actual_weight' => $weight,
            'weight_uom' => 'KG',
            'weight_source' => 'MANUAL',
        ]);
    }
}
