<?php

namespace App\Console\Commands;

use App\Models\Warehouse;
use App\Services\Spoolman\SpoolmanClient;
use App\Services\Spoolman\SpoolmanSyncService;
use Illuminate\Console\Command;
use Throwable;

class SpoolmanSyncCommand extends Command
{
    protected $signature = 'spoolman:sync
                            {--warehouse= : Default warehouse code for newly imported spools}
                            {--opening : Push current WMS units to Spoolman as weight pools}
                            {--ping : Only check Spoolman connectivity}';

    protected $description = 'Sync Spoolman spools into WMS physical_units (Spoolman = weight engine, WMS = stock ledger owner)';

    public function handle(SpoolmanClient $client, SpoolmanSyncService $syncService): int
    {
        if (! $client->enabled()) {
            $this->error('SPOOLMAN_ENABLED is false. Enable it in .env first.');

            return self::FAILURE;
        }

        try {
            $info = $client->http()->get('/api/v1/info')->json();
            $this->info('Spoolman '.((string) ($info['version'] ?? 'unknown')).' reachable at '.config('spoolman.base_url'));
        } catch (Throwable $e) {
            $this->error('Cannot reach Spoolman: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('ping')) {
            return self::SUCCESS;
        }

        if ($this->option('opening')) {
            $result = $syncService->syncOpeningUnits();
            $this->info("Opening units synced={$result['synced']} skipped={$result['skipped']}");
            foreach ($result['errors'] as $error) {
                $this->warn($error);
            }

            return count($result['errors']) > 0 ? self::FAILURE : self::SUCCESS;
        }

        $warehouse = null;
        if ($code = $this->option('warehouse')) {
            $warehouse = Warehouse::query()->where('code', $code)->first();
            if (! $warehouse) {
                $this->error("Warehouse code [{$code}] not found.");

                return self::FAILURE;
            }
        }

        $result = $syncService->syncSpools($warehouse);

        $this->table(
            ['synced', 'created', 'updated', 'skipped', 'errors'],
            [[
                $result['synced'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                count($result['errors']),
            ]]
        );

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return count($result['errors']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
