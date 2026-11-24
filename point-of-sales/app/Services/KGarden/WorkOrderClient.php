<?php

namespace App\Services\KGarden;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads ERPNext Work Orders for the production POS (kgarden.manufacturing.floor.work_orders),
 * with the same WMS service account as central access (role KG Access Service).
 */
class WorkOrderClient
{
    /** @return array<int, array<string, mixed>> open Work Orders that still have quantity to make */
    public function open(): array
    {
        return $this->call([]);
    }

    /** One Work Order in any status, or null when ERPNext has no submitted Work Order by that name. */
    public function find(string $name): ?array
    {
        return $this->call(['name' => $name])[0] ?? null;
    }

    private function call(array $query): array
    {
        $base = (string) config('kgarden.erp_url');
        if ($base === '') {
            throw new RuntimeException('KGARDEN_ERP_URL is not set');
        }
        $response = Http::timeout(15)->acceptJson()
            ->withHeaders([
                'Authorization' => 'token '.config('kgarden.service.api_key').':'.config('kgarden.service.api_secret'),
            ])
            ->get($base.'/api/method/kgarden.manufacturing.floor.work_orders', $query);
        if (! $response->ok()) {
            throw new RuntimeException('ERPNext work_orders failed ('.$response->status().')');
        }

        return array_values((array) $response->json('message'));
    }
}
