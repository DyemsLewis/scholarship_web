<?php

namespace App\Support;

use Illuminate\Support\Str;

class ScholarshipEligibilityCondition
{
    public const VERIFICATION_TYPES = [
        'automatic',
        'applicant_declaration',
        'provider_verification',
        'information',
    ];

    public static function fromJson(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    public static function normalize(array $conditions): array
    {
        return collect($conditions)
            ->take(20)
            ->map(function (mixed $condition, int $index): ?array {
                if (! is_array($condition)) {
                    return null;
                }

                $statement = Str::of((string) ($condition['statement'] ?? ''))->squish()->limit(500, '')->toString();

                if ($statement === '') {
                    return null;
                }

                $verificationType = (string) ($condition['verification_type'] ?? 'provider_verification');

                if (! in_array($verificationType, self::VERIFICATION_TYPES, true)) {
                    $verificationType = 'provider_verification';
                }

                $key = Str::of((string) ($condition['key'] ?? "condition_{$index}"))
                    ->snake()
                    ->limit(80, '')
                    ->toString();

                return [
                    'key' => $key !== '' ? $key : "condition_{$index}",
                    'label' => Str::of((string) ($condition['label'] ?? $statement))->squish()->limit(120, '')->toString(),
                    'statement' => $statement,
                    'verification_type' => $verificationType,
                    'source' => ($condition['source'] ?? null) === 'provider_condition'
                        ? 'provider_condition'
                        : 'structured_rule',
                ];
            })
            ->filter()
            ->unique('key')
            ->values()
            ->all();
    }
}
