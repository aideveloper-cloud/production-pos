<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockRetailInWarehouseMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('warehouse.is_warehouse')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if (! $routeName) {
            return $next($request);
        }

        foreach (config('warehouse.blocked_route_prefixes', []) as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        return $next($request);
    }
}
