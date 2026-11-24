<?php

namespace App\Services\Production;

use App\Enums\StockLedgerMovement;
use App\Models\Product;
use App\Models\ProductionLog;
use App\Models\ProductionOutput;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use App\Services\KGarden\WorkOrderClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The production POS: work ERPNext Work Orders from the WMS tablet.
 *
 * ERPNext owns the Work Order and its BOM. Raw materials reach the WIP warehouse when the RM POS
 * cuts them; reported good quantity is received straight into this WMS warehouse as finished
 * goods. The stock bridge posts each such receipt to ERPNext as a Manufacture Stock Entry
 * (backflush from WIP, finished goods into this warehouse), keyed by the ledger row.
 */
class ProductionFloorService
{
    public function __construct(
        private readonly WorkOrderClient $workOrders,
        private readonly InventoryPostingService $posting,
    ) {}

    /**
     * Open Work Orders with what the floor knows about them: running now, reported so far, and the
     * WMS product the finished goods go to (null when the item is not set up in the WMS yet).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(): Collection
    {
        $orders = collect($this->workOrders->open());
        $names = $orders->pluck('name')->all();
        $running = ProductionLog::query()->whereIn('work_order', $names)->whereNull('ended_at')
            ->get()->keyBy('work_order');
        $reported = $this->reportedGood($names);
        $products = Product::query()->whereIn('sku', $orders->pluck('production_item')->all())
            ->where('domain', 'FG')->get(['id', 'sku', 'base_uom'])->keyBy('sku');

        return $orders->map(function (array $wo) use ($running, $reported, $products) {
            $log = $running->get($wo['name']);
            $product = $products->get($wo['production_item']);

            return [
                ...$wo,
                'remaining_qty' => $this->remaining($wo, (float) ($reported[$wo['name']] ?? 0)),
                'reported_qty' => (float) ($reported[$wo['name']] ?? 0),
                'running' => $log ? [
                    'operator_name' => $log->operator_name,
                    'started_at' => $log->started_at?->toIso8601String(),
                ] : null,
                'product_id' => $product?->id,
            ];
        })->values();
    }

    public function start(string $workOrder, Warehouse $warehouse, User $user, string $operator, ?string $note = null): ProductionLog
    {
        $this->openOrFail($workOrder);

        return DB::transaction(function () use ($workOrder, $warehouse, $user, $operator, $note) {
            $running = ProductionLog::query()->where('work_order', $workOrder)->whereNull('ended_at')
                ->lockForUpdate()->first();
            if ($running) {
                throw ValidationException::withMessages([
                    'work_order' => 'ใบสั่งผลิต '.$workOrder.' กำลังผลิตอยู่แล้ว (โดย '.($running->operator_name ?: '-').')',
                ]);
            }

            return ProductionLog::query()->create([
                'work_order' => $workOrder,
                'warehouse_id' => $warehouse->id,
                'user_id' => $user->id,
                'operator_name' => $operator,
                'started_at' => now(),
                'note' => $note,
            ]);
        });
    }

    public function stop(string $workOrder, ?string $note = null): ProductionLog
    {
        return DB::transaction(function () use ($workOrder, $note) {
            $log = ProductionLog::query()->where('work_order', $workOrder)->whereNull('ended_at')
                ->lockForUpdate()->first();
            if (! $log) {
                throw ValidationException::withMessages(['work_order' => 'ใบสั่งผลิต '.$workOrder.' ไม่ได้กำลังผลิตอยู่']);
            }
            $log->ended_at = now();
            if ($note) {
                $log->note = $note;
            }
            $log->save();

            return $log;
        });
    }

    /**
     * Report finished quantity. Good quantity goes into $warehouse as finished goods; the same
     * $clientKey sent again returns the first report instead of counting twice.
     */
    public function report(
        string $workOrder,
        Warehouse $warehouse,
        User $user,
        string $operator,
        float $goodQty,
        float $scrapQty,
        ?string $scrapReason,
        string $clientKey,
    ): ProductionOutput {
        if ($existing = ProductionOutput::query()->where('client_key', $clientKey)->first()) {
            return $existing;
        }
        if ($goodQty < 0 || $scrapQty < 0 || ($goodQty <= 0 && $scrapQty <= 0)) {
            throw ValidationException::withMessages(['good_qty' => 'ใส่จำนวนของดีหรือของเสียมากกว่า 0']);
        }
        if ($scrapQty > 0 && trim((string) $scrapReason) === '') {
            throw ValidationException::withMessages(['scrap_reason' => 'ใส่สาเหตุของเสีย']);
        }

        $wo = $this->openOrFail($workOrder);
        $product = Product::query()->where('sku', $wo['production_item'])->where('domain', 'FG')->first();
        if (! $product) {
            throw ValidationException::withMessages([
                'work_order' => 'ยังไม่มีสินค้า '.$wo['production_item'].' ('.$wo['item_name'].') เป็น FG ใน WMS ให้ผู้ดูแลเพิ่มสินค้าก่อน',
            ]);
        }
        $uom = strtoupper((string) ($product->base_uom ?: $wo['stock_uom']));
        if (strcasecmp(self::erpUom($uom), (string) $wo['stock_uom']) !== 0) {
            throw ValidationException::withMessages([
                'work_order' => 'หน่วยของสินค้าใน WMS ('.$uom.') ไม่ตรงกับใบสั่งผลิต ('.$wo['stock_uom'].')',
            ]);
        }

        // One report per Work Order at a time, so two tablets cannot both take the last pieces.
        return Cache::lock('production-report:'.$workOrder, 30)->block(10, fn () => DB::transaction(function () use ($wo, $workOrder, $warehouse, $user, $operator, $goodQty, $scrapQty, $scrapReason, $clientKey, $product, $uom) {
            if ($existing = ProductionOutput::query()->where('client_key', $clientKey)->first()) {
                return $existing;
            }
            $remaining = $this->remaining($wo, (float) ($this->reportedGood([$workOrder])[$workOrder] ?? 0));
            if ($goodQty > $remaining + 1e-9) {
                throw ValidationException::withMessages([
                    'good_qty' => 'จำนวนของดี '.$this->fmt($goodQty).' มากกว่าที่เหลือต้องผลิต ('.$this->fmt($remaining).')',
                ]);
            }

            $output = ProductionOutput::query()->create([
                'work_order' => $workOrder,
                'item_code' => $wo['production_item'],
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'good_qty' => $goodQty,
                'scrap_qty' => $scrapQty,
                'scrap_reason' => $scrapQty > 0 ? trim((string) $scrapReason) : null,
                'uom' => $uom,
                'user_id' => $user->id,
                'operator_name' => $operator,
                'client_key' => $clientKey,
            ]);

            if ($goodQty > 0) {
                $this->posting->receive(
                    $product,
                    $warehouse->id,
                    $goodQty,
                    StockLedgerMovement::Receive,
                    new PostingContext(
                        referenceType: ProductionOutput::REFERENCE_TYPE,
                        referenceId: $output->id,
                        notes: 'ผลิตเสร็จตามใบสั่งผลิต '.$workOrder,
                        userId: $user->id,
                        operatorName: $operator,
                        // The stock bridge reads the Work Order from here to post the Manufacture entry.
                        meta: ['work_order' => $workOrder, 'source' => 'production_pos'],
                    ),
                    lotCode: $workOrder,
                );
                $output->stock_ledger_id = StockLedger::query()
                    ->where('reference_type', ProductionOutput::REFERENCE_TYPE)
                    ->where('reference_id', $output->id)
                    ->value('id');
                $output->save();
            }

            return $output;
        }));
    }

