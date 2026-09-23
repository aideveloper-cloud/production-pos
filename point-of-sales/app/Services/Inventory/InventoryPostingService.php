<?php

namespace App\Services\Inventory;

use App\Enums\StockLedgerMovement;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockLedger;
use App\Services\Spoolman\SpoolmanSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Single write path for stock movements coming from documents (goods receiving, transfers,
 * stock counts, supplier returns, opening stock). Keeps four things in step:
 *
 *  1. stock_ledgers — immutable history, always in the product's base UOM;
 *  2. product_warehouse.stock / products.stock — cached balances;
 *  3. physical units — for tracked products every posting is tied to a unit, and the ACTIVE
 *     units of a product in a warehouse always add up to its ledger balance;
 *  4. Spoolman — told after the surrounding transaction commits, never before.
 *
 * In warehouse mode the ledger is authoritative and the cache is recomputed from it. In retail
 * mode checkout still writes the cache directly, so the cache is moved by the same delta instead.
 */
class InventoryPostingService
{
    public const ACTIVE = 'ACTIVE';

    public const IN_TRANSIT = 'IN_TRANSIT';

    public const DEPLETED = 'DEPLETED';

    private const EPSILON = 1e-6;

    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly SpoolmanSyncService $spoolman,
    ) {}

    public function tracksUnits(Product $product): bool
    {
        return (bool) ($product->tracks_physical_units || $product->spoolman_enabled);
    }

    public function baseUom(Product $product): string
    {
        return strtoupper((string) ($product->base_uom ?: 'PCS'));
    }

    public function onHand(Product $product, int $warehouseId): float
    {
        if ($this->ledgerIsAuthoritative()) {
            return (float) $this->ledger->onHandQty($product->id, $warehouseId);
        }

        return (float) (ProductWarehouse::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->value('stock') ?? 0);
    }

    /** What can actually leave the warehouse: ACTIVE unit weight for tracked products. */
    public function available(Product $product, int $warehouseId): float
    {
        if (! $this->tracksUnits($product)) {
            return $this->onHand($product, $warehouseId);
        }

        return (float) PhysicalUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->where('status', self::ACTIVE)
            ->sum('actual_weight');
    }

    /**
     * Stock in. A tracked product's qty lands on $unit (top-up) or on a new unit.
     */
    public function receive(
        Product $product,
        int $warehouseId,
        float $qty,
        StockLedgerMovement $type,
        PostingContext $context,
        ?PhysicalUnit $unit = null,
        ?string $lotCode = null,
    ): ?PhysicalUnit {
        $this->assertPositive($qty);

        return DB::transaction(function () use ($product, $warehouseId, $qty, $type, $context, $unit, $lotCode) {
            $this->lockBalance($product, $warehouseId);

            if ($this->tracksUnits($product)) {
                $unit = $unit
                    ? PhysicalUnit::query()->lockForUpdate()->findOrFail($unit->id)
                    : $this->createUnit($product, $warehouseId, $lotCode ?? $context->referenceType);
                $this->addToUnit($unit, $qty);
            }

            $this->post($product, $warehouseId, $type, $qty, $context, $unit);
            $this->refreshCache($product, $warehouseId, $qty);
            $this->syncSpoolmanAfterCommit($unit ? [$unit] : []);

            return $unit;
        });
    }

    /**
     * Stock out. Tracked products are taken FIFO (oldest unit first, $preferLot before others).
     *
     * @return Collection<int, array{unit: ?PhysicalUnit, qty: float}>
     */
    public function issue(
        Product $product,
        int $warehouseId,
        float $qty,
        StockLedgerMovement $type,
        PostingContext $context,
        ?string $preferLot = null,
    ): Collection {
        $this->assertPositive($qty);

        return DB::transaction(function () use ($product, $warehouseId, $qty, $type, $context, $preferLot) {
            $this->lockBalance($product, $warehouseId);
            $this->assertAvailable($product, $warehouseId, $qty);

            if (! $this->tracksUnits($product)) {
                $this->post($product, $warehouseId, $type, -$qty, $context);
                $this->refreshCache($product, $warehouseId, -$qty);

                return collect([['unit' => null, 'qty' => $qty]]);
            }

            $takes = $this->pickFifo($product, $warehouseId, $qty, $preferLot);
            foreach ($takes as $take) {
                $this->deductFromUnit($take['unit'], $take['qty']);
                $this->post($product, $warehouseId, $type, -$take['qty'], $context, $take['unit']);
            }
            $this->refreshCache($product, $warehouseId, -$qty);
            $this->syncSpoolmanAfterCommit($takes->pluck('unit')->all());

            return $takes;
        });
    }

    /**
     * Stock count: post the difference between the counted qty and the current balance.
     * Returns the posted difference (0 when nothing changed).
     */
    public function adjustTo(Product $product, int $warehouseId, float $counted, PostingContext $context, ?string $lotCode = null): float
    {
        if ($counted < 0) {
            throw new InvalidArgumentException('Counted qty cannot be negative.');
        }

        return DB::transaction(function () use ($product, $warehouseId, $counted, $context, $lotCode) {
            $this->lockBalance($product, $warehouseId);
            $current = $this->tracksUnits($product)
                ? $this->available($product, $warehouseId)
                : $this->onHand($product, $warehouseId);
            $delta = $counted - $current;

            if (abs($delta) <= self::EPSILON) {
                return 0.0;
            }

            $delta > 0
                ? $this->receive($product, $warehouseId, $delta, StockLedgerMovement::Count, $context, null, $lotCode)
                : $this->issue($product, $warehouseId, -$delta, StockLedgerMovement::Count, $context);

            return $delta;
        });
    }

    /**
     * Move qty out of a warehouse into transit. Whole units travel as they are (their label and
     * Spoolman spool stay valid); a partial take is split off into a new IN_TRANSIT unit.
     *
     * @return Collection<int, array{unit: ?PhysicalUnit, source: ?PhysicalUnit, qty: float}>
     */
    public function dispatch(Product $product, int $fromWarehouseId, float $qty, PostingContext $context): Collection
    {
        $this->assertPositive($qty);

        return DB::transaction(function () use ($product, $fromWarehouseId, $qty, $context) {
            $this->lockBalance($product, $fromWarehouseId);
            $this->assertAvailable($product, $fromWarehouseId, $qty);

            if (! $this->tracksUnits($product)) {
                $this->post($product, $fromWarehouseId, StockLedgerMovement::TransferOut, -$qty, $context);
                $this->refreshCache($product, $fromWarehouseId, -$qty);

                return collect([['unit' => null, 'source' => null, 'qty' => $qty]]);
            }

            $travelling = collect();
            foreach ($this->pickFifo($product, $fromWarehouseId, $qty) as $take) {
                $source = $take['unit'];
                $this->post($product, $fromWarehouseId, StockLedgerMovement::TransferOut, -$take['qty'], $context, $source);

                if ((float) $source->actual_weight - $take['qty'] <= self::EPSILON) {
                    $source->status = self::IN_TRANSIT;
                    $source->save();
                    $travelling->push(['unit' => $source, 'source' => $source, 'qty' => $take['qty']]);

                    continue;
                }

                $this->deductFromUnit($source, $take['qty']);
                $part = $this->createUnit($product, $fromWarehouseId, $source->lot_code, self::IN_TRANSIT, [
                    'split_from_unit_id' => $source->id,
                ]);
                $part->actual_weight = $take['qty'];
                $part->nominal_weight = $take['qty'];
                $part->save();
                $travelling->push(['unit' => $part, 'source' => $source, 'qty' => $take['qty']]);
            }

            $this->refreshCache($product, $fromWarehouseId, -$qty);
            $this->syncSpoolmanAfterCommit($travelling->pluck('source')->all());

            return $travelling;
        });
    }

    /**
     * Book travelling stock into the destination warehouse.
     *
     * @param  iterable<array{unit: ?PhysicalUnit, qty: float}>  $travelling
     */
    public function arrive(Product $product, int $toWarehouseId, iterable $travelling, PostingContext $context): void
    {
        DB::transaction(function () use ($product, $toWarehouseId, $travelling, $context) {
            $this->lockBalance($product, $toWarehouseId);
            $total = 0.0;
            $units = [];

            foreach ($travelling as $row) {
                $unit = $row['unit'] ? PhysicalUnit::query()->lockForUpdate()->find($row['unit']->id) : null;
                if ($this->tracksUnits($product) && ! $unit) {
                    throw ValidationException::withMessages([
                        'transfer' => __('messages.inventory.transit_unit_missing'),
                    ]);
                }
                if ($unit) {
                    $unit->warehouse_id = $toWarehouseId;
                    $unit->status = self::ACTIVE;
                    $unit->save();
                    $units[] = $unit;
                }
                $this->post($product, $toWarehouseId, StockLedgerMovement::TransferIn, (float) $row['qty'], $context, $unit);
                $total += (float) $row['qty'];
            }

            $this->refreshCache($product, $toWarehouseId, $total);
            $this->syncSpoolmanAfterCommit($units);
        });
    }

    /**
     * Undo a dispatch that never arrived: whole units go back on the shelf, split parts are
     * merged back into the unit they came from.
     *
     * @param  iterable<array{unit: ?PhysicalUnit, source: ?PhysicalUnit, qty: float}>  $travelling
     */
    public function returnFromTransit(Product $product, int $fromWarehouseId, iterable $travelling, PostingContext $context): void
    {
        DB::transaction(function () use ($product, $fromWarehouseId, $travelling, $context) {
            $this->lockBalance($product, $fromWarehouseId);
            $total = 0.0;
            $units = [];

            foreach ($travelling as $row) {
                $qty = (float) $row['qty'];
                $unit = $row['unit'] ? PhysicalUnit::query()->lockForUpdate()->find($row['unit']->id) : null;
                $source = $row['source'] ? PhysicalUnit::query()->lockForUpdate()->find($row['source']->id) : null;

                if ($unit && $source && $unit->id === $source->id) {
                    $unit->status = self::ACTIVE;
                    $unit->save();
                    $target = $unit;
                } elseif ($source) {
                    $this->addToUnit($source, $qty);
                    $unit?->delete(); // the split-off part never existed on its own
                    $target = $source;
                } else {
                    $target = $unit;
                }

                if ($target) {
                    $units[] = $target;
                }
                $this->post($product, $fromWarehouseId, StockLedgerMovement::TransferIn, $qty, $context, $target);
                $total += $qty;
            }

            $this->refreshCache($product, $fromWarehouseId, $total);
            $this->syncSpoolmanAfterCommit($units);
        });
    }

    public function nextUnitCode(Product $product): string
    {
        $prefix = $product->spoolman_enabled ? 'SP' : 'PU';
        // count()+1 alone collides with an existing code once any unit has been deleted.
        $seq = PhysicalUnit::query()->count() + 1;
        do {
            $code = sprintf('%s-%06d', $prefix, $seq++);
        } while (PhysicalUnit::query()->where('unit_code', $code)->exists());

        return $code;
    }

    /**
     * Bring the cached balances in line after a posting of $delta.
     */
    public function refreshCache(Product $product, int $warehouseId, float $delta): void
    {
        if ($this->ledgerIsAuthoritative()) {
            $onHand = (int) round((float) $this->ledger->onHandQty($product->id, $warehouseId));
            ProductWarehouse::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->update(['stock' => max(0, $onHand)]);
            $product->update([
                'stock' => (int) ProductWarehouse::query()->where('product_id', $product->id)->sum('stock'),
            ]);

            return;
        }

        // Retail mode: checkout still bypasses the ledger, so shift the cache exactly as before.
        $step = (int) round($delta);
        ProductWarehouse::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->increment('stock', $step);
        $product->increment('stock', $step);
    }

    private function ledgerIsAuthoritative(): bool
    {
        return (bool) config('warehouse.is_warehouse');
    }

    /** Serialises concurrent postings on one product in one warehouse. */
    private function lockBalance(Product $product, int $warehouseId): void
    {
        ProductWarehouse::query()->firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
            ['stock' => 0]
        );
        ProductWarehouse::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();
    }

    private function assertPositive(float $qty): void
    {
        if ($qty <= self::EPSILON) {
            throw ValidationException::withMessages([
                'qty' => __('messages.validation.positive', ['attribute' => 'qty']),
            ]);
        }
    }

    private function assertAvailable(Product $product, int $warehouseId, float $qty): void
    {
        $available = $this->available($product, $warehouseId);
        if ($qty - $available > self::EPSILON) {
            throw ValidationException::withMessages([
                'qty' => __('messages.inventory.insufficient', [
                    'product' => $product->sku ?: $product->title,
                    'available' => $this->formatQty($available),
                    'qty' => $this->formatQty($qty),
                    'uom' => $this->baseUom($product),
                ]),
            ]);
        }
    }

    /**
     * @return Collection<int, array{unit: PhysicalUnit, qty: float}>
     */
    private function pickFifo(Product $product, int $warehouseId, float $qty, ?string $preferLot = null): Collection
    {
        $query = PhysicalUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->where('status', self::ACTIVE)
            ->where('actual_weight', '>', 0);
        if ($preferLot) {
            $query->orderByRaw('CASE WHEN lot_code = ? THEN 0 ELSE 1 END', [$preferLot]);
        }
        $units = $query->orderByRaw('COALESCE(opened_at, created_at)')->orderBy('id')->lockForUpdate()->get();

        $remaining = $qty;
        $takes = collect();
        foreach ($units as $unit) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            $take = min($remaining, (float) $unit->actual_weight);
            $takes->push(['unit' => $unit, 'qty' => $take]);
            $remaining -= $take;
        }

        if ($remaining > self::EPSILON) {
            $this->assertAvailable($product, $warehouseId, $qty);
        }

        return $takes;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function createUnit(Product $product, int $warehouseId, ?string $lotCode, string $status = self::ACTIVE, array $meta = []): PhysicalUnit
    {
        return PhysicalUnit::query()->create([
            'unit_code' => $this->nextUnitCode($product),
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'lot_code' => $lotCode,
            'unit_type' => $product->spoolman_enabled ? 'SPOOL' : 'ROLL',
            'status' => $status,
            'actual_weight' => 0,
            'weight_uom' => $this->baseUom($product),
            'weight_source' => 'MANUAL',
            'opened_at' => now(),
            'meta' => $meta ?: null,
        ]);
    }

    private function addToUnit(PhysicalUnit $unit, float $qty): void
    {
        $unit->actual_weight = (float) ($unit->actual_weight ?? 0) + $qty;
        $unit->status = self::ACTIVE;
        $unit->depleted_at = null;
        $unit->weight_verified_at = now();
        $unit->save();
    }

    private function deductFromUnit(PhysicalUnit $unit, float $qty): void
    {
        $weight = max(0, (float) $unit->actual_weight - $qty);
        $unit->actual_weight = $weight;
        $unit->weight_verified_at = now();
        if ($weight <= self::EPSILON) {
            $unit->status = self::DEPLETED;
            $unit->depleted_at = now();
        }
        $unit->save();
    }

    private function post(Product $product, int $warehouseId, StockLedgerMovement $type, float $qtySigned, PostingContext $context, ?PhysicalUnit $unit = null): StockLedger
    {
        return $this->ledger->post(
            product: $product,
            warehouseId: $warehouseId,
            movementType: $type,
            qtySigned: (string) $qtySigned,
            uom: $this->baseUom($product),
            physicalUnit: $unit,
            referenceType: $context->referenceType,
            referenceId: $context->referenceId,
            notes: $context->notes,
            meta: $context->meta ?: null,
            userId: $context->userId,
            operatorName: $context->operatorName,
        );
    }

    /**
     * @param  array<int, PhysicalUnit|null>  $units
     */
    private function syncSpoolmanAfterCommit(array $units): void
    {
        $ids = collect($units)->filter()->pluck('id')->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        DB::afterCommit(function () use ($ids) {
            foreach (PhysicalUnit::query()->with('product')->whereIn('id', $ids)->get() as $unit) {
                if (! $unit->spoolman_spool_id && ! $unit->product?->spoolman_enabled) {
                    continue;
                }
                try {
                    $this->spoolman->ensureSpoolmanSpool($unit);
                } catch (Throwable) {
                    // Ledger is the source of truth; reconciliation picks Spoolman up later.
                }
            }
        });
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
