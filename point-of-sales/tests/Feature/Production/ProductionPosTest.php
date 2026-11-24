<?php

namespace Tests\Feature\Production;

use App\Enums\StockCalcStrategy;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductionLog;
use App\Models\ProductionOutput;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductionPosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Product $fg;

    /** @var array<string, array<string, mixed>> what the fake ERPNext answers, by Work Order name */
    private array $erp = [];

    private bool $erpDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'warehouse.mode' => 'warehouse',
            'warehouse.is_warehouse' => true,
            'kgarden.erp_url' => 'https://erp.test',
            'kgarden.service.api_key' => 'k',
            'kgarden.service.api_secret' => 's',
        ]);
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin-prd@test.local', 'password' => Hash::make('password')]);
        $this->admin->markEmailAsVerified();
        $this->admin->syncRoles([Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])->name]);
        $this->admin->syncPermissions(Permission::all());

        $this->warehouse = Warehouse::create([
            'code' => 'WH-PHRANON', 'name' => 'โกดังพระนอน', 'type' => 'main', 'is_active' => true, 'sort_order' => 1,
        ]);
        $category = Category::create(['image' => 'c.png', 'name' => 'FG', 'description' => 'FG']);
        $this->fg = Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => 'FG-CHAIR', 'sku' => 'FG-CHAIR',
            'title' => 'เก้าอี้', 'description' => 'FG', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'FG', 'base_uom' => 'PCS', 'stock_calc_strategy' => StockCalcStrategy::QtyBased,
            'tracks_physical_units' => false, 'spoolman_enabled' => false,
        ]);

        $this->erp['MFG-WO-0001'] = [
            'name' => 'MFG-WO-0001', 'status' => 'Not Started', 'production_item' => 'FG-CHAIR', 'item_name' => 'เก้าอี้',
            'bom_no' => 'BOM-FG-CHAIR-001', 'qty' => 10, 'produced_qty' => 0, 'stock_uom' => 'Nos', 'open' => true,
        ];
        Http::fake(function (HttpRequest $request) {
            if ($this->erpDown) {
                return Http::response('down', 502);
            }
            if (! str_starts_with($request->url(), 'https://erp.test/api/method/kgarden.manufacturing.floor.work_orders')) {
                return Http::response([], 404);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $rows = isset($q['name'])
                ? array_values(array_filter([$this->erp[$q['name']] ?? null]))
                : array_values(array_filter($this->erp, fn ($wo) => $wo['open']));

            return Http::response(['message' => array_map(fn ($wo) => $wo + ['remaining_qty' => $wo['qty'] - $wo['produced_qty']], $rows)]);
        });
    }

    private function report(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('pos.production.output', ['workOrder' => 'MFG-WO-0001']), $body + [
            'warehouse_id' => $this->warehouse->id,
            'operator_name' => 'สมชาย',
            'good_qty' => 4,
            'client_key' => 'key-0000001',
        ]);
    }

    #[Test]
    public function production_pos_lists_open_work_orders_with_the_wms_product(): void
    {
        $this->actingAs($this->admin)->get(route('pos.show', ['domain' => 'production']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Production')
                ->where('currentWarehouse.id', $this->warehouse->id)
                ->has('workOrders', 1)
                ->where('workOrders.0.name', 'MFG-WO-0001')
                ->where('workOrders.0.product_id', $this->fg->id)
                ->where('workOrders.0.remaining_qty', 10)
                ->where('loadError', null));

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'token k:s'));
    }

    #[Test]
    public function start_and_stop_record_who_worked_on_the_work_order(): void
    {
        $this->actingAs($this->admin);
        $start = fn () => $this->postJson(route('pos.production.start', ['workOrder' => 'MFG-WO-0001']), [
            'warehouse_id' => $this->warehouse->id, 'operator_name' => 'สมชาย',
        ]);
        $start()->assertCreated();
        $start()->assertUnprocessable()->assertJsonValidationErrors('work_order');

        $this->get(route('pos.show', ['domain' => 'production']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workOrders.0.running.operator_name', 'สมชาย'));

        $this->postJson(route('pos.production.stop', ['workOrder' => 'MFG-WO-0001']), ['warehouse_id' => $this->warehouse->id])
            ->assertOk();
        $this->assertNotNull(ProductionLog::sole()->ended_at);
        $this->postJson(route('pos.production.stop', ['workOrder' => 'MFG-WO-0001']), ['warehouse_id' => $this->warehouse->id])
            ->assertUnprocessable();
    }

    #[Test]
    public function good_quantity_is_received_into_the_warehouse_with_the_work_order_for_the_bridge(): void
    {
        $this->actingAs($this->admin);
        $this->report(['scrap_qty' => 1, 'scrap_reason' => 'ขาแตก'])->assertCreated()->assertJson(['good_qty' => 4, 'scrap_qty' => 1]);

        $ledger = StockLedger::sole();
        $this->assertSame('production_output', $ledger->reference_type);
        $this->assertSame(ProductionOutput::sole()->id, $ledger->reference_id);
        $this->assertSame('MFG-WO-0001', $ledger->meta['work_order']);
        $this->assertSame($this->warehouse->id, $ledger->warehouse_id);
        $this->assertEquals(4, (float) $ledger->qty);
        $this->assertSame('สมชาย', $ledger->operator_name);
        $this->assertSame($ledger->id, ProductionOutput::sole()->stock_ledger_id);
        $this->assertEquals(4, (float) $this->fg->fresh()->stock);
    }

    #[Test]
    public function the_same_report_sent_twice_is_counted_once(): void
    {
        $this->actingAs($this->admin);
        $this->report()->assertCreated();
        $this->report()->assertOk()->assertJson(['good_qty' => 4]);

        $this->assertSame(1, ProductionOutput::count());
        $this->assertSame(1, StockLedger::count());
    }

    #[Test]
    public function good_quantity_cannot_exceed_what_is_left_including_reports_erpnext_has_not_booked(): void
    {
        $this->actingAs($this->admin);
        $this->report(['good_qty' => 7])->assertCreated();
        $this->report(['good_qty' => 4, 'client_key' => 'key-0000002'])
            ->assertUnprocessable()->assertJsonValidationErrors('good_qty');

        // Once ERPNext has booked the 7 the remaining stays 3, not 10 - 7 - 7.
        $this->erp['MFG-WO-0001']['produced_qty'] = 7;
        $this->report(['good_qty' => 3, 'client_key' => 'key-0000003'])->assertCreated();
        $this->assertEquals(10, (float) ProductionOutput::sum('good_qty'));
    }

    #[Test]
    public function reports_are_refused_for_closed_work_orders_missing_products_and_scrap_without_a_reason(): void
    {
        $this->actingAs($this->admin);
        $this->report(['good_qty' => 0, 'scrap_qty' => 1])->assertUnprocessable()->assertJsonValidationErrors('scrap_reason');
        $this->report(['good_qty' => 0])->assertUnprocessable()->assertJsonValidationErrors('good_qty');

        $this->fg->update(['sku' => 'FG-OTHER']);
        $this->report()->assertUnprocessable()->assertJsonValidationErrors('work_order');
        $this->fg->update(['sku' => 'FG-CHAIR']);

        $this->erp['MFG-WO-0001']['open'] = false;
        $this->report()->assertUnprocessable()->assertJsonValidationErrors('work_order');
        $this->assertSame(0, StockLedger::count());
    }

    #[Test]
    public function a_production_device_stays_production_and_cannot_use_another_warehouse(): void
    {
        $this->warehouse->update(['pos_pin' => Hash::make('5150')]);
        $other = Warehouse::create(['code' => 'WH-CHOKDEE', 'name' => 'โกดังโชคดี', 'type' => 'main', 'is_active' => true, 'sort_order' => 2]);
        $device = User::create(['name' => 'POS device', 'email' => 'pos@wms.local', 'password' => Hash::make('password')]);
        $device->markEmailAsVerified();
        $device->givePermissionTo('stock-mutations-access');

        $this->get(route('pos.show', ['domain' => 'production']))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Pos/Unlock')->where('domain', 'PRODUCTION'));
        $this->post(route('pos.unlock', ['domain' => 'production']), ['pin' => '5150'])
            ->assertRedirect(route('pos.show', ['domain' => 'production']));
        $this->get(route('pos.show', ['domain' => 'rm']))->assertRedirect(route('pos.show', ['domain' => 'production']));

        $this->report(['warehouse_id' => $other->id])->assertForbidden();
        $this->report()->assertCreated();

        // The other POS cannot record production, and this device cannot receive RM.
        $this->post(route('pos.receive', ['domain' => 'rm']), [
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->fg->id, 'qty' => 1, 'operator_name' => 'สมชาย',
        ])->assertForbidden();
    }

    #[Test]
    public function erpnext_down_shows_a_message_instead_of_an_error_page(): void
    {
        $this->erpDown = true;
        $this->actingAs($this->admin)->get(route('pos.show', ['domain' => 'production']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('workOrders', 0)->whereNot('loadError', null));
    }
}