    /** @return array<string, mixed> the Work Order, when it is submitted and still open */
    private function openOrFail(string $workOrder): array
    {
        $wo = $this->workOrders->find($workOrder);
        if (! $wo || ! ($wo['open'] ?? false)) {
            throw ValidationException::withMessages([
                'work_order' => 'ใบสั่งผลิต '.$workOrder.' ไม่ได้เปิดอยู่ใน ERPNext (ยังไม่ส่ง, เสร็จแล้ว หรือหยุดไว้)',
            ]);
        }

        return $wo;
    }

    /**
     * ERPNext counts produced quantity only after the bridge posts it, so what the floor reported
     * but ERPNext has not booked yet is taken off too.
     */
    private function remaining(array $wo, float $reported): float
    {
        return max(0.0, (float) $wo['qty'] - max((float) $wo['produced_qty'], $reported));
    }

    /** @return array<string, float> good quantity reported per Work Order */
    private function reportedGood(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return ProductionOutput::query()->whereIn('work_order', $names)
            ->groupBy('work_order')->selectRaw('work_order, sum(good_qty) as good')
            ->pluck('good', 'work_order')->map(fn ($v) => (float) $v)->all();
    }

    /** WMS base UOM as ERPNext names it — the same mapping the stock bridge uses (PCS -> Nos). */
    public static function erpUom(string $wms): string
    {
        $map = ['KG' => 'Kg', 'PCS' => 'Nos', 'PC' => 'Nos', 'EA' => 'Nos'];
        $wms = strtoupper(trim($wms));

        return $map[$wms] ?? ($wms === '' ? 'Nos' : ucfirst(strtolower($wms)));
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
