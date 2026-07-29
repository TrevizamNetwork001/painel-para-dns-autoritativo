<?php

namespace App\Actions\Fortify;

use App\Support\PasswordRules;
use Illuminate\Contracts\Validation\Rule;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return PasswordRules::rules();
    }
}
