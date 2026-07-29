<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminTwoFactorController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('security.two-factor-setup', [
            'user' => $user,
            'totpEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'passkeysCount' => $user->passkeys()->count(),
            'graceDays' => (int) config('security.admin_2fa.grace_days'),
        ]);
    }

    public function continueDuringGrace(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user->isAdministrative()
                && $user->admin_2fa_grace_expires_at?->isFuture(),
            403,
        );

        $request->session()->put('admin_2fa_grace_acknowledged', true);

        return redirect()
            ->route('dashboard')
            ->with(
                'warning',
                '2FA pendente. Operações críticas continuam bloqueadas.',
            );
    }
}
