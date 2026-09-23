<?php

namespace App\Services\Spoolman;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Outbound HTTP client to Spoolman REST API.
 * Spoolman has no auth — call only from private network / WMS service layer.
 *
 * @see https://donkie.github.io/Spoolman/
 */
class SpoolmanClient
{
    public function enabled(): bool
    {
        return (bool) config('spoolman.enabled');
    }

    public function http(): PendingRequest
    {
        $this->assertEnabled();

        $timeoutSec = max(1, (int) ceil(((int) config('spoolman.timeout_ms', 5000)) / 1000));

        return Http::baseUrl((string) config('spoolman.base_url'))
            ->acceptJson()
            ->timeout($timeoutSec)
            ->throw();
    }

    public function getSpool(int $spoolId): array
    {
        return $this->http()->get("/api/v1/spool/{$spoolId}")->json();
    }

    public function listSpools(array $query = []): array
    {
        return $this->http()->get('/api/v1/spool', $query)->json();
    }

    public function createSpool(array $payload): array
    {
        return $this->http()->post('/api/v1/spool', $payload)->json();
    }

    public function patchSpool(int $spoolId, array $payload): array
    {
        return $this->http()->patch("/api/v1/spool/{$spoolId}", $payload)->json();
    }

    public function getFilament(int $filamentId): array
    {
        return $this->http()->get("/api/v1/filament/{$filamentId}")->json();
    }

    public function listFilaments(array $query = []): array
    {
        return $this->http()->get('/api/v1/filament', $query)->json();
    }

    public function createFilament(array $payload): array
    {
        return $this->http()->post('/api/v1/filament', $payload)->json();
    }

    public function listVendors(array $query = []): array
    {
        return $this->http()->get('/api/v1/vendor', $query)->json();
    }

    public function createVendor(array $payload): array
    {
        return $this->http()->post('/api/v1/vendor', $payload)->json();
    }

    public function useSpoolWeight(int $spoolId, float $grams): array
    {
        return $this->http()->post("/api/v1/spool/{$spoolId}/use", [
            'use_weight' => $grams,
        ])->json();
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Spoolman integration is disabled.');
        }
    }
}
