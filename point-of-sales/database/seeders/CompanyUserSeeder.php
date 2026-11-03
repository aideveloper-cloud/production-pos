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
 * - superadmin@wms.local → super-admin, everything incl. users/roles/permissions; password from WMS_SUPERADMIN_PASSWORD
 * - admin@wms.local, admin2@wms.local → warehouse-admin: all warehouse work, no user/role/permission
 *   management; passwords from WMS_ADMIN_PASSWORD / WMS_ADMIN2_PASSWORD
 * - pos@wms.local (device) → unlocked via warehouse PIN on /pos/rm or /pos/fg
 *
 * Warehouse POS PINs come from WMS_POS_PINS ("WH-PHRANON:1234,WH-CHOKDEE:5678"), exactly 4 digits;
 * one PIN per warehouse unlocks both POS RM and POS FG. Change one later with `php artisan wms:pos-pin`.
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
                $plain = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $this->command?->warn("POS PIN for {$warehouse->code}: {$plain} (set WMS_POS_PINS to choose your own)");
            }
            $warehouse->update(['pos_pin' => Hash::make($plain)]);
        }

        // Keep only the admin accounts + POS device account.
        User::query()
            ->whereNotIn('email', ['superadmin@wms.local', 'admin@wms.local', 'admin2@wms.local', 'pos@wms.local'])
            ->orderBy('id')
            ->each(function (User $user) {
                $user->syncRoles([]);
                $user->syncPermissions([]);
                $user->outlets()->detach();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete();
            });

        $superAdminRole = Role::where('name', 'super-admin')->firstOrFail();
        $warehouseAdminRole = Role::firstOrCreate(['name' => 'warehouse-admin', 'guard_name' => 'web']);
        $warehouseAdminRole->syncPermissions(
            Permission::query()
                ->where('name', 'not like', 'users-%')
                ->where('name', 'not like', 'roles-%')
                ->where('name', 'not like', 'permissions-%')
                ->get()
        );
        $posRole = Role::firstOrCreate(['name' => 'warehouse-pos', 'guard_name' => 'web']);

        $posPermissionNames = [
            'dashboard-access',
            'stock-mutations-access',
            'products-access',
        ];
        $posPermissions = Permission::query()
            ->whereIn('name', $posPermissionNames)
            ->get();
        $posRole->syncPermissions($posPermissions);

        $adminOutlets = $warehouses->pluck('outlet_id')->filter()->mapWithKeys(fn ($id, $i) => [
            $id => ['is_default' => $i === 0],
        ])->all();
        foreach ([
            ['superadmin@wms.local', 'WMS Super Admin', 'WMS_SUPERADMIN_PASSWORD', $superAdminRole],
            ['admin@wms.local', 'WMS Admin', 'WMS_ADMIN_PASSWORD', $warehouseAdminRole],
            ['admin2@wms.local', 'WMS Admin 2', 'WMS_ADMIN2_PASSWORD', $warehouseAdminRole],
        ] as [$email, $name, $passwordEnv, $role]) {
            $this->provisionAdmin($email, $name, $passwordEnv, $role, $adminOutlets);
        }

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
     * Admin account on every warehouse outlet whose access comes only from $role (direct
     * permissions are cleared). The password comes from $passwordEnv; when unset an existing
     * one is kept and a missing one is generated and printed.
     *
     * @param  array<int, array{is_default: bool}>  $outlets
     */
    private function provisionAdmin(string $email, string $name, string $passwordEnv, Role $role, array $outlets): void
    {
        $admin = User::firstOrNew(['email' => $email]);
        $admin->fill(['name' => $admin->exists ? $admin->name : $name, 'locale' => 'th']);
        $password = (string) env($passwordEnv, '');
        if ($password !== '') {
            $admin->password = Hash::make($password);
        } elseif (! $admin->exists) {
            $password = Str::password(16);
            $admin->password = Hash::make($password);
            $this->command?->warn("{$email} password: {$password} (set {$passwordEnv} to choose your own)");
        }
        $admin->save();
        $admin->markEmailAsVerified();
        $admin->syncRoles([$role->name]);
        $admin->syncPermissions([]);
        $admin->outlets()->sync($outlets);
    }

    /**
     * @return array<string, string> warehouse code => plain PIN, parsed from WMS_POS_PINS
     */
    private function configuredPins(): array
    {
        $pins = [];
        foreach (array_filter(array_map('trim', explode(',', (string) env('WMS_POS_PINS', '')))) as $pair) {
            [$code, $pin] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            if ($code !== '' && preg_match('/^\d{4}$/', $pin)) {
                $pins[$code] = $pin;
            } elseif ($code !== '') {
                $this->command?->warn("WMS_POS_PINS: PIN for {$code} must be exactly 4 digits — ignored");
            }
        }

        return $pins;
    }
}
