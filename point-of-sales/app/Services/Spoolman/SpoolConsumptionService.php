<?php

namespace App\Services\Spoolman;

use App\Enums\StockLedgerMovement;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\SpoolmanConsumptionEvent;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SpoolConsumptionService
{
    public function __construct(
        private readonly StockLedgerService $stockLedgerService,
        private readonly WeightUnitConverter $weightUnitConverter,
        private readonly InventoryPostingService $postingService,
    ) {}

    /**
     * Ingest a SPOOL_CONSUMPTION event, validate, and post immutable CONSUMPTION to stock_ledgers.
     *
     * @param  array{
     *   event?: string,
     *   event_id: string,
     *   occurred_at?: string,
     *   spool_id: int|string,
     *   sku?: string,
     *   warehouse_id?: int|string,
     *   warehouse_code?: string,
     *   qty: float|int|string,
     *   uom: string,
     *   weight_before?: float|int|string|null,
     *   weight_after?: float|int|string|null,
     *   source?: string
     * }  $payload
     */
    public function handleConsumption(array $payload): SpoolmanConsumptionEvent
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '') {
            throw ValidationException::withMessages(['event_id' => 'event_id is required.']);
        }

        $existing = SpoolmanConsumptionEvent::query()->where('event_id', $eventId)->first();
        if ($existing) {
            return $existing;
        }

        $spoolId = (int) ($payload['spool_id'] ?? 0);
        $qtyRaw = (float) ($payload['qty'] ?? 0);
        $uom = strtoupper((string) ($payload['uom'] ?? 'KG'));

        if ($spoolId <= 0 || $qtyRaw <= 0) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, 'Invalid spool_id or qty.');
        }

        $unit = PhysicalUnit::query()
            ->where('spoolman_spool_id', $spoolId)
            ->first();

        if (! $unit) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, "Physical unit not found for spool_id {$spoolId}.");
        }

        if ($unit->status !== 'ACTIVE') {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, "Physical unit {$unit->unit_code} is not ACTIVE.", $unit);
        }

        /** @var Product $product */
        $product = $unit->product;
        if (! $product->spoolman_enabled) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, 'Product is not spoolman_enabled.', $unit);
        }

        $warehouse = $this->resolveWarehouse($payload, $unit);
        if (! $warehouse) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, 'Warehouse could not be resolved.', $unit);
        }

        if ((int) $unit->warehouse_id !== (int) $warehouse->id) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, 'Warehouse mismatch with physical unit.', $unit, $warehouse);
        }

        $targetUom = strtoupper((string) ($product->base_uom ?: $uom));
        $qtyInBase = $this->toBaseUom($product, $qtyRaw, $uom, $targetUom);
        if ($qtyInBase === null) {
            return $this->reject($payload, $spoolId, $qtyRaw, $uom, "Cannot convert {$uom} to {$targetUom} (set standard_weight_kg on the product).", $unit, $warehouse);
        }

        return DB::transaction(function () use ($payload, $spoolId, $qtyInBase, $targetUom, $unit, $product, $warehouse) {
            $event = SpoolmanConsumptionEvent::create([
                'event_id' => (string) $payload['event_id'],
                'spoolman_spool_id' => $spoolId,
                'physical_unit_id' => $unit->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'qty_consumed' => $qtyInBase,
                'qty_uom' => $targetUom,
                'weight_before' => $payload['weight_before'] ?? $unit->actual_weight,
                'weight_after' => $payload['weight_after'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'payload' => $payload,
                'process_status' => 'PENDING',
            ]);

            $ledger = $this->stockLedgerService->post(
                product: $product,
                warehouseId: $warehouse->id,
                movementType: StockLedgerMovement::Consumption,
                qtySigned: (string) (-abs($qtyInBase)),
                uom: $targetUom,
                physicalUnit: $unit,
                referenceType: 'spoolman_consumption_event',
                referenceId: $event->id,
                idempotencyKey: 'spoolman:'.$event->event_id,
                notes: 'Spoolman printer consumption',
                meta: [
                    'spoolman_spool_id' => $spoolId,
                    'source' => $payload['source'] ?? 'spoolman',
                ],
            );

            // The unit moves by exactly what the ledger moved (base UOM). Spoolman's weight_after is in
            // its own unit (usually grams) and stays on the event for reconciliation only.
            $newWeight = max(0, (float) ($unit->actual_weight ?? 0) - abs($qtyInBase));

            $unit->fill([
                'actual_weight' => $newWeight,
                'weight_source' => 'SPOOLMAN',
                'weight_verified_at' => now(),
            ]);

            if ($newWeight !== null && (float) $newWeight <= 0) {
                $unit->status = 'DEPLETED';
                $unit->depleted_at = now();
            }

            $unit->save();
            $this->postingService->refreshCache($product, $warehouse->id, -abs($qtyInBase));

            $event->update([
                'process_status' => 'POSTED',
                'stock_ledger_id' => $ledger->id,
            ]);

            // refresh() keeps wasRecentlyCreated, which the webhook uses to tell new events from replays.
            return $event->refresh()->load(['stockLedger', 'physicalUnit']);
        });
    }

    /**
     * Mass UOMs convert directly; count UOMs (ROLL, PCS, ...) go through the product's standard weight.
     */
    private function toBaseUom(Product $product, float $qty, string $fromUom, string $baseUom): ?float
    {
        try {
            return $this->weightUnitConverter->convert($qty, $fromUom, $baseUom);
        } catch (InvalidArgumentException) {
            // fall through to the standard-weight route
        }

        $standardKg = (float) ($product->standard_weight_kg ?? 0);
        if ($standardKg <= 0) {
            return null;
        }

        try {
            return $this->weightUnitConverter->convert($qty, $fromUom, 'KG') / $standardKg;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function resolveWarehouse(array $payload, PhysicalUnit $unit): ?Warehouse
    {
        if (! empty($payload['warehouse_id']) && is_numeric($payload['warehouse_id'])) {
            return Warehouse::query()->find((int) $payload['warehouse_id']);
        }

        if (! empty($payload['warehouse_code'])) {
            return Warehouse::query()->where('code', $payload['warehouse_code'])->first();
        }

        return $unit->warehouse;
    }

    private function reject(
        array $payload,
        int $spoolId,
        float $qty,
        string $uom,
        string $reason,
        ?PhysicalUnit $unit = null,
        ?Warehouse $warehouse = null,
    ): SpoolmanConsumptionEvent {
        return SpoolmanConsumptionEvent::create([
            'event_id' => (string) ($payload['event_id'] ?? uniqid('reject-', true)),
            'spoolman_spool_id' => max($spoolId, 0),
            'physical_unit_id' => $unit?->id,
            'product_id' => $unit?->product_id,
            'warehouse_id' => $warehouse?->id ?? $unit?->warehouse_id,
            'qty_consumed' => abs($qty),
            'qty_uom' => $uom,
            'weight_before' => $payload['weight_before'] ?? null,
            'weight_after' => $payload['weight_after'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'payload' => $payload,
            'process_status' => 'REJECTED',
            'reject_reason' => $reason,
        ]);
    }
}
