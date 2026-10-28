<?php

namespace App\Support;

class EligibilityRulePolicy
{
    public const VERSION = 1;

    public const MODES = ['required', 'preferred'];

    public const KEYS = [
        'academic', 'education_level', 'course', 'school_type', 'year_level',
        'location', 'income', 'current_scholarship_support', 'enrollment_status',
    ];

    public static function normalize(mixed $modes): array
    {
        $modes = is_array($modes) ? $modes : [];

        return collect(self::KEYS)->mapWithKeys(fn (string $key): array => [
            $key => ($modes[$key] ?? null) === 'preferred' ? 'preferred' : 'required',
        ])->all();
    }

    public static function isBlocking(array $criterion): bool
    {
        return ($criterion['key'] ?? null) !== 'documents'
            && ($criterion['category'] ?? 'eligibility') === 'eligibility'
            && ($criterion['status'] ?? null) === 'fail'
            && ($criterion['rule_mode'] ?? 'required') !== 'preferred';
    }
}
