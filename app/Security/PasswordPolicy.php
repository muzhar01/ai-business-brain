<?php

namespace App\Security;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min(config('brain.security.password_min', 12))->letters()->mixedCase()->numbers();

        return config('brain.security.password_uncompromised') ? $rule->uncompromised() : $rule;
    }
}
