<?php

namespace App\Domain\Provider\Programs;

use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ProgramLifecycle
{
    public function phase(string $status): string
    {
        return match ($status) {
            'draft', 'rejected' => 'drafts',
            'pending_review' => 'review',
            'closed' => 'closed',
            default => 'published',
        };
    }

    public function status(string $status): array
    {
        return [
            'value' => $status,
            'label' => match ($status) {
                'draft' => 'Draft',
                'rejected' => 'Changes requested',
                'pending_review' => 'Under review',
                'published' => 'Published',
                'closed' => 'Closed',
                default => Str::headline($status),
            },
            'tone' => match ($status) {
                'rejected' => 'attention',
                'published' => 'positive',
                'pending_review' => 'waiting',
                default => 'neutral',
            },
        ];
    }

    public function deadline(Scholarship $program): array
    {
        if (! $program->deadline) {
            return [
                'date' => null,
                'display' => 'Not set',
                'days_remaining' => null,
                'state' => 'not_set',
            ];
        }

        $deadline = CarbonImmutable::parse($program->deadline)->startOfDay();
        $daysRemaining = (int) CarbonImmutable::today()->diffInDays($deadline, false);

        return [
            'date' => $deadline->toDateString(),
            'display' => $deadline->format('M d, Y'),
            'days_remaining' => $daysRemaining,
            'state' => match (true) {
                $daysRemaining < 0 => 'passed',
                $daysRemaining === 0 => 'today',
                $daysRemaining <= 14 => 'due_soon',
                default => 'scheduled',
            },
        ];
    }

    public function recordUrl(Scholarship $program): string
    {
        return route('provider.programs.show', $program, false);
    }
}
