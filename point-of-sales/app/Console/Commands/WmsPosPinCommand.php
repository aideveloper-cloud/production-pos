<?php

namespace App\Console\Commands;

use App\Models\Warehouse;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Set a warehouse's 4-digit tablet PIN. The PIN is typed hidden (never an argument), so it stays
 * out of shell history and process lists; the same PIN unlocks POS RM and POS FG.
 *
 *   php artisan wms:pos-pin WH-PHRANON
 *   php artisan wms:pos-pin --list
 */
class WmsPosPinCommand extends Command
{
    protected $signature = 'wms:pos-pin
        {warehouse? : Warehouse code, e.g. WH-PHRANON}
        {--list : Show which warehouses have a PIN (never the PIN itself)}
        {--visible : Show the digits while typing (for terminals where hidden input does not work)}';

    protected $description = 'Set the 4-digit tablet POS PIN of a warehouse';

    public function handle(AuditLogService $auditLog): int
    {
        if ($this->option('list') || ! $this->argument('warehouse')) {
            $this->table(['Code', 'Name', 'PIN set'], Warehouse::query()->orderBy('sort_order')->get()
                ->map(fn (Warehouse $w) => [$w->code, $w->name, $w->pos_pin ? 'yes' : 'no'])->all());

            return self::SUCCESS;
        }

        $warehouse = Warehouse::query()->where('code', strtoupper((string) $this->argument('warehouse')))->first();
        if (! $warehouse) {
            $this->error('Unknown warehouse code. Run with --list to see the codes.');

            return self::FAILURE;
        }

        $read = fn (string $question) => trim((string) ($this->option('visible') ? $this->ask($question) : $this->secret($question)));

        // Containers without a usable terminal: the host reads it hidden (`read -s`) and passes
        // WMS_NEW_PIN, which also keeps it out of shell history.
        $fromEnv = trim((string) getenv('WMS_NEW_PIN'));

        $pin = $fromEnv !== '' ? $fromEnv : $read("New 4-digit PIN for {$warehouse->code} ({$warehouse->name})");
        if (! preg_match('/^\d{4}$/', $pin)) {
            $this->error('The PIN must be exactly 4 digits.'.($pin === '' ? ' Nothing was read — pass it with WMS_NEW_PIN (see the README).' : ''));

            return self::FAILURE;
        }
        if ($fromEnv === '' && $read('Type it again') !== $pin) {
            $this->error('The two PINs do not match; nothing changed.');

            return self::FAILURE;
        }

        $taken = Warehouse::query()->whereKeyNot($warehouse->id)->whereNotNull('pos_pin')->get()
            ->first(fn (Warehouse $other) => Hash::check($pin, (string) $other->pos_pin));
        if ($taken) {
            // Unlock finds the warehouse by PIN, so two warehouses can never share one.
            $this->error("That PIN is already used by {$taken->code}; pick another.");

            return self::FAILURE;
        }

        $warehouse->update(['pos_pin' => Hash::make($pin)]);
        $auditLog->log(
            event: 'warehouse.pos_pin_changed',
            module: 'warehouses',
            auditable: $warehouse,
            description: "เปลี่ยน PIN เครื่อง POS ของ {$warehouse->code}",
        );
        $this->info("PIN for {$warehouse->code} updated.");

        return self::SUCCESS;
    }
}
