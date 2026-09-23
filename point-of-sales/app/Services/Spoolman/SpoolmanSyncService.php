<?php

namespace App\Services\Spoolman;

use App\Enums\StockCalcStrategy;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\ProductSpoolmanMapping;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps WMS physical_units in sync with Spoolman spools (Spoolman owns weight telemetry).
 */
class SpoolmanSyncService
{
    public function __construct(
        private readonly SpoolmanClient $client,
        private readonly WeightUnitConverter $weightUnitConverter,
    ) {}

    /**
     * @return array{synced:int, created:int, updated:int, skipped:int, errors:list<string>}
     */
    public function syncSpools(?Warehouse $defaultWarehouse = null): array
    {
        $result = [
            'synced' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $spools = $this->client->listSpools();
        if (! is_array($spools)) {
            $result['errors'][] = 'Unexpected Spoolman spool list response.';

            return $result;
        }

        // Spoolman may return a bare list or { items: [...] }
        $items = array_is_list($spools) ? $spools : ($spools['items'] ?? $spools['spools'] ?? []);

        foreach ($items as $spool) {
            try {
                $outcome = $this->upsertPhysicalUnitFromSpool($spool, $defaultWarehouse);
                $result['synced']++;
                $result[$outcome]++;
            } catch (Throwable $e) {
                $result['errors'][] = 'spool '.($spool['id'] ?? '?').': '.$e->getMessage();
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Ensure a WMS physical unit is mirrored as a Spoolman spool (outbound create).
     */
    public function ensureSpoolmanSpool(PhysicalUnit $unit): PhysicalUnit
    {
        if (! $this->client->enabled()) {
            return $unit;
        }

        $unit->loadMissing(['product.spoolmanMapping', 'warehouse']);
        $product = $unit->product;
        if (! $product) {
            return $unit;
        }

        $remainingKg = $this->remainingWeightKg($unit);
        if ($remainingKg === null) {
            return $unit;
        }

        $mapping = $product->spoolmanMapping ?? $this->ensureFilamentForProduct($product);
        $remainingWeightG = round($remainingKg * 1000, 3);

        if ($unit->spoolman_spool_id) {
            $this->client->patchSpool((int) $unit->spoolman_spool_id, [
                'remaining_weight' => $remainingWeightG,
                'location' => $unit->warehouse?->code,
            ]);
            $unit->weight_source = 'SPOOLMAN';
            $unit->weight_verified_at = now();
            $unit->save();

            return $unit->fresh();
        }

        $created = $this->client->createSpool([
            'filament_id' => $mapping->spoolman_filament_id,
            'remaining_weight' => $remainingWeightG,
            'initial_weight' => $remainingWeightG,
            'location' => $unit->warehouse?->code,
            'lot_nr' => $unit->lot_code,
            'comment' => 'WMS '.$unit->unit_code,
        ]);

        $unit->spoolman_spool_id = (int) ($created['id'] ?? 0);
        $unit->weight_source = 'SPOOLMAN';
        $unit->weight_verified_at = now();
        $unit->save();

        if (! $product->spoolman_enabled) {
            $product->forceFill(['spoolman_enabled' => true])->save();
        }

        return $unit->fresh();
    }

    public function useWeight(PhysicalUnit $unit, float $weightKg): PhysicalUnit
    {
        $unit = $this->ensureSpoolmanSpool($unit);
        if (! $unit->spoolman_spool_id || $weightKg <= 0) {
            return $unit;
        }

        $this->client->useSpoolWeight((int) $unit->spoolman_spool_id, round($weightKg * 1000, 3));
        $unit->weight_source = 'SPOOLMAN';
        $unit->weight_verified_at = now();
        $unit->save();

        return $unit->fresh();
    }

    public function remainingWeightKg(PhysicalUnit $unit): ?float
    {
        $qty = (float) ($unit->actual_weight ?? 0);
        $uom = strtoupper((string) ($unit->weight_uom ?: $unit->product?->base_uom ?: ''));

        if (in_array($uom, ['KG', 'KILOGRAM', 'KILOGRAMS'], true)) {
            return $qty;
        }
        if (in_array($uom, ['G', 'GRAM', 'GRAMS'], true)) {
            return $qty / 1000;
        }

        $standard = $unit->product?->standard_weight_kg;
        if ($standard === null) {
            return null;
        }

        return $qty * (float) $standard;
    }

    public function ensureFilamentForProduct(Product $product): ProductSpoolmanMapping
    {
        $existing = $product->spoolmanMapping;
        if ($existing) {
            return $existing;
        }

        $product->loadMissing('category');
        $vendorId = $this->ensureVendorId();
        $standardG = max(1, (float) ($product->standard_weight_kg ?? 1) * 1000);
        $created = $this->client->createFilament([
            'name' => mb_substr((string) $product->sku, 0, 64),
            'vendor_id' => $vendorId,
            'material' => $product->category?->name ?: 'RM',
            'density' => 7.85,
            'diameter' => 1.75,
            'weight' => $standardG,
            'article_number' => mb_substr((string) $product->sku, 0, 64),
            'comment' => $product->title,
        ]);

        return ProductSpoolmanMapping::query()->create([
            'product_id' => $product->id,
            'spoolman_filament_id' => (int) ($created['id'] ?? 0),
            'spoolman_vendor_name' => 'WMS RM',
            'spoolman_material' => $product->category?->name ?: 'RM',
            'weight_uom' => 'g',
            'is_active' => true,
            'synced_at' => now(),
        ]);
    }

    public function syncOpeningUnits(): array
    {
        $result = ['synced' => 0, 'skipped' => 0, 'errors' => []];
        if (! $this->client->enabled()) {
            $result['errors'][] = 'Spoolman disabled';

            return $result;
        }

        $units = PhysicalUnit::query()
            ->with(['product.spoolmanMapping', 'product.category', 'warehouse'])
            ->active()
            ->whereNull('spoolman_spool_id')
            ->get();

        foreach ($units as $unit) {
            try {
                if ($this->remainingWeightKg($unit) === null) {
                    $result['skipped']++;

                    continue;
                }
                $this->ensureSpoolmanSpool($unit);
                $result['synced']++;
            } catch (Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = $unit->unit_code.': '.$e->getMessage();
            }
        }

        return $result;
    }

    private function ensureVendorId(): int
    {
        $vendors = $this->client->listVendors();
        $items = is_array($vendors) ? (array_is_list($vendors) ? $vendors : ($vendors['items'] ?? [])) : [];
        foreach ($items as $vendor) {
            if (($vendor['name'] ?? '') === 'WMS RM') {
                return (int) $vendor['id'];
            }
        }

        $created = $this->client->createVendor([
            'name' => 'WMS RM',
            'comment' => 'Warehouse raw materials',
        ]);

        return (int) ($created['id'] ?? 0);
    }

    /**
     * Register filament mapping + pull remote weight for a product.
     */
    public function linkFilament(Product $product, int $filamentId): ProductSpoolmanMapping
    {
        $filament = $this->client->getFilament($filamentId);

        return DB::transaction(function () use ($product, $filament, $filamentId) {
            ProductSpoolmanMapping::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $mapping = ProductSpoolmanMapping::query()->create([
                'product_id' => $product->id,
                'spoolman_filament_id' => $filamentId,
                'spoolman_vendor_name' => $filament['vendor']['name'] ?? ($filament['vendor_name'] ?? null),
                'spoolman_material' => $filament['material'] ?? null,
                'spoolman_color_hex' => $filament['color_hex'] ?? null,
                'weight_uom' => 'g',
                'is_active' => true,
                'synced_at' => now(),
            ]);

            $product->forceFill([
                'spoolman_enabled' => true,
                'tracks_physical_units' => true,
                'stock_calc_strategy' => StockCalcStrategy::SpoolWeightBased,
                'base_uom' => $product->base_uom ?: 'KG',
                'domain' => $product->domain ?: 'RM',
            ])->save();

            return $mapping;
        });
    }

    /**
     * @param  array<string, mixed>  $spool
     * @return 'created'|'updated'|'skipped'
     */
    private function upsertPhysicalUnitFromSpool(array $spool, ?Warehouse $defaultWarehouse): string
    {
        $spoolId = (int) ($spool['id'] ?? 0);
        if ($spoolId <= 0) {
            return 'skipped';
        }

        $filamentId = (int) ($spool['filament']['id'] ?? $spool['filament_id'] ?? 0);
        $mapping = $filamentId > 0
            ? ProductSpoolmanMapping::query()->active()->where('spoolman_filament_id', $filamentId)->first()
            : null;

        $unit = PhysicalUnit::query()->where('spoolman_spool_id', $spoolId)->first();

        if (! $unit && ! $mapping) {
            return 'skipped';
        }

        $remainingG = (float) ($spool['remaining_weight'] ?? $spool['remaining_weight_g'] ?? 0);
        $weightKg = $this->weightUnitConverter->convert($remainingG, 'g', 'KG');
        $archived = (bool) ($spool['archived'] ?? false);

        if ($unit) {
            $unit->fill([
                'actual_weight' => $weightKg,
                'weight_uom' => 'KG',
                'weight_source' => 'SPOOLMAN',
                'weight_verified_at' => now(),
                'status' => $archived || $weightKg <= 0 ? ($archived ? 'SCRAPPED' : 'DEPLETED') : 'ACTIVE',
                'lot_code' => $spool['lot_nr'] ?? $unit->lot_code,
                'meta' => array_merge($unit->meta ?? [], [
                    'spoolman' => [
                        'location' => $spool['location'] ?? null,
                        'comment' => $spool['comment'] ?? null,
                    ],
                ]),
            ])->save();

            return 'updated';
        }

        $warehouse = $defaultWarehouse
            ?? Warehouse::query()->where('code', $spool['location'] ?? '')->first()
            ?? Warehouse::query()->orderBy('id')->first();

        if (! $warehouse || ! $mapping) {
            return 'skipped';
        }

        PhysicalUnit::query()->create([
            'unit_code' => 'SP-'.str_pad((string) $spoolId, 6, '0', STR_PAD_LEFT),
            'product_id' => $mapping->product_id,
            'warehouse_id' => $warehouse->id,
            'unit_type' => 'SPOOL',
            'status' => $archived || $weightKg <= 0 ? 'DEPLETED' : 'ACTIVE',
            'actual_weight' => $weightKg,
            'weight_uom' => 'KG',
            'weight_source' => 'SPOOLMAN',
            'weight_verified_at' => now(),
            'spoolman_spool_id' => $spoolId,
            'lot_code' => $spool['lot_nr'] ?? null,
            'meta' => [
                'spoolman' => [
                    'location' => $spool['location'] ?? null,
                    'comment' => $spool['comment'] ?? null,
                    'imported_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        return 'created';
    }
}
