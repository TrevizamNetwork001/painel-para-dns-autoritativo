<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    private const NEUTRAL_MESSAGE =
        'Se o e-mail estiver cadastrado, enviaremos um link de recuperação.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $status = Password::broker()->sendResetLink([
            'email' => $validated['email'],
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            $user = User::query()->where('email', $validated['email'])->first();

            if ($user !== null) {
                SecurityAuditLogger::record(
                    'PASSWORD_RESET_REQUESTED',
                    $user,
                    'SUCCESS',
                    'user',
                    'WEB',
                    $request->ip(),
                    $request->userAgent(),
                );
            }
        }

        return back()->with('status', self::NEUTRAL_MESSAGE);
    }
}
