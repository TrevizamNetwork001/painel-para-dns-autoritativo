<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public const AVATARS = [
        'amber',
        'blue',
        'cyan',
        'emerald',
        'grape',
        'indigo',
        'lime',
        'orange',
        'pink',
        'red',
        'slate',
        'violet',
    ];

    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'avatars' => self::AVATARS,
        ]);
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'avatar_key' => [
                'nullable',
                Rule::in(self::AVATARS),
            ],
        ]);

        $request->user()->forceFill([
            'avatar_key' => $validated['avatar_key'] ?: null,
        ])->save();

        return redirect()
            ->route('profile.edit')
            ->with(
                'status',
                'Avatar atualizado com sucesso.'
            );
    }
}
