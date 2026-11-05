<?php

namespace App\Rules;

use App\Support\PhilippineLand;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

final class PhilippineLandPin implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Online/contact handoffs discard venue coordinates before persistence.
        if (str_starts_with($attribute, 'handoff_') && in_array(data_get($this->data, 'handoff_mode'), ['online', 'provider_contact'], true)) {
            return;
        }
        $isLatitude = str_ends_with($attribute, 'latitude');
        $partner = preg_replace('/'.($isLatitude ? 'latitude' : 'longitude').'$/', $isLatitude ? 'longitude' : 'latitude', $attribute);
        $other = data_get($this->data, $partner);
        if (! is_numeric($value) || ! is_numeric($other)) {
            $fail('Select a complete location pin on the map.');

            return;
        }

        if (! app(PhilippineLand::class)->contains((float) ($isLatitude ? $value : $other), (float) ($isLatitude ? $other : $value))) {
            $fail(PhilippineLand::MESSAGE);
        }
    }
}
