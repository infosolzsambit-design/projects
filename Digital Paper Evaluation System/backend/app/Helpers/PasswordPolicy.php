<?php

namespace App\Helpers;

use Illuminate\Validation\Rules\Password;

/**
 * Centralized password policy so it's defined once and easy to strengthen
 * later — used by every Form Request that accepts a new password.
 */
class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->symbols();
    }
}
