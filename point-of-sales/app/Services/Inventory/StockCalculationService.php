<?php

namespace App\Services\Inventory;

use App\Enums\StockCalcStrategy;
use App\Models\PhysicalUnit;
use App\Models\Product;

class StockCalculationService
{
    public function __construct(
        private readonly StockLedgerService $stockLedgerService
    ) {}

    /**
     * @return array{
     *   on_hand_qty: string,
     *   on_hand_standard_weight: ?string,
     *   on_hand_actual_weight: ?string,
     *   on_hand_spool_weight: ?string,
     *   available_weight: ?string,
     *   variance_weight: ?string,
     *   strategy: string
     * }
     */
    public function summarize(Product $product, int $warehouseId): array
    {
        $strategy = $product->stock_calc_strategy ?? StockCalcStrategy::QtyBased;
        $onHandQty = $this->stockLedgerService->onHandQty($product->id, $warehouseId);

        $physicalWeight = (float) PhysicalUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->active()
            ->whereNotNull('actual_weight')
            ->sum('actual_weight');

        if ($product->standard_weight_kg !== null) {
            $standardWeight = (float) $onHandQty * (float) $product->standard_weight_kg;
        } elseif ($strategy === StockCalcStrategy::QtyBased) {
            $standardWeight = (float) $onHandQty;
        } else {
            $standardWeight = null;
        }

        $availableWeight = match ($strategy) {
            StockCalcStrategy::ActualWeightBased,
            StockCalcStrategy::SpoolWeightBased => $physicalWeight,
            StockCalcStrategy::QtyBased => $standardWeight,
            default => $physicalWeight > 0 ? $physicalWeight : $standardWeight,
        };

        $variance = $standardWeight === null ? null : $physicalWeight - $standardWeight;

        $physicalFormatted = $this->formatWeight($physicalWeight);

        return [
            'on_hand_qty' => $onHandQty,
            'on_hand_standard_weight' => $this->formatWeight($standardWeight),
            'on_hand_actual_weight' => $physicalFormatted,
            'on_hand_spool_weight' => $physicalFormatted,
            'available_weight' => $this->formatWeight($availableWeight === null ? null : (float) $availableWeight),
            'variance_weight' => $this->formatWeight($variance),
            'strategy' => $strategy->value,
        ];
    }

    private function formatWeight(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 4, '.', '');
    }
}
