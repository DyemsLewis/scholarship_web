<?php

namespace App\Support;

class ScholarshipSelectionPlan
{
    public const STAGES = ['screening', 'formal_application', 'exam', 'interview', 'decision'];

    public const SCHEDULABLE_STAGES = ['exam', 'interview'];

    public const DEFAULT = ['screening', 'formal_application', 'decision'];

    public static function normalize(mixed $stages): array
    {
        if (is_string($stages)) {
            $decoded = json_decode($stages, true);
            $stages = is_array($decoded) ? $decoded : preg_split('/\r\n|\r|\n|,/', $stages);
        }

        $selected = collect(is_array($stages) ? $stages : [])
            ->map(fn (mixed $stage) => strtolower(trim((string) $stage)))
            ->map(fn (string $stage) => $stage === 'distribution' ? 'decision' : $stage)
            ->filter(fn (string $stage) => in_array($stage, self::STAGES, true))
            ->unique()
            ->values();

        if ($selected->isEmpty()) {
            return self::DEFAULT;
        }

        $middle = $selected
            ->reject(fn (string $stage): bool => in_array($stage, ['screening', 'decision'], true))
            ->values();

        // Older plans did not contain the provider handoff. Appending it keeps their old stage order stable.
        if (! $middle->contains('formal_application')) {
            $middle->push('formal_application');
        }

        return ['screening', ...$middle->all(), 'decision'];
    }

    public static function providerStages(mixed $stages): array
    {
        return collect(self::normalize($stages))
            ->reject(fn (string $stage): bool => in_array($stage, ['screening', 'decision'], true))
            ->values()
            ->all();
    }

    public static function nextStage(string $currentStage, mixed $stages): ?string
    {
        $stages = self::normalize($stages);
        $index = array_search($currentStage, $stages, true);

        return $index === false ? null : ($stages[$index + 1] ?? null);
    }

    public static function nextApprovalStatus(string $currentStatus, mixed $stages): ?string
    {
        $stages = self::normalize($stages);

        $currentStage = match (true) {
            in_array($currentStatus, ['submitted', 'under_review', 'qualified', 'shortlisted'], true) => 'screening',
            in_array($currentStatus, ['exam_taken', 'exam_passed'], true) => 'exam',
            $currentStatus === 'interview' => 'interview',
            default => null,
        };
        $nextStage = $currentStage ? self::nextStage($currentStage, $stages) : null;

        return match ($nextStage) {
            'exam' => 'exam_qualified',
            'interview' => 'interview',
            'formal_application', 'decision' => 'approved',
            default => null,
        };
    }

    public static function isSchedulable(string $stage): bool
    {
        return in_array($stage, self::SCHEDULABLE_STAGES, true);
    }

    public static function rejectionStatus(string $currentStatus): ?string
    {
        if (in_array($currentStatus, ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed'], true)) {
            return 'exam_failed';
        }

        if ($currentStatus === 'interview') {
            return 'interview_failed';
        }

        if (in_array($currentStatus, ['submitted', 'under_review', 'qualified', 'shortlisted'], true)) {
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
            'formal_application' => ['approved'],
            'decision' => ['approved', 'waitlisted'],
            default => [],
        };
    }

    public static function scheduledStatus(string $type): string
    {
        return match ($type) {
            'screening' => 'under_review',
            'exam' => 'exam_scheduled',
            'interview' => 'interview',
            default => 'under_review',
        };
    }

    public static function decisionReason(string $type): string
    {
        return match ($type) {
            'screening' => 'under_review',
            'exam' => 'exam_scheduled',
            'interview' => 'for_interview',
            default => 'other',
        };
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'screening' => 'screening',
            'formal_application' => 'formal application',
            'exam' => 'exam',
            'interview' => 'interview',
            'decision' => 'final decision',
            default => 'activity',
        };
    }
}
