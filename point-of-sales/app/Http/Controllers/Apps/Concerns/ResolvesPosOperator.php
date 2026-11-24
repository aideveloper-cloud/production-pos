<?php

namespace App\Http\Controllers\Apps\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Who is working at a shared POS tablet (RM, FG and production POS): the person signed in with
 * employee code + PIN (K Garden central access), the person signed in through ERPNext, or a
 * name typed at a PIN-unlocked device.
 */
trait ResolvesPosOperator
{
    protected function posIdleExpired(Request $request): bool
    {
        $last = (int) $request->session()->get('pos.last_seen', 0);

        return (bool) $request->session()->get('pos.person')
            && $last > 0
            && now()->timestamp - $last > (int) config('kgarden.pos_idle_minutes', 10) * 60;
    }

    protected function endPosPerson(Request $request): void
    {
        $request->session()->forget(['pos.warehouse_id', 'pos.operator_name', 'pos.person', 'pos.last_seen']);
        Auth::guard('web')->logout();
        $request->session()->regenerateToken();
    }

    /**
     * The person who does every cut and receipt when that is known from the sign-in (K Garden
     * central access): the employee signed in at the POS tablet, or the person signed in through
     * ERPNext on the dashboard. Null when the operator still has to type their name.
     */
    protected function signedInOperator(Request $request): ?string
    {
        if ($request->session()->get('pos.person')) {
            return (string) $request->session()->get('pos.operator_name');
        }
        $user = $request->user();
        if (config('kgarden.enabled') && $user?->kgarden_managed) {
            return $user->name.($user->employee_code ? ' ('.$user->employee_code.')' : '');
        }

        return null;
    }

    protected function requireOperator(Request $request, array $validated): string
    {
        if ($request->session()->get('pos.person')) {
            if ($this->posIdleExpired($request)) {
                $this->endPosPerson($request);
                abort(401, 'หมดเวลาใช้งาน กรุณาเข้าสู่ระบบใหม่');
            }
            $request->session()->put('pos.last_seen', now()->timestamp);

            return (string) $request->session()->get('pos.operator_name');
        }
        if (($signedIn = $this->signedInOperator($request)) !== null) {
            return $signedIn;
        }
        $operator = trim((string) ($validated['operator_name'] ?? ''));
        if (mb_strlen($operator) < 2) {
            throw ValidationException::withMessages([
                'operator_name' => 'กรุณาใส่ชื่อผู้ทำรายการก่อนตัดหรือรับเข้า',
            ]);
        }

        $request->session()->put('pos.operator_name', $operator);

        return $operator;
    }

    /**
     * The POS a PIN-unlocked device was opened as; null for admins and dashboard users.
     */
    protected function deviceDomain(Request $request): ?string
    {
        if ($request->user()?->isWarehouseAdmin() || (int) $request->session()->get('pos.warehouse_id', 0) <= 0) {
            return null;
        }

        $domain = (string) $request->session()->get('pos.domain', '');

        return in_array($domain, \App\Http\Controllers\Apps\WarehouseFloorController::POS_DOMAINS, true) ? $domain : null;
    }
}
