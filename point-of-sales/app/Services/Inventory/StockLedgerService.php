<?php

namespace App\Services\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single write path for immutable WMS stock ledger postings.
 * Corrections must reverse + repost — never mutate posted rows.
 */
class StockLedgerService
{
    public function onHandQty(int $productId, int $warehouseId): string
    {
        $sum = StockLedger::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('qty');

        return number_format((float) $sum, 6, '.', '');
    }

    public function post(
        Product $product,
        int $warehouseId,
        StockLedgerMovement $movementType,
        string $qtySigned,
        string $uom,
        ?PhysicalUnit $physicalUnit = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
        ?string $notes = null,
        ?array $meta = null,
        ?int $userId = null,
        ?string $operatorName = null,
        ?string $weightKg = null,
    ): StockLedger {
        if ($idempotencyKey) {
            $existing = StockLedger::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $qty = (float) $qtySigned;
        if ($qty == 0.0) {
            throw new InvalidArgumentException('Ledger qty must be non-zero.');
        }

        return DB::transaction(function () use (
            $product,
            $warehouseId,
            $movementType,
            $qty,
            $uom,
            $physicalUnit,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $notes,
            $meta,
            $userId,
            $operatorName,
            $weightKg,
        ) {
            $before = (float) $this->onHandQty($product->id, $warehouseId);

            return StockLedger::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'physical_unit_id' => $physicalUnit?->id,
                'movement_type' => $movementType,
                'qty' => $qty,
                'uom' => strtoupper($uom),
                'qty_before' => $before,
                'qty_after' => $before + $qty,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'meta' => $meta,
                'created_by' => $userId,
                'operator_name' => $operatorName,
                'weight_kg' => $weightKg,
                'posted_at' => now(),
            ]);
        });
    }
}
