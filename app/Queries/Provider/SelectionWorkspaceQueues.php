<?php

namespace App\Queries\Provider;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Models\ApplicationStageSession;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class SelectionWorkspaceQueues
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    public function programs(User $actor): Builder
    {
        return $this->scope->programs($actor)->whereIn('status', ['published', 'closed']);
    }

    /** @return array<string, Builder> */
    public function forProgramIds(iterable $programIds): array
    {
        $sessions = ApplicationStageSession::query()->whereIn('scholarship_id', $programIds);
        $applications = ScholarshipApplication::query()
            ->whereIn('scholarship_id', $programIds)
            ->whereIn('workflow_stage', ApplicationStageCatalog::SESSION_STAGES)
            ->whereNotIn('application_state', ['closed', 'withdrawn']);

        return [
            'active' => (clone $sessions)->whereIn('status', ['draft', 'scheduled', 'ongoing']),
            'history' => (clone $sessions)->whereIn('status', ['completed', 'cancelled']),
            'unassigned' => (clone $applications)
                ->whereIn('workflow_stage', [ApplicationStageCatalog::EXAM, ApplicationStageCatalog::INTERVIEW])
                ->whereHas('stageProgresses', fn (Builder $progress) => $progress
                    ->where('status', 'current')
                    ->whereColumn('stage_key', 'scholarship_applications.workflow_stage')
                    ->where(fn (Builder $status) => $status
                        ->whereNull('operational_status')
                        ->orWhereIn('operational_status', ['not_started', 'waiting_for_schedule']))),
            'results' => $applications->where(fn (Builder $ready) => $ready
                ->whereHas('stageProgresses', fn (Builder $progress) => $progress
                    ->where('status', 'current')
                    ->where('operational_status', 'ready_for_review')
                    ->whereColumn('stage_key', 'scholarship_applications.workflow_stage'))
                ->orWhereHas('schedules', fn (Builder $schedule) => $schedule
                    ->where('status', 'completed')
                    ->whereColumn('type', 'scholarship_applications.workflow_stage'))
                ->orWhere(fn (Builder $formal) => $formal
                    ->where('workflow_stage', ApplicationStageCatalog::FORMAL_APPLICATION)
                    ->whereDoesntHave('stageAssignments', fn (Builder $assignment) => $assignment
                        ->whereIn('status', ['assigned', 'acknowledged'])
                        ->whereHas('session', fn (Builder $session) => $session
                            ->where('stage_key', ApplicationStageCatalog::FORMAL_APPLICATION)
                            ->where('status', '!=', 'cancelled')))
                    ->whereDoesntHave('schedules', fn (Builder $schedule) => $schedule
                        ->where('type', ApplicationStageCatalog::FORMAL_APPLICATION)
                        ->where('status', '!=', 'cancelled')))),
        ];
    }
}
