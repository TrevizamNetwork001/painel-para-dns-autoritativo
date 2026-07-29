<?php

namespace App\Http\Controllers;

use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'current_password:web',
            ],
            'password' => [
                ...PasswordRules::rules(),
                'different:current_password',
            ],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
            'password_changed_at' => now(),
        ])->save();

        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Senha alterada com sucesso.');
    }
}
