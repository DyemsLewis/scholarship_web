<?php

namespace App\Support;

class ScholarshipSelectionPlan
{
    public const STAGES = ['screening', 'exam', 'interview', 'distribution'];

    public const DEFAULT = ['screening', 'distribution'];

    public static function normalize(mixed $stages): array
    {
        if (is_string($stages)) {
            $decoded = json_decode($stages, true);
            $stages = is_array($decoded) ? $decoded : preg_split('/\r\n|\r|\n|,/', $stages);
        }

        $selected = collect(is_array($stages) ? $stages : [])
            ->map(fn (mixed $stage) => strtolower(trim((string) $stage)))
            ->filter(fn (string $stage) => in_array($stage, self::STAGES, true))
            ->unique()
            ->all();

        return collect(self::STAGES)
            ->filter(fn (string $stage) => in_array($stage, $selected, true) || in_array($stage, self::DEFAULT, true))
            ->values()
            ->all();
    }

    public static function nextApprovalStatus(string $currentStatus, mixed $stages): ?string
    {
        $stages = self::normalize($stages);

        if (in_array($currentStatus, ['submitted', 'under_review', 'qualified', 'shortlisted'], true)) {
            if (in_array('exam', $stages, true)) {
                return 'exam_qualified';
            }

            return in_array('interview', $stages, true) ? 'interview' : 'approved';
        }

        if (in_array($currentStatus, ['exam_taken', 'exam_passed'], true)) {
            return in_array('interview', $stages, true) ? 'interview' : 'approved';
        }

        if ($currentStatus === 'interview') {
            return 'approved';
        }

        return null;
    }

    public static function rejectionStatus(string $currentStatus): ?string
    {
        if (in_array($currentStatus, ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed'], true)) {
            return 'exam_failed';
        }

        if (in_array($currentStatus, ['submitted', 'under_review', 'qualified', 'shortlisted', 'interview'], true)) {
            return 'rejected';
        }

        return null;
    }

    public static function stageStatuses(string $type): array
    {
        return match ($type) {
            'screening' => ['submitted', 'under_review'],
            'exam' => ['exam_qualified', 'exam_scheduled'],
            'interview' => ['interview'],
            'distribution' => ['approved', 'awarded', 'distribution_scheduled'],
            default => [],
        };
    }

    public static function scheduledStatus(string $type): string
    {
        return match ($type) {
            'screening' => 'other',
            'exam' => 'exam_scheduled',
            'interview' => 'interview',
            'distribution' => 'distribution_scheduled',
            default => 'under_review',
        };
    }

    public static function decisionReason(string $type): string
    {
        return match ($type) {
            'screening' => 'under_review',
            'exam' => 'exam_scheduled',
            'interview' => 'for_interview',
            'distribution' => 'distribution_scheduled',
            default => 'other',
        };
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'screening' => 'screening',
            'exam' => 'exam',
            'interview' => 'interview',
            'distribution' => 'reward distribution',
            default => 'activity',
        };
    }
}
