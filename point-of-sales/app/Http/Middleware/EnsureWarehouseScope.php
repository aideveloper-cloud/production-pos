<?php

namespace App\Http\Middleware;

use App\Models\Warehouse;
use App\Services\OutletAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user may act on the warehouse_id in the request.
 */
class EnsureWarehouseScope
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $warehouseId = $request->input('warehouse_id')
            ?? $request->route('warehouse')?->id
            ?? $request->route('warehouse');

        if (! $warehouseId) {
            return $next($request);
        }

        $warehouse = $warehouseId instanceof Warehouse
            ? $warehouseId
            : Warehouse::query()->find((int) $warehouseId);

        if (! $warehouse || ! $this->outletAccessService->canUseWarehouse($user, $warehouse)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this warehouse.');
        }

        return $next($request);
    }
}
