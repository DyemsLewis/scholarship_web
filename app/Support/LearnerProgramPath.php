<?php

namespace App\Support;

class LearnerProgramPath
{
    private const ALIASES = [
        'bsit' => 'BS Information Technology',
        'bs it' => 'BS Information Technology',
        'b s it' => 'BS Information Technology',
        'bs information technology' => 'BS Information Technology',
        'bachelor of science in information technology' => 'BS Information Technology',
        'bachelor of science information technology' => 'BS Information Technology',
        'information technology' => 'BS Information Technology',
        'bsed' => 'BS Education',
        'bs ed' => 'BS Education',
        'bs education' => 'BS Education',
        'bachelor of science in education' => 'BS Education',
        'bachelor of secondary education' => 'BS Education',
        'bsn' => 'BS Nursing',
        'bs nursing' => 'BS Nursing',
        'bachelor of science in nursing' => 'BS Nursing',
        'bsa' => 'BS Accountancy',
        'bs accountancy' => 'BS Accountancy',
        'bachelor of science in accountancy' => 'BS Accountancy',
        'bsba' => 'BS Business Administration',
        'bs business administration' => 'BS Business Administration',
        'bachelor of science in business administration' => 'BS Business Administration',
        'ict' => 'ICT / Computer Systems Servicing',
        'computer systems servicing' => 'ICT / Computer Systems Servicing',
        'ict computer systems servicing' => 'ICT / Computer Systems Servicing',
        'automotive' => 'Automotive Servicing',
        'automotive servicing' => 'Automotive Servicing',
        'electrical installation' => 'Electrical Installation and Maintenance',
        'electrical installation and maintenance' => 'Electrical Installation and Maintenance',
    ];

    public static function normalize(?string $value): string
    {
        return self::key(self::canonicalLabel($value));
    }

    public static function canonicalLabel(?string $value): string
    {
        $original = trim((string) $value);

        return self::ALIASES[self::key($original)] ?? $original;
    }

    public static function canonicalizeList(?string $value): ?string
    {
        $paths = collect(preg_split('/\r\n|\r|\n|,/', (string) $value))
            ->map(fn (string $path) => self::canonicalLabel($path))
            ->filter()
            ->unique(fn (string $path) => self::normalize($path))
            ->values();

        return $paths->isEmpty() ? null : $paths->implode("\n");
    }

    public static function matches(string $value, string $option): bool
    {
        $normalizedValue = self::normalize($value);
        $normalizedOption = self::normalize($option);

        if ($normalizedValue === '' || $normalizedOption === '') {
            return false;
        }

        return $normalizedValue === $normalizedOption
            || str_contains($normalizedValue, $normalizedOption)
            || str_contains($normalizedOption, $normalizedValue);
    }

    public static function matchesAny(string $value, array $options): bool
    {
        return collect($options)->contains(
            fn (string $option) => self::matches($value, $option)
        );
    }

    private static function key(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = str_replace('&', ' and ', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }
}
