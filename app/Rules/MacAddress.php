<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MacAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');
            return;
        }

        $normalized = strtolower(trim($value));
        $pattern = '/^([0-9a-f]{2}[:-]){5}([0-9a-f]{2})$/';

        if (! preg_match($pattern, $normalized)) {
            $fail('The :attribute must be a valid MAC address.');
        }
    }
}
