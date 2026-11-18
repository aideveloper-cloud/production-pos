<?php

namespace App\Services\KGarden;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Keeps the WMS copy of a person in step with ERPNext: the local user (created on first sign-in,
 * no usable password), its WMS role, and the warehouses (outlets) it may use.
 */
class UserProvisioner
{
    /** @param array{user:string, full_name:string, employee_code?:string, role:?string, scopes:array} $identity */
    public function sync(array $identity): User
    {
        return DB::transaction(function () use ($identity) {
            $user = User::query()->firstOrNew(['email' => strtolower($identity['user'])]);
            if (! $user->exists) {
                $user->password = Hash::make(Str::random(64)); // never used: sign-in goes through ERPNext
                $user->email_verified_at = now();
            }
            $user->name = $identity['full_name'] ?: $identity['user'];
            $user->employee_code = ($identity['employee_code'] ?? '') ?: null;
            $user->kgarden_managed = true;
            $user->save();

            $role = config('kgarden.roles')[$identity['role'] ?? ''] ?? null;
            $user->syncRoles($role ? [$role] : []);
            $user->outlets()->sync($this->outletIds($identity['scopes'] ?? []));

            return $user;
        });
    }

    /** Take every WMS right away from a person who lost WMS access in ERPNext. */
    public function strip(User $user): void
    {
        $user->syncRoles([]);
        $user->outlets()->sync([]);
    }

    /** ERPNext warehouse names -> WMS warehouse outlets; no scope means every active warehouse. */
    public function outletIds(array $scopes): array
    {
        $query = Warehouse::query()->active()->whereNotNull('outlet_id');
        if ($scopes !== []) {
            $query->whereIn('name', array_map(fn ($s) => $this->wmsWarehouseName($s), $scopes));
        }

        return $query->pluck('outlet_id')->unique()->values()->all();
    }

    public function wmsWarehouseName(string $erpName): string
    {
        $suffix = (string) config('kgarden.warehouse_suffix');

        return $suffix !== '' && str_ends_with($erpName, $suffix) ? substr($erpName, 0, -strlen($suffix)) : $erpName;
    }

    public function erpWarehouseName(Warehouse $warehouse): string
    {
        return $warehouse->name.config('kgarden.warehouse_suffix');
    }
}
