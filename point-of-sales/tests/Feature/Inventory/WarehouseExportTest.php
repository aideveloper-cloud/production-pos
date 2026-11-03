<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WarehouseExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $phranon;

    private Warehouse $chokdee;

    private Product $finished;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warehouse.mode' => 'warehouse', 'warehouse.is_warehouse' => true]);
        app()->setLocale('th');

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin-export@test.local', 'password' => Hash::make('password'), 'locale' => 'th']);
        $this->admin->markEmailAsVerified();
        $this->admin->syncRoles([Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])->name]);
        $this->admin->syncPermissions(Permission::all());

        $this->phranon = Warehouse::create(['code' => 'WH-PHRANON', 'name' => 'พระนอน', 'type' => 'main', 'is_active' => true, 'sort_order' => 1]);
        $this->chokdee = Warehouse::create(['code' => 'WH-CHOKDEE', 'name' => 'โชคดี', 'type' => 'main', 'is_active' => true, 'sort_order' => 2]);

        $category = Category::create(['image' => 'c.png', 'name' => 'ลวด', 'description' => 'RM']);
        $product = Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => 'RM-W18-H', 'sku' => 'RM-W18-H',
            'title' => 'ลวดแข็ง 1.8', 'description' => 'RM', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'RM', 'base_uom' => 'ROLL', 'tracks_physical_units' => true, 'standard_weight_kg' => 100,
        ]);
        $posting = app(InventoryPostingService::class);
        $ctx = new PostingContext('stock_rm_opening', null, 'opening', $this->admin->id);
        $posting->receive($product, $this->phranon->id, 45, StockLedgerMovement::Receive, $ctx, null, 'OPENING');
        $posting->receive($product, $this->chokdee->id, 5, StockLedgerMovement::Receive, $ctx, null, 'OPENING');
        $posting->issue($product, $this->phranon->id, 3, StockLedgerMovement::Issue, new PostingContext('warehouse_floor_cut', null, 'cut', $this->admin->id, 'สมชาย'));

        $this->finished = Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => 'FG-MESH-1', 'sku' => 'FG-MESH-1',
            'title' => 'ตะแกรง 1x2', 'description' => 'FG', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0,
            'domain' => 'FG', 'base_uom' => 'PCS', 'tracks_physical_units' => false,
        ]);
        $posting->receive($this->finished, $this->phranon->id, 7, StockLedgerMovement::Receive, $ctx, null, 'OPENING');
    }

    public static function exports(): array
    {
        return [
            'materials' => ['export.warehouse.materials', 'รหัส (SKU)'],
            'units' => ['export.warehouse.units', 'รหัสหน่วย'],
            'balances' => ['export.warehouse.balances', 'รหัสคลัง'],
            'movements' => ['export.warehouse.stock-ledgers', 'วันเวลา'],
        ];
    }

    #[Test]
    #[DataProvider('exports')]
    public function each_export_downloads_as_xlsx_and_csv(string $route, string $firstHeading): void
    {
        $this->actingAs($this->admin);

        $xlsx = $this->get(route($route));
        $xlsx->assertOk();
        $this->assertMatchesRegularExpression('/.xlsx"?$/', (string) $xlsx->headers->get('content-disposition'));

        $csv = $this->csv(route($route, ['format' => 'csv']));
        $this->assertStringStartsWith("\u{FEFF}", $csv, 'BOM so Excel reads Thai');
        $this->assertStringContainsString($firstHeading, $csv);
    }

    #[Test]
    public function balances_come_from_the_ledger_per_warehouse(): void
    {
        $rows = $this->rows(route('export.warehouse.balances', ['format' => 'csv']));

        $rm = collect($rows)->filter(fn (array $row) => ($row[2] ?? null) === 'RM-W18-H')->keyBy(0);
        $this->assertSame('RM', $rm['WH-PHRANON'][4]);
        $this->assertSame('42', $rm['WH-PHRANON'][7]);
        $this->assertSame('5', $rm['WH-CHOKDEE'][7]);
    }

    #[Test]
    public function materials_have_a_column_per_warehouse_and_a_total(): void
    {
        $rows = $this->rows(route('export.warehouse.materials', ['format' => 'csv', 'domain' => 'RM']));

        $this->assertSame(['WH-PHRANON', 'WH-CHOKDEE'], array_slice($rows[0], -2));
        $this->assertSame(['47', '42', '5'], array_slice($rows[1], -3));
        $this->assertSame(['0', '0'], array_slice($rows[1], 9, 2), 'zero min/max stay 0, not blank');
    }

    #[Test]
    public function movement_export_follows_the_page_filters(): void
    {
        $rows = $this->rows(route('export.warehouse.stock-ledgers', ['format' => 'csv', 'movement_type' => 'ISSUE']));

        $this->assertCount(2, $rows, 'heading + the one cut');
        $this->assertSame('RM', $rows[1][5]);
        $this->assertSame('-3', $rows[1][9]);
        $this->assertSame('สมชาย', $rows[1][14]);
    }

    #[Test]
    public function every_export_can_be_limited_to_rm_or_fg(): void
    {
        $skus = fn (string $route, string $domain, int $skuColumn) => collect($this->rows(route($route, ['format' => 'csv', 'domain' => $domain])))
            ->slice(1)->pluck($skuColumn)->unique()->values()->all();

        $this->assertSame(['FG-MESH-1'], $skus('export.warehouse.materials', 'FG', 0));
        $this->assertSame(['RM-W18-H'], $skus('export.warehouse.materials', 'RM', 0));
        $this->assertSame(['FG-MESH-1'], $skus('export.warehouse.balances', 'FG', 2));
        $this->assertSame(['FG-MESH-1'], $skus('export.warehouse.stock-ledgers', 'FG', 3));
        $this->assertSame(['RM-W18-H'], $skus('export.warehouse.stock-ledgers', 'rm', 3));
        $this->assertSame(['RM-W18-H'], $skus('export.warehouse.units', 'RM', 1));
        $this->assertSame([], $skus('export.warehouse.units', 'FG', 1), 'FG here is qty-based, no units');
    }

    #[Test]
    public function movement_history_page_filters_by_domain(): void
    {
        $this->actingAs($this->admin)
            ->get(route('stock-ledgers.index', ['domain' => 'FG']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('filters.domain', 'FG')
                ->has('ledgers.data', 1)
                ->where('ledgers.data.0.product.domain', 'FG'));

        $this->get(route('stock-ledgers.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('filters.domain', null)
                ->has('ledgers.data', 4));
    }

    #[Test]
    public function labels_page_filters_by_domain(): void
    {
        $this->actingAs($this->admin)
            ->get(route('labels.index', ['domain' => 'FG']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('domain', 'FG')
                ->has('units', 0));

        $this->get(route('labels.index', ['domain' => 'RM']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('domain', 'RM')
                ->where('units.0.domain', 'RM'));
    }

    #[Test]
    public function pos_device_user_cannot_export(): void
    {
        $device = User::create(['name' => 'POS', 'email' => 'pos-export@test.local', 'password' => Hash::make('password')]);
        $device->markEmailAsVerified();
        $device->givePermissionTo(['stock-mutations-access', 'products-access']);

        $this->actingAs($device)->get(route('export.warehouse.stock-ledgers'))->assertForbidden();
    }

    private function csv(string $url): string
    {
        $response = $this->actingAs($this->admin)->get($url);
        $response->assertOk();

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    /** @return array<int, array<int, string>> */
    private function rows(string $url): array
    {
        $lines = preg_split('/\r?\n/', trim(ltrim($this->csv($url), "\u{FEFF}")));

        return array_map('str_getcsv', $lines);
    }
}
