<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationDecisionReason;
use App\Support\ApplicationSchedulePayload;
use App\Support\PreScreeningHandoffRecord;
use App\Support\RecipientAgreement;
use App\Support\ReviewRubric;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

trait HandlesProviderApplicationQueries
{
    public function applicationsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'filter' => ['sometimes', Rule::in([
                'needs_review',
                'waiting_activity',
                'ready_result',
                'final_decision',
                'selected',
                'waitlisted',
                'pending_review',
                'document_issues',
                'active_stages',
                'formal_application',
                'decided',
                'all',
            ])],
            'sort' => ['sometimes', Rule::in(['priority', 'dss', 'documents', 'oldest'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);

        $providerId = $request->user()->providerOrganizationId();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filter = $validated['filter'] ?? 'needs_review';
        $sort = $validated['sort'] ?? 'priority';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->withAvg('applications as average_match_score', 'eligibility_score')
            ->withAvg('applications as average_dss_score', 'dss_score')
            ->latest()
            ->get();
        $reviewers = $this->providerApplicationReviewers($providerId, $request->user());
        $applicationsBase = $this->providerApplicationsQuery($request->user());

        if ($selectedScholarship) {
            $applicationsBase->where('scholarship_id', $selectedScholarship->id);
        } elseif (! empty($validated['program_id'])) {
            $programId = (int) $validated['program_id'];
            abort_unless($scholarships->contains(fn (Scholarship $scholarship): bool => $scholarship->id === $programId), 403);
            $applicationsBase->where('scholarship_id', $programId);
        }

        $statusCounts = (clone $applicationsBase)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $recommendationCounts = (clone $applicationsBase)
            ->selectRaw('dss_recommendation, count(*) as total')
            ->whereNotNull('dss_recommendation')
            ->groupBy('dss_recommendation')
            ->pluck('total', 'dss_recommendation');
        $stageCounts = (clone $applicationsBase)
            ->selectRaw("COALESCE(workflow_stage, 'screening') as workflow_stage, count(*) as total")
            ->whereNotIn('application_state', ['closed', 'withdrawn'])
            ->groupByRaw("COALESCE(workflow_stage, 'screening')")
            ->pluck('total', 'workflow_stage');
        $activityWaitingCounts = $this->providerActivityWaitingCounts($applicationsBase);
        $filterCounts = $this->providerApplicationFilterCounts($applicationsBase);
        $totalApplications = (int) ($filterCounts['all'] ?? 0);

        $applicationsQuery = (clone $applicationsBase)
            ->with([
                'applicant.studentProfile',
                'applicant.applicantVerificationDocuments',
                'documents.reviewer',
                'assignedReviewer.providerProfile',
                'statusHistories.actor',
                'schedules',
                'stageProgresses',
                'scholarship' => fn ($query) => $query
                    ->with(['events', 'benefits'])
                    ->withCount($this->providerProgramCountRelations()),
            ]);
        $this->applyProviderApplicationSearch($applicationsQuery, $search);
        $this->applyProviderApplicationFilter($applicationsQuery, $filter);
        $this->applyProviderApplicationSort($applicationsQuery, $sort);

        $applications = $applicationsQuery->paginate($perPage);
        $applications->getCollection()
            ->filter(fn (ScholarshipApplication $application): bool => $application->dss_score === null
                || blank($application->dss_recommendation)
                || blank($application->dss_breakdown))
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'reviewers' => $reviewers
                ->map(fn (User $reviewer) => $this->applicationReviewerPayload($reviewer, $providerId))
                ->values(),
            'stats' => [
                'scholarships' => $scholarships->count(),
                'applications' => $totalApplications,
                'drafts' => $scholarships->where('status', 'draft')->count(),
                'under_review' => $statusCounts['under_review'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
                'average_match_score' => round((float) (clone $applicationsBase)->avg('eligibility_score'), 1),
                'average_dss_score' => round((float) (clone $applicationsBase)->avg('dss_score'), 1),
                'pending_documents' => ApplicationDocument::query()
                    ->where('status', 'pending')
                    ->whereHas('application.scholarship', fn (Builder $query) => $this->applyProviderScholarshipScope($query, $request->user()))
                    ->count(),
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'selected_scholarship' => $selectedScholarship
                ? $this->scholarshipPayload($selectedScholarship)
                : null,
            'program_events' => $selectedScholarship
                ? $selectedScholarship->events
                    ->sortBy('scheduled_at')
                    ->map(fn (ScholarshipEvent $event) => ScholarshipEventPayload::make($event))
                    ->values()
                : [],
            'applications' => $applications->getCollection()
                ->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))
                ->values(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'from' => $applications->firstItem(),
                'to' => $applications->lastItem(),
            ],
            'filter_counts' => $filterCounts,
            'stage_counts' => $stageCounts,
            'activity_waiting_counts' => $activityWaitingCounts,
            'status_counts' => [
                'submitted' => $statusCounts['submitted'] ?? 0,
                'under_review' => $statusCounts['under_review'] ?? 0,
                'qualified' => $statusCounts['qualified'] ?? 0,
                'exam_qualified' => $statusCounts['exam_qualified'] ?? 0,
                'exam_scheduled' => $statusCounts['exam_scheduled'] ?? 0,
                'exam_taken' => $statusCounts['exam_taken'] ?? 0,
                'exam_passed' => $statusCounts['exam_passed'] ?? 0,
                'exam_failed' => $statusCounts['exam_failed'] ?? 0,
                'interview_failed' => $statusCounts['interview_failed'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
            ],
            'recommendation_counts' => [
                'highly_recommended' => $recommendationCounts['highly_recommended'] ?? 0,
                'recommended' => $recommendationCounts['recommended'] ?? 0,
                'needs_review' => $recommendationCounts['needs_review'] ?? 0,
                'low_priority' => $recommendationCounts['low_priority'] ?? 0,
                'not_recommended' => $recommendationCounts['not_recommended'] ?? 0,
            ],
            'program_performance' => $scholarships->map(function (Scholarship $scholarship) {
                return [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                    'applications' => (int) ($scholarship->applications_count ?? 0),
                    'average_match_score' => round((float) ($scholarship->average_match_score ?? 0), 1),
                    'average_dss_score' => round((float) ($scholarship->average_dss_score ?? 0), 1),
                    'saved_count' => $scholarship->bookmarks_count ?? 0,
                    'deadline' => $scholarship->deadline?->format('M d, Y'),
                    'days_left' => $scholarship->deadline ? now()->startOfDay()->diffInDays($scholarship->deadline->startOfDay(), false) : null,
                ];
            })->values(),
        ]);
    }

