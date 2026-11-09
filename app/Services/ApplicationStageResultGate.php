<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Database\Eloquent\Builder;

final class ApplicationStageResultGate
{
    public function activityComplete(ScholarshipApplication $application, string $stage): bool
    {
        if (! ScholarshipSelectionPlan::isSchedulable($stage)) {
            return true;
        }

        $assignments = $application->stageAssignments()
            ->whereIn('status', ['assigned', 'acknowledged'])
            ->whereHas('session', fn (Builder $session) => $session
                ->where('stage_key', $stage)->where('status', '!=', 'cancelled'));

        if ((clone $assignments)
            ->whereHas('attendance', fn (Builder $attendance) => $attendance->where('status', '!=', 'pending'))
            ->whereHas('session', fn (Builder $session) => $session
                ->where('status', 'completed')
                ->where(fn (Builder $proof) => $proof->where('proof_required', false)
                    ->orWhereHas('evidence', fn (Builder $evidence) => $evidence
                        ->where('scope', 'session')->where('is_current', true))))
            ->exists()) {
            return true;
        }

        $schedules = $application->schedules()->where('type', $stage)->where('status', '!=', 'cancelled');

        // Formal applications can be reviewed directly unless a session or appointment was assigned.
        return ($stage === 'formal_application' && ! $assignments->exists() && ! (clone $schedules)->exists())
            || $schedules->where('status', 'completed')->exists();
    }
}
