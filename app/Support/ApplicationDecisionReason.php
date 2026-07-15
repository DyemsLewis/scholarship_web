<?php

namespace App\Support;

class ApplicationDecisionReason
{
    public const NEGATIVE_STATUSES = ['rejected', 'not_awarded', 'exam_failed'];

    private const OPTIONS = [
        'complete_requirements' => 'Complete requirements',
        'missing_documents' => 'Missing documents',
        'academic_requirement_not_met' => 'Academic requirement not met',
        'outside_eligibility' => 'Outside eligibility',
        'for_exam' => 'Meets exam eligibility',
        'exam_scheduled' => 'Exam scheduled',
        'exam_completed' => 'Exam completed',
        'passed_exam' => 'Passed exam',
        'failed_exam' => 'Failed exam',
        'for_interview' => 'For interview',
        'approved_for_award' => 'Approved for award',
        'distribution_scheduled' => 'Distribution scheduled',
        'award_released' => 'Reward distributed',
        'renewed_support' => 'Renewed support',
        'funds_limited' => 'Funds limited',
        'not_selected' => 'Not selected',
        'other' => 'Other',
    ];

    private const LEGACY_ALIASES = [
        'meets_all_criteria' => 'complete_requirements',
        'incomplete_requirements' => 'missing_documents',
    ];

    public static function values(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function acceptedValues(): array
    {
        return [...self::values(), ...array_keys(self::LEGACY_ALIASES)];
    }

    public static function canonical(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return self::LEGACY_ALIASES[$reason] ?? $reason;
    }

    public static function label(?string $reason): ?string
    {
        $reason = self::canonical($reason);

        return $reason === null ? null : (self::OPTIONS[$reason] ?? self::OPTIONS['other']);
    }

    public static function options(): array
    {
        return collect(self::OPTIONS)
            ->map(fn (string $label, string $value): array => compact('value', 'label'))
            ->values()
            ->all();
    }

    public static function requiredForStatus(?string $status): bool
    {
        return in_array($status, self::NEGATIVE_STATUSES, true);
    }
}
