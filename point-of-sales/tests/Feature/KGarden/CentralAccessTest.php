<?php

namespace Tests\Feature\KGarden;

use App\Enums\StockCalcStrategy;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * K Garden central access: who may use the WMS is decided in ERPNext (faked here with Http::fake).
 */
class CentralAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ERP = 'http://erp.test';

    private Warehouse $decha;

    private Warehouse $phranon;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'warehouse.mode' => 'warehouse', 'warehouse.is_warehouse' => true,
            'kgarden.enabled' => true, 'kgarden.erp_url' => self::ERP, 'kgarden.app_code' => 'WMS',
            'kgarden.oidc.client_id' => 'wms-client', 'kgarden.oidc.client_secret' => 'secret',
            'kgarden.oidc.redirect_uri' => 'http://wms.test/auth/kgarden/callback',
            'kgarden.service.api_key' => 'k', 'kgarden.service.api_secret' => 's',
            'kgarden.warehouse_suffix' => ' - KG', 'kgarden.local_login' => ['superadmin@wms.local'],
        ]);
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'warehouse-admin', 'guard_name' => 'web'])->syncPermissions(Permission::all());
        Role::firstOrCreate(['name' => 'warehouse-pos', 'guard_name' => 'web'])
            ->syncPermissions(Permission::whereIn('name', ['dashboard-access', 'stock-mutations-access', 'products-access'])->get());

        $this->decha = $this->warehouse('WH-DECHA-POST', 'โกดังเดชา / ผลิตเสา+ประตู', 1);
        $this->phranon = $this->warehouse('WH-PHRANON', 'โกดังพระนอน', 2);
        $category = Category::create(['image' => 'c.png', 'name' => 'RM', 'description' => 'RM']);
        $this->product = Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => 'RM-PIPE', 'sku' => 'RM-PIPE',
            'title' => 'ท่อ', 'description' => 'ท่อ', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'RM', 'base_uom' => 'PCS', 'stock_calc_strategy' => StockCalcStrategy::QtyBased,
            'tracks_physical_units' => false, 'spoolman_enabled' => false,
        ]);
        $this->product->warehouses()->attach($this->decha->id, ['stock' => 0]);
    }

    private function warehouse(string $code, string $name, int $order): Warehouse
    {
        $outlet = Outlet::create(['code' => $code, 'name' => $name, 'is_active' => true, 'is_sales_enabled' => false]);

        return Warehouse::create(['code' => $code, 'name' => $name, 'type' => 'warehouse', 'is_active' => true,
            'sort_order' => $order, 'outlet_id' => $outlet->id]);
    }

    private function person(array $over = []): array
    {
        return array_merge([
            'ok' => true, 'granted' => true, 'user' => 'somchai@example.com', 'full_name' => 'สมชาย ใจดี',
            'employee_code' => 'EMP1', 'role' => 'operator', 'scopes' => ['โกดังเดชา / ผลิตเสา+ประตู - KG'],
        ], $over);
    }

    #[Test]
    public function the_person_unlocks_the_pos_with_employee_code_and_pin_and_is_recorded_as_operator(): void
    {
        Http::fake([self::ERP.'/api/method/kgarden.access.api.verify_pin' => Http::response(['message' => $this->person()])]);

        $this->post(route('pos.unlock', ['domain' => 'rm']), [
            'employee_code' => 'emp1', 'pin' => '482613', 'warehouse_id' => $this->decha->id,
        ])->assertRedirect(route('pos.show', ['domain' => 'rm']));

        Http::assertSent(fn ($r) => $r['employee_code'] === 'EMP1'
            && $r['scope_value'] === 'โกดังเดชา / ผลิตเสา+ประตู - KG' && $r['app_code'] === 'WMS');
        $user = User::where('email', 'somchai@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->kgarden_managed);
        $this->assertSame(['warehouse-pos'], $user->getRoleNames()->all());
        $this->assertSame([$this->decha->outlet_id], $user->outlets()->pluck('outlets.id')->all());

        $this->get(route('pos.show', ['domain' => 'rm']))->assertInertia(fn ($page) => $page
            ->component('Pos/Index')->where('operatorLocked', true)->where('operatorName', 'สมชาย ใจดี (EMP1)'));

        $this->post(route('pos.receive', ['domain' => 'rm']), [
            'warehouse_id' => $this->decha->id, 'product_id' => $this->product->id, 'qty' => 3, 'uom' => 'PCS',
            'operator_name' => 'ชื่อปลอม',
        ])->assertRedirect();
        $this->assertSame('สมชาย ใจดี (EMP1)', StockLedger::latest('id')->first()->operator_name);
    }

    #[Test]
    public function a_refused_pin_does_not_unlock(): void
    {
        Http::fake([self::ERP.'/*' => Http::response(['message' => ['ok' => false, 'reason' => 'no']])]);

        $this->from(route('pos.show', ['domain' => 'rm']))->post(route('pos.unlock', ['domain' => 'rm']), [
            'employee_code' => 'EMP1', 'pin' => '000000', 'warehouse_id' => $this->phranon->id,
        ])->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    #[Test]
    public function password_login_is_only_for_break_glass_accounts(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@wms.local', 'password' => 'password'])->markEmailAsVerified();
        config(['security.bot_guard.enabled' => false]);

        $this->post(route('login'), ['email' => 'admin@wms.local', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function signing_in_through_erpnext_mirrors_role_and_warehouses(): void
    {
        Http::fake([
            self::ERP.'/api/method/frappe.integrations.oauth2.get_token' => Http::response(['access_token' => 't']),
            self::ERP.'/api/method/frappe.integrations.oauth2.openid_profile' => Http::response([
                'email' => 'boss@example.com', 'name' => 'หัวหน้าคลัง', 'kg_app' => 'WMS', 'kg_access' => true,
                'kg_role' => 'admin', 'kg_scopes' => [], 'kg_employee_code' => 'EMP9',
            ]),
        ]);
        $this->get(route('kgarden.redirect'))->assertRedirectContains(self::ERP.'/api/method/frappe.integrations.oauth2.authorize');
        $state = session('kgarden.oidc_state');

        $this->get(route('kgarden.callback', ['code' => 'c', 'state' => $state]))->assertRedirect(route('dashboard'));
        $boss = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertSame(['warehouse-admin'], $boss->getRoleNames()->all());
        $this->assertEqualsCanonicalizing([$this->decha->outlet_id, $this->phranon->outlet_id], $boss->outlets()->pluck('outlets.id')->all());
    }

    #[Test]
    public function erpnext_sign_in_without_wms_access_or_with_a_bad_state_is_refused(): void
    {
        Http::fake([
            self::ERP.'/api/method/frappe.integrations.oauth2.get_token' => Http::response(['access_token' => 't']),
            self::ERP.'/api/method/frappe.integrations.oauth2.openid_profile' => Http::response([
                'email' => 'mes@example.com', 'name' => 'คนหน้างาน', 'kg_app' => 'WMS', 'kg_access' => false, 'kg_role' => null,
            ]),
        ]);
        $this->get(route('kgarden.callback', ['code' => 'c', 'state' => 'forged']))->assertSessionHasErrors('email');
        $this->get(route('kgarden.redirect'));
        $this->get(route('kgarden.callback', ['code' => 'c', 'state' => session('kgarden.oidc_state')]))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'mes@example.com']);
    }

    #[Test]
    public function access_removed_in_erpnext_strips_rights_and_ends_sessions(): void
    {
        $user = User::create(['name' => 'สมชาย', 'email' => 'somchai@example.com', 'password' => 'x']);
        $user->forceFill(['kgarden_managed' => true])->save();
        $user->syncRoles(['warehouse-pos']);
        $user->outlets()->sync([$this->decha->outlet_id]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        Http::fake([self::ERP.'/api/method/kgarden.access.api.access_changes*' => Http::response(['message' => [
            ['user' => 'somchai@example.com', 'granted' => false, 'changed_at' => '2026-11-18 17:00:00'],
        ]])]);

        Artisan::call('kgarden:sync-access');

        $this->assertSame([], $user->fresh()->getRoleNames()->all());
        $this->assertSame(0, $user->fresh()->outlets()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    #[Test]
    public function a_session_of_an_account_erpnext_does_not_manage_is_ended(): void
    {
        // e.g. the shared POS account a warehouse PIN opened before central access was switched on
        $pos = User::create(['name' => 'เครื่อง POS คลัง', 'email' => 'pos@wms.local', 'password' => 'x']);
        $pos->markEmailAsVerified();
        $pos->syncRoles(['warehouse-pos']);

        $this->actingAs($pos)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function break_glass_and_erpnext_managed_sessions_stay(): void
    {
        $breakGlass = User::create(['name' => 'Super', 'email' => 'superadmin@wms.local', 'password' => 'x']);
        $breakGlass->markEmailAsVerified();
        $breakGlass->syncRoles(['warehouse-admin']);
        $this->actingAs($breakGlass)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($breakGlass);

        $managed = User::create(['name' => 'หัวหน้าคลัง', 'email' => 'boss@example.com', 'password' => 'x']);
        $managed->forceFill(['kgarden_managed' => true, 'employee_code' => 'EMP9'])->save();
        $managed->markEmailAsVerified();
        $managed->syncRoles(['warehouse-admin']);
        $this->actingAs($managed)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($managed);
    }

    #[Test]
    public function the_dashboard_floor_records_the_signed_in_person_as_operator(): void
    {
        $boss = User::create(['name' => 'หัวหน้าคลัง', 'email' => 'boss@example.com', 'password' => 'x']);
        $boss->forceFill(['kgarden_managed' => true, 'employee_code' => 'EMP9'])->save();
        $boss->markEmailAsVerified();
        $boss->syncRoles(['warehouse-admin']);

        $this->actingAs($boss)->get('/dashboard/warehouse-floor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('operatorLocked', true)
                ->where('operatorName', 'หัวหน้าคลัง (EMP9)'));
    }
}
