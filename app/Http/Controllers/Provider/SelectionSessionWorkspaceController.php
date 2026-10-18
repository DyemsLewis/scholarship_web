<?php

namespace App\Http\Controllers\Provider;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\Applications\ApplicationStageSessionResource;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SelectionSessionWorkspaceController extends Controller
{
    public function directoryData(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['active', 'history', 'unassigned', 'results'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'stage_key' => ['sometimes', 'nullable', Rule::in(ApplicationStageCatalog::SESSION_STAGES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $queue = $validated['queue'] ?? 'active';
        $search = trim((string) ($validated['search'] ?? ''));
        $programs = $this->programsFor($actor);
        $programIds = $programs->pluck('id');

        if (! empty($validated['program_id'])) {
            abort_unless($programIds->contains((int) $validated['program_id']), 403);
            $programIds = collect([(int) $validated['program_id']]);
        }

        $sessionBase = ApplicationStageSession::query()->whereIn('scholarship_id', $programIds);
        $applicationBase = $this->applicationBase($programIds->all());
        $activeSessions = (clone $sessionBase)->whereIn('status', ['draft', 'scheduled', 'ongoing']);
        $historySessions = (clone $sessionBase)->whereIn('status', ['completed', 'cancelled']);
        $unassigned = $this->unassignedApplications(clone $applicationBase);
        $results = $this->resultApplications(clone $applicationBase);

        $response = [
            'workspace' => [
                'role' => 'Selection officer',
                'organization_name' => $actor->providerOrganizationOwner()->name,
                'program_access_mode' => $actor->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'active' => $activeSessions->count(),
                'unassigned' => $unassigned->count(),
                'results' => $results->count(),
                'history' => $historySessions->count(),
            ],
            'programs' => $programs->map(fn (Scholarship $program): array => [
                'id' => (int) $program->id,
                'title' => $program->title,
                'stages' => collect(ScholarshipSelectionPlan::normalize($program->selection_stages))
                    ->filter(fn (string $stage): bool => ApplicationStageCatalog::supportsSessions($stage))
                    ->values(),
            ])->values(),
            'sessions' => [],
            'applications' => [],
        ];

        if (in_array($queue, ['active', 'history'], true)) {
            $query = $queue === 'history' ? $historySessions : $activeSessions;
            $query
                ->with(['scholarship'])
                ->withCount([
                    'assignments as active_assignments_count' => fn (Builder $assignmentQuery) => $assignmentQuery
                        ->whereIn('status', ['assigned', 'acknowledged']),
                    'evidence as current_evidence_count' => fn (Builder $evidenceQuery) => $evidenceQuery
                        ->where('is_current', true),
                    'assignments as pending_attendance_count' => fn (Builder $assignmentQuery) => $assignmentQuery
                        ->whereIn('status', ['assigned', 'acknowledged'])
                        ->whereHas('attendance', fn (Builder $attendanceQuery) => $attendanceQuery
                            ->where('status', 'pending')),
                ])
                ->when($search !== '', fn (Builder $builder) => $builder->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhereHas('scholarship', fn (Builder $programQuery) => $programQuery
                            ->where('title', 'like', "%{$search}%"));
                }))
                ->when($validated['stage_key'] ?? null, fn (Builder $builder, string $stage) => $builder
                    ->where('stage_key', $stage))
                ->orderByRaw("CASE status WHEN 'ongoing' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END")
                ->orderBy($queue === 'history' ? 'completed_at' : 'starts_at', $queue === 'history' ? 'desc' : 'asc')
                ->orderBy('id');
            $items = $query->paginate(8);
            $response['sessions'] = ApplicationStageSessionResource::collection($items->getCollection())->resolve($request);
        } else {
            $query = $queue === 'results' ? $results : $unassigned;
            $this->applyApplicationSearch($query, $search);
            $query->when($validated['stage_key'] ?? null, fn (Builder $builder, string $stage) => $builder
                ->where('workflow_stage', $stage));
            $query
                ->with(['applicant.studentProfile', 'scholarship', 'stageProgresses'])
                ->orderBy('submitted_at')
                ->orderBy('id');
            $items = $query->paginate(8);
            $response['applications'] = $items->getCollection()
                ->map(fn (ScholarshipApplication $application): array => $this->applicationItem($application, $queue))
                ->values();
        }

        $response['pagination'] = [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'from' => $items->firstItem(),
            'to' => $items->lastItem(),
        ];
        $response['active_queue'] = $queue;

        return response()->json($response);
    }

    public function formData(Request $request, ?ApplicationStageSession $session = null): JsonResponse
    {
        $actor = $this->actor($request);
        $programs = $this->programsFor($actor);

        if ($session) {
            abort_unless($actor->canAccessProviderProgram($session->scholarship), 403);
            abort_unless($session->status === 'draft', 422, 'Only draft sessions can be edited.');
        }

        return response()->json([
            'programs' => $programs->map(fn (Scholarship $program): array => [
                'id' => (int) $program->id,
                'title' => $program->title,
                'stages' => collect(ScholarshipSelectionPlan::normalize($program->selection_stages))
                    ->filter(fn (string $stage): bool => ApplicationStageCatalog::supportsSessions($stage))
                    ->map(fn (string $stage): array => [
                        'key' => $stage,
                        'label' => Str::headline($stage),
                        'proof_required' => ApplicationStageCatalog::requiresProofByDefault($stage),
                    ])->values(),
            ])->values(),
            'session' => $session
                ? (new ApplicationStageSessionResource($session->load('scholarship')))->resolve($request)
                : null,
        ]);
    }

    private function actor(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->isProvider(), 403);
        abort_unless($actor->hasPortalPermission('manage_selection_activities'), 403);

        return $actor;
    }

