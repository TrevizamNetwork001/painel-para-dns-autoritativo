<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\PasswordRules;
use App\Support\SecurityAuditLogger;
use App\Support\UserPasswordResetter;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => mb_strtolower(trim((string) $request->query('email'))),
        ]);
    }

    public function store(
        Request $request,
        UserPasswordResetter $resetter,
    ): RedirectResponse {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $credentials = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => PasswordRules::rules(),
        ])->validate();

        $status = Password::broker()->reset(
            $credentials,
            function (User $user, string $password) use (
                $request,
                $resetter,
            ): void {
                $changedAt = now();

                $resetter->reset(
                    $user,
                    $password,
                    false,
                    'PASSWORD_RESET_COMPLETED',
                    'user',
                    'WEB',
                    $request->ip(),
                    $request->userAgent(),
                );

                event(new PasswordReset($user));

                $user->notify(new PasswordChangedNotification(
                    $changedAt->timezone(config('app.timezone'))->format('d/m/Y H:i:s T'),
                    $request->ip(),
                ));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with(
                'status',
                'Senha redefinida com sucesso. Entre com sua nova senha.',
            );
        }

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if ($user !== null) {
            SecurityAuditLogger::record(
                'PASSWORD_RESET_COMPLETED',
                $user,
                'ERROR',
                'user',
                'WEB',
                $request->ip(),
                $request->userAgent(),
            );
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => 'O link de recuperação é inválido ou expirou.',
            ]);
    }
}
