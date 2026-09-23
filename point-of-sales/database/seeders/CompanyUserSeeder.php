<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * WMS accounts:
 * - admin@wms.local → dashboard; password from WMS_ADMIN_PASSWORD
 * - pos@wms.local (device) → unlocked via warehouse PIN on /pos
 *
 * Warehouse POS PINs come from WMS_POS_PINS ("WH-PHRANON:123456,WH-CHOKDEE:654321").
 * Credentials are never hard-coded here. When a value is not configured, an existing password/PIN
 * is kept and a missing one is generated and printed once, so re-running on production never
 * silently resets what staff already use.
 */
class CompanyUserSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            CompanyWarehouseSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $warehouses = Warehouse::query()
            ->whereIn('code', ['WH-PHRANON', 'WH-DECHA-MESH', 'WH-DECHA-POST', 'WH-CHOKDEE'])
            ->orderBy('sort_order')
            ->get();

        foreach ($warehouses as $warehouse) {
            $outlet = Outlet::query()->firstOrCreate(
                ['code' => $warehouse->code],
                [
                    'name' => $warehouse->name,
                    'is_active' => true,
                    'is_sales_enabled' => false,
                    'address' => $warehouse->address,
                    'phone' => $warehouse->phone,
                ]
            );

            if ((int) $warehouse->outlet_id !== (int) $outlet->id) {
                $warehouse->update(['outlet_id' => $outlet->id]);
            }
        }

        $warehouses = $warehouses->fresh('outlet');

        $pins = $this->configuredPins();

        foreach ($warehouses as $warehouse) {
            $plain = $pins[$warehouse->code] ?? null;
            if ($plain === null && $warehouse->pos_pin) {
                continue; // keep the PIN staff already use
            }
            if ($plain === null) {
                $plain = (string) random_int(100000, 999999);
                $this->command?->warn("POS PIN for {$warehouse->code}: {$plain} (set WMS_POS_PINS to choose your own)");
            }
            $warehouse->update(['pos_pin' => Hash::make($plain)]);
        }

        // Keep only admin + POS device account.
        User::query()
            ->whereNotIn('email', ['admin@wms.local', 'pos@wms.local'])
            ->orderBy('id')
            ->each(function (User $user) {
                $user->syncRoles([]);
                $user->syncPermissions([]);
                $user->outlets()->detach();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete();
            });

        $allPermissions = Permission::all();
        $superAdminRole = Role::where('name', 'super-admin')->first();
        $posRole = Role::firstOrCreate(['name' => 'warehouse-pos', 'guard_name' => 'web']);

        $posPermissionNames = [
            'stock-mutations-access',
            'products-access',
        ];
        $posPermissions = Permission::query()
            ->whereIn('name', $posPermissionNames)
            ->get();
        $posRole->syncPermissions($posPermissions);

        $admin = User::firstOrNew(['email' => 'admin@wms.local']);
        $admin->fill(['name' => 'WMS Admin', 'locale' => 'th']);
        $adminPassword = (string) env('WMS_ADMIN_PASSWORD', '');
        if ($adminPassword !== '') {
            $admin->password = Hash::make($adminPassword);
        } elseif (! $admin->exists) {
            $adminPassword = Str::password(16);
            $admin->password = Hash::make($adminPassword);
            $this->command?->warn("admin@wms.local password: {$adminPassword} (set WMS_ADMIN_PASSWORD to choose your own)");
        }
        $admin->save();
        $admin->markEmailAsVerified();
        if ($superAdminRole) {
            $admin->syncRoles([$superAdminRole->name]);
        }
        $admin->syncPermissions($allPermissions);
        $admin->outlets()->sync(
            $warehouses->pluck('outlet_id')->filter()->mapWithKeys(fn ($id, $i) => [
                $id => ['is_default' => $i === 0],
            ])->all()
        );

        $posUser = User::updateOrCreate(
            ['email' => 'pos@wms.local'],
            [
                'name' => 'เครื่อง POS คลัง',
                'password' => Hash::make(str()->random(32)),
                'locale' => 'th',
            ]
        );
        $posUser->markEmailAsVerified();
        $posUser->syncRoles([$posRole->name]);
        $posUser->syncPermissions($posPermissions);
        $posUser->outlets()->sync(
            $warehouses->pluck('outlet_id')->filter()->mapWithKeys(fn ($id, $i) => [
                $id => ['is_default' => $i === 0],
            ])->all()
        );

        // Accounts are provisioned here, so the first-install wizard must stay closed.
        Setting::set('app_setup_completed', true, 'Penanda wizard setup awal sudah diselesaikan');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, string> warehouse code => plain PIN, parsed from WMS_POS_PINS
     */
    private function configuredPins(): array
    {
        $pins = [];
        foreach (array_filter(array_map('trim', explode(',', (string) env('WMS_POS_PINS', '')))) as $pair) {
            [$code, $pin] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if ($code !== '' && preg_match('/^\d{4,6}$/', $pin)) {
                $pins[$code] = $pin;
            }
        }

        return $pins;
    }
}
