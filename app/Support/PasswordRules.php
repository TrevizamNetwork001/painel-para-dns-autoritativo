<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** @return array<int, mixed> */
    public static function rules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            Password::min(12)->mixedCase()->numbers()->symbols(),
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
