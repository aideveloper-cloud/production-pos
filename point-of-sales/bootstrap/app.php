<?php

use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnsureActiveCashierShift;
use App\Http\Middleware\EnsureBotGuard;
use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\EnsurePublicRegistrationEnabled;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Caddy terminates HTTPS and forwards the original scheme/host.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SetLocale::class,
            SecureHeaders::class,
            EnforceAbsoluteSessionLifetime::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\BlockRetailInWarehouseMode::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active_shift' => EnsureActiveCashierShift::class,
            'registration.enabled' => EnsurePublicRegistrationEnabled::class,
            'bot.guard' => EnsureBotGuard::class,
            'setup.notinstalled' => EnsureNotInstalled::class,
            'step_up' => EnsureRecentPasswordConfirmation::class,
            'abilities' => CheckAbilities::class,
            'warehouse.scope' => \App\Http\Middleware\EnsureWarehouseScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($exception instanceof ValidationException) {
                return null;
            }

            // Authenticated token lacks a required ability → 403, not 401/500.
            if ($exception instanceof MissingAbilityException) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Missing required ability.',
                ], Response::HTTP_FORBIDDEN);
            }

            // Session expired / not logged in → redirect to login (bukan 500)
            // But for JSON/API requests, return 401 JSON instead.
            if ($exception instanceof AuthenticationException) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'message' => 'Unauthenticated.',
                    ], Response::HTTP_UNAUTHORIZED);
                }

                return redirect()->guest(route('login'));
            }

            $status = match (true) {
                $exception instanceof UnauthorizedException => Response::HTTP_FORBIDDEN,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => Response::HTTP_INTERNAL_SERVER_ERROR,
            };

            if ($request->expectsJson()) {
                $message = $exception instanceof UnauthorizedException
                    ? __('Anda tidak memiliki izin untuk mengakses halaman tersebut.')
                    : ($exception->getMessage() ?: Response::$statusTexts[$status] ?? 'Server Error');

                return response()->json([
                    'message' => $message,
                ], $status);
            }

            if (! in_array($status, [
                Response::HTTP_UNAUTHORIZED,
                Response::HTTP_FORBIDDEN,
                Response::HTTP_NOT_FOUND,
                419,
                Response::HTTP_TOO_MANY_REQUESTS,
                Response::HTTP_INTERNAL_SERVER_ERROR,
                Response::HTTP_SERVICE_UNAVAILABLE,
            ], true)) {
                return null;
            }

            // Custom error page only when APP_DEBUG=false; debug mode keeps Laravel's
            // default error screen so the real message/trace stays visible.
            if (config('app.debug')) {
                return null;
            }

            $user = $request->user();
            $warehouse = (bool) config('warehouse.is_warehouse');
            $canDashboard = $user && $user->can('dashboard-access');

            if (! $user) {
                $homeUrl = url('/');
                $homeLabel = $warehouse ? 'กลับหน้าเข้าสู่ระบบ' : __('Kembali ke Beranda');
            } elseif ($warehouse && ! $canDashboard) {
                $homeUrl = route('pos.index');
                $homeLabel = 'กลับหน้า POS';
            } elseif ($canDashboard) {
                $homeUrl = route('dashboard');
                $homeLabel = $warehouse ? 'กลับแดชบอร์ด' : __('Kembali ke Dashboard');
            } else {
                $homeUrl = route('dashboard.access');
                $homeLabel = $warehouse ? 'กลับหน้าแรก' : __('Kembali ke Dashboard');
            }

            return Inertia::render('Error', [
                'status' => $status,
                'homeUrl' => $homeUrl,
                'homeLabel' => $homeLabel,
                'copy' => $warehouse ? [
                    'brand' => 'K Garden',
                    'statusLabel' => 'สถานะ',
                    'errorState' => 'ข้อผิดพลาด',
                    'back' => 'ย้อนกลับ',
                    'themeLight' => 'โหมดมืด',
                    'themeDark' => 'โหมดสว่าง',
                    'hint' => match ($status) {
                        403 => $canDashboard
                            ? 'บัญชีนี้ไม่มีสิทธิ์สำหรับรายการนี้'
                            : 'บัญชีเครื่อง POS เปิดแดชบอร์ดไม่ได้ กลับไปทำงานที่หน้า POS ส่วนแดชบอร์ดใช้บัญชีแอดมิน',
                        500 => 'ระบบขัดข้องชั่วคราว ลองใหม่อีกครั้ง',
                        default => 'ตรวจสอบที่อยู่หรือสิทธิ์ แล้วลองใหม่อีกครั้ง',
                    },
                    'title' => match ($status) {
                        401 => 'ต้องเข้าสู่ระบบใหม่',
                        403 => 'ไม่มีสิทธิ์เปิดหน้านี้',
                        404 => 'ไม่พบหน้านี้',
                        419 => 'เซสชันหมดอายุ',
                        429 => 'คำขอมากเกินไป',
                        500 => 'เซิร์ฟเวอร์ขัดข้อง',
                        503 => 'ระบบยังไม่พร้อม',
                        default => 'เกิดข้อผิดพลาด',
                    },
                    'description' => match ($status) {
                        401 => 'เซสชันไม่ถูกต้อง ให้เข้าสู่ระบบอีกครั้ง',
                        403 => $canDashboard
                            ? 'บัญชีนี้ไม่มีสิทธิ์เปิดหน้ารายการนี้'
                            : 'เครื่องนี้ล็อกอินเป็นบัญชี POS จึงเปิดหน้าแดชบอร์ดไม่ได้',
                        404 => 'ไม่พบหน้าที่ขอ หรือที่อยู่ไม่ถูกต้อง',
                        419 => 'เซสชันหมดอายุ โหลดหน้าใหม่แล้วลองอีกครั้ง',
                        429 => 'รอสักครู่แล้วลองใหม่',
                        500 => 'ระบบขัดข้องชั่วคราว ลองใหม่อีกครั้ง',
                        503 => 'ระบบกำลังปิดปรับปรุง',
                        default => 'ลองใหม่อีกครั้ง',
                    },
                ] : null,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