    private function applyProviderApplicationSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $likeSearch = '%'.$search.'%';

        $query->where(function (Builder $query) use ($likeSearch): void {
            $query
                ->whereHas('applicant', function (Builder $applicantQuery) use ($likeSearch): void {
                    $applicantQuery
                        ->where('email', 'like', $likeSearch)
                        ->orWhere('username', 'like', $likeSearch)
                        ->orWhereHas('studentProfile', fn (Builder $profileQuery) => $profileQuery
                            ->where('first_name', 'like', $likeSearch)
                            ->orWhere('last_name', 'like', $likeSearch));
                })
                ->orWhereHas('scholarship', fn (Builder $scholarshipQuery) => $scholarshipQuery
                    ->where('title', 'like', $likeSearch));
        });
    }

    private function applyProviderApplicationFilter(Builder $query, string $filter): void
    {
        if (in_array($filter, ['needs_review', 'pending_review'], true)) {
            $query
                ->where(fn (Builder $query) => $query
                    ->where('workflow_stage', 'screening')
                    ->orWhere(fn (Builder $legacyQuery) => $legacyQuery
                        ->whereNull('workflow_stage')
                        ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted'])))
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'waiting_activity') {
            $query
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $examQuery): void {
                            $examQuery
                                ->where('workflow_stage', 'exam')
                                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'exam')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $interviewQuery): void {
                            $interviewQuery
                                ->where('workflow_stage', 'interview')
                                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'interview')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $formalApplicationQuery): void {
                            $formalApplicationQuery
                                ->where('workflow_stage', 'formal_application')
                                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'formal_application')
                                    ->where('status', 'completed'));
                        });
                });

            return;
        }

        if ($filter === 'ready_result') {
            $query
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $formalApplicationQuery): void {
                            $formalApplicationQuery
                                ->where('workflow_stage', 'formal_application')
                                ->whereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'formal_application')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $examQuery): void {
                            $examQuery
                                ->where('workflow_stage', 'exam')
                                ->whereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'exam')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $interviewQuery): void {
                            $interviewQuery
                                ->where('workflow_stage', 'interview')
                                ->whereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'interview')
                                    ->where('status', 'completed'));
                        });
                });

            return;
        }

        if ($filter === 'final_decision') {
            $query
                ->where('workflow_stage', 'decision')
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'selected') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'selected')
                    ->orWhereIn('status', ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated']);
            });

            return;
        }

        if ($filter === 'waitlisted') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'waitlisted')
                    ->orWhere('status', 'waitlisted');
            });

            return;
        }

        if ($filter === 'document_issues') {
            $driver = DB::connection()->getDriverName();
            $checklistLength = in_array($driver, ['mysql', 'mariadb'], true)
                ? 'COALESCE(JSON_LENGTH(scholarship_applications.document_checklist), 0)'
                : 'COALESCE(json_array_length(scholarship_applications.document_checklist), 0)';

            $query->where(function (Builder $query) use ($checklistLength): void {
                $query
                    ->whereHas('documents', fn (Builder $documentQuery) => $documentQuery
                        ->whereIn('status', ['pending', 'needs_replacement']))
                    ->orWhereRaw("{$checklistLength} > (
                        SELECT COUNT(*)
                        FROM application_documents
                        WHERE application_documents.scholarship_application_id = scholarship_applications.id
                          AND application_documents.status = 'accepted'
                    )");
            });

            return;
        }

        if ($filter === 'active_stages') {
            $query
                ->whereIn('workflow_stage', ScholarshipSelectionPlan::SCHEDULABLE_STAGES)
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'formal_application') {
            $query
                ->whereIn('workflow_stage', ['formal_application', 'decision'])
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'decided') {
            $query->where(function (Builder $query): void {
                $query
                    ->whereIn('application_state', ['closed', 'withdrawn'])
                    ->orWhereNotNull('final_outcome');
            });
        }
    }

    private function applyProviderApplicationSort(Builder $query, string $sort): void
    {
        if ($sort === 'dss') {
            $query->orderByDesc('dss_score')->orderBy('submitted_at');

            return;
        }

        if ($sort === 'documents') {
            $query
                ->withCount([
                    'documents as unresolved_documents_count' => fn (Builder $documentQuery) => $documentQuery
                        ->whereIn('status', ['pending', 'needs_replacement']),
                ])
                ->orderByDesc('unresolved_documents_count')
                ->orderBy('submitted_at');

            return;
        }

        if ($sort === 'oldest') {
            $query->orderBy('submitted_at')->orderBy('id');

            return;
        }

        $query
            ->orderByRaw("CASE
                WHEN correction_status = 'submitted' THEN 0
                WHEN COALESCE(workflow_stage, 'screening') = 'screening' AND application_state NOT IN ('closed', 'withdrawn') THEN 1
                WHEN workflow_stage IN ('exam', 'interview') AND application_state NOT IN ('closed', 'withdrawn') THEN 2
                WHEN workflow_stage IN ('formal_application', 'decision') AND application_state NOT IN ('closed', 'withdrawn') THEN 3
                ELSE 4
            END")
            ->orderByDesc('dss_score')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    private function providerApplicationFilterCounts(Builder $baseQuery): array
    {
        $counts = ['all' => (clone $baseQuery)->count()];

        foreach (['needs_review', 'waiting_activity', 'ready_result', 'final_decision', 'selected', 'waitlisted'] as $filter) {
            $query = clone $baseQuery;
            $this->applyProviderApplicationFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        // Keep old URLs and dashboard links compatible while the UI moves to task-based queues.
        $counts['pending_review'] = $counts['needs_review'];

        foreach (['document_issues', 'active_stages', 'formal_application', 'decided'] as $filter) {
            $query = clone $baseQuery;
            $this->applyProviderApplicationFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        return $counts;
    }

    private function providerActivityWaitingCounts(Builder $baseQuery): Collection
    {
        return collect(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)->mapWithKeys(function (string $stage) use ($baseQuery): array {
            $query = (clone $baseQuery)
                ->where('workflow_stage', $stage)
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                    ->where('type', $stage)
                    ->where('status', 'completed'));

            return [$stage => $query->count()];
        });
    }

    public function applicationDetailData(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $application->load(['applicant.studentProfile', 'documents.reviewer', 'assignedReviewer.providerProfile', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($application);
        $application = $application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'assignedReviewer.providerProfile', 'statusHistories.actor', 'scholarship']);

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'application' => $this->applicationPayload($application, true),
            'application_navigation' => $this->applicationSiblingNavigationPayload($application),
        ]);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user();
        $providerId = $provider->providerOrganizationId();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filename = $selectedScholarship
            ? "provider-applications-program-{$selectedScholarship->id}.xlsx"
            : 'provider-applications.xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'provider-applications-');

        abort_if($tempPath === false, 500, 'Unable to prepare the applicant export.');

        $headers = [
            'Application ID',
            'Scholarship',
            'Applicant',
            'Email',
            'Contact Number',
            'Current Stage',
            'Final Outcome',
            'Match Score',
            'Match Recommendation',
            'Document Readiness %',
            'Documents Requiring Action',
            'Missing Required Documents',
            'Decision Reason',
            'Assigned Reviewer',
            'Waitlist Position',
            'Submitted At',
            'Review Notes',
        ];
        $query = $this->providerApplicationsQuery($provider)
            ->with(['applicant.studentProfile', 'documents', 'assignedReviewer', 'reviewer', 'scholarship']);

        if ($selectedScholarship) {
            $query->where('scholarship_id', $selectedScholarship->id);
        }

        $owner = $provider->providerOrganizationOwner();
        $owner->loadMissing('providerProfile');
        $providerName = $owner->providerProfile?->provider_name ?: $owner->name;
        $scope = $selectedScholarship?->title ?: 'All scholarship programs';
        $subtitle = "Provider: {$providerName} | Scope: {$scope} | Exported: ".now()->format('M d, Y h:i A');

        try {
            XlsxExport::create(
                $tempPath,
                'Applicant Review Report',
                $subtitle,
                $headers,
                $query->orderBy('id')->lazyById(200)->map(
                    fn (ScholarshipApplication $application): array => $this->providerApplicationExportRow($application),
                ),
            );
        } catch (Throwable $error) {
            @unlink($tempPath);
            throw $error;
        }

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function providerApplicationExportRow(ScholarshipApplication $application): array
    {
        $application->loadMissing(['applicant.studentProfile', 'documents', 'assignedReviewer', 'reviewer']);
        $dss = app(DecisionSupportService::class)->syncApplication($application, 'provider_export');
        $workflow = $this->workflowService->payload($application);
        $readiness = $this->documentReadiness($application);
        $documents = $application->documents->sortBy('id');
        $documentsRequiringAction = $documents
            ->filter(fn (ApplicationDocument $document): bool => $document->status !== 'accepted')
            ->map(fn (ApplicationDocument $document): string => "{$document->document_name} ({$this->documentStatusLabel($document->status)})")
            ->values()
            ->implode('; ');

        return [
            $application->id,
            $application->scholarship?->title,
            $application->applicant?->name,
            $application->applicant?->email,
            $application->applicant?->contact_number,
            $workflow['current_stage_label'] ?? null,
            $workflow['final_outcome_label'] ?? 'Not decided',
            $dss['score'] ?? $application->dss_score,
            $dss['label'] ?? Str::headline((string) $application->dss_recommendation),
            $readiness['accepted_percent'] ?? 0,
            $documentsRequiringAction,
            implode('; ', $readiness['missing'] ?? []),
            ApplicationDecisionReason::label($application->decision_reason),
            $application->assignedReviewer?->name ?? $application->reviewer?->name,
            $application->waitlist_position,
            $application->submitted_at?->format('Y-m-d H:i:s'),
            $application->review_notes,
        ];
    }

    private function documentStatusLabel(?string $status): string
    {
        return match ($status) {
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'needs_replacement' => 'Needs replacement',
            default => 'Pending review',
        };
    }

    private function applicationPayload(ScholarshipApplication $application, bool $includeApplicantProfile = false): array
    {
        $workflow = $this->workflowService->payload($application);
        $readiness = $this->documentReadiness($application);
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);
        $recipientAgreement = RecipientAgreement::payload($application);
        $application->loadMissing(['schedules', 'assignedReviewer.providerProfile']);
        $application->scholarship?->loadMissing('events');
        $latestDocumentUploadedAt = $application->documents
            ->pluck('uploaded_at')
            ->filter()
            ->sortDesc()
            ->first();
        $submittedAt = $application->submitted_at ?? $application->created_at;

        if ($includeApplicantProfile) {
            $application->loadMissing('applicant.applicantVerificationDocuments');
        }

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application),
            'status' => $application->status,
            'application_state' => $workflow['application_state'],
            'workflow_stage' => $workflow['current_stage'],
            'final_outcome' => $workflow['final_outcome'],
            'workflow' => $workflow,
            'submission_version' => (int) data_get($application->submission_snapshot, 'version', 1),
            'submitted_profile' => data_get($application->submission_snapshot, 'current.applicant'),
            'document_checklist' => $application->document_checklist ?? [],
            'optional_document_checklist' => $application->optional_document_checklist
                ?? app(ScholarshipEligibilityService::class)->optionalDocumentRequirements($application->scholarship),
            'document_readiness' => $readiness,
            'bulk_advance_targets' => $this->bulkAdvanceTargets($application, $readiness),
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'application_answers' => $application->application_answers ?? [],
            'eligibility_score' => $application->eligibility_score,
            'eligibility_breakdown' => AcademicRequirement::withReferenceEquivalence($application->eligibility_breakdown),
            'dss_score' => $dss['score'],
            'dss_recommendation' => $dss['recommendation'],
            'dss_breakdown' => $dss,
            'dss_explanation' => $decisionSupport->explainApplication($application, $dss),
            'rubric_review' => ReviewRubric::result(
                $application->review_rubric_snapshot ?: ($application->scholarship?->review_rubric ?? []),
                $application->rubric_scores ?? [],
            ),
            'rubric_scored_at' => $application->rubric_scored_at?->format('M d, Y h:i A'),
            'status_progress' => $decisionSupport->statusProgress($application),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'correction_status' => $application->correction_status,
            'correction_message' => $application->correction_message,
            'correction_targets' => $application->correction_targets ?? [],
            'correction_response' => $application->correction_response,
            'correction_requested_at' => $application->correction_requested_at?->format('M d, Y h:i A'),
            'correction_responded_at' => $application->correction_responded_at?->format('M d, Y h:i A'),
            'correction_resolved_at' => $application->correction_resolved_at?->format('M d, Y h:i A'),
            'withdrawal_reason' => $application->withdrawal_reason,
            'withdrawn_at' => $application->withdrawn_at?->format('M d, Y h:i A'),
            'waitlist_position' => $application->waitlist_position,
            'waitlisted_at' => $application->waitlisted_at?->format('M d, Y h:i A'),
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('Y-m-d'),
            'distribution_scheduled_for' => $application->distribution_scheduled_for?->format('Y-m-d'),
            'distribution_scheduled_label' => $application->distribution_scheduled_for?->format('M d, Y'),
            'distribution_instructions' => $application->distribution_instructions,
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
            'assigned_reviewer' => $application->assignedReviewer
                ? $this->applicationReviewerPayload(
                    $application->assignedReviewer,
                    $application->scholarship?->provider_id ?? $application->assignedReviewer->providerOrganizationId(),
                )
                : null,
            'waiting_days' => $submittedAt
                ? (int) $submittedAt->startOfDay()->diffInDays(now()->startOfDay())
                : 0,
            'latest_document_uploaded_at' => $latestDocumentUploadedAt?->format('M d, Y h:i A'),
            'documents_changed_since_review' => (bool) (
                $latestDocumentUploadedAt
                && $application->reviewed_at
                && $latestDocumentUploadedAt->gt($application->reviewed_at)
            ),
            'recipient_agreement' => $recipientAgreement,
            'requires_student_response' => $recipientAgreement['requires_response'] ?? false,
            'can_receive_student_response' => $recipientAgreement !== null,
            'pre_screening_handoff' => PreScreeningHandoffRecord::make($application, $workflow, $readiness, $dss),
            'schedules' => $application->schedules
                ->sortBy('scheduled_at')
                ->map(fn (ApplicationSchedule $schedule) => ApplicationSchedulePayload::make($schedule))
                ->values(),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'applicant' => $this->applicantPayload($application, $includeApplicantProfile),
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship)
                : null,
            'exam' => $application->scholarship
                && in_array('exam', ScholarshipSelectionPlan::normalize($application->scholarship->selection_stages), true)
                ? $this->examPayload($application->scholarship)
                : null,
        ];
    }

    private function freshApplicationPayload(ScholarshipApplication $application): array
    {
        return $this->applicationPayload($application->fresh()->load([
            'applicant.studentProfile',
            'applicant.applicantVerificationDocuments',
            'documents.reviewer',
            'assignedReviewer.providerProfile',
            'statusHistories.actor',
            'scholarship',
        ]), true);
    }

    private function applicantPayload(ScholarshipApplication $application, bool $includeProfileDetails): array
    {
        $applicant = $application->applicant;
        $profile = $applicant?->studentProfile;
        $payload = [
            'name' => $applicant?->name,
            'email' => $applicant?->email,
            'username' => $applicant?->username,
            'contact_number' => $applicant?->contact_number,
            'citizenship_status' => $profile?->citizenship_status,
            'education_level' => $profile?->education_level,
            'school' => $profile?->school,
            'school_type' => $profile?->school_type,
            'learner_reference_number' => $profile?->learner_reference_number,
            'course_or_strand' => $profile?->course_or_strand,
            'year_level' => $profile?->year_level,
            'academic_year' => $profile?->academic_year,
            'academic_term' => $profile?->academic_term,
            'gwa' => $profile?->gwa,
            'grading_scale' => $profile?->grading_scale,
            'academic_result_source' => $profile?->academic_result_source,
            'academic_result_extracted_at' => $profile?->academic_result_extracted_at?->format('M d, Y h:i A'),
            'academic_scan_required' => $this->academicRecordOcrService->configured(),
            'income_bracket' => $profile?->income_bracket,
            'household_size' => $profile?->household_size,
            'preferred_categories' => $profile?->preferred_categories,
            'preferred_locations' => $profile?->preferred_locations,
            'willing_to_relocate' => $profile?->willing_to_relocate,
            'support_needs' => $profile?->support_needs,
            'current_scholarship_status' => $profile?->current_scholarship_status,
            'current_scholarship_details' => $profile?->current_scholarship_details,
            'platform_active_scholarships' => $this->eligibilityService->activePlatformScholarships(
                $applicant,
                $application->scholarship_id,
            ),
            'scholarship_goal' => $profile?->scholarship_goal,
            'achievements' => $profile?->achievements,
            'activities_and_responsibilities' => $profile?->activities_and_responsibilities,
            'location' => collect([
                $profile?->barangay,
                $profile?->city,
                $profile?->province,
                $profile?->region,
            ])->filter()->implode(', '),
            'latitude' => $profile?->latitude,
            'longitude' => $profile?->longitude,
            'profile_verification_status' => $applicant?->applicantAcademicVerificationStatus() ?? 'unsubmitted',
            'profile_verified_at' => $applicant?->applicantAcademicVerificationStatus() === 'approved'
                ? $profile?->verified_at?->format('M d, Y')
                : null,
            'profile_photo_url' => $profile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application)
                : null,
            'profile_photo_review_status' => $profile?->profile_photo_review_status,
            'profile_photo_review_note' => $profile?->profile_photo_review_note,
            'profile_photo_reviewed_at' => $profile?->profile_photo_reviewed_at?->format('M d, Y h:i A'),
        ];

        if (! $includeProfileDetails) {
            return $payload;
        }

        return array_merge($payload, [
            'first_name' => $profile?->first_name,
            'middle_initial' => $profile?->middle_initial,
            'last_name' => $profile?->last_name,
            'suffix' => $profile?->suffix,
            'gender' => $profile?->gender,
            'birthdate' => $profile?->birthdate?->format('M d, Y'),
            'age' => $profile?->birthdate?->age,
            'account_managed_by' => $profile?->account_managed_by,
            'enrollment_status' => $profile?->enrollment_status,
            'address' => $profile?->address,
            'profile_updated_at' => $profile?->updated_at?->format('M d, Y h:i A'),
            'profile_verification_notes' => $profile?->verification_notes,
            'guardian_name' => $profile?->guardian_name,
            'guardian_relationship' => $profile?->guardian_relationship,
            'guardian_contact' => $profile?->guardian_contact,
            'guardian_email' => $profile?->guardian_email,
            'guardian_is_account_owner' => (bool) $profile?->guardian_is_account_owner,
            'profile_proofs' => ($applicant?->applicantVerificationDocuments ?? collect())
                ->whereIn('document_type', ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES)
                ->sortByDesc('uploaded_at')
                ->map(fn (ApplicantVerificationDocument $document) => $this->applicantProfileProofPayload($application, $document))
                ->values(),
        ]);
    }

    private function documentReadiness(ScholarshipApplication $application): array
    {
        return app(ScholarshipEligibilityService::class)
            ->applicationDocumentReadiness($application);
    }

    private function applicationReviewerPayload(User $reviewer, int $providerId): array
    {
        $reviewer->loadMissing('providerProfile');
        $profile = $reviewer->providerProfile;
        $contactName = collect([
            $profile?->first_name,
            filled($profile?->middle_initial) ? strtoupper($profile->middle_initial).'.' : null,
            $profile?->last_name,
        ])->filter()->implode(' ');
        $isOwner = $reviewer->id === $providerId;

        return [
            'id' => $reviewer->id,
            'name' => $isOwner
                ? ($profile?->provider_name ?: $contactName ?: $reviewer->username ?: $reviewer->email)
                : ($contactName ?: $reviewer->username ?: $reviewer->email),
            'role_label' => $isOwner
                ? 'Provider owner'
                : (self::PROVIDER_TEAM_ROLES[$reviewer->account_title] ?? 'Team member'),
            'program_access_mode' => $reviewer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            'assigned_program_ids' => $reviewer->assignedProviderProgramIds(),
        ];
    }

    private function documentPayload(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('documents.view', $document),
            'download_url' => route('documents.download', $document),
        ];
    }

    private function timelinePayload(ScholarshipApplication $application): array
    {
        if ($application->statusHistories->isEmpty()) {
            return [[
                'id' => "submitted-{$application->id}",
                'from_status' => null,
                'to_status' => $application->status,
                'decision_reason' => $application->decision_reason,
                'review_notes' => 'Application record created.',
                'actor' => $application->applicant?->name ?? 'Applicant',
                'changed_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ]];
        }

        return $application->statusHistories
            ->sortBy('changed_at')
            ->map(fn (ApplicationStatusHistory $history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'decision_reason' => $history->decision_reason,
                'review_notes' => $history->review_notes,
                'actor' => $history->actor?->name ?? 'System',
                'changed_at' => $history->changed_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function requestedProviderScholarship(Request $request): ?Scholarship
    {
        $scholarshipId = $request->integer('scholarship_id');

        if (! $scholarshipId) {
            return null;
        }

        return $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->findOrFail($scholarshipId);
    }

    private function providerScholarshipsQuery(User $actor): Builder
    {
        return $this->applyProviderScholarshipScope(Scholarship::query(), $actor);
    }

    private function applyProviderScholarshipScope(Builder $query, User $actor): Builder
    {
        $query->where('provider_id', $actor->providerOrganizationId());

        if ($actor->hasLimitedProviderProgramAccess()) {
            $query->whereIn('scholarships.id', $actor->assignedProviderProgramIds());
        }

        return $query;
    }

    private function providerApplicationsQuery(User $actor): Builder
    {
        return ScholarshipApplication::query()
            ->whereHas('scholarship', fn (Builder $query) => $this->applyProviderScholarshipScope($query, $actor));
    }

    private function reviewNavigationPayload(
        ScholarshipApplication $application,
        ?string $reviewedStage = null,
    ): array {
        $remainingApplicationsQuery = ScholarshipApplication::query()
            ->with(['applicant', 'scholarship', 'stageProgresses'])
            ->where('scholarship_id', $application->scholarship_id)
            ->where('id', '!=', $application->id);

        if ($reviewedStage === null) {
            $remainingApplicationsQuery->whereIn('status', self::REVIEW_DECISION_STATUSES);
        } else {
            $remainingApplicationsQuery->where(function (Builder $query) use ($reviewedStage): void {
                $query->where('workflow_stage', $reviewedStage)
                    ->orWhereNull('workflow_stage');
            });
        }

        $remainingApplications = $remainingApplicationsQuery
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        if ($reviewedStage !== null) {
            $remainingApplications = $remainingApplications
                ->filter(function (ScholarshipApplication $candidate) use ($reviewedStage): bool {
                    $workflow = $this->workflowService->payload($candidate);

                    return ! $workflow['is_closed'] && $workflow['current_stage'] === $reviewedStage;
                })
                ->values();
        }

        $nextApplication = $remainingApplications->first();

        return [
            'remaining_count' => $remainingApplications->count(),
            'stage' => $reviewedStage,
            'stage_label' => $reviewedStage !== null
                ? ScholarshipSelectionPlan::label($reviewedStage)
                : 'Review',
            'list_url' => route(match ($reviewedStage) {
                'exam', 'interview', 'formal_application' => 'provider.programs.applications.results',
                'decision' => 'provider.programs.applications.decisions',
                default => 'provider.programs.applications.review',
            }, $application->scholarship_id, false),
            'next_application' => $nextApplication ? [
                'id' => $nextApplication->id,
                'applicant_name' => $nextApplication->applicant?->name ?: "Application #{$nextApplication->id}",
                'url' => route('provider.applications.show', $nextApplication, false),
            ] : null,
        ];
    }

    private function applicationSiblingNavigationPayload(ScholarshipApplication $application): array
    {
        $applications = ScholarshipApplication::query()
            ->with('applicant')
            ->where('scholarship_id', $application->scholarship_id)
            ->latest('submitted_at')
            ->latest('id')
            ->get();
        $position = $applications->search(fn (ScholarshipApplication $item) => $item->is($application));
        $position = $position === false ? 0 : $position;
        $navigationItem = static fn (?ScholarshipApplication $item): ?array => $item ? [
            'id' => $item->id,
            'applicant_name' => $item->applicant?->name ?: "Application #{$item->id}",
            'url' => route('provider.applications.show', $item, false),
        ] : null;

        return [
            'position' => $applications->isEmpty() ? 0 : $position + 1,
            'total' => $applications->count(),
            'previous_application' => $navigationItem($applications->get($position - 1)),
            'next_application' => $navigationItem($applications->get($position + 1)),
        ];
    }

    private function providerProgramCountRelations(): array
    {
        return [
            'bookmarks',
            'applications',
            'applications as pending_review_applications_count' => fn ($query) => $query
                ->whereIn('status', ['submitted', 'under_review']),
            'applications as awarded_slots_count' => fn ($query) => $query
                ->whereIn('status', self::AWARD_SLOT_STATUSES),
        ];
    }


    private function providerApplicationReviewers(int $providerId, ?User $assigner = null)
    {
        return User::query()
            ->with(['providerProfile', 'parentAccount.providerProfile'])
            ->where('role', 'provider')
            ->where(function ($query) use ($providerId): void {
                $query->whereKey($providerId)
                    ->orWhere('parent_account_id', $providerId);
            })
            ->get()
            ->filter(fn (User $reviewer) => $reviewer->isActive()
                && $reviewer->providerOrganizationOwner()->isActive()
                && $reviewer->hasPortalPermission('verify_applications')
                && (! $assigner || $reviewer->id !== $assigner->id)
                && (! $assigner || ! $this->providerReviewerHasBroaderAccess($assigner, $reviewer)))
            ->sortBy(fn (User $reviewer) => sprintf(
                '%d-%s',
                $reviewer->id === $providerId ? 0 : 1,
                strtolower($reviewer->email),
            ))
            ->values();
    }

    private function authorizeProviderStageAction(User $actor, string $stage): void
    {
        $permission = $stage === 'screening'
            ? 'verify_applications'
            : 'manage_selection_activities';

        abort_unless($actor->hasPortalPermission($permission), 403);
    }

    private function providerReviewerHasBroaderAccess(User $assigner, User $reviewer): bool
    {
        $assignerPermissions = array_values(array_intersect(
            User::PROVIDER_PERMISSIONS,
            $assigner->effectivePortalPermissions(),
        ));
        $reviewerPermissions = array_values(array_intersect(
            User::PROVIDER_PERMISSIONS,
            $reviewer->effectivePortalPermissions(),
        ));

        if (array_diff($reviewerPermissions, $assignerPermissions) !== []) {
            return true;
        }

        if (! $assigner->hasLimitedProviderProgramAccess()) {
            return false;
        }

        return ! $reviewer->hasLimitedProviderProgramAccess()
            || array_diff(
                $reviewer->assignedProviderProgramIds(),
                $assigner->assignedProviderProgramIds(),
            ) !== [];
    }

}
