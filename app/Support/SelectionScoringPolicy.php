<?php

namespace App\Support;

final class SelectionScoringPolicy
{
    public const VERSION = '1.0';

    public static function preset(?array $stages, array $rubric): array
    {
        $stages = ScholarshipSelectionPlan::normalize($stages);
        // Eligibility and file completeness are qualifying checks, not competitive points.
        $screening = array_values(array_filter($rubric, fn (array $row): bool => in_array($row['key'] ?? null, ['academic_merit', 'financial_need'], true)));
        $keys = array_values(array_filter(['screening', 'exam', 'interview'], fn (string $key): bool => in_array($key, $stages, true) && ($key !== 'screening' || $screening !== [])));
        $weights = match (implode('+', $keys)) {
            'screening+exam+interview' => [30, 40, 30],
            'screening+exam' => [40, 60],
            'screening+interview' => [50, 50],
            'exam+interview' => [60, 40],
            default => $keys === [] ? [] : [100],
        };

        return [
            'version' => self::VERSION,
            'stages' => $stages,
            'weights' => $keys === [] ? [] : array_combine($keys, $weights),
            'review_rubric' => $rubric,
            'screening_criteria' => $screening,
            'notice' => 'Stage evaluations support the final decision. They do not automatically select or reject an applicant.',
        ];
    }

    public static function fingerprint(array $policy): string
    {
        return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
    }
}
