<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Apps\Concerns\ResolvesPosOperator;
use App\Http\Controllers\Controller;
use App\Models\ProductionOutput;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\Production\ProductionFloorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Production POS (/pos/production): the shop floor works ERPNext Work Orders on the WMS tablet —
 * start / stop work and report finished quantity, which is received into the device's warehouse.
 * Unlock (warehouse PIN or employee code + PIN) is shared with the RM and FG POS.
 */
class ProductionFloorController extends Controller
{
    use ResolvesPosOperator;

    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly ProductionFloorService $floor,
    ) {}

    public function render(Request $request, ?int $lockedWarehouseId = null): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $warehouses = $this->outletAccessService->warehousesFor($user);
        $warehouse = $this->warehouseFor($request, $warehouses, $lockedWarehouseId);

        $error = null;
        try {
            $orders = $this->floor->board();
        } catch (Throwable $e) {
            Log::warning('Production POS could not read Work Orders', ['error' => $e->getMessage()]);
            $orders = collect();
            $error = 'อ่านใบสั่งผลิตจาก ERPNext ไม่ได้ ลองใหม่อีกครั้ง';
        }

        return Inertia::render('Pos/Production', [
            'warehouses' => $user->isWarehouseAdmin()
                ? $warehouses->map(fn (Warehouse $w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name])->values()
                : [],
            'currentWarehouse' => ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name],
            'workOrders' => $orders,
            'loadError' => $error,
            'recent' => ProductionOutput::query()
                ->where('warehouse_id', $warehouse->id)
                ->latest('id')->limit(10)
                ->get(['id', 'work_order', 'item_code', 'good_qty', 'scrap_qty', 'uom', 'operator_name', 'created_at']),
            'operatorName' => $this->signedInOperator($request) ?? (string) $request->session()->get('pos.operator_name', ''),
            'operatorLocked' => $this->signedInOperator($request) !== null,
        ]);
    }

    public function start(Request $request, string $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'operator_name' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        [$warehouse, $operator] = $this->context($request, $validated);
        $log = $this->floor->start($workOrder, $warehouse, $request->user(), $operator, $validated['note'] ?? null);

        return response()->json(['ok' => true, 'log_id' => $log->id], 201);
    }

    public function stop(Request $request, string $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'operator_name' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->context($request, $validated, needsOperator: false);
        $log = $this->floor->stop($workOrder, $validated['note'] ?? null);

        return response()->json(['ok' => true, 'log_id' => $log->id]);
    }

    public function output(Request $request, string $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'operator_name' => ['nullable', 'string', 'max:191'],
            'good_qty' => ['nullable', 'numeric', 'min:0'],
            'scrap_qty' => ['nullable', 'numeric', 'min:0'],
            'scrap_reason' => ['nullable', 'string', 'max:500'],
            'client_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        [$warehouse, $operator] = $this->context($request, $validated);
        $output = $this->floor->report(
            $workOrder,
            $warehouse,
            $request->user(),
            $operator,
            (float) ($validated['good_qty'] ?? 0),
            (float) ($validated['scrap_qty'] ?? 0),
            $validated['scrap_reason'] ?? null,
            $validated['client_key'],
        );

        return response()->json([
            'ok' => true,
            'output_id' => $output->id,
            'good_qty' => (float) $output->good_qty,
            'scrap_qty' => (float) $output->scrap_qty,
            'uom' => $output->uom,
        ], $output->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * The warehouse finished goods go into and the operator, after checking that this device is a
     * production POS and may use that warehouse.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Warehouse, 1: string}
     */
    private function context(Request $request, array $validated, bool $needsOperator = true): array
    {
        $deviceDomain = $this->deviceDomain($request);
        if ($deviceDomain && $deviceDomain !== 'PRODUCTION') {
            abort(403, 'POS device is locked to '.$deviceDomain.'.');
        }
        $user = $request->user();
        $warehouses = $this->outletAccessService->warehousesFor($user);
        $locked = (int) $request->session()->get('pos.warehouse_id', 0);
        $warehouseId = (int) $validated['warehouse_id'];
        if (! $user->isWarehouseAdmin() && $locked > 0 && $locked !== $warehouseId) {
            abort(403, 'Warehouse locked by POS PIN.');
        }
        $warehouse = $warehouses->firstWhere('id', $warehouseId);
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'ไม่มีสิทธิ์ใช้โกดังนี้']);
        }

        return [$warehouse, $needsOperator ? $this->requireOperator($request, $validated) : ''];
    }

    private function warehouseFor(Request $request, $warehouses, ?int $lockedWarehouseId): Warehouse
    {
        $user = $request->user();
        $id = (! $user->isWarehouseAdmin() && $lockedWarehouseId) ? $lockedWarehouseId : (int) $request->integer('warehouse_id');
        $warehouse = $id > 0 ? $warehouses->firstWhere('id', $id) : $warehouses->first();
        abort_unless($warehouse, 403);

        return $warehouse;
    }
}
