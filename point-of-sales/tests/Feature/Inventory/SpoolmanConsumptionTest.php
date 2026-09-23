<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockCalcStrategy;
use App\Enums\StockLedgerMovement;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\SpoolmanConsumptionEvent;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Services\Inventory\StockCalculationService;
use App\Services\Spoolman\SpoolConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpoolmanConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private PhysicalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['spoolman.integration_token' => 'test-integration-token']);

        $this->warehouse = Warehouse::create([
            'code' => 'WH-01',
            'name' => 'Warehouse 01',
            'type' => 'warehouse',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $category = Category::create([
            'image' => 'cat.png',
            'name' => 'Filament',
            'description' => 'RM filament',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'RM-PLA-BLACK',
            'sku' => 'RM-PLA-BLACK',
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
        ]);

        $this->unit = PhysicalUnit::create([
            'unit_code' => 'SP-000123',
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'unit_type' => 'SPOOL',
            'status' => 'ACTIVE',
            'actual_weight' => 12.5,
            'weight_uom' => 'KG',
            'weight_source' => 'SPOOLMAN',
            'spoolman_spool_id' => 123,
        ]);
    }

    #[Test]
    public function it_posts_consumption_to_immutable_stock_ledger(): void
    {
        $response = $this->withToken('test-integration-token')
            ->postJson('/api/integrations/spoolman/events', [
                'event' => 'SPOOL_CONSUMPTION',
                'event_id' => 'sm-con-001',
                'occurred_at' => now()->toIso8601String(),
                'spool_id' => 123,
                'sku' => 'RM-PLA-BLACK',
                'warehouse_code' => 'WH-01',
                'qty' => 0.4,
                'uom' => 'KG',
                'weight_before' => 12.5,
                'weight_after' => 12.1,
                'source' => 'spoolman_websocket',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.process_status', 'POSTED');

        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockLedgerMovement::Consumption->value,
            'uom' => 'KG',
            'idempotency_key' => 'spoolman:sm-con-001',
        ]);

        $ledger = StockLedger::first();
        $this->assertSame('-0.400000', number_format((float) $ledger->qty, 6, '.', ''));

        $this->assertSame('12.1000', number_format((float) $this->unit->fresh()->actual_weight, 4, '.', ''));

        $summary = app(StockCalculationService::class)->summarize($this->product, $this->warehouse->id);
        $this->assertSame('12.1000', $summary['available_weight']);
        $this->assertSame('-0.400000', $summary['on_hand_qty']);
    }

    #[Test]
    public function it_is_idempotent_for_duplicate_event_ids(): void
    {
        $payload = [
            'event' => 'SPOOL_CONSUMPTION',
            'event_id' => 'sm-con-dup',
            'spool_id' => 123,
            'warehouse_id' => $this->warehouse->id,
            'qty' => 0.1,
            'uom' => 'KG',
            'weight_after' => 12.4,
        ];

        $this->withToken('test-integration-token')
            ->postJson('/api/integrations/spoolman/events', $payload)
            ->assertCreated();

        $this->withToken('test-integration-token')
            ->postJson('/api/integrations/spoolman/events', $payload)
            ->assertOk()
            ->assertJsonPath('data.process_status', 'POSTED');

        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, SpoolmanConsumptionEvent::count());
    }

    #[Test]
    public function it_rejects_unknown_spool(): void
    {
        $this->withToken('test-integration-token')
            ->postJson('/api/integrations/spoolman/events', [
                'event' => 'SPOOL_CONSUMPTION',
                'event_id' => 'sm-con-missing',
                'spool_id' => 999,
                'qty' => 0.1,
                'uom' => 'KG',
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.process_status', 'REJECTED');

        $this->assertSame(0, StockLedger::count());
    }

    #[Test]
    public function it_requires_integration_token(): void
    {
        $this->postJson('/api/integrations/spoolman/events', [
            'event' => 'SPOOL_CONSUMPTION',
            'event_id' => 'sm-con-noauth',
            'spool_id' => 123,
            'qty' => 0.1,
            'uom' => 'KG',
        ])->assertUnauthorized();
    }

    #[Test]
    public function service_posts_without_http(): void
    {
        $event = app(SpoolConsumptionService::class)->handleConsumption([
            'event_id' => 'svc-001',
            'spool_id' => 123,
            'warehouse_id' => $this->warehouse->id,
            'qty' => 400,
            'uom' => 'g',
            'weight_after' => 12.1,
        ]);

        $this->assertSame('POSTED', $event->process_status);
        $this->assertSame('KG', $event->qty_uom);
        $this->assertSame('0.400000', number_format((float) $event->qty_consumed, 6, '.', ''));
    }
}
