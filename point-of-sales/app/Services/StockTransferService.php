<?php

namespace App\Services;

use App\Enums\StockLedgerMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTransferUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly StockMutationService $stockMutationService,
        private readonly InventoryPostingService $postingService
    ) {}

    public function generateDocumentNumber(): string
    {
        $prefix = 'ST-'.now()->format('Ymd').'-';
        $last = StockTransfer::where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data, array $items, int $userId): StockTransfer
    {
        $this->ensureWarehouseAccess($userId, $data['source_warehouse_id']);
        $this->ensureWarehouseAccess($userId, $data['destination_warehouse_id']);

        if ($data['source_warehouse_id'] === $data['destination_warehouse_id']) {
            throw ValidationException::withMessages([
                'destination_warehouse_id' => 'Gudang asal dan tujuan harus berbeda.',
            ]);
        }

        // ponytail: retry only on document_number unique collisions (concurrent drafts pick the same next number)
        return retry(3, function () use ($data, $items, $userId) {
            return DB::transaction(function () use ($data, $items, $userId) {
                $transfer = StockTransfer::create([
                    'source_warehouse_id' => $data['source_warehouse_id'],
                    'destination_warehouse_id' => $data['destination_warehouse_id'],
                    'document_number' => $data['document_number'] ?? $this->generateDocumentNumber(),
                    'status' => 'draft',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                foreach ($items as $item) {
                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $item['product_id'],
                        'qty' => $item['qty'],
                    ]);
                }

                $this->auditLogService->log(
                    event: 'stock_transfer.created',
                    module: 'stock',
                    auditable: $transfer,
                    description: 'Transfer stok '.$transfer->document_number.' dibuat.',
                    after: [
                        'document_number' => $transfer->document_number,
                        'source_warehouse_id' => $transfer->source_warehouse_id,
                        'destination_warehouse_id' => $transfer->destination_warehouse_id,
                        'status' => 'draft',
                        'total_items' => count($items),
                    ],
                    meta: ['stock_transfer_id' => $transfer->id],
                );

                return $transfer;
            });
        }, 0, function ($e) {
            return $e instanceof QueryException && str_contains($e->getMessage(), 'document_number');
        });
    }

    public function send(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId) {
            // ponytail: lock the transfer row and re-check status inside the transaction (prevents double-send / send-after-cancel races)
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureWarehouseAccess($userId, $transfer->source_warehouse_id);

            if (! $transfer->isDraft()) {
                throw ValidationException::withMessages([
                    'transfer' => 'Hanya transfer dengan status draft yang bisa dikirim.',
                ]);
            }

            $transfer->load('items.product');
            $before = $transfer->replicate();

            // Take stock out of the source warehouse into transit (ledger + units + cache)
            $context = $this->postingContext($transfer, $userId, 'Transfer ke '.$transfer->destinationWarehouse->code);
            foreach ($transfer->items as $item) {
                $available = (int) floor($this->postingService->available($item->product, $transfer->source_warehouse_id));
                if ($available < $item->qty) {
                    throw ValidationException::withMessages([
                        'transfer' => __('messages.inventory.insufficient', [
                            'product' => $item->product->sku ?: $item->product->title,
                            'available' => $available,
                            'qty' => $item->qty,
                            'uom' => $this->postingService->baseUom($item->product),
                        ]),
                    ]);
                }

                $stockAfter = $available - $item->qty;
                $travelling = $this->postingService->dispatch($item->product, $transfer->source_warehouse_id, (float) $item->qty, $context);
                foreach ($travelling as $row) {
                    if ($row['unit']) {
                        StockTransferUnit::create([
                            'stock_transfer_id' => $transfer->id,
                            'stock_transfer_item_id' => $item->id,
                            'physical_unit_id' => $row['unit']->id,
                            'source_unit_id' => $row['source']?->id,
                            'qty' => $row['qty'],
                        ]);
                    }
                }

                $this->stockMutationService->recordMutation(
                    product: $item->product,
                    warehouseId: $transfer->source_warehouse_id,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    mutationType: 'out',
                    qty: $item->qty,
                    stockBefore: $available,
                    stockAfter: $stockAfter,
                    notes: 'Transfer ke '.$transfer->destinationWarehouse->code,
                    userId: $userId,
                );
            }

            $transfer->update([
                'status' => 'in_transit',
            ]);

            $this->auditLogService->log(
                event: 'stock_transfer.sent',
                module: 'stock',
                auditable: $transfer,
                description: 'Transfer stok '.$transfer->document_number.' dikirim.',
                before: ['status' => $before->status],
                after: ['status' => 'in_transit'],
                meta: ['stock_transfer_id' => $transfer->id],
            );
        });
    }

    public function receive(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId) {
            // ponytail: lock the transfer row and re-check status inside the transaction (prevents double-receive)
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureWarehouseAccess($userId, $transfer->destination_warehouse_id);

            if (! $transfer->isInTransit()) {
                throw ValidationException::withMessages([
                    'transfer' => 'Hanya transfer dengan status in_transit yang bisa diterima.',
                ]);
            }

            $transfer->load('items.product');
            $before = $transfer->replicate();

            // Book the travelling stock into the destination warehouse
            $context = $this->postingContext($transfer, $userId, 'Transfer dari '.$transfer->sourceWarehouse->code);
            foreach ($transfer->items as $item) {
                $product = $item->product;
                $stockBefore = (int) $product->stock;

                $travelling = $this->travellingRows($transfer, $item);
                if ($travelling->isEmpty() && $this->postingService->tracksUnits($product)) {
                    // Sent before units were tracked on transfers: book it in as a new unit.
                    $this->postingService->receive($product, $transfer->destination_warehouse_id, (float) $item->qty,
                        StockLedgerMovement::TransferIn, $context, null, $transfer->document_number);
                } else {
                    $this->postingService->arrive($product, $transfer->destination_warehouse_id,
                        $travelling->isEmpty() ? [['unit' => null, 'qty' => (float) $item->qty]] : $travelling, $context);
                }

                $this->stockMutationService->recordMutation(
                    product: $product,
                    warehouseId: $transfer->destination_warehouse_id,
                    referenceType: 'stock_transfer',
                    referenceId: $transfer->id,
                    mutationType: 'in',
                    qty: $item->qty,
                    stockBefore: $stockBefore,
                    stockAfter: (int) $product->stock,
                    notes: 'Transfer dari '.$transfer->sourceWarehouse->code,
                    userId: $userId,
                );
            }

            $transfer->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->auditLogService->log(
                event: 'stock_transfer.received',
                module: 'stock',
                auditable: $transfer,
                description: 'Transfer stok '.$transfer->document_number.' diterima.',
                before: ['status' => $before->status],
                after: ['status' => 'completed'],
                meta: ['stock_transfer_id' => $transfer->id],
            );
        });
    }

    public function cancel(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId) {
            // ponytail: lock the transfer row and re-check status inside the transaction (prevents cancel-after-complete races)
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureWarehouseAccess($userId, $transfer->source_warehouse_id);

            if (! in_array($transfer->status, ['draft', 'in_transit'])) {
                throw ValidationException::withMessages([
                    'transfer' => 'Hanya transfer draft atau in_transit yang bisa dibatalkan.',
                ]);
            }

            $before = $transfer->replicate();
            $returnStock = $transfer->isInTransit();

            // If sent but not received, return stock to source
            if ($returnStock) {
                $transfer->load('items.product');

                $context = $this->postingContext($transfer, $userId, 'Pembatalan transfer '.$transfer->document_number, 'stock_transfer_cancel');
                foreach ($transfer->items as $item) {
                    $product = $item->product;
                    $travelling = $this->travellingRows($transfer, $item);

                    if ($travelling->isEmpty() && $this->postingService->tracksUnits($product)) {
                        $this->postingService->receive($product, $transfer->source_warehouse_id, (float) $item->qty,
                            StockLedgerMovement::TransferIn, $context, null, $transfer->document_number);
                    } else {
                        $this->postingService->returnFromTransit($product, $transfer->source_warehouse_id,
                            $travelling->isEmpty() ? [['unit' => null, 'source' => null, 'qty' => (float) $item->qty]] : $travelling, $context);
                    }
                }
            }

            $transfer->update(['status' => 'cancelled']);

            $this->auditLogService->log(
                event: 'stock_transfer.cancelled',
                module: 'stock',
                auditable: $transfer,
                description: 'Transfer stok '.$transfer->document_number.' dibatalkan.'.($returnStock ? ' Stok dikembalikan ke gudang asal.' : ''),
                before: ['status' => $before->status],
                after: ['status' => 'cancelled'],
                meta: ['stock_transfer_id' => $transfer->id],
            );
        });
    }

    private function postingContext(StockTransfer $transfer, int $userId, string $notes, string $referenceType = 'stock_transfer'): PostingContext
    {
        return new PostingContext(
            referenceType: $referenceType,
            referenceId: $transfer->id,
            notes: $notes,
            userId: $userId,
            meta: ['document_number' => $transfer->document_number],
        );
    }

    /**
     * Units recorded as travelling for one transfer line.
     *
     * @return Collection<int, array{unit: ?\App\Models\PhysicalUnit, source: ?\App\Models\PhysicalUnit, qty: float}>
     */
    private function travellingRows(StockTransfer $transfer, StockTransferItem $item): Collection
    {
        return StockTransferUnit::query()
            ->with(['unit', 'sourceUnit'])
            ->where('stock_transfer_id', $transfer->id)
            ->where('stock_transfer_item_id', $item->id)
            ->get()
            ->map(fn (StockTransferUnit $row) => [
                'unit' => $row->unit,
                'source' => $row->sourceUnit,
                'qty' => (float) $row->qty,
            ]);
    }

    private function ensureWarehouseAccess(int $userId, int $warehouseId): void
    {
        $user = User::findOrFail($userId);
        $warehouse = Warehouse::findOrFail($warehouseId);

        abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);
    }
}
