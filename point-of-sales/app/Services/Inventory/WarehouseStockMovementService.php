<?php

namespace App\Services\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Spoolman\SpoolmanClient;
use App\Services\Spoolman\SpoolmanSyncService;
use App\Services\Spoolman\WeightUnitConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class WarehouseStockMovementService
{
    public function __construct(
        private readonly StockLedgerService $stockLedgerService,
        private readonly SpoolmanClient $spoolmanClient,
        private readonly SpoolmanSyncService $spoolmanSyncService,
        private readonly WeightUnitConverter $weightUnitConverter,
        private readonly InventoryPostingService $postingService,
    ) {}

    /**
     * Receive stock into a warehouse (optionally create / top-up a physical unit).
     *
     * @param  array{
     *   product_id:int,
     *   warehouse_id:int,
     *   qty:float|int|string,
     *   uom?:string,
     *   unit_code?:string|null,
     *   physical_unit_id?:int|null,
     *   lot_code?:string|null,
     *   create_unit?:bool,
     *   notes?:string|null
     * }  $data
     * @return array{ledger:\App\Models\StockLedger, physical_unit:?PhysicalUnit}
     */
    public function receive(array $data, User $user): array
    {
        $product = Product::query()->findOrFail((int) $data['product_id']);
        $warehouse = Warehouse::query()->findOrFail((int) $data['warehouse_id']);
        $qty = (float) $data['qty'];
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Quantity must be greater than zero.']);
        }

        // The ledger sums qty per product, so every movement is stored in the product's base UOM.
        $baseUom = strtoupper((string) ($product->base_uom ?: 'KG'));
        $qty = $this->convertOrFail($qty, strtoupper((string) ($data['uom'] ?? $baseUom)), $baseUom);
        $uom = $baseUom;
        $operator = trim((string) ($data['operator_name'] ?? ''));

        $result = DB::transaction(function () use ($data, $user, $product, $warehouse, $qty, $uom, $operator) {
            $unit = $this->resolveOrCreateUnit($data, $product, $warehouse, $qty, $uom);

            if ($unit) {
                $unit->actual_weight = (float) ($unit->actual_weight ?? 0) + $qty;
                $unit->weight_uom = $uom;
                $unit->weight_source = 'MANUAL';
                $unit->weight_verified_at = now();
                $unit->status = 'ACTIVE';
                $unit->save();
            }

            $weightKg = $unit ? $this->deltaWeightKg($product, $qty, $uom) : null;

            $ledger = $this->stockLedgerService->post(
                product: $product,
                warehouseId: $warehouse->id,
                movementType: StockLedgerMovement::Receive,
                qtySigned: (string) $qty,
                uom: $uom,
                physicalUnit: $unit,
                referenceType: 'warehouse_floor_receive',
                referenceId: $unit?->id,
                idempotencyKey: null,
                notes: $data['notes'] ?? 'Warehouse floor receive',
                meta: [
                    'source' => 'warehouse_floor',
                    'operator_name' => $operator,
                    'weight_kg' => $weightKg,
                    'spoolman_spool_id' => $unit?->spoolman_spool_id,
                ],
                userId: $user->id,
                operatorName: $operator !== '' ? $operator : null,
                weightKg: $weightKg !== null ? (string) $weightKg : null,
            );

            $this->postingService->refreshCache($product, $warehouse->id, $qty);

            return ['ledger' => $ledger, 'physical_unit' => $unit];
        });

        // Spoolman only after commit (ledger is source of truth; a failed receive must not create spools).
        $unit = $result['physical_unit'];
        if ($unit && ($product->spoolman_enabled || $unit->spoolman_spool_id)) {
            try {
                $unit = $this->spoolmanSyncService->ensureSpoolmanSpool($unit);
            } catch (Throwable) {
                // Spoolman can retry later.
            }
        }

        return ['ledger' => $result['ledger'], 'physical_unit' => $unit?->fresh()];
    }

    /**
     * Cut / issue stock from a physical unit (Spoolman-style weight reduction).
     *
     * @param  array{
     *   physical_unit_id:int,
     *   qty:float|int|string,
     *   uom?:string,
     *   notes?:string|null
     * }  $data
     * @return array{ledger:\App\Models\StockLedger, physical_unit:PhysicalUnit}
     */
    public function cut(array $data, User $user): array
    {
        $qty = (float) $data['qty'];
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => __('messages.validation.positive', ['attribute' => 'qty'])]);
        }

        $operator = trim((string) ($data['operator_name'] ?? ($data['meta']['operator_name'] ?? '')));

        $result = DB::transaction(function () use ($data, $user, $qty, $operator) {
            // Row lock: two devices cutting the same roll must not both pass the remaining check.
            $unit = PhysicalUnit::query()->with('product')->lockForUpdate()->findOrFail((int) $data['physical_unit_id']);
            if ($unit->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'physical_unit_id' => __('Unit is not active.'),
                ]);
            }

            $product = $unit->product;
            $uom = strtoupper((string) ($data['uom'] ?? $unit->weight_uom ?? $product->base_uom ?? 'KG'));
            $remaining = (float) ($unit->actual_weight ?? 0);
            $unitUom = strtoupper((string) ($unit->weight_uom ?: $product->base_uom ?: $uom));
            $baseUom = strtoupper((string) ($product->base_uom ?: $unitUom));
            $qtyInUnitUom = $this->convertOrFail($qty, $uom, $unitUom);
            $ledgerQty = $this->convertOrFail($qtyInUnitUom, $unitUom, $baseUom);

            if ($qtyInUnitUom - $remaining > 1e-6) {
                throw ValidationException::withMessages([
                    'qty' => __('messages.warehouse_floor.cut_exceeds', [
                        'qty' => $qty,
                        'uom' => $uom,
                        'remaining' => $remaining,
                        'remaining_uom' => $unit->weight_uom ?: 'KG',
                    ]),
                ]);
            }

            $weightKg = $this->deltaWeightKg($product, $qtyInUnitUom, $unitUom);

            $newWeight = max(0, $remaining - $qtyInUnitUom);
            $unit->actual_weight = $newWeight;
            $unit->weight_verified_at = now();
            if ($newWeight <= 1e-6) {
                $unit->status = 'DEPLETED';
                $unit->depleted_at = now();
            }
            $unit->save();

            $ledger = $this->stockLedgerService->post(
                product: $product,
                warehouseId: $unit->warehouse_id,
                movementType: StockLedgerMovement::Issue,
                qtySigned: (string) (-1 * abs($ledgerQty)),
                uom: $baseUom,
                physicalUnit: $unit,
                referenceType: 'warehouse_floor_cut',
                referenceId: $unit->id,
                notes: $data['notes'] ?? 'Warehouse floor cut',
                meta: array_merge(
                    [
                        'source' => 'warehouse_floor',
                        'operator_name' => $operator,
                        'weight_kg' => $weightKg,
                        'spoolman_spool_id' => $unit->spoolman_spool_id,
                    ],
                    is_array($data['meta'] ?? null) ? $data['meta'] : []
                ),
                userId: $user->id,
                operatorName: $operator !== '' ? $operator : null,
                weightKg: $weightKg !== null ? (string) $weightKg : null,
            );

            $this->postingService->refreshCache($product, $unit->warehouse_id, -abs($ledgerQty));

            return ['ledger' => $ledger, 'unit' => $unit, 'weight_kg' => $weightKg];
        });

        // Spoolman is best-effort and only told once the ledger (source of truth) is committed,
        // so a rolled-back cut can never leave Spoolman already deducted.
        $unit = $result['unit'];
        try {
            if ($result['weight_kg'] !== null && $result['weight_kg'] > 0) {
                $unit = $this->spoolmanSyncService->useWeight($unit, $result['weight_kg']);
            }
        } catch (Throwable) {
            try {
                $unit = $this->spoolmanSyncService->ensureSpoolmanSpool($unit);
            } catch (Throwable) {
                // Reconciliation picks it up later.
            }
        }

        return ['ledger' => $result['ledger'], 'physical_unit' => $unit->fresh()];
    }

    private function convertOrFail(float $qty, string $fromUom, string $toUom): float
    {
        try {
            return $this->weightUnitConverter->convert($qty, $fromUom, $toUom);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'uom' => __('messages.warehouse_floor.uom_mismatch', ['from' => $fromUom, 'to' => $toUom]),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveOrCreateUnit(array $data, Product $product, Warehouse $warehouse, float $qty, string $uom): ?PhysicalUnit
    {
        if (! empty($data['physical_unit_id'])) {
            $unit = PhysicalUnit::query()->findOrFail((int) $data['physical_unit_id']);
            if ((int) $unit->warehouse_id !== (int) $warehouse->id || (int) $unit->product_id !== (int) $product->id) {
                throw ValidationException::withMessages(['physical_unit_id' => 'Unit does not match product/warehouse.']);
            }

            return $unit;
        }

        $tracks = $product->tracks_physical_units || $product->spoolman_enabled || ! empty($data['create_unit']);
        if (! $tracks) {
            return null;
        }

        $opening = PhysicalUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('status', 'ACTIVE')
            ->where('lot_code', 'OPENING')
            ->first();
        if ($opening) {
            return $opening;
        }

        $code = $data['unit_code'] ?? null;
        if ($code) {
            $existing = PhysicalUnit::query()->where('unit_code', $code)->first();
            if ($existing) {
                return $existing;
            }
        }

        $unit = PhysicalUnit::query()->create([
            'unit_code' => $code ?: $this->postingService->nextUnitCode($product),
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'lot_code' => $data['lot_code'] ?? null,
            'unit_type' => $product->spoolman_enabled ? 'SPOOL' : 'ROLL',
            'status' => 'ACTIVE',
            'nominal_weight' => $qty,
            'actual_weight' => 0,
            'weight_uom' => $uom,
            'weight_source' => 'MANUAL',
            'opened_at' => now(),
        ]);

        return $unit->fresh();
    }

    private function deltaWeightKg(Product $product, float $qty, string $uom): ?float
    {
        $uom = strtoupper($uom);
        if (in_array($uom, ['KG', 'KILOGRAM', 'KILOGRAMS'], true)) {
            return $qty;
        }
        if (in_array($uom, ['G', 'GRAM', 'GRAMS'], true)) {
            return $qty / 1000;
        }
        if ($product->standard_weight_kg === null) {
            return null;
        }

        return $qty * (float) $product->standard_weight_kg;
    }
}
