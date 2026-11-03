<?php

namespace App\Console\Commands;

use App\Enums\StockLedgerMovement;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs floor receives that were posted onto another product's unit (typing an existing unit code
 * in the receive form used to reuse that unit whatever product it belonged to). The ledger qty is
 * right and stays as is; only the unit side is wrong, so for each such row:
 *   - the qty is taken back off the wrong unit (restoring its uom, depleted again if it hits zero)
 *   - a new unit of the received product is created in the same warehouse with that qty
 *   - the ledger row is relinked to the new unit
 * Dry run by default; --apply writes the changes.
 */
class InventoryRepairUnitMismatchCommand extends Command
{
    protected $signature = 'inventory:repair-unit-mismatch
        {--apply : Write the repair (default is a dry run that only lists what would change)}';

    protected $description = 'Move floor receives that landed on another product\'s unit onto a new unit of the right product';

    public function handle(InventoryPostingService $postingService): int
    {
        $rows = StockLedger::query()
            ->join('physical_units as u', 'u.id', '=', 'stock_ledgers.physical_unit_id')
            ->where(fn ($q) => $q->whereColumn('u.product_id', '!=', 'stock_ledgers.product_id')
                ->orWhereColumn('u.warehouse_id', '!=', 'stock_ledgers.warehouse_id'))
            ->orderBy('stock_ledgers.id')
            ->select('stock_ledgers.*')
            ->with(['product', 'physicalUnit.product', 'warehouse:id,code'])
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No ledger rows point at another product\'s unit.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $fixable = 0;

        foreach ($rows as $ledger) {
            $wrong = $ledger->physicalUnit;
            $qty = (float) $ledger->qty;
            $label = "ledger #{$ledger->id} {$ledger->movement_type?->value} {$ledger->product?->sku} {$qty} {$ledger->uom} @ {$ledger->warehouse?->code}"
                ." → unit {$wrong->unit_code} ({$wrong->product?->sku}, remaining {$wrong->actual_weight} {$wrong->weight_uom})";

            if ($ledger->movement_type !== StockLedgerMovement::Receive || $qty <= 0) {
                $this->warn("  SKIP  {$label}: only receives can be repaired automatically");

                continue;
            }
            if ((float) $wrong->actual_weight + 0.0001 < $qty) {
                $this->warn("  SKIP  {$label}: the unit no longer holds that qty (already cut?) — fix by hand / stock count");

                continue;
            }

            $fixable++;
            $this->line("  FIX   {$label}");

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($ledger, $qty, $postingService) {
                $wrong = PhysicalUnit::query()->with('product')->lockForUpdate()->findOrFail($ledger->physical_unit_id);
                $product = Product::query()->findOrFail($ledger->product_id);

                $left = round((float) $wrong->actual_weight - $qty, 4);
                $wrong->actual_weight = max(0, $left);
                $wrong->weight_uom = strtoupper((string) ($wrong->product?->base_uom ?: $wrong->weight_uom));
                if ($left <= 0.0001) {
                    $wrong->status = InventoryPostingService::DEPLETED;
                    $wrong->depleted_at = $wrong->depleted_at ?? now();
                }
                $wrong->save();

                $unit = PhysicalUnit::query()->create([
                    'unit_code' => $postingService->nextUnitCode($product),
                    'product_id' => $product->id,
                    'warehouse_id' => $ledger->warehouse_id,
                    'unit_type' => match (true) {
                        (bool) $product->spoolman_enabled => 'SPOOL',
                        strtoupper((string) $product->base_uom) === 'ROLL' => 'ROLL',
                        strtoupper((string) $product->base_uom) === 'SHEET' => 'SHEET_PACK',
                        default => 'OTHER',
                    },
                    'status' => InventoryPostingService::ACTIVE,
                    'nominal_weight' => $qty,
                    'actual_weight' => $qty,
                    'weight_uom' => $ledger->uom,
                    'weight_source' => 'MANUAL',
                    'opened_at' => $ledger->posted_at ?? now(),
                ]);

                $ledger->physical_unit_id = $unit->id;
                if ($ledger->reference_type === 'warehouse_floor_receive') {
                    $ledger->reference_id = $unit->id;
                }
                $ledger->meta = [...($ledger->meta ?? []), 'repaired_from_unit' => $wrong->unit_code, 'repaired_at' => now()->toIso8601String()];
                $ledger->save();

                $this->info("        → new unit {$unit->unit_code} for {$product->sku}; {$wrong->unit_code} back to {$wrong->actual_weight} {$wrong->weight_uom}");
            });
        }

        if (! $apply) {
            $this->newLine();
            $this->comment("Dry run: {$fixable} row(s) would be repaired. Back up the database, then re-run with --apply.");
        } else {
            $this->newLine();
            $this->info("Repaired {$fixable} row(s). Run php artisan inventory:ledger-check to confirm.");
        }

        return self::SUCCESS;
    }
}
