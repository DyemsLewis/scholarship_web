<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = trim((string) $value);

        if ($number === '' || ! preg_match('/^[0-9+\s().-]+$/', $number)) {
            $fail('Enter a valid contact number.');

            return;
        }

        $digitCount = strlen((string) preg_replace('/\D/', '', $number));

        if ($digitCount < 10 || $digitCount > 15) {
            $fail('A contact number must contain 10 to 15 digits.');
        }
    }
}
