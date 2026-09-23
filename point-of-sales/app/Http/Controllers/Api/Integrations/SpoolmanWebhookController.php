<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponder;
use App\Services\Spoolman\SpoolConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpoolmanWebhookController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly SpoolConsumptionService $spoolConsumptionService
    ) {}

    /**
     * POST /api/integrations/spoolman/events
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', 'in:SPOOL_CONSUMPTION,SPOOL_WEIGHT_SYNC'],
            'event_id' => ['required', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'date'],
            'spool_id' => ['required_if:event,SPOOL_CONSUMPTION', 'integer'],
            'sku' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable'],
            'warehouse_code' => ['nullable', 'string', 'max:50'],
            'qty' => ['required_if:event,SPOOL_CONSUMPTION', 'numeric', 'gt:0'],
            'uom' => ['required_if:event,SPOOL_CONSUMPTION', 'string', 'max:20'],
            'weight_before' => ['nullable', 'numeric'],
            'weight_after' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:60'],
            'spools' => ['required_if:event,SPOOL_WEIGHT_SYNC', 'array'],
        ]);

        if ($validated['event'] === 'SPOOL_WEIGHT_SYNC') {
            // Sprint A: acknowledge; full sync/reconcile lands in Sprint C.
            return $this->ok([
                'accepted' => true,
                'event' => 'SPOOL_WEIGHT_SYNC',
                'event_id' => $validated['event_id'],
                'note' => 'Queued for reconciliation (Sprint C).',
            ], 'Weight sync accepted');
        }

        $event = $this->spoolConsumptionService->handleConsumption($validated);

        [$status, $message] = match (true) {
            // Replayed event_id: nothing new was written, so report the stored outcome as 200.
            ! $event->wasRecentlyCreated => [200, 'Event already processed'],
            $event->process_status === 'POSTED' => [201, 'Consumption posted to stock ledger'],
            $event->process_status === 'REJECTED' => [422, 'Consumption rejected'],
            default => [200, 'Consumption processed'],
        };

        return $this->ok([
            'event_id' => $event->event_id,
            'process_status' => $event->process_status,
            'reject_reason' => $event->reject_reason,
            'stock_ledger_id' => $event->stock_ledger_id,
            'physical_unit_id' => $event->physical_unit_id,
            'qty_consumed' => $event->qty_consumed,
            'qty_uom' => $event->qty_uom,
        ], $message, $status);
    }
}
