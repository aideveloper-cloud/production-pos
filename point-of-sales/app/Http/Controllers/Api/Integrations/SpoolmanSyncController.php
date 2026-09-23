<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponder;
use App\Models\Warehouse;
use App\Services\Spoolman\SpoolmanClient;
use App\Services\Spoolman\SpoolmanSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SpoolmanSyncController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly SpoolmanClient $client,
        private readonly SpoolmanSyncService $syncService,
    ) {}

    /**
     * GET /api/integrations/spoolman/health
     */
    public function health(): JsonResponse
    {
        $payload = [
            'wms' => true,
            'spoolman_enabled' => $this->client->enabled(),
            'spoolman_base_url' => config('spoolman.base_url'),
            'spoolman' => null,
            'ok' => false,
        ];

        if (! $this->client->enabled()) {
            return $this->ok($payload, 'Spoolman disabled');
        }

        try {
            $info = $this->client->http()->get('/api/v1/info')->json();
            $payload['spoolman'] = [
                'version' => $info['version'] ?? null,
                'db_type' => $info['db_type'] ?? null,
            ];
            $payload['ok'] = true;

            return $this->ok($payload, 'WMS + Spoolman healthy');
        } catch (Throwable $e) {
            $payload['spoolman'] = ['error' => $e->getMessage()];

            return $this->error('Spoolman unreachable', 503, $payload);
        }
    }

    /**
     * POST /api/integrations/spoolman/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_code' => ['nullable', 'string', 'max:50'],
        ]);

        $warehouse = null;
        if (! empty($validated['warehouse_code'])) {
            $warehouse = Warehouse::query()->where('code', $validated['warehouse_code'])->firstOrFail();
        }

        $result = $this->syncService->syncSpools($warehouse);

        return $this->ok($result, 'Spoolman sync finished');
    }
}
