<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * K Garden central access: while it is on, only people ERPNext manages (signed in through ERPNext
 * or with employee code + PIN) and the break-glass accounts may stay signed in.
 *
 * A session that started before central access was switched on, for example the shared POS account
 * a warehouse PIN used to open, would otherwise keep working with access nobody granted in ERPNext.
 * Such a session is ended on its next request.
 */
class EnsureKGardenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! config('kgarden.enabled') || ! $user || $user->kgarden_managed) {
            return $next($request);
        }

        $breakGlass = array_map('strtolower', config('kgarden.local_login', []));
        if (in_array(strtolower((string) $user->email), $breakGlass, true)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'บัญชีนี้ไม่ได้จัดการสิทธิ์ผ่าน K Garden แล้ว กรุณาเข้าสู่ระบบด้วย K Garden';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
