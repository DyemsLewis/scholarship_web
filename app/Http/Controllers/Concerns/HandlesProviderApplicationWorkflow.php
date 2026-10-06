<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use App\Services\AcademicRecordOcrService;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Services\ScholarshipEventService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationDecisionReason;
use App\Support\ApplicationSchedulePayload;
use App\Support\ReviewRubric;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait HandlesProviderApplicationWorkflow
{
    public function upsertScholarshipEvent(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'venue' => [
                Rule::requiredIf(in_array($request->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'online_url' => [
                Rule::requiredIf(
                    in_array($request->input('mode'), ['online', 'hybrid'], true)
                ),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'instructions' => ['required', 'string', 'max:3000'],
        ]);

        [$event, $audienceCount] = $this->persistScholarshipEvent(
            $scholarship,
            $validated,
            $request->user(),
        );
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at']);
        $eventLabel = ScholarshipSelectionPlan::label($validated['type']);

        ActivityLog::record(
            $request->user(),
            'scholarship_event_published',
            "{$request->user()->name} published the {$eventLabel} schedule for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'audience_count' => $audienceCount,
            ],
        );

        return response()->json([
            'message' => $audienceCount > 0
                ? ucfirst($eventLabel)." schedule published to {$audienceCount} eligible applicant(s)."
                : ucfirst($eventLabel).' schedule saved. Eligible applicants will receive it when they reach this stage.',
            'event' => ScholarshipEventPayload::make($event->fresh()),
            'audience_count' => $audienceCount,
        ]);
    }

    public function completeScholarshipEvent(
        Request $request,
        Scholarship $scholarship,
        ScholarshipEvent $event,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($event->scholarship_id === $scholarship->id, 404);

        if ($event->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'event' => 'This event cannot be completed before its scheduled date and time.',
            ]);
        }

        $participantCount = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('workflow_stage', $event->type)
            ->count();

        $completedAt = now();

        $event->update([
            'status' => 'completed',
            'updated_by' => $request->user()->id,
        ]);

        ApplicationSchedule::query()
            ->where('type', $event->type)
            ->where('status', 'scheduled')
            ->whereHas('application', fn (Builder $query) => $query
                ->where('scholarship_id', $scholarship->id)
                ->where('workflow_stage', $event->type))
            ->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'updated_by' => $request->user()->id,
                'updated_at' => $completedAt,
            ]);

        ActivityLog::record(
            $request->user(),
            'scholarship_event_completed',
            "{$request->user()->name} completed the {$event->type} event for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'participant_count' => $participantCount,
            ],
        );

        return response()->json([
            'message' => ucfirst(ScholarshipSelectionPlan::label($event->type)).' schedule archived. Applicant results are recorded independently.',
            'event' => ScholarshipEventPayload::make($event->fresh()),
            'participant_count' => $participantCount,
        ]);
    }

    public function bulkUpdateScholarshipEventAttendance(
        Request $request,
        Scholarship $scholarship,
        ScholarshipEvent $event,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($event->scholarship_id === $scholarship->id, 404);
        abort_unless(ScholarshipSelectionPlan::isSchedulable($event->type), 404);

        if ($event->status !== 'completed') {
            throw ValidationException::withMessages([
                'event' => 'Mark the '.ScholarshipSelectionPlan::label($event->type).' activity as completed before recording applicant results.',
            ]);
        }

        $validated = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['required', 'integer', 'distinct'],
            'attendance_status' => ['required', Rule::in(['passed', 'failed'])],
            'attendance_notes' => ['nullable', 'string', 'max:1500'],
        ]);
        $applicationIds = collect($validated['application_ids'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $applications = ScholarshipApplication::query()
            ->with(['applicant', 'scholarship', 'schedules'])
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('id', $applicationIds)
            ->get();

        if ($applications->count() !== $applicationIds->count()) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applicants do not belong to this program.',
            ]);
        }

        $invalidStageApplication = $applications->first(function (ScholarshipApplication $application) use ($event): bool {
            $workflow = $this->workflowService->payload($application);

            return $workflow['current_stage'] !== $event->type || $workflow['is_closed'];
        });

        if ($invalidStageApplication) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applicants are no longer waiting for this activity result. Refresh the list and try again.',
            ]);
        }

        $result = $validated['attendance_status'] === 'passed' ? 'passed' : 'not_passed';
        $updatedApplications = collect();

        DB::transaction(function () use ($applications, $event, $request, $validated, $result, $updatedApplications): void {
            foreach ($applications as $application) {
                $schedule = $application->schedules->firstWhere('type', $event->type);

                if ($schedule) {
                    $schedule->update([
                        'status' => 'completed',
                        'attendance_status' => $validated['attendance_status'],
                        'attendance_notes' => $validated['attendance_notes'] ?? null,
                        'completed_at' => now(),
                        'updated_by' => $request->user()->id,
                    ]);
                }

                $updatedApplications->push($this->workflowService->recordStageResult(
                    $application,
                    $event->type,
                    $result,
                    $request->user(),
                    $validated['attendance_notes'] ?? null,
                ));
            }
        });

        foreach ($updatedApplications as $freshApplication) {
            $freshApplication->loadMissing(['applicant', 'scholarship']);
            app(ScholarshipEventService::class)->syncApplication($freshApplication);
            $nextWorkflow = $this->workflowService->payload($freshApplication);
            $notification = $this->workflowStageNotification(
                $freshApplication,
                $event->type,
                $result,
                $nextWorkflow['current_stage_label'],
            );

            PortalNotification::create([
                'user_id' => $freshApplication->applicant_id,
                'type' => 'application_status',
                'title' => $notification['title'],
                'message' => $notification['message'],
                'action_url' => route('dashboard.applications.show', $freshApplication, false),
            ]);
            app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_bulk_schedule_tracking_updated');
        }

        ActivityLog::record(
            $request->user(),
            'application_schedule_bulk_tracking_updated',
            "{$request->user()->name} updated {$event->type} results for {$applications->count()} applicant(s).",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'result' => $result,
                'application_ids' => $applicationIds->all(),
            ],
        );

        return response()->json([
            'message' => "Updated {$applications->count()} applicant record(s).",
            'updated_count' => $applications->count(),
            'result' => $result,
            'attendance_status' => $validated['attendance_status'],
        ]);
    }

    public function decideApplication(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_reason' => [
                Rule::requiredIf($request->input('decision') === 'reject'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $workflow = $this->workflowService->payload($application);
        $stage = $workflow['current_stage'];

        $this->authorizeProviderStageAction($request->user(), $stage);

        if (! in_array($stage, ['screening', 'formal_application', 'exam', 'interview'], true)) {
            throw ValidationException::withMessages([
                'decision' => $stage === 'decision'
                    ? 'Record Selected, Waitlisted, or Not selected as the final outcome.'
                    : 'This application no longer needs a stage decision.',
            ]);
        }

        $request->merge([
            'result' => $validated['decision'] === 'approve' ? 'passed' : 'not_passed',
            'notes' => $validated['review_notes'] ?? null,
        ]);

        return $this->recordApplicationStageResult($request, $application, $stage);
    }

    public function recordApplicationStageResult(
        Request $request,
        ScholarshipApplication $application,
        string $stage,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(in_array($stage, ['screening', 'formal_application', 'exam', 'interview'], true), 404);
        $this->authorizeProviderStageAction($request->user(), $stage);

        $validated = $request->validate([
            'result' => ['required', Rule::in(ApplicationWorkflowService::RESULTS)],
            'decision_reason' => [
                Rule::requiredIf($request->input('result') === 'not_passed'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'notes' => ['nullable', 'string', 'max:1500'],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $notes = $validated['notes'] ?? $validated['review_notes'] ?? null;

        $this->ensureStageActivityCompleted($application, $stage);

        if ($stage === 'screening') {
            $rubric = $this->requireCompleteApplicationRubric($application, $validated['rubric_scores'] ?? null);

            if ($validated['result'] === 'passed') {
                $this->ensureApplicationDocumentsReadyForStatus($application, 'approved');
            }

            if ($rubric !== null) {
                $application->update([
                    'rubric_scores' => $rubric['scores'],
                    'rubric_total_score' => $rubric['total_score'],
                    'rubric_scored_by' => $request->user()->id,
                    'rubric_scored_at' => now(),
                ]);
            }
        }

        $previousStage = $this->workflowService->payload($application)['current_stage'];
        $updated = $this->workflowService->recordStageResult(
            $application,
            $stage,
            $validated['result'],
            $request->user(),
            $notes,
            $validated['decision_reason'] ?? null,
        );
        app(ScholarshipEventService::class)->syncApplication($updated);
        $updated = $updated->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'stageProgresses',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        $workflow = $this->workflowService->payload($updated);
        $notification = $this->workflowStageNotification(
            $updated,
            $previousStage,
            $validated['result'],
            $workflow['current_stage_label'],
        );

        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_status',
            'title' => $notification['title'],
            'message' => $notification['message'],
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_stage_result_recorded',
            "{$request->user()->name} recorded {$validated['result']} for {$stage} on application #{$updated->id}.",
            $request,
            ['application_id' => $updated->id, 'stage' => $stage, 'result' => $validated['result']],
        );
        ScholarshipFunnelEvent::record(
            $updated->applicant,
            "application_stage_{$stage}_{$validated['result']}",
            $updated->scholarship,
            $updated,
            'provider',
            [
                'stage' => $stage,
                'result' => $validated['result'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'canonical_decision_reason' => ApplicationDecisionReason::canonical($validated['decision_reason'] ?? null),
                'reviewed_by' => $request->user()->id,
                'rubric_total_score' => $updated->rubric_total_score,
            ],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_stage_result');

        return response()->json([
            'message' => $notification['title'].'.',
            'application' => $this->applicationPayload($updated, true),
            'review_navigation' => $this->reviewNavigationPayload($updated, $previousStage),
        ]);
    }

    public function recordApplicationFinalOutcome(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless($request->user()->hasPortalPermission('record_final_decisions'), 403);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(ApplicationWorkflowService::FINAL_OUTCOMES)],
            'decision_reason' => [
                Rule::requiredIf($request->input('outcome') === 'not_selected'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        if ($validated['outcome'] === 'selected') {
            $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'outcome');
        }

        $updated = $this->workflowService->recordFinalOutcome(
            $application,
            $validated['outcome'],
            $request->user(),
            $validated['notes'] ?? null,
            $validated['decision_reason'] ?? null,
        );

        if (array_key_exists('awarded_amount', $validated)) {
            $updated->update(['awarded_amount' => $validated['awarded_amount']]);
            $updated = $this->workflowService->refreshRecipientAgreementSnapshot($updated->fresh());
        }

        $updated = $updated->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'stageProgresses',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        $outcomeLabel = $this->workflowService->payload($updated)['final_outcome_label'];
        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_outcome',
            'title' => "Application outcome: {$outcomeLabel}",
            'message' => "The provider recorded {$outcomeLabel} as the final outcome for {$updated->scholarship?->title}. Open the application to review the details.",
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_final_outcome_recorded',
            "{$request->user()->name} recorded {$validated['outcome']} for application #{$updated->id}.",
            $request,
            ['application_id' => $updated->id, 'outcome' => $validated['outcome']],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_final_outcome');

        return response()->json([
            'message' => "Final outcome recorded as {$outcomeLabel}.",
            'application' => $this->applicationPayload($updated, true),
            'review_navigation' => $this->reviewNavigationPayload($updated, 'decision'),
        ]);
    }

    public function bulkAdvanceApplications(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:100'],
            'application_ids.*' => ['required', 'integer', 'distinct'],
            'target_stage' => ['required', Rule::in([
                'pass_prescreening',
                'pass_stage',
                'selected',
            ])],
        ]);
        $applicationIds = collect($validated['application_ids'])->map(fn ($id) => (int) $id)->values();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'schedules', 'scholarship.events'])
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('id', $applicationIds)
            ->orderBy('id')
            ->get();

        if ($applications->count() !== $applicationIds->unique()->count()) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applications do not belong to this program.',
            ]);
        }

        $targetStage = $validated['target_stage'];
        foreach ($applications as $application) {
            if ($targetStage === 'selected') {
                abort_unless($request->user()->hasPortalPermission('record_final_decisions'), 403);

                continue;
            }

            $this->authorizeProviderStageAction(
                $request->user(),
                $this->workflowService->payload($application)['current_stage'],
            );
        }
        $invalidApplications = $applications
            ->reject(function (ScholarshipApplication $application) use ($targetStage): bool {
                return in_array($targetStage, $this->bulkAdvanceTargets($application), true);
            });

        if ($invalidApplications->isNotEmpty()) {
            $applicantNames = $invalidApplications
                ->take(4)
                ->map(fn (ScholarshipApplication $application) => $application->applicant?->name ?: "Application #{$application->id}")
                ->implode(', ');
            $message = match ($targetStage) {
                'selected' => 'Only applicants at the final decision stage can be selected.',
                'pass_stage' => 'Only applicants at an active provider stage can be advanced.',
                default => 'Only pre-screening applications with accepted required files can be advanced.',
            };

            throw ValidationException::withMessages([
                'application_ids' => "{$message} Review: {$applicantNames}.",
            ]);
        }

        DB::transaction(function () use ($applications, $request, $scholarship, $targetStage): void {
            foreach ($applications as $application) {
                $workflow = $this->workflowService->payload($application);
                $stage = $workflow['current_stage'];

                if ($targetStage === 'selected') {
                    $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'application_ids');
                    $updated = $this->workflowService->recordFinalOutcome(
                        $application,
                        'selected',
                        $request->user(),
                        'Selected through the provider bulk decision list.',
                    );
                    $title = 'Application outcome: Selected';
                    $message = "The provider selected you for {$scholarship->title}. Open the application to review the result.";
                } else {
                    $updated = $this->workflowService->recordStageResult(
                        $application,
                        $stage,
                        'passed',
                        $request->user(),
                        'Passed through the provider bulk review list.',
                    );
                    $nextWorkflow = $this->workflowService->payload($updated);
                    $notification = $this->workflowStageNotification(
                        $updated,
                        $stage,
                        'passed',
                        $nextWorkflow['current_stage_label'],
                    );
                    $title = $notification['title'];
                    $message = $notification['message'];
                }

                app(ScholarshipEventService::class)->syncApplication($updated);

                PortalNotification::create([
                    'user_id' => $updated->applicant_id,
                    'type' => $targetStage === 'selected' ? 'application_outcome' : 'application_status',
                    'title' => $title,
                    'message' => $message,
                    'action_url' => route('dashboard.applications.show', $updated, false),
                ]);
                app(DecisionSupportService::class)->syncApplication($updated, 'provider_bulk_stage_result');
            }
        });

        ActivityLog::record(
            $request->user(),
            'applications_bulk_advanced',
            "{$request->user()->name} completed {$targetStage} for {$applications->count()} application(s).",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'target_stage' => $targetStage,
                'application_ids' => $applicationIds->all(),
            ],
        );

        $message = match ($targetStage) {
            'selected' => "Recorded {$applications->count()} selected recipient(s).",
            'pass_stage' => "Advanced {$applications->count()} applicant(s) to the next configured stage.",
            default => "Passed pre-screening for {$applications->count()} applicant(s).",
        };

        return response()->json([
            'message' => $message,
            'updated_count' => $applications->count(),
            'application_ids' => $applicationIds,
        ]);
    }

    public function assignApplicationReviewer(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission('verify_applications'), 403);

        $providerId = $request->user()->providerOrganizationId();

        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'assigned_reviewer_id' => ['nullable', 'integer'],
        ]);
        $reviewerId = $validated['assigned_reviewer_id'] ?? null;

        if ($reviewerId && (int) $reviewerId === (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'You cannot assign an application review to yourself.',
            ]);
        }

        $requestedReviewer = $reviewerId
            ? User::query()
                ->where('role', 'provider')
                ->where(function ($query) use ($providerId): void {
                    $query->whereKey($providerId)
                        ->orWhere('parent_account_id', $providerId);
                })
                ->find($reviewerId)
            : null;

        if ($requestedReviewer && $this->providerReviewerHasBroaderAccess($request->user(), $requestedReviewer)) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'You cannot assign a reviewer with broader permissions or program access than your own.',
            ]);
        }

        $reviewer = $reviewerId
            ? $this->providerApplicationReviewers($providerId, $request->user())->firstWhere('id', $reviewerId)
            : null;

        if ($reviewerId && ! $reviewer) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'Choose an active reviewer from this provider organization.',
            ]);
        }

        if ($reviewer && ! $reviewer->canAccessProviderProgram($application->scholarship)) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'This reviewer is not assigned to this program.',
            ]);
        }

        $previousReviewerId = $application->assigned_reviewer_id;
        $application->forceFill(['assigned_reviewer_id' => $reviewer?->id])->save();

        if ((int) $previousReviewerId !== (int) ($reviewer?->id)) {
            ActivityLog::record(
                $request->user(),
                'application_reviewer_assigned',
                $reviewer
                    ? "{$request->user()->name} assigned application #{$application->id} to {$reviewer->name}."
                    : "{$request->user()->name} removed the reviewer from application #{$application->id}.",
                $request,
                [
                    'application_id' => $application->id,
                    'previous_reviewer_id' => $previousReviewerId,
                    'assigned_reviewer_id' => $reviewer?->id,
                ],
            );

            if ($reviewer && $reviewer->id !== $request->user()->id) {
                PortalNotification::create([
                    'user_id' => $reviewer->id,
                    'type' => 'application_assignment',
                    'title' => 'Application assigned to you',
                    'message' => "Review {$application->applicant?->name}'s application for {$application->scholarship?->title}.",
                    'action_url' => route('provider.applications.show', $application, false),
                ]);
            }
        }

        $application = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'assignedReviewer.providerProfile',
            'statusHistories.actor',
            'scholarship' => fn ($query) => $query->withCount($this->providerProgramCountRelations()),
        ]);

        return response()->json([
            'message' => $reviewer ? 'Reviewer assigned.' : 'Application returned to the unassigned queue.',
            'application' => $this->applicationPayload($application),
        ]);
    }

    public function upsertApplicationSchedule(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'venue' => [
                Rule::requiredIf(in_array($request->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'online_url' => [
                Rule::requiredIf(
                    in_array($request->input('mode'), ['online', 'hybrid'], true)
                ),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'instructions' => ['required', 'string', 'max:3000'],
        ]);

        $application->loadMissing(['scholarship.events', 'applicant']);
        $this->ensureScheduleCanBePublished($application, $validated['type']);

        $eventLabel = $this->scheduleTypeLabel($validated['type']);
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at']);
        $usesVenue = in_array($validated['mode'], ['onsite', 'hybrid'], true);
        $usesOnlineLink = in_array($validated['mode'], ['online', 'hybrid'], true);
        $scheduleData = [
            'title' => filled($validated['title'] ?? null) ? trim($validated['title']) : "{$eventLabel} schedule",
            'scheduled_at' => $scheduledAt,
            'mode' => $validated['mode'],
            'venue' => $usesVenue ? ($validated['venue'] ?? null) : null,
            'location_address' => $usesVenue ? ($validated['location_address'] ?? null) : null,
            'latitude' => $usesVenue ? ($validated['latitude'] ?? null) : null,
            'longitude' => $usesVenue ? ($validated['longitude'] ?? null) : null,
            'online_url' => $usesOnlineLink ? ($validated['online_url'] ?? null) : null,
            'instructions' => $validated['instructions'],
            'status' => 'scheduled',
            'attendance_status' => 'not_required',
            'attendance_notes' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'updated_by' => $request->user()->id,
        ];

        [$schedule, $announcementChanged] = DB::transaction(function () use (
            $application,
            $request,
            $validated,
            $scheduleData,
        ): array {
            $lockedApplication = ScholarshipApplication::query()
                ->with(['scholarship', 'stageProgresses'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureScheduleCanBePublished($lockedApplication, $validated['type']);
            $schedule = $lockedApplication->schedules()->where('type', $validated['type'])->first();

            if ($schedule) {
                $schedule->update($scheduleData);
            } else {
                $schedule = $lockedApplication->schedules()->create([
                    ...$scheduleData,
                    'type' => $validated['type'],
                    'created_by' => $request->user()->id,
                ]);
            }

            $announcementChanged = $schedule->wasRecentlyCreated || $schedule->wasChanged([
                'title',
                'scheduled_at',
                'mode',
                'venue',
                'location_address',
                'latitude',
                'longitude',
                'online_url',
                'instructions',
                'status',
            ]);

            return [$schedule, $announcementChanged];
        });

        ActivityLog::record(
            $request->user(),
            'application_schedule_published',
            "{$request->user()->name} published the {$eventLabel} schedule for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'schedule_type' => $schedule->type,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ],
        );

        if ($announcementChanged) {
            $destination = $schedule->mode === 'online'
                ? ' online'
                : ' at '.($schedule->venue ?: $schedule->location_address ?: 'the provider location');

            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => "{$eventLabel} schedule posted",
                'message' => "Your {$eventLabel} for {$application->scholarship?->title} is scheduled for {$scheduledAt->format('M d, Y h:i A')}{$destination}. Open the application to review the schedule details.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_published');

        return response()->json([
            'message' => "{$eventLabel} schedule published and the applicant was notified.",
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function updateApplicationScheduleTracking(
        Request $request,
        ScholarshipApplication $application,
        ApplicationSchedule $schedule,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless($schedule->scholarship_application_id === $application->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'attendance_status' => ['nullable', Rule::in(['pending', 'not_required'])],
            'attendance_notes' => ['nullable', 'string', 'max:1500'],
        ]);

        if ($validated['status'] === 'completed' && $schedule->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'status' => 'This activity cannot be marked complete before its scheduled date and time.',
            ]);
        }

        $previousScheduleStatus = $schedule->status;
        $schedule->update([
            'status' => $validated['status'],
            'attendance_status' => 'not_required',
            'attendance_notes' => $validated['attendance_notes'] ?? null,
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
            'cancelled_at' => $validated['status'] === 'cancelled' ? now() : null,
            'updated_by' => $request->user()->id,
        ]);
        $trackingChanged = $previousScheduleStatus !== $schedule->status
            || $schedule->wasChanged('attendance_notes');

        ActivityLog::record(
            $request->user(),
            'application_schedule_tracking_updated',
            "{$request->user()->name} updated {$schedule->type} tracking for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'status' => $schedule->status,
                'application_stage_unchanged' => true,
            ],
        );

        if ($trackingChanged) {
            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => $this->scheduleTypeLabel($schedule->type).' schedule updated',
                'message' => "The provider updated your {$this->scheduleTypeLabel($schedule->type)} schedule to {$schedule->status}. Open the application to review the details.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);

        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_tracking_updated');

        return response()->json([
            'message' => 'Schedule record updated. The applicant stage was not changed.',
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function viewApplicantProfileProof(
        Request $request,
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless($document->applicant_id === $application->applicant_id, 403);
        abort_unless(in_array($document->document_type, ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES, true), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewApplicantProfilePhoto(Request $request, ScholarshipApplication $application)
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $profile = $application->applicant?->studentProfile;
        abort_unless($profile?->profile_photo_path, 404);
        abort_unless(Storage::disk('local')->exists($profile->profile_photo_path), 404);

        return Storage::disk('local')->response(
            $profile->profile_photo_path,
            $profile->profile_photo_original_name ?: 'applicant-photo',
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function reviewApplicantProfilePhoto(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $applicant = $application->applicant;
        abort_unless($applicant?->isApplicant(), 404);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'request_replacement'])],
            'reason' => [
                Rule::requiredIf($request->input('action') === 'request_replacement'),
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);
        $profile = $applicant->studentProfile()->firstOrCreate(['user_id' => $applicant->id]);

        if ($validated['action'] === 'approve' && blank($profile->profile_photo_path)) {
            return response()->json(['message' => 'The applicant has not uploaded a 2x2 photo yet.'], 422);
        }

        $needsReplacement = $validated['action'] === 'request_replacement';
        $profile->update([
            'profile_photo_review_status' => $needsReplacement ? 'needs_replacement' : 'approved',
            'profile_photo_review_note' => $needsReplacement ? $validated['reason'] : null,
            'profile_photo_reviewed_by' => $request->user()->id,
            'profile_photo_reviewed_at' => now(),
            'profile_photo_review_application_id' => $application->id,
        ]);

        $providerOwner = $request->user()->providerOrganizationOwner();
        $providerName = $providerOwner->provider_name ?: $providerOwner->name;
        PortalNotification::updateOrCreate(
            [
                'user_id' => $applicant->id,
                'deduplication_key' => "profile-photo-review:{$applicant->id}",
            ],
            [
                'type' => 'profile_photo_review',
                'title' => $needsReplacement ? 'Replace your applicant photo' : 'Applicant photo reviewed',
                'message' => $needsReplacement
                    ? "{$providerName} requested a new 2x2 photo for your {$application->scholarship->title} application. Reason: {$validated['reason']}"
                    : "{$providerName} checked your applicant photo for {$application->scholarship->title}.",
                'action_url' => $needsReplacement ? '/dashboard/profile?section=personal&photo=replace' : route('dashboard.applications.show', $application, false),
                'read_at' => null,
            ],
        );

        ActivityLog::record(
            $request->user(),
            $needsReplacement ? 'applicant_profile_photo_replacement_requested' : 'applicant_profile_photo_approved',
            $needsReplacement
                ? "{$request->user()->name} requested a replacement 2x2 photo for application #{$application->id}."
                : "{$request->user()->name} approved the 2x2 photo for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'applicant_id' => $applicant->id,
                'photo_review_status' => $profile->profile_photo_review_status,
                'reason' => $profile->profile_photo_review_note,
            ],
        );

        return response()->json([
            'message' => $needsReplacement
                ? 'The applicant was asked to upload a new 2x2 photo.'
                : 'The applicant photo was marked as acceptable.',
            'application' => $this->freshApplicationPayload($application),
        ]);
    }

    public function verifyApplicantProfile(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $applicant = $application->applicant;
        abort_unless($applicant?->isApplicant(), 404);

        $reviewedScale = $request->input('academic_grading_scale');
        $reviewedResultIsNumeric = AcademicRequirement::requiresNumeric($reviewedScale);
        $validated = $request->validate([
            'academic_grading_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'academic_result' => [
                Rule::requiredIf($reviewedResultIsNumeric),
                'nullable',
                'numeric',
                'gt:0',
                $reviewedScale === AcademicRequirement::SCALE_GRADE_POINT ? 'max:5' : 'max:100',
            ],
        ]);
        $reviewedAcademicResult = filled($validated['academic_grading_scale'] ?? null)
            ? [
                'grading_scale' => $validated['academic_grading_scale'],
                'gwa' => $reviewedResultIsNumeric ? (float) $validated['academic_result'] : null,
            ]
            : null;

        $verificationStatus = $applicant->applicantAcademicVerificationStatus();

        if ($verificationStatus === 'approved') {
            return response()->json([
                'message' => 'The applicant academic record is already verified.',
                'application' => $this->freshApplicationPayload($application),
            ]);
        }

        if ($verificationStatus === 'rejected') {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must replace the rejected academic record before it can be verified.',
            ]);
        }

        if ($verificationStatus === 'pending' && filled($applicant->studentProfile?->verification_notes)) {
            throw ValidationException::withMessages([
                'verification' => 'An administrator reopened this verification. Wait for a replacement academic record or an admin decision before verifying it again.',
            ]);
        }

        if (! $applicant->applicantVerificationDocuments()
            ->where('document_type', 'academic_record')
            ->exists()) {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must upload an academic record before it can be verified.',
            ]);
        }

        if (filled($applicant->studentProfile?->achievements)
            && ! $applicant->applicantVerificationDocuments()->where('document_type', 'achievement_evidence')->exists()) {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must upload evidence for the listed achievement before the profile can be verified.',
            ]);
        }

        if ($this->academicRecordOcrService->configured()) {
            $academicRecord = $applicant->applicantVerificationDocuments
                ->firstWhere('document_type', 'academic_record');

            if ($academicRecord?->ocr_status !== AcademicRecordOcrService::STATUS_SUCCEEDED && $reviewedAcademicResult === null) {
                throw ValidationException::withMessages([
                    'verification' => 'The scan did not produce a usable result. Enter the verified academic result from the uploaded record before approving it.',
                ]);
            }
        }

        if (! $applicant->applicantVerificationDocuments()
            ->where('document_type', 'recent_school_id')
            ->exists()) {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must upload a recent school ID before the profile can be verified.',
            ]);
        }

        $academicRecord ??= $applicant->applicantVerificationDocuments
            ->firstWhere('document_type', 'academic_record');
        $previousAcademicResult = [
            'grading_scale' => $applicant->studentProfile?->grading_scale,
            'gwa' => $applicant->studentProfile?->gwa !== null
                ? (float) $applicant->studentProfile->gwa
                : null,
        ];
        $academicResultCorrected = $reviewedAcademicResult !== null
            && (
                $reviewedAcademicResult['grading_scale'] !== $previousAcademicResult['grading_scale']
                || $reviewedAcademicResult['gwa'] !== $previousAcademicResult['gwa']
                || $academicRecord?->ocr_status !== AcademicRecordOcrService::STATUS_SUCCEEDED
            );

        DB::transaction(function () use ($applicant, $request, $reviewedAcademicResult, $academicResultCorrected): void {
            $profileUpdates = [
                'verification_status' => 'approved',
                'verification_notes' => null,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ];

            if ($academicResultCorrected) {
                $profileUpdates = array_merge($profileUpdates, $reviewedAcademicResult, [
                    'academic_result_source' => 'provider_review',
                    'academic_result_extracted_at' => now(),
                ]);
            }

            $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], $profileUpdates);

            $applicant->applicantVerificationDocuments()
                ->whereIn('document_type', ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES)
                ->update([
                    'status' => 'approved',
                    'review_notes' => null,
                ]);
        });

        $providerOwner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $providerName = $providerOwner->providerProfile?->provider_name ?: $request->user()->name;

        ActivityLog::record(
            $request->user(),
            'applicant_profile_verified_by_provider',
            "{$request->user()->name} verified applicant {$applicant->name}'s academic record for {$application->scholarship?->title}.",
            $request,
            [
                'applicant_id' => $applicant->id,
                'application_id' => $application->id,
                'scholarship_id' => $application->scholarship_id,
                'provider_id' => $request->user()->providerOrganizationId(),
                'academic_result_corrected' => $academicResultCorrected,
                'previous_academic_result' => $previousAcademicResult,
                'verified_academic_result' => $academicResultCorrected ? $reviewedAcademicResult : null,
            ],
        );

        if ($academicResultCorrected) {
            ScholarshipApplication::query()
                ->where('applicant_id', $applicant->id)
                ->where(fn ($query) => $query
                    ->whereNull('application_state')
                    ->orWhereNotIn('application_state', ['closed', 'withdrawn']))
                ->get()
                ->each(fn (ScholarshipApplication $activeApplication) => app(DecisionSupportService::class)
                    ->syncApplication($activeApplication, 'provider_academic_result_corrected'));
        }

        PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'applicant_profile_verification',
            'title' => 'Academic record verified',
            'message' => "{$providerName} verified your academic record while reviewing your application for {$application->scholarship?->title}.",
            'action_url' => '/dashboard/profile',
        ]);

        return response()->json([
            'message' => 'Applicant academic record verified. Application approval remains a separate review decision.',
            'application' => $this->freshApplicationPayload($application),
        ]);
    }

    public function handleApplicationCorrection(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['request', 'resolve'])],
            'message' => [Rule::requiredIf($request->input('action') === 'request'), 'nullable', 'string', 'min:5', 'max:1500'],
            'targets' => ['sometimes', 'array', 'min:1', 'max:5'],
            'targets.*' => ['required', 'string', 'distinct', Rule::in(ApplicationWorkflowService::CORRECTION_TARGETS)],
        ]);
        $isRequest = $validated['action'] === 'request';
        $application = $isRequest
            ? $this->workflowService->requestCorrection(
                $application,
                $request->user(),
                $validated['message'],
                $validated['targets'] ?? ['other'],
            )
            : $this->workflowService->resolveCorrection($application);

        $application->loadMissing(['applicant', 'scholarship']);
        $targetLabels = collect($application->correction_targets ?? [])
            ->map(fn (string $target): string => $this->correctionTargetLabel($target))
            ->implode(', ');
        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'application_correction',
            'title' => $isRequest ? 'Application correction requested' : 'Application correction accepted',
            'message' => $isRequest
                ? "The provider requested an update for your {$application->scholarship->title} application".($targetLabels ? ": {$targetLabels}." : '.')
                : "The provider accepted your correction for {$application->scholarship->title}. You can continue from the current application stage.",
            'action_url' => route('dashboard.applications.show', $application, false),
        ]);
        ActivityLog::record(
            $request->user(),
            $isRequest ? 'application_correction_requested' : 'application_correction_resolved',
            $isRequest
                ? "{$request->user()->name} requested a correction for application #{$application->id}."
                : "{$request->user()->name} resolved the correction for application #{$application->id}.",
            $request,
            ['application_id' => $application->id],
        );

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship.events',
        ]);

        return response()->json([
            'message' => $isRequest ? 'Correction request sent to the applicant.' : 'Correction marked as resolved.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function handleApplicationWaitlist(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['waitlist', 'promote', 'restore'])],
            'note' => ['nullable', 'string', 'max:1500'],
        ]);

        $workflow = $this->workflowService->payload($application);

        if ($validated['action'] === 'waitlist' && $workflow['current_stage'] !== 'decision') {
            throw ValidationException::withMessages([
                'action' => 'Complete the configured provider stages before placing an applicant on the waitlist.',
            ]);
        }

        if (in_array($validated['action'], ['promote', 'restore'], true) && $workflow['final_outcome'] !== 'waitlisted') {
            throw ValidationException::withMessages([
                'action' => 'Only a waitlisted applicant can use this action.',
            ]);
        }

        if ($validated['action'] === 'restore') {
            $updated = $this->workflowService->restoreWaitlistedForDecision(
                $application,
                $request->user(),
                $validated['note'] ?? null,
            );
            $message = 'Applicant returned to final decision review.';
        } else {
            if ($validated['action'] === 'promote') {
                $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'action');
            }

            $updated = $this->workflowService->recordFinalOutcome(
                $application,
                $validated['action'] === 'promote' ? 'selected' : 'waitlisted',
                $request->user(),
                $validated['note'] ?? null,
            );
            $message = $validated['action'] === 'promote'
                ? 'Waitlisted applicant marked as selected.'
                : 'Applicant added to the waitlist.';
        }

        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_outcome',
            'title' => 'Application outcome updated',
            'message' => $message,
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_waitlist_updated',
            "{$request->user()->name} completed {$validated['action']} for application #{$updated->id}.",
            $request,
            [
                'application_id' => $updated->id,
                'action' => $validated['action'],
                'final_outcome' => $updated->final_outcome,
            ],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_waitlist_updated');

        return response()->json([
            'message' => $message,
            'application' => $this->applicationPayload($updated->fresh(), true),
        ]);
    }

    public function updateApplicationStatus(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $outcomeStatuses = ['awarded', 'not_awarded', 'disbursed', 'renewed'];
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'submitted',
                'under_review',
                'qualified',
                'shortlisted',
                'interview',
                'exam_qualified',
                'exam_scheduled',
                'exam_taken',
                'exam_passed',
                'exam_failed',
                'interview_failed',
                'approved',
                'waitlisted',
                'awarded',
                'distribution_scheduled',
                'not_awarded',
                'disbursed',
                'renewed',
                'benefits_terminated',
                'rejected',
            ])],
            'decision_reason' => [
                Rule::requiredIf(ApplicationDecisionReason::requiredForStatus($request->input('status'))),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'outcome_notes' => [
                Rule::requiredIf($request->input('status') === 'benefits_terminated'),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'outcome_at' => ['nullable', 'date'],
            'distribution_scheduled_for' => $request->input('status') === 'distribution_scheduled'
                ? ['required', 'date', 'after_or_equal:today']
                : ['nullable', 'date'],
            'distribution_instructions' => ['nullable', 'string', 'max:2000'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $workflow = $this->workflowService->payload($application);
        $application->refresh();
        $previousStatus = $application->status;
        $isPostSelectionUpdate = $workflow['final_outcome'] === 'selected'
            && match ($previousStatus) {
                'awarded' => in_array($validated['status'], ['distribution_scheduled', 'benefits_terminated'], true),
                'distribution_scheduled' => in_array($validated['status'], ['disbursed', 'benefits_terminated'], true),
                'disbursed' => in_array($validated['status'], ['renewed', 'benefits_terminated'], true),
                'renewed' => $validated['status'] === 'benefits_terminated',
                default => false,
            };

        if ($previousStatus !== $validated['status'] && ! $isPostSelectionUpdate) {
            throw ValidationException::withMessages([
                'status' => 'Use the current stage result or final outcome action so configured stages cannot be skipped.',
            ]);
        }

        $isOutcomeStatus = in_array($validated['status'], $outcomeStatuses, true);

        $requiredRubricResult = $request->attributes->get('provider_decision_validated', false)
            || array_key_exists('rubric_scores', $validated)
                ? $this->requireCompleteApplicationRubric($application, $validated['rubric_scores'] ?? null)
                : null;

        $decisionReason = array_key_exists('decision_reason', $validated)
            ? $validated['decision_reason']
            : $application->decision_reason;
        $reviewNotes = array_key_exists('review_notes', $validated)
            ? $validated['review_notes']
            : $application->review_notes;
        $outcomeNotes = array_key_exists('outcome_notes', $validated)
            ? $validated['outcome_notes']
            : $application->outcome_notes;
        $distributionScheduledFor = array_key_exists('distribution_scheduled_for', $validated)
            ? $validated['distribution_scheduled_for']
            : $application->distribution_scheduled_for?->toDateString();
        $distributionInstructions = array_key_exists('distribution_instructions', $validated)
            ? $validated['distribution_instructions']
            : $application->distribution_instructions;
        $waitlistPosition = $validated['status'] === 'waitlisted'
            ? ($application->waitlist_position ?: ((int) ScholarshipApplication::query()
                ->where('scholarship_id', $application->scholarship_id)
                ->where('status', 'waitlisted')
                ->max('waitlist_position') + 1))
            : null;

        if ($validated['status'] === 'distribution_scheduled'
            && ! in_array($previousStatus, ['awarded', 'distribution_scheduled'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Record the applicant as an award recipient before scheduling distribution.',
            ]);
        }

        if ($validated['status'] === 'disbursed') {
            if ($previousStatus !== 'distribution_scheduled' || blank($distributionScheduledFor)) {
                throw ValidationException::withMessages([
                    'status' => 'Schedule reward distribution before marking it as distributed.',
                ]);
            }

            if ($distributionScheduledFor > now()->toDateString()) {
                throw ValidationException::withMessages([
                    'status' => 'Reward distribution cannot be marked complete before its scheduled date.',
                ]);
            }
        }

        $outcomeAt = array_key_exists('outcome_at', $validated)
            ? $validated['outcome_at']
            : ($isOutcomeStatus && $previousStatus !== $validated['status'] ? now() : $application->outcome_at);
        $rubricResult = $requiredRubricResult;
        $applicantFacingChanged = $previousStatus !== $validated['status']
            || $this->comparableScholarshipValue($application->decision_reason) !== $this->comparableScholarshipValue($decisionReason)
            || $this->comparableScholarshipValue($application->awarded_amount) !== $this->comparableScholarshipValue($validated['awarded_amount'] ?? $application->awarded_amount)
            || $this->comparableScholarshipValue($application->outcome_notes) !== $this->comparableScholarshipValue($outcomeNotes)
            || $this->comparableScholarshipValue($application->outcome_at) !== $this->comparableScholarshipValue($outcomeAt)
            || $this->comparableScholarshipValue($application->distribution_scheduled_for) !== $this->comparableScholarshipValue($distributionScheduledFor)
            || $this->comparableScholarshipValue($application->distribution_instructions) !== $this->comparableScholarshipValue($distributionInstructions);
        $reviewNoteChanged = $this->comparableScholarshipValue($application->review_notes)
            !== $this->comparableScholarshipValue($reviewNotes);

        $applicationUpdate = [
            'status' => $validated['status'],
            'decision_reason' => $decisionReason,
            'review_notes' => $reviewNotes,
            'awarded_amount' => array_key_exists('awarded_amount', $validated) ? $validated['awarded_amount'] : $application->awarded_amount,
            'outcome_notes' => $outcomeNotes,
            'outcome_at' => $outcomeAt,
            'distribution_scheduled_for' => $distributionScheduledFor,
            'distribution_instructions' => $distributionInstructions,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rubric_scores' => $rubricResult ? $rubricResult['scores'] : $application->rubric_scores,
            'rubric_total_score' => $rubricResult ? $rubricResult['total_score'] : $application->rubric_total_score,
            'rubric_scored_by' => $rubricResult ? $request->user()->id : $application->rubric_scored_by,
            'rubric_scored_at' => $rubricResult && $rubricResult['completed'] > 0 ? now() : $application->rubric_scored_at,
            'waitlist_position' => $waitlistPosition,
            'waitlisted_at' => $validated['status'] === 'waitlisted'
                ? ($application->waitlisted_at ?? now())
                : null,
        ];

        DB::transaction(function () use (
            $application,
            $applicationUpdate,
            $previousStatus,
            $validated,
            $applicantFacingChanged,
            $reviewNoteChanged,
            $request,
        ): void {
            $currentStatus = ScholarshipApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->value('status');

            if ($currentStatus !== $previousStatus) {
                throw ValidationException::withMessages([
                    'status' => 'This application changed during review. Refresh the page before recording another decision.',
                ]);
            }

            $this->ensureScholarshipAwardSlotAvailable(
                $application,
                $previousStatus,
                $validated['status'],
            );
            $application->update($applicationUpdate);

            if ($applicantFacingChanged || $reviewNoteChanged) {
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $previousStatus,
                    'to_status' => $validated['status'],
                    'decision_reason' => $validated['decision_reason'] ?? null,
                    'review_notes' => $validated['status'] === 'benefits_terminated'
                        ? ($validated['outcome_notes'] ?? null)
                        : ($validated['review_notes'] ?? null),
                    'changed_at' => now(),
                ]);
            }
        });

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $application->applicant,
                "application_status_{$validated['status']}",
                $application->scholarship,
                $application,
                'provider',
                [
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'decision_reason' => $decisionReason,
                    'canonical_decision_reason' => ApplicationDecisionReason::canonical($decisionReason),
                    'awarded_amount' => $application->awarded_amount,
                    'reviewed_by' => $request->user()->id,
                    'rubric_total_score' => $application->rubric_total_score,
                ],
            );
        }

        ActivityLog::record(
            $request->user(),
            'application_status_updated',
            "{$request->user()->name} updated application #{$application->id} to {$validated['status']}.",
            $request,
            [
                'application_id' => $application->id,
                'status' => $validated['status'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'distribution_scheduled_for' => $distributionScheduledFor,
                'rubric_total_score' => $rubricResult['total_score'] ?? null,
            ],
        );

        if ($applicantFacingChanged) {
            PortalNotification::create(array_merge(
                ['user_id' => $application->applicant_id],
                $this->applicationStatusNotificationPayload($application, $validated['status'], $decisionReason, $isOutcomeStatus),
            ));
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        app(ScholarshipEventService::class)->syncApplication($freshApplication);
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_status_updated');

        return response()->json([
            'message' => $applicantFacingChanged ? 'Application status updated.' : 'Provider review saved.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    private function ensureScholarshipAwardSlotAvailable(
        ScholarshipApplication $application,
        string $previousStatus,
        string $nextStatus,
        string $errorKey = 'status',
    ): void {
        if (! in_array($nextStatus, self::AWARD_SLOT_STATUSES, true)
            || in_array($previousStatus, self::AWARD_SLOT_STATUSES, true)) {
            return;
        }

        $scholarship = Scholarship::query()
            ->whereKey($application->scholarship_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($scholarship->slots_available === null) {
            return;
        }

        $occupiedSlots = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('id', '!=', $application->id)
            ->whereIn('status', self::AWARD_SLOT_STATUSES)
            ->count();

        if ($occupiedSlots >= $scholarship->slots_available) {
            throw ValidationException::withMessages([
                $errorKey => 'All available award slots have already been filled. Increase the program slots or review an existing award before recording another recipient.',
            ]);
        }
    }

    private function ensureApplicationStatusTransition(
        ScholarshipApplication $application,
        string $nextStatus,
    ): void {
        $currentStatus = $application->status;
        $selectionStages = ScholarshipSelectionPlan::normalize($application->scholarship?->selection_stages);
        $approvalStatus = ScholarshipSelectionPlan::nextApprovalStatus($currentStatus, $selectionStages);
        $rejectionStatus = ScholarshipSelectionPlan::rejectionStatus($currentStatus);
        $allowed = match ($currentStatus) {
            'submitted' => array_filter(['under_review', $approvalStatus, $rejectionStatus]),
            'under_review' => array_filter(['qualified', 'shortlisted', $approvalStatus, $rejectionStatus]),
            'qualified' => array_filter(['shortlisted', $approvalStatus, $rejectionStatus]),
            'shortlisted' => array_filter([$approvalStatus, $rejectionStatus]),
            'exam_qualified' => ['exam_scheduled', 'exam_failed'],
            'exam_scheduled' => ['exam_taken', 'exam_failed'],
            'exam_taken' => array_filter(['exam_passed', $approvalStatus, $rejectionStatus]),
            'exam_passed' => array_filter([$approvalStatus, $rejectionStatus]),
            'interview' => ['approved', 'interview_failed'],
            'approved' => ['waitlisted', 'awarded', 'not_awarded'],
            'waitlisted' => ['approved', 'awarded', 'not_awarded'],
            'awarded' => ['distribution_scheduled', 'benefits_terminated'],
            'distribution_scheduled' => ['disbursed', 'benefits_terminated'],
            'disbursed' => ['renewed', 'benefits_terminated'],
            'renewed' => ['benefits_terminated'],
            default => [],
        };

        if (! in_array($nextStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "The application cannot move from {$this->statusLabel($currentStatus)} to {$this->statusLabel($nextStatus)}.",
            ]);
        }
    }

    private function ensureApplicationDocumentsReadyForStatus(
        ScholarshipApplication $application,
        string $nextStatus,
    ): void {
        $statusesRequiringAcceptedDocuments = [
            'qualified',
            'shortlisted',
            'exam_qualified',
            'exam_scheduled',
            'exam_taken',
            'exam_passed',
            'interview',
            'approved',
            'waitlisted',
            'awarded',
            'distribution_scheduled',
            'disbursed',
            'renewed',
        ];

        if (! in_array($nextStatus, $statusesRequiringAcceptedDocuments, true)) {
            return;
        }

        $readiness = app(ScholarshipEligibilityService::class)
            ->applicationDocumentReadiness($application);

        if ($readiness['ready']) {
            return;
        }

        $message = match (true) {
            $readiness['missing'] !== [] => 'The applicant must upload every required file before this application can advance.',
            $readiness['needs_attention'] !== [] => 'Resolve every rejected or replacement document before this application can advance.',
            default => 'Review and accept every required document before this application can advance.',
        };

        throw ValidationException::withMessages([
            'status' => $message,
        ]);
    }

    public function updateDocumentStatus(Request $request, ApplicationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $document->load('application.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($document->application?->scholarship), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'accepted', 'rejected', 'needs_replacement'])],
            'review_notes' => [Rule::requiredIf(in_array($request->input('status'), ['rejected', 'needs_replacement'], true)), 'nullable', 'string', 'max:1000'],
        ]);

        [$document, $previousStatus] = DB::transaction(function () use ($document, $validated, $request): array {
            $lockedApplication = ScholarshipApplication::query()
                ->with(['applicant', 'scholarship', 'stageProgresses'])
                ->whereKey($document->scholarship_application_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->workflowService->payload($lockedApplication)['is_closed']) {
                throw ValidationException::withMessages([
                    'status' => 'Document decisions are locked after the application is closed.',
                ]);
            }

            $lockedDocument = ApplicationDocument::query()
                ->whereKey($document->id)
                ->where('scholarship_application_id', $lockedApplication->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $lockedDocument->status;
            $lockedDocument->update([
                'status' => $validated['status'],
                'review_notes' => $validated['review_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $lockedDocument->setRelation('application', $lockedApplication);

            return [$lockedDocument, $previousStatus];
        });

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $document->application->applicant,
                "application_document_{$validated['status']}",
                $document->application->scholarship,
                $document->application,
                'provider',
                [
                    'document_id' => $document->id,
                    'document_name' => $document->document_name,
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'reviewed_by' => $request->user()->id,
                ],
            );
        }

        ActivityLog::record(
            $request->user(),
            'document_status_updated',
            "{$request->user()->name} marked {$document->document_name} as {$validated['status']} for application #{$document->application?->id}.",
            $request,
            [
                'application_id' => $document->application?->id,
                'document_id' => $document->id,
                'document_status' => $validated['status'],
            ],
        );

        $documentMessage = "{$document->document_name} was marked {$this->statusLabel($validated['status'])}.";

        if (in_array($validated['status'], ['rejected', 'needs_replacement'], true)) {
            $documentMessage .= " Reason: {$validated['review_notes']}";
        }

        PortalNotification::create([
            'user_id' => $document->application->applicant_id,
            'type' => 'document_review',
            'title' => 'Document review updated',
            'message' => $documentMessage,
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $document->application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_document_reviewed');

        return response()->json([
            'message' => 'Document status updated.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }






















    private function bulkAdvanceTargets(ScholarshipApplication $application, ?array $readiness = null): array
    {
        if (in_array($application->correction_status, ['requested', 'submitted'], true)) {
            return [];
        }

        $readiness ??= $this->documentReadiness($application);

        if (! ($readiness['ready'] ?? false)) {
            return [];
        }

        $workflow = $this->workflowService->payload($application);

        if ($workflow['current_stage'] === 'screening') {
            return ['pass_prescreening'];
        }

        if (ScholarshipSelectionPlan::isSchedulable($workflow['current_stage'])) {
            return $this->stageActivityIsComplete($application, $workflow['current_stage'])
                ? ['pass_stage']
                : [];
        }

        return $workflow['current_stage'] === 'decision' ? ['selected'] : [];
    }

    private function applicantProfileProofPayload(
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ): array {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'ocr_status' => $document->ocr_status ?? AcademicRecordOcrService::STATUS_NOT_REQUESTED,
            'ocr_provider' => $document->ocr_provider,
            'ocr_grade' => $document->ocr_grade,
            'ocr_grading_scale' => $document->ocr_grading_scale,
            'ocr_label' => $document->ocr_label,
            'ocr_message' => $document->ocr_message,
            'ocr_processed_at' => $document->ocr_processed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('provider.applications.profile-proofs.view', [$application, $document]),
        ];
    }


    private function ensureScheduleCanBePublished(ScholarshipApplication $application, string $type): void
    {
        $workflow = $this->workflowService->payload($application);

        if (! ScholarshipSelectionPlan::isSchedulable($type) || $workflow['current_stage'] !== $type) {
            throw ValidationException::withMessages([
                'type' => 'This applicant is not currently at that scheduled stage.',
            ]);
        }
    }

    private function stageActivityIsComplete(ScholarshipApplication $application, string $stage): bool
    {
        if (! ScholarshipSelectionPlan::isSchedulable($stage)) {
            return true;
        }

        if ($application->relationLoaded('schedules')) {
            $stageSchedules = $application->schedules->where('type', $stage);

            // Existing formal handoffs did not require a dated schedule. Once one is
            // published, it follows the same completion rule as exams and interviews.
            if ($stage === 'formal_application' && $stageSchedules->isEmpty()) {
                return true;
            }

            return $stageSchedules->contains(fn (ApplicationSchedule $schedule): bool => (
                $schedule->type === $stage && $schedule->status === 'completed'
            ));
        }

        $stageSchedules = $application->schedules()->where('type', $stage);

        if ($stage === 'formal_application' && ! (clone $stageSchedules)->exists()) {
            return true;
        }

        return $stageSchedules
            ->where('type', $stage)
            ->where('status', 'completed')
            ->exists();
    }

    private function ensureStageActivityCompleted(ScholarshipApplication $application, string $stage): void
    {
        if ($this->stageActivityIsComplete($application, $stage)) {
            return;
        }

        throw ValidationException::withMessages([
            'result' => 'Mark the '.ScholarshipSelectionPlan::label($stage).' activity as completed before recording an applicant result.',
        ]);
    }

    private function requireCompleteApplicationRubric(
        ScholarshipApplication $application,
        ?array $scores,
    ): ?array {
        $rubric = $application->review_rubric_snapshot
            ?: ($application->scholarship?->review_rubric ?? []);

        if ($rubric === []) {
            return null;
        }

        if ($scores === null) {
            throw ValidationException::withMessages([
                'rubric_scores' => 'Score every provider review criterion before saving the review.',
            ]);
        }

        $result = ReviewRubric::result($rubric, $scores);

        if (! $result['is_complete']) {
            throw ValidationException::withMessages([
                'rubric_scores' => 'Score every provider review criterion before saving the review.',
            ]);
        }

        return $result;
    }

    private function applicationStatusForEventResult(
        ScholarshipApplication $application,
        string $eventType,
        string $result,
    ): ?string {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'disbursed' : null;
        }

        if ($result === 'failed') {
            return $eventType === 'exam' ? 'exam_failed' : 'interview_failed';
        }

        return $eventType === 'exam'
            ? ScholarshipSelectionPlan::nextApprovalStatus('exam_taken', $application->scholarship?->selection_stages)
            : 'approved';
    }

    private function decisionReasonForEventResult(string $eventType, string $result, ?string $nextStatus): ?string
    {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'award_released' : null;
        }

        if ($result === 'failed') {
            return $eventType === 'exam' ? 'failed_exam' : 'failed_interview';
        }

        return match ($nextStatus) {
            'interview' => 'passed_exam',
            'approved' => 'qualified_for_formal_application',
            default => null,
        };
    }

    private function defaultReviewNoteForEventResult(string $eventType, string $result): string
    {
        $stage = $eventType === 'exam' ? 'exam' : ($eventType === 'interview' ? 'interview' : 'reward distribution');

        return match ($result) {
            'passed' => "Applicant passed the scholarship {$stage}.",
            'failed' => "Applicant did not pass the scholarship {$stage}.",
            'received' => 'Applicant received the scholarship benefits.',
            default => 'No distribution tracking was required for this applicant.',
        };
    }

    private function eventResultNotificationTitle(string $eventType, string $result): string
    {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'Scholarship benefits received' : 'Distribution record updated';
        }

        return ucfirst($eventType).' '.($result === 'passed' ? 'passed' : 'not passed');
    }

    private function eventResultNotificationMessage(
        ScholarshipApplication $application,
        string $eventType,
        string $result,
    ): string {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';

        if ($eventType === 'distribution') {
            return $result === 'received'
                ? "The provider recorded that you received the scholarship benefits for {$programTitle}."
                : "The provider updated your distribution record for {$programTitle}.";
        }

        if ($result === 'failed') {
            return "Your application for {$programTitle} did not pass the scholarship {$eventType}. Open the application to review the provider note.";
        }

        return $application->status === 'interview'
            ? "You passed the scholarship exam for {$programTitle}. Your application will proceed to the interview stage."
            : "You passed the scholarship {$eventType} for {$programTitle}. Your application has advanced to the next stage.";
    }

    private function workflowStageNotification(
        ScholarshipApplication $application,
        string $stage,
        string $result,
        string $nextStageLabel,
    ): array {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';
        $stageLabel = match ($stage) {
            'screening' => 'pre-screening',
            'formal_application' => 'formal application',
            'exam' => 'exam',
            'interview' => 'interview',
            default => 'application stage',
        };

        if ($result === 'not_passed') {
            $reasonLabel = filled($application->decision_reason)
                ? Str::headline($application->decision_reason)
                : 'Provider decision';
            $reviewNote = filled($application->review_notes)
                ? ' '.$application->review_notes
                : '';

            return [
                'title' => ucfirst($stageLabel).' not passed',
                'message' => "Your application for {$programTitle} did not pass the {$stageLabel}. Reason: {$reasonLabel}.{$reviewNote}",
            ];
        }

        return [
            'title' => $stage === 'screening' ? 'Pre-screening passed' : ucfirst($stageLabel).' passed',
            'message' => "You passed the {$stageLabel} for {$programTitle}. Your next step is {$nextStageLabel}.",
        ];
    }

    private function scheduleTypeLabel(string $type): string
    {
        return match ($type) {
            'formal_application' => 'formal application',
            'exam' => 'exam',
            'interview' => 'interview',
            'distribution' => 'award release',
            default => 'activity',
        };
    }

    private function correctionTargetLabel(string $target): string
    {
        return match ($target) {
            'profile' => 'profile information',
            'academic_record' => 'academic record',
            'application_files' => 'application files',
            'application_answers' => 'application answers',
            default => 'other application information',
        };
    }

    private function scheduleApplicationStatus(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'interview',
            'distribution' => 'distribution_scheduled',
            default => 'under_review',
        };
    }

    private function scheduleDecisionReason(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'for_interview',
            'distribution' => 'distribution_scheduled',
            default => 'other',
        };
    }

    private function applicationStatusNotificationPayload(
        ScholarshipApplication $application,
        string $status,
        ?string $decisionReason,
        bool $isOutcomeStatus
    ): array {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';
        $actionUrl = route('dashboard.applications.show', $application, false);
        $distributionDate = $application->distribution_scheduled_for?->format('M d, Y');

        if ($status === 'under_review' && $decisionReason === 'missing_documents') {
            return [
                'type' => 'application_status',
                'title' => 'Documents needed',
                'message' => "Your application for {$programTitle} needs updated documents. Please review the provider note.",
                'action_url' => $actionUrl,
            ];
        }

        $payload = $isOutcomeStatus
            ? match ($status) {
                'awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship award confirmed',
                    'message' => "The provider selected you for {$programTitle} after its formal application process. Open your application for award and distribution updates.",
                ],
                'not_awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Formal application result',
                    'message' => "The provider completed its formal process for {$programTitle}, but your application was not selected. Review the provider note for details.",
                ],
                'disbursed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship reward distributed',
                    'message' => "The scholarship reward for {$programTitle} has been marked as distributed.",
                ],
                'renewed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship renewed',
                    'message' => "Your scholarship support for {$programTitle} has been renewed.",
                ],
                default => [
                    'type' => 'application_outcome',
                    'title' => 'Application outcome recorded',
                    'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
                ],
            }
        : match ($status) {
            'submitted' => [
                'type' => 'application_status',
                'title' => 'Application returned to submitted',
                'message' => "Your application for {$programTitle} was returned to submitted status.",
            ],
            'under_review' => [
                'type' => 'application_status',
                'title' => 'Application review started',
                'message' => "Your application for {$programTitle} is now under provider review.",
            ],
            'qualified' => [
                'type' => 'application_status',
                'title' => 'Application qualified',
                'message' => "Your application for {$programTitle} has been marked qualified for provider review.",
            ],
            'shortlisted' => [
                'type' => 'application_status',
                'title' => 'Application shortlisted',
                'message' => "Your application for {$programTitle} has been shortlisted for the next review step.",
            ],
            'interview' => [
                'type' => 'application_status',
                'title' => 'Interview or follow-up needed',
                'message' => "Your application for {$programTitle} was moved to interview or follow-up screening.",
            ],
            'exam_qualified' => [
                'type' => 'application_status',
                'title' => 'Qualified for exam',
                'message' => "Your application for {$programTitle} passed initial screening and is qualified for the scholarship exam.",
            ],
            'exam_scheduled' => [
                'type' => 'application_status',
                'title' => 'Scholarship exam scheduled',
                'message' => "Your scholarship exam for {$programTitle} has been scheduled. Check provider notes for instructions.",
            ],
            'exam_taken' => [
                'type' => 'application_status',
                'title' => 'Exam marked taken',
                'message' => "Your scholarship exam for {$programTitle} was marked as taken.",
            ],
            'exam_passed' => [
                'type' => 'application_status',
                'title' => 'Exam passed',
                'message' => "You passed the scholarship exam for {$programTitle}. Your application will proceed to final review.",
            ],
            'exam_failed' => [
                'type' => 'application_status',
                'title' => 'Exam not passed',
                'message' => "Your application for {$programTitle} did not pass the scholarship exam. Review the provider note for details.",
            ],
            'interview_failed' => [
                'type' => 'application_status',
                'title' => 'Interview not passed',
                'message' => "Your application for {$programTitle} did not advance after the interview. Review the provider note for details.",
            ],
            'approved' => [
                'type' => 'application_status',
                'title' => 'Qualified for formal application',
                'message' => "You passed pre-screening for {$programTitle}. Open your submission to review the documents and instructions for continuing with the provider. This is not yet a final scholarship award.",
            ],
            'waitlisted' => [
                'type' => 'application_outcome',
                'title' => 'Added to the alternate recipient list',
                'message' => "You remain eligible for {$programTitle}, but the provider placed you on its waitlist. You will be notified if a slot becomes available.",
            ],
            'distribution_scheduled' => [
                'type' => 'application_outcome',
                'title' => 'Reward distribution scheduled',
                'message' => "Your scholarship reward for {$programTitle} is scheduled for {$distributionDate}. Open the application to review provider instructions.",
            ],
            'benefits_terminated' => [
                'type' => 'application_outcome',
                'title' => 'Scholarship benefits stopped',
                'message' => "The provider stopped future scholarship benefits for {$programTitle}. Open your application to review the recorded reason and provider explanation.",
            ],
            'rejected' => [
                'type' => 'application_status',
                'title' => 'Pre-screening not qualified',
                'message' => "Your submission for {$programTitle} did not qualify for the next stage. Review the provider note for details.",
            ],
            default => [
                'type' => 'application_status',
                'title' => 'Application status updated',
                'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
            ],
        };

        if (in_array($status, ['rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'], true) && filled($decisionReason)) {
            $payload['message'] .= " Reason: {$this->statusLabel($decisionReason)}.";
        }

        return array_merge($payload, ['action_url' => $actionUrl]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Qualified for formal application',
            'waitlisted' => 'Waitlisted alternate',
            'withdrawn' => 'Withdrawn',
            'rejected' => 'Not qualified',
            'exam_qualified' => 'Qualified for exam',
            'exam_scheduled' => 'Exam scheduled',
            'exam_taken' => 'Exam taken',
            'exam_passed' => 'Passed exam',
            'exam_failed' => 'Failed exam',
            'interview_failed' => 'Failed interview',
            'benefits_terminated' => 'Benefits stopped',
            'for_exam' => 'Meets exam eligibility',
            'exam_completed' => 'Exam completed',
            'passed_exam' => 'Passed exam',
            'failed_exam' => 'Failed exam',
            'failed_interview' => 'Failed interview',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
