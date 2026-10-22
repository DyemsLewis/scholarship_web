<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ApplicationStageSession;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class SelectionOfficerDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $programIds = $this->scope->programs($officer)->pluck('id');
        $sessions = ApplicationStageSession::query()->whereIn('scholarship_id', $programIds);
        $activeSessions = (clone $sessions)->whereIn('status', ['draft', 'scheduled', 'ongoing']);
        $historySessions = (clone $sessions)->whereIn('status', ['completed', 'cancelled']);
        $applications = $this->applicationBase($officer);
        $unassigned = $this->unassignedApplications(clone $applications);
        $results = $this->resultApplications(clone $applications);

        $prioritySessions = (clone $activeSessions)
            ->with('scholarship')
            ->withCount(['assignments as active_assignments_count' => fn (Builder $query) => $query
                ->whereIn('status', ['assigned', 'acknowledged'])])
            ->orderByRaw("CASE status WHEN 'ongoing' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (ApplicationStageSession $session): array => $this->sessionItem($session));

        $resultItems = (clone $results)
            ->with(['applicant.studentProfile', 'scholarship', 'stageProgresses'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->applicationItem($application, 'result'));
        $unassignedItems = (clone $unassigned)
            ->with(['applicant.studentProfile', 'scholarship', 'stageProgresses'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->applicationItem($application, 'unassigned'));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'selection-officer',
                'eyebrow' => 'Selection activities',
                'title' => 'Selection dashboard',
                'description' => 'Review formal applications and coordinate scheduled selection activities.',
                'icon' => 'fa-solid fa-calendar-check',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: [
                'label' => 'Open sessions',
                'url' => '/provider/workspaces/selection/active',
            ],
            metrics: [
                [
                    'key' => 'active-sessions',
                    'label' => 'Active sessions',
                    'value' => (clone $activeSessions)->count(),
                    'detail' => 'Draft, scheduled, or ongoing',
                    'url' => '/provider/workspaces/selection/active',
                ],
                [
                    'key' => 'unassigned',
                    'label' => 'Need a session',
                    'value' => (clone $unassigned)->count(),
                    'detail' => 'Exam or interview applicants',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/selection/unassigned',
                ],
                [
                    'key' => 'results',
                    'label' => 'Ready for review',
                    'value' => (clone $results)->count(),
                    'detail' => 'Applications or completed activities',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/selection/results',
                ],
                [
                    'key' => 'history',
                    'label' => 'Session history',
                    'value' => (clone $historySessions)->count(),
                    'detail' => 'Completed or cancelled',
                    'url' => '/provider/workspaces/selection/history',
                ],
            ],
            sections: [
                [
                    'key' => 'selection-sessions',
                    'eyebrow' => 'Schedule',
                    'title' => 'Sessions to manage',
                    'description' => 'Ongoing sessions appear first, followed by the next scheduled activity.',
                    'empty_message' => 'No active selection sessions.',
                    'action' => [
                        'label' => 'Manage sessions',
                        'url' => '/provider/workspaces/selection/active',
                    ],
                    'items' => $prioritySessions,
                ],
                [
                    'key' => 'candidate-actions',
                    'eyebrow' => 'Candidate work',
                    'title' => 'Applicants needing action',
                    'description' => 'Review formal applications and completed session results.',
                    'empty_message' => 'No applicant activity needs action.',
                    'action' => [
                        'label' => 'Open results',
                        'url' => '/provider/workspaces/selection/results',
                    ],
                    'items' => $resultItems->concat($unassignedItems)->unique('id')->take(5)->values(),
                ],
            ],
        );
    }

    private function applicationBase(User $officer): Builder
    {
        return $this->scope->applications($officer)
            ->whereIn('workflow_stage', ApplicationStageCatalog::SESSION_STAGES)
            ->whereNotIn('application_state', ['closed', 'withdrawn']);
    }

    private function unassignedApplications(Builder $query): Builder
    {
        return $query
            ->whereIn('workflow_stage', [ApplicationStageCatalog::EXAM, ApplicationStageCatalog::INTERVIEW])
            ->whereHas('stageProgresses', fn (Builder $progress) => $progress
                ->where('status', 'current')
                ->whereColumn('stage_key', 'scholarship_applications.workflow_stage')
                ->where(fn (Builder $status) => $status
                    ->whereNull('operational_status')
                    ->orWhereIn('operational_status', ['not_started', 'waiting_for_schedule'])));
    }

    private function resultApplications(Builder $query): Builder
    {
        return $query->where(fn (Builder $ready) => $ready
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
                    ->where('status', '!=', 'cancelled'))));
    }

    /** @return array<string, mixed> */
    private function sessionItem(ApplicationStageSession $session): array
    {
        $date = $session->starts_at?->format('M d, Y h:i A') ?: 'Schedule not set';

        return [
            'id' => (int) $session->id,
            'title' => $session->title,
            'subtitle' => $session->scholarship?->title ?: 'Scholarship program',
            'status' => [
                'label' => Str::headline($session->status),
                'tone' => $session->status === 'ongoing' ? 'attention' : 'neutral',
            ],
            'meta' => [
                Str::headline($session->stage_key).' · '.$date,
                ((int) ($session->active_assignments_count ?? 0)).' assigned applicant'.((int) ($session->active_assignments_count ?? 0) === 1 ? '' : 's'),
            ],
            'action' => [
                'label' => $session->status === 'draft' ? 'Continue setup' : 'Open session',
                'url' => $session->status === 'draft'
                    ? route('provider.workspaces.selection.sessions.edit', $session, false)
                    : route('provider.workspaces.selection.sessions.show', $session, false),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function applicationItem(ScholarshipApplication $application, string $kind): array
    {
        $profile = $application->applicant?->studentProfile;
        $stage = Str::headline($application->workflow_stage);
        $isFormalApplication = $application->workflow_stage === ApplicationStageCatalog::FORMAL_APPLICATION;

        return [
            'id' => (int) $application->id,
            'title' => $application->applicant?->name ?: 'Applicant record',
            'subtitle' => $application->scholarship?->title ?: 'Scholarship program',
            'image_url' => $profile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application, false)
                : null,
            'status' => [
                'label' => $kind === 'result'
                    ? ($isFormalApplication ? 'Ready for review' : 'Result ready')
                    : 'Needs session',
                'tone' => 'attention',
            ],
            'meta' => [
                $stage,
                $kind === 'result'
                    ? ($isFormalApplication ? 'Review the formal application record' : 'Participation is ready for review')
                    : 'No active session assignment',
            ],
            'action' => [
                'label' => $kind === 'result'
                    ? ($isFormalApplication ? 'Review formal application' : 'Review result')
                    : 'Create session',
                'url' => $kind === 'result'
                    ? route('provider.applications.show', [
                        'application' => $application,
                        'workspace' => 'selection',
                        'return_to' => '/provider/workspaces/selection/results',
                    ], false)
                    : route('provider.workspaces.selection.unassigned', [
                        'program_id' => $application->scholarship_id,
                        'stage_key' => $application->workflow_stage,
                        'application_id' => $application->id,
                        'create_session' => 1,
                    ], false),
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    private function accessScope(array $context): string
    {
        return $context['program_access_mode'] === 'selected'
            ? $context['assigned_program_count'].' assigned programs'
            : 'All organization programs';
    }
}
