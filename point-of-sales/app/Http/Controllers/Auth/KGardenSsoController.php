<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\KGarden\AccessClient;
use App\Services\KGarden\UserProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "เข้าสู่ระบบด้วย K Garden": OpenID Connect against ERPNext. ERPNext signs the person in and refuses
 * anyone without WMS access; the WMS then mirrors the person, role and warehouses from the answer.
 */
class KGardenSsoController extends Controller
{
    public function redirect(Request $request, AccessClient $access): RedirectResponse
    {
        abort_unless(config('kgarden.enabled'), 404);
        $state = Str::random(40);
        $request->session()->put('kgarden.oidc_state', $state);

        return redirect()->away($access->authorizeUrl($state));
    }

    public function callback(Request $request, AccessClient $access, UserProvisioner $provisioner): RedirectResponse
    {
        abort_unless(config('kgarden.enabled'), 404);
        $expected = $request->session()->pull('kgarden.oidc_state');
        if ($request->query('error')) {
            return $this->fail('ERPNext ไม่อนุญาตให้เข้าสู่ระบบ WMS');
        }
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->fail('การเข้าสู่ระบบหมดอายุหรือไม่ถูกต้อง ลองใหม่อีกครั้ง');
        }

        try {
            $identity = $access->identityFromCode((string) $request->query('code'));
        } catch (Throwable $e) {
            Log::warning('K Garden sign-in failed', ['error' => $e->getMessage()]);

            return $this->fail('ตรวจสอบกับ ERPNext ไม่ได้ ลองใหม่อีกครั้ง');
        }
        if ($identity['app'] !== config('kgarden.app_code') || ! $identity['granted'] || ! $identity['role']) {
            return $this->fail('บัญชีนี้ไม่มีสิทธิ์เข้าใช้ WMS');
        }

        $user = $provisioner->sync($identity);
        Auth::login($user);
        $request->session()->regenerate();

        // Admins land on the dashboard; operators work at the POS.
        return redirect()->intended($user->isWarehouseAdmin() ? route('dashboard') : route('pos.index'));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
