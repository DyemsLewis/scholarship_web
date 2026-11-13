<?php

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;

final class WorkflowDemoTimeline
{
    public const TIMEZONE = 'Asia/Manila';

    public readonly CarbonImmutable $anchor;

    public function __construct()
    {
        $this->anchor = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    public function at(int $day, int $hour = 9, int $minute = 0): CarbonImmutable
    {
        return $this->anchor->addDays($day)->setTime($hour, $minute);
    }

    public function intake(string $round): array
    {
        // Open, close, formal handoff deadline, expected results, support start, support end.
        [$opens, $closes, $handoff, $results, $starts, $ends] = match ($round) {
            'intake', 'partner_intake' => [-5, 14, 21, 35, 40, 220],
            'draft', 'review', 'partner_draft' => [4, 24, 31, 45, 50, 230],
            'screening', 'partner_screening' => [-35, -10, 4, 28, 35, 215],
            'exam' => [-44, -23, -16, 24, 30, 210],
            'interview' => [-52, -33, -26, 20, 27, 207],
            'decision' => [-58, -38, -30, 10, 16, 196],
            'support' => [-76, -52, -44, -35, -30, 150],
            'one_time' => [-72, -48, -38, -34, -29, 42],
        };

        return [
            'application_opens_at' => $this->at($opens)->toDateString(),
            'deadline' => $this->at($closes)->toDateString(),
            'handoff_deadline' => $this->at($handoff)->toDateString(),
            'expected_results_at' => $this->at($results)->toDateString(),
            'support_starts_at' => $this->at($starts)->toDateString(),
            'support_ends_at' => $this->at($ends)->toDateString(),
            'created_at' => $this->at(min(-2, $opens - 2)),
        ];
    }

    public function progression(string $round): array
    {
        // Submission, screening result, formal result, and any earlier decision and agreement.
        return match ($round) {
            'screening', 'partner_screening' => [-14, -8, null, null, null],
            'exam' => [-30, -22, -18, null, null],
            'interview' => [-40, -32, -28, null, null],
            'decision' => [-45, -37, -33, null, null],
            'support' => [-60, -51, -48, -35, -33],
            'one_time' => [-55, -47, -44, -34, -32],
        };
    }

    public function sessionDay(string $round, string $stage, string $scenario): int
    {
        return match ([$round, $stage, $scenario]) {
            ['exam', 'exam', 'exam.result'] => -5,
            ['exam', 'exam', 'exam.complete'] => -3,
            ['exam', 'exam', 'exam.confirm'] => 3,
            ['interview', 'interview', 'interview.result'] => -4,
            ['interview', 'interview', 'interview.confirm'] => 7,
            default => match ([$round, $stage]) {
                ['interview', 'exam'] => -22,
                ['decision', 'exam'] => -27,
                ['decision', 'interview'] => -24,
                ['support', 'exam'] => -41,
                ['support', 'interview'] => -38,
            },
        };
    }
}
