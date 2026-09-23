<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        $this->auditLogService->log(
            event: 'auth.verification_resent',
            module: 'auth',
            auditable: $request->user(),
            description: 'Email verifikasi dikirim ulang.',
            meta: [
                'severity' => 'info',
                'route' => $request->route()?->getName(),
            ],
        );

        return back()->with('status', 'verification-link-sent');
    }
}
