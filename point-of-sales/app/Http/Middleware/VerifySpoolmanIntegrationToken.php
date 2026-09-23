<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySpoolmanIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('spoolman.integration_token');

        if (blank($expected)) {
            return response()->json([
                'message' => 'Spoolman integration token is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $provided = (string) ($request->bearerToken() ?: $request->header('X-Spoolman-Token', ''));

        if (! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized integration token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
