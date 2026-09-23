<?php

namespace App\Services;

use App\Enums\StockLedgerMovement;
use App\Models\GoodsReceiving;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierReturnService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly StockMutationService $stockMutationService,
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryPostingService $postingService
    ) {}

    public function generateDocumentNumber(): string
    {
        $prefix = 'SR-'.now()->format('Ymd').'-';
        $last = SupplierReturn::where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createReturn(array $data, array $items, int $userId): SupplierReturn
    {
        $warehouseId = $data['warehouse_id'] ?? null;
        if (! $warehouseId && ! empty($data['goods_receiving_id'])) {
            $warehouseId = GoodsReceiving::whereKey($data['goods_receiving_id'])->value('warehouse_id');
            $data['warehouse_id'] = $warehouseId;
        }
        if ($warehouseId) {
            $this->ensureWarehouseAccess($userId, $warehouseId);
        }

        return DB::transaction(function () use ($data, $items, $userId) {
            $return = SupplierReturn::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'goods_receiving_id' => $data['goods_receiving_id'] ?? null,
                'payable_id' => $data['payable_id'] ?? null,
                'document_number' => $this->generateDocumentNumber(),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($items as $item) {
                SupplierReturnItem::create([
                    'supplier_return_id' => $return->id,
                    'goods_receiving_item_id' => $item['goods_receiving_item_id'] ?? null,
                    'product_id' => $item['product_id'],
                    'qty_returned' => $item['qty_returned'],
                    'unit_price' => $item['unit_price'] ?? 0,
                    'reason' => $item['reason'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->auditLogService->log(
                event: 'supplier_return.created',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' dibuat.',
                after: [
                    'document_number' => $return->document_number,
                    'supplier_id' => $return->supplier_id,
                    'status' => 'draft',
                    'total_items' => count($items),
                ],
                meta: ['supplier_return_id' => $return->id],
            );

            return $return;
        });
    }

    public function complete(SupplierReturn $return, int $userId): void
    {
        DB::transaction(function () use ($return, $userId) {
            // Lock + re-check status: completing twice used to deduct the stock twice.
            $return = SupplierReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya supplier return berstatus draft yang dapat diselesaikan.',
                ]);
            }

            if ($return->warehouse_id) {
                $this->ensureWarehouseAccess($userId, $return->warehouse_id);
            }

            $return->load(['items.product', 'items.goodsReceivingItem.goodsReceiving']);
            $context = new PostingContext(
                referenceType: 'supplier_return',
                referenceId: $return->id,
                notes: 'Retur ke supplier '.$return->document_number,
                userId: $userId,
                meta: ['document_number' => $return->document_number],
            );

            foreach ($return->items as $item) {
                $product = $item->product;
                $stockBefore = (int) $product->stock;

                if ($return->warehouse_id) {
                    // Checks availability; tracked products give back units from the receiving's lot first.
                    $this->postingService->issue(
                        $product,
                        $return->warehouse_id,
                        (float) $item->qty_returned,
                        StockLedgerMovement::Issue,
                        $context->with($item->reason),
                        $item->goodsReceivingItem?->goodsReceiving?->document_number,
                    );
                } else {
                    $product->decrement('stock', $item->qty_returned);
                }

                $this->stockMutationService->recordSupplierReturnOut(
                    product: $product,
                    supplierReturn: $return,
                    qty: $item->qty_returned,
                    stockBefore: $stockBefore,
                    stockAfter: (int) $product->stock,
                    notes: $item->reason ?? 'Retur barang ke supplier',
                    userId: $return->created_by,
                );
            }

            if ($return->payable_id && $return->payable) {
                $returnAmount = $return->items->sum(fn ($i) => $i->qty_returned * $i->unit_price);
                $payable = $return->payable;
                $payable->total = max(0, $payable->total - $returnAmount);
                if ($payable->total <= 0) {
                    $payable->total = 0;
                    $payable->status = 'paid';
                } elseif ($payable->paid > 0) {
                    $payable->status = $payable->paid >= $payable->total ? 'paid' : 'partial';
                }
                $payable->save();
            }

            $return->update([
                'status' => 'completed',
                'returned_at' => now(),
            ]);

            $this->auditLogService->log(
                event: 'supplier_return.completed',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' diselesaikan. Stok dikurangi dan hutang dikoreksi.',
                after: ['status' => 'completed'],
                meta: ['supplier_return_id' => $return->id],
            );
        });
    }

    private function ensureWarehouseAccess(int $userId, int $warehouseId): void
    {
        $user = User::findOrFail($userId);
        $warehouse = Warehouse::findOrFail($warehouseId);

        abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);
    }

    public function cancel(SupplierReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $return->update(['status' => 'cancelled']);

            $this->auditLogService->log(
                event: 'supplier_return.cancelled',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' dibatalkan.',
                after: ['status' => 'cancelled'],
                meta: ['supplier_return_id' => $return->id],
            );
        });
    }
}
