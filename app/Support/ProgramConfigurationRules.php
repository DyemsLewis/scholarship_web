<?php

namespace App\Support;

use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ProgramConfigurationRules
{
    public const FIELDS = [
        'slots_available', 'application_limit', 'application_opens_at', 'deadline',
        'expected_results_at', 'handoff_deadline', 'support_starts_at', 'support_ends_at',
    ];

    public static function errors(array $attributes, ?Scholarship $scholarship = null): array
    {
        // Updates must be checked against the saved counterpart, not only submitted fields.
        $values = array_replace($scholarship?->only(self::FIELDS) ?? [], $attributes);
        $errors = [];
        if (filled($values['application_limit'] ?? null) && filled($values['slots_available'] ?? null)
            && (int) $values['application_limit'] < (int) $values['slots_available']) {
            $errors['application_limit'] = 'The application limit must be at least the number of recipient slots.';
        }

        foreach ([
            ['deadline', 'application_opens_at', 'application_opens_at', 'The application opening date must be on or before the deadline.'],
            ['expected_results_at', 'deadline', 'expected_results_at', 'The expected results date must be on or after the application deadline.'],
            ['handoff_deadline', 'deadline', 'handoff_deadline', 'The formal application deadline must be on or after the pre-screening deadline.'],
            ['support_ends_at', 'support_starts_at', 'support_ends_at', 'The support end date must be on or after the support start date.'],
        ] as [$later, $earlier, $field, $message]) {
            if (filled($values[$later] ?? null) && filled($values[$earlier] ?? null)
                && CarbonImmutable::parse($values[$later])->startOfDay()->isBefore(CarbonImmutable::parse($values[$earlier])->startOfDay())) {
                $errors[$field] = $message;
            }
        }

        foreach ([
            'application_opens_at' => 'application opening date', 'deadline' => 'application deadline',
            'expected_results_at' => 'expected results date', 'handoff_deadline' => 'formal application deadline',
            'support_starts_at' => 'support start date', 'support_ends_at' => 'support end date',
        ] as $field => $label) {
            if (blank($values[$field] ?? null)) {
                continue;
            }
            $date = CarbonImmutable::parse($values[$field])->startOfDay();
            $savedDate = $scholarship?->getAttribute($field);
            $keepsHistoricalDate = in_array($scholarship?->status, ['published', 'closed'], true)
                && $savedDate && $date->isSameDay($savedDate);
            if ($date->isBefore(CarbonImmutable::today()) && ! $keepsHistoricalDate) {
                $errors[$field] = "The {$label} must be today or a future date.";
            }
        }

        return $errors;
    }

    public static function assertConsistent(array $attributes, ?Scholarship $scholarship = null): void
    {
        $errors = self::errors($attributes, $scholarship);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
