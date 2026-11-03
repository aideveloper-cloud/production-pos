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
    public function finished_goods_are_visible_and_can_be_received_and_issued_in_pieces(): void
    {
        $this->product->update([
            'domain' => 'FG',
            'base_uom' => 'PCS',
            'stock_calc_strategy' => StockCalcStrategy::QtyBased,
            'tracks_physical_units' => false,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 0]);
        $this->actingAs($this->user)
            ->get(route('pos.show', ['domain' => 'fg', 'warehouse_id' => $this->warehouse->id]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->has('products', 1)
                ->where('products.0.id', $this->product->id)
                ->where('products.0.domain', 'FG')
                ->where('posDomain', 'FG'));
        $this->post(route('warehouse-floor.receive'), [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 10,
            'uom' => 'PCS',
            'create_unit' => true,
            'unit_code' => 'FG-UNIT-01',
            'operator_name' => 'สมชาย',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $unit = PhysicalUnit::where('unit_code', 'FG-UNIT-01')->firstOrFail();
        $this->assertSame('OTHER', $unit->unit_type);
        $this->post(route('warehouse-floor.cut'), [
            'warehouse_id' => $this->warehouse->id,
            'physical_unit_id' => $unit->id,
            'qty' => 3,
            'uom' => 'PCS',
            'operator_name' => 'สมชาย',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(7, StockLedger::where('product_id', $this->product->id)->sum('qty'));
        $this->assertEquals(7, $this->product->fresh()->stock);
        $this->assertEquals(7, $unit->fresh()->actual_weight);
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
        // The SKU-named code is already used by this product in another warehouse, so a PU-… code is generated.
        $elsewhere = Warehouse::create(['code' => 'WH-CHOKDEE', 'name' => 'โชคดี', 'type' => 'warehouse', 'is_active' => true, 'sort_order' => 4]);
        $this->makeUnit('RM-TEST-1', 1, $elsewhere);

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
            ->withSession(['pos.warehouse_id' => $this->warehouse->id, 'pos.domain' => 'RM'])
            ->postJson(route('pos.lookup', ['domain' => 'rm']), [
                'code' => $foreign->unit_code,
                'warehouse_id' => $other->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function pos_receive_rejects_the_other_domain_while_admin_floor_accepts_both(): void
    {
        $this->actingAs($this->user);

        $this->post(route('pos.receive', ['domain' => 'fg']), [
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $this->product->id,
                'qty' => 1,
                'create_unit' => true,
                'operator_name' => 'สมชาย',
            ])
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, StockLedger::count());

        $this->post(route('warehouse-floor.receive'), [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'create_unit' => true,
            'operator_name' => 'สมชาย',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, StockLedger::count());
    }

    #[Test]
    public function rm_and_fg_pos_are_separate_pages_without_a_domain_chooser(): void
    {
        $this->actingAs($this->user);

        $this->get(route('pos.index'))->assertRedirect(route('pos.show', ['domain' => 'rm']));
        $this->get(route('pos.show', ['domain' => 'rm']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->where('posDomain', 'RM')
                ->has('products', 1));
        $this->get(route('pos.show', ['domain' => 'fg']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('posDomain', 'FG')
                ->has('products', 0));
        $this->get('/pos/xx')->assertNotFound();
    }

    #[Test]
    public function a_pin_unlocked_device_stays_on_the_pos_it_was_opened_as(): void
    {
        $this->warehouse->update(['pos_pin' => Hash::make('4321')]);
        $device = User::create([
            'name' => 'POS device',
            'email' => 'pos@wms.local',
            'password' => Hash::make('password'),
        ]);
        $device->markEmailAsVerified();
        $device->givePermissionTo('stock-mutations-access');

        $this->get(route('pos.show', ['domain' => 'fg']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Pos/Unlock')
                ->where('domain', 'FG'));

        $this->post(route('pos.unlock', ['domain' => 'fg']), ['pin' => '432100'])
            ->assertSessionHasErrors('pin');
        $this->assertGuest();

        $this->post(route('pos.unlock', ['domain' => 'fg']), ['pin' => '4321'])
            ->assertRedirect(route('pos.show', ['domain' => 'fg']));
        $this->assertSame('FG', session('pos.domain'));

        $this->get(route('pos.show', ['domain' => 'rm']))
            ->assertRedirect(route('pos.show', ['domain' => 'fg']));
        $this->get(route('pos.index'))
            ->assertRedirect(route('pos.show', ['domain' => 'fg']));

        $this->post(route('pos.receive', ['domain' => 'rm']), [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'create_unit' => true,
            'operator_name' => 'สมชาย',
        ])->assertForbidden();
        $this->assertSame(0, StockLedger::count());

        $this->post(route('pos.lock'))
            ->assertRedirect(route('pos.show', ['domain' => 'fg']));
    }

    #[Test]
    public function one_warehouse_pin_unlocks_both_rm_and_fg_pos(): void
    {
        $this->warehouse->update(['pos_pin' => Hash::make('4829')]);
        $device = User::create([
            'name' => 'POS device',
            'email' => 'pos@wms.local',
            'password' => Hash::make('password'),
        ]);
        $device->markEmailAsVerified();
        $device->givePermissionTo('stock-mutations-access');

        foreach (['rm', 'fg'] as $domain) {
            $this->post(route('pos.unlock', ['domain' => $domain]), ['pin' => '4829'])
                ->assertRedirect(route('pos.show', ['domain' => $domain]));
            $this->assertSame(strtoupper($domain), session('pos.domain'));
            $this->post(route('pos.lock'));
        }
    }

    #[Test]
    public function admin_floor_shows_every_warehouse_by_default_and_one_when_picked(): void
    {
        $other = Warehouse::create(['code' => 'WH-DECHA-POST', 'name' => 'โกดังเดชา / ผลิตเสา+ประตู', 'type' => 'warehouse', 'is_active' => true, 'sort_order' => 3]);
        $this->makeUnit('PU-HERE', 5);
        $this->makeUnit('PU-THERE', 7, $other);

        $this->actingAs($this->user)
            ->get(route('warehouse-floor.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('currentWarehouseId', null)
                ->has('units', 2)
                ->where('units.1.unit_code', 'PU-THERE')
                ->where('units.1.warehouse_name', 'โกดังเดชา / ผลิตเสา+ประตู'));

        $this->get(route('warehouse-floor.index', ['warehouse_id' => $this->warehouse->id]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('currentWarehouseId', $this->warehouse->id)
                ->has('units', 1)
                ->where('units.0.unit_code', 'PU-HERE'));

        // Products say which warehouse they live in so the all-warehouses receive can preselect it.
        $this->product->warehouses()->attach($other->id, ['stock' => 0]);
        $this->get(route('warehouse-floor.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('products.0.warehouse_ids', [$other->id]));

        // The tablet POS stays on one warehouse.
        $this->get(route('pos.show', ['domain' => 'rm']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('currentWarehouseId', $this->warehouse->id)
                ->has('units', 1));
    }

    #[Test]
    public function receiving_bulk_stock_keeps_one_unit_named_after_the_sku(): void
    {
        $wire = Product::create([
            'category_id' => $this->product->category_id, 'image' => 'p.png', 'barcode' => 'RM-W25-S', 'sku' => 'RM-W25-S',
            'title' => 'ลวดนิ่ม 2.5', 'description' => 'RM', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'RM', 'base_uom' => 'ROLL', 'tracks_physical_units' => true,
        ]);
        $receive = fn (float $qty) => $this->post(route('warehouse-floor.receive'), [
            'warehouse_id' => $this->warehouse->id, 'product_id' => $wire->id, 'qty' => $qty, 'uom' => 'ROLL',
            'create_unit' => true, 'operator_name' => 'โบว์',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user);
        $receive(169);
        $receive(25);

        $units = PhysicalUnit::query()->where('product_id', $wire->id)->get();
        $this->assertCount(1, $units);
        $this->assertSame('RM-W25-S', $units[0]->unit_code);
        $this->assertEquals(194, $units[0]->actual_weight);

        $this->get(route('pos.show', ['domain' => 'rm']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('units.0.unit_code', 'RM-W25-S'));
    }

    #[Test]
    public function receive_refuses_a_unit_code_that_belongs_to_another_product(): void
    {
        $pipe = $this->makeUnit('PU-000069', 2);
        $other = Product::create([
            'category_id' => $this->product->category_id, 'image' => 'p.png', 'barcode' => 'RM-W25-S', 'sku' => 'RM-W25-S',
            'title' => 'ลวดนิ่ม 2.5', 'description' => 'RM', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'RM', 'base_uom' => 'ROLL', 'tracks_physical_units' => true,
        ]);
        $payload = [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $other->id,
            'qty' => 25,
            'uom' => 'ROLL',
            'unit_code' => 'PU-000069',
            'create_unit' => true,
            'operator_name' => 'test',
        ];

        $this->actingAs($this->user)
            ->post(route('warehouse-floor.receive'), $payload)
            ->assertSessionHasErrors('unit_code');

        $this->assertSame(0, StockLedger::count(), 'nothing posted');
        $this->assertEquals(2, $pipe->fresh()->actual_weight, 'the other roll is untouched');

        // Same product and warehouse may still top up its own unit.
        $this->post(route('warehouse-floor.receive'), [...$payload, 'product_id' => $this->product->id, 'qty' => 3, 'uom' => 'KG'])
            ->assertSessionHasNoErrors();
        $this->assertEquals(5, $pipe->fresh()->actual_weight);
    }

    #[Test]
    public function pos_stays_thai_when_the_tablet_os_is_in_english(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get(route('pos.show', ['domain' => 'rm']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Pos/Unlock')
                ->where('locale.current', 'th'));
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