    private function programsFor($actor)
    {
        $query = Scholarship::query()
            ->where('provider_id', $actor->providerOrganizationId())
            ->whereIn('status', ['published', 'closed'])
            ->orderBy('title');

        if ($actor->hasLimitedProviderProgramAccess()) {
            $query->whereIn('id', $actor->assignedProviderProgramIds());
        }

        return $query->get(['id', 'provider_id', 'title', 'status', 'selection_stages']);
    }

    private function applicationBase(array $programIds): Builder
    {
        return ScholarshipApplication::query()
            ->whereIn('scholarship_id', $programIds)
            ->whereIn('workflow_stage', ApplicationStageCatalog::SESSION_STAGES)
            ->whereNotIn('application_state', ['closed', 'withdrawn']);
    }

    private function unassignedApplications(Builder $query): Builder
    {
        return $query->whereHas('stageProgresses', fn (Builder $progressQuery) => $progressQuery
            ->where('status', 'current')
            ->whereColumn('stage_key', 'scholarship_applications.workflow_stage')
            ->where(function (Builder $statusQuery): void {
                $statusQuery
                    ->whereNull('operational_status')
                    ->orWhereIn('operational_status', ['not_started', 'waiting_for_schedule']);
            }));
    }

    private function resultApplications(Builder $query): Builder
    {
        return $query->where(function (Builder $readyQuery): void {
            $readyQuery
                ->whereHas('stageProgresses', fn (Builder $progressQuery) => $progressQuery
                    ->where('status', 'current')
                    ->where('operational_status', 'ready_for_review')
                    ->whereColumn('stage_key', 'scholarship_applications.workflow_stage'))
                ->orWhereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                    ->where('status', 'completed')
                    ->whereColumn('type', 'scholarship_applications.workflow_stage'));
        });
    }

    private function applyApplicationSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            $searchQuery
                ->whereHas('applicant', fn (Builder $applicantQuery) => $applicantQuery
                    ->where('email', 'like', "%{$search}%")
                    ->orWhereHas('studentProfile', fn (Builder $profileQuery) => $profileQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")))
                ->orWhereHas('scholarship', fn (Builder $programQuery) => $programQuery
                    ->where('title', 'like', "%{$search}%"));
        });
    }

    private function applicationItem(ScholarshipApplication $application, string $queue): array
    {
        $profile = $application->applicant?->studentProfile;
        $progress = $application->stageProgresses->firstWhere('stage_key', $application->workflow_stage);

        return [
            'id' => (int) $application->id,
            'applicant' => [
                'name' => $application->applicant?->name ?: 'Applicant record',
                'education' => collect([
                    $profile?->education_level,
                    $profile?->course_or_strand,
                    $profile?->year_level,
                ])->filter()->implode(' - '),
                'profile_photo_url' => $profile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
            ],
            'program' => [
                'id' => (int) $application->scholarship_id,
                'title' => $application->scholarship?->title ?: 'Scholarship program',
            ],
            'stage' => [
                'key' => $application->workflow_stage,
                'label' => Str::headline($application->workflow_stage),
                'operational_status' => $progress?->operational_status,
            ],
            'submitted_at' => $application->submitted_at?->format('M d, Y'),
            'action' => $queue === 'results'
                ? [
                    'label' => 'Review outcome',
                    'url' => route('provider.applications.show', [
                        'application' => $application,
                        'workspace' => 'selection',
                        'return_to' => '/provider/workspaces/selection/results',
                    ], false),
                ]
                : [
                    'label' => 'Create session',
                    'url' => route('provider.workspaces.selection.sessions.create', [
                        'program_id' => $application->scholarship_id,
                        'stage_key' => $application->workflow_stage,
                        'application_id' => $application->id,
                    ], false),
                ],
        ];
    }
}
