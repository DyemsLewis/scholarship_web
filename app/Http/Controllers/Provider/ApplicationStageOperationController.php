<?php

namespace App\Http\Controllers\Provider;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\Applications\AssignStageSessionApplicationsRequest;
use App\Http\Requests\Provider\Applications\CancelStageSessionRequest;
use App\Http\Requests\Provider\Applications\RecordStageAttendanceRequest;
use App\Http\Requests\Provider\Applications\RescheduleStageAssignmentRequest;
use App\Http\Requests\Provider\Applications\StoreStageEvidenceRequest;
use App\Http\Requests\Provider\Applications\StoreStageSessionRequest;
use App\Http\Requests\Provider\Applications\UpdateStageSessionRequest;
use App\Http\Resources\Provider\Applications\ApplicationStageEvidenceResource;
use App\Http\Resources\Provider\Applications\ApplicationStageSessionResource;
use App\Models\ActivityLog;
use App\Models\ApplicationStageAssignment;
use App\Models\ApplicationStageEvidence;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Services\ApplicationStageOperationsService;
use App\Services\ApplicationStageResultGate;
use App\Services\ApplicationStageSessionCompletion;
use App\Services\ProviderApplicationNextStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationStageOperationController extends Controller
{
    public function __construct(private readonly ApplicationStageOperationsService $operations) {}

    public function index(Request $request, Scholarship $scholarship): JsonResponse
    {
        $this->authorizeProgram($request, $scholarship);
        $validated = $request->validate([
            'stage_key' => ['nullable', Rule::in(ApplicationStageCatalog::SESSION_STAGES)],
            'status' => ['nullable', Rule::in(ApplicationStageCatalog::SESSION_STATUSES)],
        ]);
        $sessions = $scholarship->stageSessions()
            ->when($validated['stage_key'] ?? null, fn ($query, $stage) => $query->where('stage_key', $stage))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->withCount([
                'assignments as active_assignments_count' => fn ($query) => $query
                    ->whereIn('status', ['assigned', 'acknowledged']),
            ])
            ->latest('starts_at')
            ->get();

        return response()->json([
            'sessions' => ApplicationStageSessionResource::collection($sessions)->resolve($request),
        ]);
    }

    public function show(
        Request $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $this->authorizeProgram($request, $scholarship);
        $this->ensureSessionProgram($session, $scholarship);
        $session->load([
            'scholarship',
            'assignments.application.applicant.studentProfile',
            'assignments.attendance',
            'assignments.evidence.uploader',
            'evidence.uploader',
        ]);

        $active = $session->assignments->whereIn('status', ['assigned', 'acknowledged']);
        $blocker = app(ApplicationStageSessionCompletion::class)->blocker($session, $active);
        $steps = app(ProviderApplicationNextStep::class);
        $owner = 'Selection officer';
        $hasPendingResults = $active->contains(fn (ApplicationStageAssignment $assignment): bool =>
            $assignment->application?->workflow_stage === $session->stage_key
            && ! in_array($assignment->application->application_state, ['closed', 'withdrawn'], true));
        $resultsUrl = route('provider.workspaces.selection.results', [
            'program_id' => $scholarship->id,
            'stage_key' => $session->stage_key,
        ], false);
        $nextStep = match (true) {
            $session->status === 'cancelled' => null,
            $session->status === 'completed' => $hasPendingResults
                ? $steps->step('handoff', 'Record individual applicant outcomes',
                    'Completing the session does not advance applicants. Record an outcome for each applicant still at this stage.', $owner,
                    ['label' => 'Open result queue', 'url' => $resultsUrl])
                : null,
            $session->status === 'draft' && $session->starts_at->isPast() => $steps->step('blocked', 'Update the session date',
                'Choose a future date and time before publishing.', $owner, [
                    'label' => 'Edit session',
                    'url' => route('provider.workspaces.selection.sessions.edit', $session, false),
                ]),
            $session->status === 'draft' && $active->isEmpty() => $steps->step('blocked', 'Add applicants to the draft',
                'Add the intended applicants so they receive the schedule when it is published.', $owner,
                ['label' => 'Open roster', 'section' => 'roster']),
            $session->status === 'draft' => $steps->step('handoff', 'Publish the session',
                'Publishing shares the schedule and instructions with the assigned applicants.', $owner),
            default => match ($blocker['key'] ?? null) {
                'not_started' => $steps->step('waiting', 'Wait for the scheduled start', $blocker['message'], 'Scheduled start'),
                'empty_roster' => $steps->step('blocked', 'Assign applicants', $blocker['message'], $owner,
                    ['label' => 'Open roster', 'section' => 'roster']),
                'attendance' => $steps->step('blocked', 'Record attendance', $blocker['message'], $owner,
                    ['label' => 'Open roster', 'section' => 'roster']),
                'proof' => $steps->step('blocked', 'Upload session proof', $blocker['message'], $owner,
                    ['label' => 'Open proof', 'section' => 'proof']),
                default => null,
            },
        };

        return response()->json([
            'session' => [
                ...(new ApplicationStageSessionResource($session))->resolve($request),
                'completion' => ['can_complete' => $blocker === null, 'blocked_by' => $blocker['key'] ?? null],
                'next_step' => $nextStep,
            ],
        ]);
    }

    public function candidates(
        Request $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $this->authorizeProgram($request, $scholarship);
        $this->ensureSessionProgram($session, $scholarship);
        abort_unless(in_array($session->status, ['draft', 'scheduled'], true), 422);
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $query = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('workflow_stage', $session->stage_key)
            ->whereNotIn('application_state', ['closed', 'withdrawn'])
            ->whereHas('stageProgresses', fn (Builder $progressQuery) => $progressQuery
                ->where('stage_key', $session->stage_key)
                ->where('status', 'current'))
            ->whereDoesntHave('stageAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                ->whereIn('status', ['assigned', 'acknowledged'])
                ->whereHas('session', fn (Builder $sessionQuery) => $sessionQuery
                    ->where('stage_key', $session->stage_key)
                    ->whereIn('status', ['draft', 'scheduled', 'ongoing'])))
            ->when($search !== '', fn (Builder $builder) => $builder->whereHas(
                'applicant',
                fn (Builder $applicantQuery) => $applicantQuery
                    ->where('email', 'like', "%{$search}%")
                    ->orWhereHas('studentProfile', fn (Builder $profileQuery) => $profileQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")),
            ))
            ->with(['applicant.studentProfile'])
            ->orderBy('submitted_at')
            ->orderBy('id');
        $candidates = $query->paginate(20);

        return response()->json([
            'candidates' => $candidates->getCollection()->map(function (ScholarshipApplication $application): array {
                $profile = $application->applicant?->studentProfile;

                return [
                    'application_id' => (int) $application->id,
                    'name' => $application->applicant?->name ?: 'Applicant record',
                    'education' => collect([
                        $profile?->education_level,
                        $profile?->course_or_strand,
                        $profile?->year_level,
                    ])->filter()->implode(' - '),
                    'profile_photo_url' => $profile?->profile_photo_path
                        ? route('provider.applications.profile-photo.view', $application, false)
                        : null,
                    'submitted_at' => $application->submitted_at?->format('M d, Y'),
                ];
            })->values(),
            'pagination' => [
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
                'total' => $candidates->total(),
            ],
        ]);
    }

    public function applicationReadiness(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        $this->authorizeProgram($request, $application->scholarship);
        $stage = $application->workflow_stage;
        abort_unless(ApplicationStageCatalog::supportsSessions($stage), 422);

        $progress = $application->stageProgresses()
            ->where('stage_key', $stage)
            ->where('status', 'current')
            ->first();
        $assignment = $application->stageAssignments()
            ->with(['session.evidence', 'attendance'])
            ->whereIn('status', ['assigned', 'acknowledged'])
            ->whereHas('session', fn (Builder $query) => $query->where('stage_key', $stage)->where('status', '!=', 'cancelled'))
            ->latest('assigned_at')
            ->first();
        $session = $assignment?->session;
        $proofCount = $session?->evidence->where('scope', 'session')->where('is_current', true)->count() ?? 0;
        $legacySchedule = $session ? null : $application->schedules()->where('type', $stage)
            ->where('status', '!=', 'cancelled')->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")
            ->latest('id')->first();
        $blockedReason = match (true) {
            ! $progress || in_array($application->application_state, ['closed', 'withdrawn'], true) => 'This application is no longer ready for a stage result.',
            in_array($application->correction_status, ['requested', 'submitted'], true) => 'Resolve the open correction request before recording the result.',
            ! app(ApplicationStageResultGate::class)->activityComplete($application, $stage) => 'Complete the activity and its required attendance and proof before recording a result.',
            default => null,
        };
        $steps = app(ProviderApplicationNextStep::class);
        $owner = 'Selection officer';
        $stageLabel = str($stage)->headline()->toString();
        $sessionAction = fn (string $label = 'Open session', string $section = 'overview'): array => [
            'label' => $label,
            'url' => route('provider.workspaces.selection.sessions.show', ['session' => $session, 'section' => $section], false),
        ];
        $queueFilters = ['program_id' => $application->scholarship_id, 'stage_key' => $stage];
        $nextStep = match (true) {
            $blockedReason === null => null,
            ! $progress || in_array($application->application_state, ['closed', 'withdrawn'], true) =>
                $steps->step('waiting', 'No result can be recorded', $blockedReason, ''),
            $application->correction_status === 'requested' => $steps->step('waiting', 'Wait for the applicant to respond',
                $blockedReason, 'Applicant'),
            $application->correction_status === 'submitted' => $steps->step('blocked', 'Review the submitted correction',
                $blockedReason, 'Application reviewer'),
            $session?->status === 'draft' => $steps->step('blocked', 'Publish the session',
                'The schedule is still a draft. Publish it before recording the activity result.', $owner, $sessionAction()),
            $session?->starts_at?->isFuture() && $session->status !== 'completed' => $steps->step('waiting', 'Wait for the scheduled activity',
                'The session has not started. Record attendance and any required proof after the activity.', 'Scheduled start', $sessionAction()),
            $session && ($assignment->attendance?->status ?? 'pending') === 'pending' => $steps->step('blocked', 'Record attendance',
                'This applicant has no attendance result for the session.', $owner, $sessionAction('Open roster', 'roster')),
            $session?->proof_required && $proofCount === 0 => $steps->step('blocked', 'Upload session proof',
                'Add the required proof to the session before recording the outcome.', $owner, $sessionAction('Open proof', 'proof')),
            $session !== null => $steps->step('blocked', 'Complete the session',
                'Open the session to finish the remaining checks before recording an outcome.', $owner, $sessionAction()),
            $legacySchedule !== null => $steps->step('blocked', 'Complete the scheduled activity',
                'Finish the existing appointment before recording an outcome.', $owner),
            default => $steps->step('blocked', 'Schedule the '.strtolower($stageLabel),
                'Assign this applicant to a session and complete it before recording the outcome.', $owner, [
                    'label' => 'Schedule activity',
                    'url' => route('provider.workspaces.selection.unassigned', [
                        ...$queueFilters,
                        'application_id' => $application->id,
                        'create_session' => 1,
                    ], false),
                ]),
        };

        return response()->json([
            'readiness' => [
                'stage_key' => $stage,
                'stage_label' => $stageLabel,
                'operational_status' => $progress?->operational_status,
                'can_record_result' => $blockedReason === null,
                'blocked_reason' => $blockedReason,
                'next_step' => $nextStep,
                'attendance_status' => $assignment?->attendance?->status ?? $legacySchedule?->attendance_status,
                'proof_required' => (bool) ($session?->proof_required ?? false),
                'proof_count' => $proofCount,
                'session' => $session ? [
                    'id' => (int) $session->id,
                    'title' => $session->title,
                    'status' => $session->status,
                    'completed_at' => $session->completed_at?->toIso8601String(),
                    'record_url' => route('provider.workspaces.selection.sessions.show', $session, false),
                ] : null,
                'legacy_schedule' => $legacySchedule ? [
                    'title' => $legacySchedule->title,
                    'status' => $legacySchedule->status,
                    'completed_at' => $legacySchedule->completed_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function store(StoreStageSessionRequest $request, Scholarship $scholarship): JsonResponse
    {
        $session = $this->operations->createSession($scholarship, $request->user(), $request->validated());
        $this->log($request, 'application_stage_session_created', $scholarship, $session);

        return response()->json([
            'message' => 'Draft session created.',
            'session' => (new ApplicationStageSessionResource($session))->resolve($request),
        ], 201);
    }

    public function update(
        UpdateStageSessionRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $session = $this->operations->updateSession(
            $scholarship,
            $session,
            $request->user(),
            $request->validated(),
        );
        $this->log($request, 'application_stage_session_updated', $scholarship, $session);

        return response()->json([
            'message' => 'Draft session updated.',
            'session' => (new ApplicationStageSessionResource($session))->resolve($request),
        ]);
    }

    public function publish(
        Request $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $session = $this->operations->publishSession($scholarship, $session, $request->user());
        $this->log($request, 'application_stage_session_published', $scholarship, $session);

        return response()->json([
            'message' => 'Session published. Assigned applicants were notified.',
            'session' => (new ApplicationStageSessionResource($session))->resolve($request),
        ]);
    }

    public function assign(
        AssignStageSessionApplicationsRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $validated = $request->validated();
        $assignments = $this->operations->assignApplications(
            $scholarship,
            $session,
            $request->user(),
            $validated['application_ids'],
            $validated['notes'] ?? null,
        );
        $this->log($request, 'application_stage_applicants_assigned', $scholarship, $session, [
            'assignment_count' => $assignments->count(),
        ]);

        return response()->json([
            'message' => "Assigned {$assignments->count()} applicant(s).",
            'assignment_ids' => $assignments->pluck('id')->values(),
        ], 201);
    }

    public function reschedule(
        RescheduleStageAssignmentRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
    ): JsonResponse {
        $validated = $request->validated();
        $target = $scholarship->stageSessions()->findOrFail($validated['target_session_id']);
        $replacement = $this->operations->rescheduleAssignment(
            $scholarship,
            $session,
            $assignment,
            $target,
            $request->user(),
            $validated['notes'],
        );
        $this->log($request, 'application_stage_applicant_rescheduled', $scholarship, $target, [
            'assignment_id' => $replacement->id,
            'previous_assignment_id' => $assignment->id,
        ]);

        return response()->json([
            'message' => 'Applicant moved to the replacement session.',
            'assignment_id' => (int) $replacement->id,
            'target_session_id' => (int) $target->id,
        ]);
    }

    public function attendance(
        RecordStageAttendanceRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
    ): JsonResponse {
        $validated = $request->validated();
        $attendance = $this->operations->recordAttendance(
            $scholarship,
            $session,
            $assignment,
            $request->user(),
            $validated['attendance_status'],
            $validated['notes'] ?? null,
        );
        $this->log($request, 'application_stage_attendance_recorded', $scholarship, $session, [
            'assignment_id' => $assignment->id,
            'attendance_status' => $attendance->status,
        ]);

        return response()->json([
            'message' => 'Attendance recorded. The application result was not changed.',
            'attendance' => [
                'id' => (int) $attendance->id,
                'status' => $attendance->status,
                'notes' => $attendance->notes,
                'recorded_at' => $attendance->recorded_at?->toIso8601String(),
            ],
        ]);
    }

    public function complete(
        Request $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $session = $this->operations->completeSession($scholarship, $session, $request->user());
        $this->log($request, 'application_stage_session_completed', $scholarship, $session);

        return response()->json([
            'message' => 'Session completed. Applicant outcomes must still be reviewed and recorded separately.',
            'session' => (new ApplicationStageSessionResource($session))->resolve($request),
        ]);
    }

    public function cancel(
        CancelStageSessionRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $session = $this->operations->cancelSession(
            $scholarship,
            $session,
            $request->user(),
            $request->validated('reason'),
        );
        $this->log($request, 'application_stage_session_cancelled', $scholarship, $session);

        return response()->json([
            'message' => 'Session cancelled. Assigned applicants were notified.',
            'session' => (new ApplicationStageSessionResource($session))->resolve($request),
        ]);
    }

    public function storeSessionEvidence(
        StoreStageEvidenceRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
    ): JsonResponse {
        $evidence = $this->operations->storeEvidence(
            $scholarship,
            $session,
            $request->user(),
            $request->file('evidence_file'),
            $request->safe()->except('evidence_file'),
        );
        $this->log($request, 'application_stage_evidence_uploaded', $scholarship, $session, [
            'evidence_id' => $evidence->id,
            'scope' => 'session',
        ]);

        return response()->json([
            'message' => 'Session proof uploaded.',
            'evidence' => (new ApplicationStageEvidenceResource($evidence))->resolve($request),
        ], 201);
    }

    public function storeAssignmentEvidence(
        StoreStageEvidenceRequest $request,
        Scholarship $scholarship,
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
    ): JsonResponse {
        $evidence = $this->operations->storeEvidence(
            $scholarship,
            $session,
            $request->user(),
            $request->file('evidence_file'),
            $request->safe()->except('evidence_file'),
            $assignment,
        );
        $this->log($request, 'application_stage_evidence_uploaded', $scholarship, $session, [
            'evidence_id' => $evidence->id,
            'scope' => 'applicant',
            'assignment_id' => $assignment->id,
        ]);

        return response()->json([
            'message' => 'Applicant proof uploaded.',
            'evidence' => (new ApplicationStageEvidenceResource($evidence))->resolve($request),
        ], 201);
    }

    public function viewEvidence(Request $request, ApplicationStageEvidence $evidence): StreamedResponse
    {
        $evidence->loadMissing('scholarship');
        $this->authorizeProgram($request, $evidence->scholarship);
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);

        return Storage::disk($evidence->disk)->response($evidence->path, $evidence->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeProgram(Request $request, Scholarship $scholarship): void
    {
        $actor = $request->user();

        abort_unless($actor?->isProvider(), 403);
        abort_unless($actor->hasPortalPermission('manage_selection_activities'), 403);
        abort_unless((int) $scholarship->provider_id === $actor->providerOrganizationId(), 403);
        abort_unless($actor->canAccessProviderProgram($scholarship), 403);
    }

    private function ensureSessionProgram(
        ApplicationStageSession $session,
        Scholarship $scholarship,
    ): void {
        abort_unless((int) $session->scholarship_id === (int) $scholarship->id, 404);
    }

    private function log(
        Request $request,
        string $action,
        Scholarship $scholarship,
        ApplicationStageSession $session,
        array $metadata = [],
    ): void {
        ActivityLog::record(
            $request->user(),
            $action,
            "{$request->user()->name} updated a ".(ApplicationStageCatalog::definitions()[$session->stage_key]['label'] ?? str($session->stage_key)->headline())." session for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_stage_session_id' => $session->id,
                ...$metadata,
            ],
        );
    }
}
