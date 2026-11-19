<?php

namespace App\Services;

use App\Models\ApplicationStageAssignment;
use App\Models\ApplicationStageSession;
use Illuminate\Support\Collection;

final class ApplicationStageSessionCompletion
{
    public function blocker(ApplicationStageSession $session, Collection $activeAssignments): ?array
    {
        return match (true) {
            ! in_array($session->status, ['scheduled', 'ongoing'], true) => [
                'key' => 'unpublished',
                'field' => 'session',
                'message' => 'Only a published session can be completed.',
            ],
            $session->starts_at->isFuture() => [
                'key' => 'not_started',
                'field' => 'session',
                'message' => 'This session cannot be completed before it starts.',
            ],
            $activeAssignments->isEmpty() => [
                'key' => 'empty_roster',
                'field' => 'session',
                'message' => 'Assign at least one applicant before completing this session.',
            ],
            $activeAssignments->contains(fn (ApplicationStageAssignment $assignment): bool => ($assignment->attendance?->status ?? 'pending') === 'pending') => [
                'key' => 'attendance',
                'field' => 'attendance',
                'message' => 'Record attendance for every assigned applicant first.',
            ],
            default => null,
        };
    }
}
