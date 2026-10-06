<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProviderVerificationDocument;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringCycle;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\RecipientAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait HandlesProviderRoleWorkspaceData
{
    public function programCoordinatorWorkspaceData(Request $request): JsonResponse
    {
        $coordinator = $request->user();
        abort_unless($coordinator?->isProvider(), 403);
        abort_unless($coordinator->hasPortalPermission('manage_programs'), 403);

        $validated = $request->validate([
            'section' => ['sometimes', Rule::in(['overview', 'drafts', 'review', 'published', 'closed'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $section = $validated['section'] ?? 'overview';
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $owner = $coordinator->providerOrganizationOwner()->loadMissing('providerProfile');
        $today = CarbonImmutable::today();
        $programs = $this->providerScholarshipsQuery($coordinator)
            ->with('events')
            ->withCount($this->providerProgramCountRelations())
            ->latest('updated_at')
            ->get()
            ->map(function (Scholarship $program) use ($today): array {
                $deadlineDays = $program->deadline
                    ? (int) $today->diffInDays(CarbonImmutable::parse($program->deadline)->startOfDay(), false)
                    : null;
                $deadlineState = match (true) {
                    $deadlineDays === null => 'not_set',
                    $deadlineDays < 0 => 'passed',
                    $deadlineDays <= 14 => 'due_soon',
                    default => 'scheduled',
                };
                $phase = match ($program->status) {
                    'draft', 'rejected' => 'drafts',
                    'pending_review' => 'review',
                    'closed' => 'closed',
                    default => 'published',
                };
                [$actionLabel, $actionHref] = match ($program->status) {
                    'draft' => ['Continue setup', "/provider/programs/{$program->id}/edit"],
                    'rejected' => ['Revise program', "/provider/programs/{$program->id}/edit"],
                    'pending_review' => ['View submission', "/provider/programs/{$program->id}"],
                    default => ['Open program', "/provider/programs/{$program->id}"],
                };

                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'category' => $program->category,
                    'program_cycle' => $program->program_cycle,
                    'status' => $program->status,
                    'status_label' => match ($program->status) {
                        'draft' => 'Draft setup',
                        'rejected' => 'Changes requested',
                        'pending_review' => 'Admin review',
                        'published' => 'Published',
                        'closed' => 'Closed',
                        default => Str::headline($program->status),
                    },
                    'phase' => $phase,
                    'deadline' => $program->deadline?->format('M d, Y'),
                    'deadline_iso' => $program->deadline?->format('Y-m-d'),
                    'deadline_days' => $deadlineDays,
                    'deadline_state' => $deadlineState,
                    'application_opens_at' => $program->application_opens_at?->format('M d, Y'),
                    'applications_count' => (int) $program->applications_count,
                    'application_limit' => $program->application_limit,
                    'slots_available' => $program->slots_available,
                    'bookmarks_count' => (int) $program->bookmarks_count,
                    'updated_at' => $program->updated_at?->format('M d, Y'),
                    'action_label' => $actionLabel,
                    'action_href' => $actionHref,
                ];
            })
            ->values();

        $phaseCounts = [
            'drafts' => $programs->where('phase', 'drafts')->count(),
            'review' => $programs->where('phase', 'review')->count(),
            'published' => $programs->where('phase', 'published')->count(),
            'closed' => $programs->where('phase', 'closed')->count(),
        ];
        $attention = $programs
            ->filter(fn (array $program): bool => in_array($program['status'], ['draft', 'rejected'], true)
                || ($program['status'] === 'published' && in_array($program['deadline_state'], ['passed', 'due_soon'], true)))
            ->sortBy(fn (array $program): int => match (true) {
                $program['status'] === 'rejected' => 0,
                $program['status'] === 'draft' => 1,
                $program['deadline_state'] === 'passed' => 2,
                default => 3,
            })
            ->values();

        $nextAction = match (true) {
            ! $owner->hasVerifiedEmail() || ! $owner->providerProfile?->isVerified() => [
                'type' => 'blocked',
                'title' => 'Organization verification is incomplete',
                'description' => 'Program publishing stays paused until the representative completes verification.',
                'label' => 'View verification',
                'href' => '/provider/profile/verification',
            ],
            $programs->isEmpty() => [
                'type' => 'create',
                'title' => 'Create the first scholarship program',
                'description' => 'Start with the support package, timeline, and applicant requirements.',
                'label' => 'Create program',
                'href' => '/provider/programs/create',
            ],
            $programs->contains('status', 'rejected') => $this->programCoordinatorAction(
                $programs->firstWhere('status', 'rejected'),
                'Address the requested program changes',
                'Revise program',
            ),
            $programs->contains('status', 'draft') => $this->programCoordinatorAction(
                $programs->firstWhere('status', 'draft'),
                'Finish the next program draft',
                'Continue setup',
            ),
            $programs->contains(fn (array $program): bool => $program['status'] === 'published' && $program['deadline_state'] === 'passed') => $this->programCoordinatorAction(
                $programs->first(fn (array $program): bool => $program['status'] === 'published' && $program['deadline_state'] === 'passed'),
                'Review a program with a passed deadline',
                'Open program',
            ),
            $programs->contains(fn (array $program): bool => $program['status'] === 'published' && $program['deadline_state'] === 'due_soon') => $this->programCoordinatorAction(
                $programs->first(fn (array $program): bool => $program['status'] === 'published' && $program['deadline_state'] === 'due_soon'),
                'Confirm an approaching deadline',
                'Review timeline',
            ),
            $phaseCounts['review'] > 0 => [
                'type' => 'waiting',
                'title' => 'Admin review is in progress',
                'description' => "{$phaseCounts['review']} program submission".($phaseCounts['review'] === 1 ? ' is' : 's are').' awaiting an admin result.',
                'label' => 'View submissions',
                'href' => '/provider/workspaces/programs/review',
            ],
            default => [
                'type' => 'steady',
                'title' => 'Published program details are current',
                'description' => 'Monitor dates and update public information when the program changes.',
                'label' => 'Review programs',
                'href' => '/provider/workspaces/programs/published',
            ],
        };

        $sectionPrograms = $section === 'overview'
            ? collect()
            : $programs->where('phase', $section)->values();

        if ($search !== '') {
            $sectionPrograms = $sectionPrograms
                ->filter(function (array $program) use ($search): bool {
                    $haystack = Str::lower(collect([
                        $program['title'],
                        $program['category'],
                        $program['program_cycle'],
                    ])->filter()->join(' '));

                    return Str::contains($haystack, $search);
                })
                ->values();
        }

        $sectionTotal = $sectionPrograms->count();
        $lastPage = max(1, (int) ceil($sectionTotal / $perPage));
        $page = min($page, $lastPage);
        $pagePrograms = $sectionPrograms->forPage($page, $perPage)->values();

        return response()->json([
            'workspace' => [
                'role' => 'Program coordinator',
                'staff_name' => $coordinator->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $coordinator->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'total' => $programs->count(),
                'attention' => $attention->count(),
                'applications' => $programs->sum('applications_count'),
                'published' => $phaseCounts['published'],
            ],
            'phases' => $phaseCounts,
            'next_action' => $nextAction,
            'attention_program_ids' => $attention->pluck('id')->all(),
            'section' => $section,
            'programs' => $pagePrograms,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $sectionTotal,
                'from' => $sectionTotal === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $sectionTotal === 0 ? null : min($page * $perPage, $sectionTotal),
            ],
        ]);
    }

    public function applicationReviewerWorkspaceData(Request $request): JsonResponse
    {
        $reviewer = $request->user();
        abort_unless($reviewer?->isProvider(), 403);
        abort_unless($reviewer->hasPortalPermission('verify_applications'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['mine', 'unassigned', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'mine';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $reviewer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($reviewer)
            ->orderBy('title')
            ->get(['id', 'title', 'status']);

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $reviewable = $this->providerApplicationsQuery($reviewer);
        $this->applyProviderApplicationFilter($reviewable, 'needs_review');

        if (! empty($validated['program_id'])) {
            $reviewable->where('scholarship_id', (int) $validated['program_id']);
        }

        $mineCount = (clone $reviewable)->where('assigned_reviewer_id', $reviewer->id)->count();
        $unassignedCount = (clone $reviewable)->whereNull('assigned_reviewer_id')->count();
        $teamCount = (clone $reviewable)->count();
        $oldestSubmittedAt = (clone $reviewable)->min('submitted_at');

        $nextReview = (clone $reviewable)
            ->where(fn (Builder $query) => $query
                ->where('assigned_reviewer_id', $reviewer->id)
                ->orWhereNull('assigned_reviewer_id'))
            ->with([
                'applicant.studentProfile',
                'documents',
                'assignedReviewer.providerProfile',
                'scholarship',
            ])
            ->orderByRaw('CASE WHEN assigned_reviewer_id = ? THEN 0 ELSE 1 END', [$reviewer->id])
            ->orderByRaw("CASE WHEN correction_status = 'submitted' THEN 0 ELSE 1 END")
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->first();

        $queueQuery = (clone $reviewable)
            ->with([
                'applicant.studentProfile',
                'documents',
                'assignedReviewer.providerProfile',
                'scholarship',
            ]);

        if ($queue === 'mine') {
            $queueQuery->where('assigned_reviewer_id', $reviewer->id);
        } elseif ($queue === 'unassigned') {
            $queueQuery->whereNull('assigned_reviewer_id');
        }

        $this->applyProviderApplicationSearch($queueQuery, $search);
        $queueQuery
            ->orderByRaw("CASE WHEN correction_status = 'submitted' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN assigned_reviewer_id = ? THEN 0 WHEN assigned_reviewer_id IS NULL THEN 1 ELSE 2 END', [$reviewer->id])
            ->orderBy('submitted_at')
            ->orderBy('id');

        $applications = $queueQuery->paginate($perPage);
        $applications->setCollection($applications->getCollection()
            ->map(fn (ScholarshipApplication $application): array => $this->applicationReviewerWorkspaceItem($application, $reviewer)));

        return response()->json([
            'workspace' => [
                'role' => 'Application reviewer',
                'staff_name' => $reviewer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $reviewer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'mine' => $mineCount,
                'unassigned' => $unassignedCount,
                'team' => $teamCount,
                'assigned_elsewhere' => max(0, $teamCount - $mineCount - $unassignedCount),
                'oldest_wait_days' => $oldestSubmittedAt
                    ? max(0, (int) CarbonImmutable::parse($oldestSubmittedAt)->startOfDay()->diffInDays(CarbonImmutable::today()))
                    : 0,
            ],
            'next_review' => $nextReview
                ? $this->applicationReviewerWorkspaceItem($nextReview, $reviewer)
                : null,
            'programs' => $programs->map(fn (Scholarship $program): array => [
                'id' => $program->id,
                'title' => $program->title,
                'status' => $program->status,
            ])->values(),
            'applications' => $applications->items(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'from' => $applications->firstItem(),
                'to' => $applications->lastItem(),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function selectionOfficerWorkspaceData(Request $request): JsonResponse
    {
        $officer = $request->user();
        abort_unless($officer?->isProvider(), 403);
        abort_unless($officer->hasPortalPermission('manage_selection_activities'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['setup', 'results', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'setup';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $officer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($officer)
            ->orderBy('title')
            ->get(['id', 'title', 'status']);

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $activeActivities = $this->providerApplicationsQuery($officer);
        $this->applySelectionOfficerQueueFilter($activeActivities, 'all');

        if (! empty($validated['program_id'])) {
            $activeActivities->where('scholarship_id', (int) $validated['program_id']);
        }

        $setupQuery = clone $activeActivities;
        $this->applySelectionOfficerQueueFilter($setupQuery, 'setup');
        $resultsQuery = clone $activeActivities;
        $this->applySelectionOfficerQueueFilter($resultsQuery, 'results');
        $stageCounts = (clone $activeActivities)
            ->selectRaw('workflow_stage, count(*) as total')
            ->groupBy('workflow_stage')
            ->pluck('total', 'workflow_stage');

        $nextActivity = (clone $activeActivities)
            ->with([
                'applicant.studentProfile',
                'schedules',
                'scholarship',
            ])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(150)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->selectionOfficerWorkspaceItem($application))
            ->sortBy(fn (array $application): string => sprintf(
                '%02d-%010d-%010d',
                $application['priority'],
                999999999 - min(999999999, $application['waiting_days']),
                $application['id'],
            ))
            ->first();

        $queueQuery = clone $activeActivities;
        $this->applySelectionOfficerQueueFilter($queueQuery, $queue);
        $queueQuery->with([
            'applicant.studentProfile',
            'schedules',
            'scholarship',
        ]);
        $this->applyProviderApplicationSearch($queueQuery, $search);
        $queueQuery
            ->orderBy('submitted_at')
            ->orderBy('id');

        $applications = $queueQuery->paginate($perPage);
        $applications->setCollection($applications->getCollection()
            ->map(fn (ScholarshipApplication $application): array => $this->selectionOfficerWorkspaceItem($application))
            ->sortBy('priority')
            ->values());

        return response()->json([
            'workspace' => [
                'role' => 'Selection officer',
                'staff_name' => $officer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $officer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'setup' => $setupQuery->count(),
                'results' => $resultsQuery->count(),
                'active' => (clone $activeActivities)->count(),
            ],
            'stages' => [
                'formal_application' => (int) ($stageCounts['formal_application'] ?? 0),
                'exam' => (int) ($stageCounts['exam'] ?? 0),
                'interview' => (int) ($stageCounts['interview'] ?? 0),
            ],
            'next_activity' => $nextActivity,
            'programs' => $programs->map(fn (Scholarship $program): array => [
                'id' => $program->id,
                'title' => $program->title,
                'status' => $program->status,
            ])->values(),
            'applications' => $applications->items(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'from' => $applications->firstItem(),
                'to' => $applications->lastItem(),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function decisionOfficerWorkspaceData(Request $request): JsonResponse
    {
        $officer = $request->user();
        abort_unless($officer?->isProvider(), 403);
        abort_unless($officer->hasPortalPermission('record_final_decisions'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['pending', 'waitlist', 'recorded'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'pending';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $officer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($officer)
            ->withCount($this->providerProgramCountRelations())
            ->orderBy('title')
            ->get();

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $decisionBase = $this->providerApplicationsQuery($officer);

        if (! empty($validated['program_id'])) {
            $decisionBase->where('scholarship_id', (int) $validated['program_id']);
        }

        $pendingQuery = clone $decisionBase;
        $this->applyDecisionOfficerQueueFilter($pendingQuery, 'pending');
        $waitlistQuery = clone $decisionBase;
        $this->applyDecisionOfficerQueueFilter($waitlistQuery, 'waitlist');
        $recordedQuery = clone $decisionBase;
        $this->applyDecisionOfficerQueueFilter($recordedQuery, 'recorded');

        $applicationRelations = [
            'applicant.studentProfile',
            'scholarship' => fn ($query) => $query->withCount($this->providerProgramCountRelations()),
        ];
        $nextDecision = (clone $pendingQuery)
            ->with($applicationRelations)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->first();

        if (! $nextDecision) {
            $nextDecision = (clone $waitlistQuery)
                ->with($applicationRelations)
                ->orderBy('waitlist_position')
                ->orderBy('waitlisted_at')
                ->limit(150)
                ->get()
                ->first(fn (ScholarshipApplication $application): bool => $this->decisionOfficerRemainingSlots($application->scholarship) !== 0);
        }

        $queueQuery = clone $decisionBase;
        $this->applyDecisionOfficerQueueFilter($queueQuery, $queue);
        $queueQuery->with($applicationRelations);
        $this->applyProviderApplicationSearch($queueQuery, $search);

        if ($queue === 'waitlist') {
            $queueQuery->orderBy('waitlist_position')->orderBy('waitlisted_at');
        } elseif ($queue === 'recorded') {
            $queueQuery->latest('outcome_at')->latest('reviewed_at')->latest('id');
        } else {
            $queueQuery->orderBy('submitted_at')->orderBy('id');
        }

        $applications = $queueQuery->paginate($perPage);
        $applications->setCollection($applications->getCollection()
            ->map(fn (ScholarshipApplication $application): array => $this->decisionOfficerWorkspaceItem($application)));

        return response()->json([
            'workspace' => [
                'role' => 'Decision officer',
                'staff_name' => $officer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $officer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'pending' => $pendingQuery->count(),
                'waitlist' => $waitlistQuery->count(),
                'recorded' => $recordedQuery->count(),
            ],
            'next_decision' => $nextDecision
                ? $this->decisionOfficerWorkspaceItem($nextDecision)
                : null,
            'programs' => $programs->map(function (Scholarship $program): array {
                $remaining = $this->decisionOfficerRemainingSlots($program);

                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'status' => $program->status,
                    'slots_total' => $program->slots_available,
                    'slots_occupied' => (int) $program->awarded_slots_count,
                    'slots_remaining' => $remaining,
                ];
            })->values(),
            'applications' => $applications->items(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'from' => $applications->firstItem(),
                'to' => $applications->lastItem(),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function recipientOfficerWorkspaceData(Request $request): JsonResponse
    {
        $officer = $request->user();
        abort_unless($officer?->isProvider(), 403);
        abort_unless($officer->hasPortalPermission('manage_recipients'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['awaiting', 'active', 'declined', 'closed'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'awaiting';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $officer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($officer)
            ->orderBy('title')
            ->get(['id', 'title', 'status', 'support_starts_at', 'support_ends_at']);

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $recipientBase = $this->providerApplicationsQuery($officer)
            ->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'selected')
                    ->orWhereIn('status', [...self::AWARD_SLOT_STATUSES, 'benefits_terminated']);
            });

        if (! empty($validated['program_id'])) {
            $recipientBase->where('scholarship_id', (int) $validated['program_id']);
        }

        $awaitingQuery = clone $recipientBase;
        $this->applyRecipientOfficerQueueFilter($awaitingQuery, 'awaiting');
        $activeQuery = clone $recipientBase;
        $this->applyRecipientOfficerQueueFilter($activeQuery, 'active');
        $declinedQuery = clone $recipientBase;
        $this->applyRecipientOfficerQueueFilter($declinedQuery, 'declined');
        $closedQuery = clone $recipientBase;
        $this->applyRecipientOfficerQueueFilter($closedQuery, 'closed');

        $relations = [
            'applicant.studentProfile',
            'scholarship',
            'supportDecisions.decider',
        ];
        $nextRecipient = (clone $declinedQuery)
            ->with($relations)
            ->orderBy('student_responded_at')
            ->orderBy('id')
            ->first();

        if (! $nextRecipient) {
            $nextRecipient = (clone $awaitingQuery)
                ->with($relations)
                ->orderBy('outcome_at')
                ->orderBy('id')
                ->first();
        }

        $queueQuery = clone $recipientBase;
        $this->applyRecipientOfficerQueueFilter($queueQuery, $queue);
        $queueQuery->with($relations);
        $this->applyProviderApplicationSearch($queueQuery, $search);

        if ($queue === 'declined') {
            $queueQuery->orderBy('student_responded_at')->orderBy('id');
        } elseif ($queue === 'closed') {
            $queueQuery->latest('reviewed_at')->latest('id');
        } elseif ($queue === 'active') {
            $queueQuery->orderBy('scholarship_id')->orderBy('applicant_id');
        } else {
            $queueQuery->orderBy('outcome_at')->orderBy('id');
        }

        $recipients = $queueQuery->paginate($perPage);
        $recipients->setCollection($recipients->getCollection()
            ->map(fn (ScholarshipApplication $application): array => $this->recipientOfficerWorkspaceItem($application)));

        return response()->json([
            'workspace' => [
                'role' => 'Recipient officer',
                'staff_name' => $officer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $officer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => [
                'awaiting' => $awaitingQuery->count(),
                'active' => $activeQuery->count(),
                'declined' => $declinedQuery->count(),
                'closed' => $closedQuery->count(),
            ],
            'next_recipient' => $nextRecipient
                ? $this->recipientOfficerWorkspaceItem($nextRecipient)
                : null,
            'programs' => $programs->map(fn (Scholarship $program): array => [
                'id' => $program->id,
                'title' => $program->title,
                'status' => $program->status,
                'support_period' => collect([
                    $program->support_starts_at?->format('M d, Y'),
                    $program->support_ends_at?->format('M d, Y'),
                ])->filter()->implode(' to '),
            ])->values(),
            'recipients' => $recipients->items(),
            'pagination' => [
                'current_page' => $recipients->currentPage(),
                'last_page' => $recipients->lastPage(),
                'per_page' => $recipients->perPage(),
                'total' => $recipients->total(),
                'from' => $recipients->firstItem(),
                'to' => $recipients->lastItem(),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function monitoringOfficerWorkspaceData(Request $request): JsonResponse
    {
        $officer = $request->user();
        abort_unless($officer?->isProvider(), 403);
        abort_unless($officer->hasPortalPermission('manage_monitoring'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['review', 'followup', 'awaiting', 'history'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'review';
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $officer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($officer)
            ->with(['monitoringPlan.requirements'])
            ->orderBy('title')
            ->get();

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
            $programs = $programs->where('id', (int) $validated['program_id'])->values();
        }

        $cycles = RecipientMonitoringCycle::query()
            ->whereIn('scholarship_id', $programs->pluck('id'))
            ->with([
                'scholarship',
                'creator',
                'requirements',
                'submissions.requirement',
                'submissions.reviewer',
                'submissions.reviews.reviewer',
                'adjustmentRequests.decider',
                'interventions.creator',
            ])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();
        $recipientApplications = $programs->mapWithKeys(fn (Scholarship $program): array => [
            $program->id => $this->selectedRecipientApplications($program),
        ]);
        $items = $cycles->map(function (RecipientMonitoringCycle $cycle) use ($recipientApplications): array {
            $payload = $this->recipientMonitoringCyclePayload(
                $cycle,
                $recipientApplications->get($cycle->scholarship_id, new EloquentCollection),
            );

            return $this->monitoringOfficerWorkspaceItem($cycle, $payload);
        });
        $summary = [
            'review' => $items->where('work_state', 'review')->count(),
            'followup' => $items->where('work_state', 'followup')->count(),
            'awaiting' => $items->where('work_state', 'awaiting')->count(),
            'history' => $items->where('work_state', 'history')->count(),
        ];
        $nextTask = $items
            ->whereIn('work_state', ['review', 'followup'])
            ->sortBy(fn (array $item): string => sprintf(
                '%02d-%s-%010d',
                $item['priority'],
                $item['due_at'] ?: '9999-12-31',
                $item['id'],
            ))
            ->first();

        if (! $nextTask) {
            $nextProgram = $programs->first(function (Scholarship $program) use ($items, $recipientApplications): bool {
                return $program->monitoringPlan?->status === 'active'
                    && $recipientApplications->get($program->id, new EloquentCollection)->isNotEmpty()
                    && ! $items->contains(fn (array $item): bool => $item['program']['id'] === $program->id
                        && $item['cycle_status'] === 'open');
            });

            if ($nextProgram) {
                $nextTask = [
                    'kind' => 'program',
                    'id' => $nextProgram->id,
                    'work_state' => 'publish',
                    'work_label' => 'Check-in needed',
                    'title' => 'Publish the next recipient check-in',
                    'detail' => 'The monitoring plan is active and no check-in is currently open.',
                    'program' => ['id' => $nextProgram->id, 'title' => $nextProgram->title],
                    'action_label' => 'Open monitoring',
                    'action_url' => route('provider.monitoring.academic', $nextProgram, false),
                ];
            }
        }

        $filtered = $items->where('work_state', $queue);

        if ($search !== '') {
            $filtered = $filtered->filter(fn (array $item): bool => Str::contains(
                Str::lower($item['title'].' '.$item['program']['title'].' '.$item['period_label']),
                $search,
            ));
        }

        $filtered = $filtered->sortBy(fn (array $item): string => sprintf(
            '%02d-%s-%010d',
            $item['priority'],
            $item['due_at'] ?: '9999-12-31',
            $item['id'],
        ))->values();
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'workspace' => [
                'role' => 'Monitoring officer',
                'staff_name' => $officer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $officer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => $summary,
            'next_task' => $nextTask,
            'programs' => $programs->map(function (Scholarship $program) use ($items, $recipientApplications): array {
                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'status' => $program->status,
                    'plan_status' => $program->monitoringPlan?->status ?? 'missing',
                    'plan_status_label' => match ($program->monitoringPlan?->status) {
                        'active' => 'Active plan',
                        'draft' => 'Draft plan',
                        default => 'No plan',
                    },
                    'recipient_count' => $recipientApplications->get($program->id, new EloquentCollection)->count(),
                    'open_check_in_count' => $items
                        ->where('program.id', $program->id)
                        ->where('cycle_status', 'open')
                        ->count(),
                ];
            })->values(),
            'check_ins' => $pageItems,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function benefitReleaseOfficerWorkspaceData(Request $request): JsonResponse
    {
        $officer = $request->user();
        abort_unless($officer?->isProvider(), 403);
        abort_unless($officer->hasPortalPermission('manage_benefit_releases'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['issues', 'record', 'upcoming', 'history'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'issues';
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $officer->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerScholarshipsQuery($officer)
            ->orderBy('title')
            ->get(['id', 'title', 'status']);

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $programIds = ! empty($validated['program_id'])
            ? collect([(int) $validated['program_id']])
            : $programs->pluck('id');
        $releases = RecipientBenefitRelease::query()
            ->whereIn('scholarship_id', $programIds)
            ->with([
                'scholarship',
                'creator',
                'records.applicant.studentProfile',
                'records.recorder',
                'records.receiptResponse.resolver',
            ])
            ->orderBy('release_at')
            ->orderBy('id')
            ->get();
        $items = $releases->map(fn (RecipientBenefitRelease $release): array => $this->benefitReleaseOfficerWorkspaceItem($release));
        $summary = [
            'issues' => $items->where('work_state', 'issues')->count(),
            'record' => $items->where('work_state', 'record')->count(),
            'upcoming' => $items->where('work_state', 'upcoming')->count(),
            'history' => $items->where('work_state', 'history')->count(),
        ];
        $nextTask = $items
            ->whereIn('work_state', ['issues', 'record'])
            ->sortBy(fn (array $item): string => sprintf(
                '%02d-%s-%010d',
                $item['priority'],
                $item['release_at'] ?: '9999-12-31T23:59',
                $item['id'],
            ))
            ->first();
        $recipientApplications = $programs->mapWithKeys(fn (Scholarship $program): array => [
            $program->id => $this->selectedRecipientApplications($program)
                ->where('student_response_status', 'accepted')
                ->values(),
        ]);

        if (! $nextTask) {
            $nextProgram = $programs->first(function (Scholarship $program) use ($items, $recipientApplications): bool {
                return $recipientApplications->get($program->id, new EloquentCollection)->isNotEmpty()
                    && ! $items->contains(fn (array $item): bool => $item['program']['id'] === $program->id
                        && in_array($item['work_state'], ['record', 'upcoming'], true));
            });

            if ($nextProgram) {
                $nextTask = [
                    'kind' => 'program',
                    'id' => $nextProgram->id,
                    'work_state' => 'schedule',
                    'work_label' => 'Release needed',
                    'title' => 'Schedule the next benefit distribution',
                    'detail' => 'Active recipients are ready and no pending release is scheduled for this program.',
                    'program' => ['id' => $nextProgram->id, 'title' => $nextProgram->title],
                    'action_label' => 'Schedule release',
                    'action_url' => route('provider.monitoring.releases', $nextProgram, false),
                ];
            }
        }

        $filtered = $items->where('work_state', $queue);

        if ($search !== '') {
            $filtered = $filtered->filter(fn (array $item): bool => Str::contains(
                Str::lower(implode(' ', [
                    $item['title'],
                    $item['program']['title'],
                    $item['benefit_description'],
                    $item['release_method_label'],
                ])),
                $search,
            ));
        }

        $filtered = ($queue === 'history'
            ? $filtered->sortByDesc(fn (array $item): string => $item['release_at'] ?: '')
            : $filtered->sortBy(fn (array $item): string => sprintf(
                '%02d-%s-%010d',
                $item['priority'],
                $item['release_at'] ?: '9999-12-31T23:59',
                $item['id'],
            )))->values();
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'workspace' => [
                'role' => 'Benefit release officer',
                'staff_name' => $officer->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $officer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => $summary,
            'next_task' => $nextTask,
            'programs' => $programs->map(function (Scholarship $program) use ($items, $recipientApplications): array {
                return [
                    'id' => $program->id,
                    'title' => $program->title,
                    'status' => $program->status,
                    'recipient_count' => $recipientApplications->get($program->id, new EloquentCollection)->count(),
                    'open_release_count' => $items
                        ->where('program.id', $program->id)
                        ->whereIn('work_state', ['issues', 'record', 'upcoming'])
                        ->count(),
                    'schedule_url' => route('provider.monitoring.releases', $program, false),
                ];
            })->values(),
            'releases' => $pageItems,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
            ],
            'active_queue' => $queue,
        ]);
    }

    public function organizationProfileManagerWorkspaceData(Request $request): JsonResponse
    {
        $manager = $request->user();
        abort_unless($manager?->isProvider(), 403);
        abort_unless($manager->hasPortalPermission('manage_profile'), 403);

        $owner = $manager->providerOrganizationOwner()->loadMissing('providerProfile');
        $profile = $owner->providerProfile;
        $documents = $owner->providerVerificationDocuments()
            ->latest('uploaded_at')
            ->latest('id')
            ->get();
        $identityFields = [
            'Organization name' => $profile?->provider_name,
            'Provider type' => $profile?->provider_type,
            'Public description' => $profile?->provider_description,
            'Organization logo' => $profile?->logo_path,
        ];
        $contactFields = [
            'Public email' => $profile?->provider_contact_email,
            'Public phone' => $profile?->provider_contact_number,
        ];
        $locationFields = [
            'Office address' => $profile?->provider_address,
            'Service area' => $profile?->service_area,
        ];
        $verificationFields = [
            'Legal name' => $profile?->legal_name,
            'Registration authority' => $profile?->registration_authority,
            'Registration number' => $profile?->registration_number,
            'Verification proof' => $documents->isNotEmpty() ? 'uploaded' : null,
        ];
        $sectionPayload = function (
            string $key,
            string $title,
            string $description,
            string $icon,
            array $fields,
            string $actionLabel,
            string $actionUrl,
        ): array {
            $missing = collect($fields)
                ->filter(fn (mixed $value): bool => blank($value))
                ->keys()
                ->values();
            $complete = count($fields) - $missing->count();

            return [
                'key' => $key,
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
                'complete' => $complete,
                'total' => count($fields),
                'is_ready' => $missing->isEmpty(),
                'status_label' => $missing->isEmpty() ? 'Ready' : $missing->count().' missing',
                'missing' => $missing,
                'action_label' => $actionLabel,
                'action_url' => $actionUrl,
            ];
        };
        $sections = collect([
            $sectionPayload(
                'identity',
                'Public identity',
                'The name, type, logo, and short introduction applicants see.',
                'fa-building',
                $identityFields,
                'Edit identity',
                route('provider.profile.details', absolute: false),
            ),
            $sectionPayload(
                'contact',
                'Applicant contact',
                'The public email and phone applicants use for program questions.',
                'fa-address-book',
                $contactFields,
                'Edit contact',
                route('provider.profile.details', absolute: false),
            ),
            $sectionPayload(
                'location',
                'Location and reach',
                'The office location and service area used across scholarship pages.',
                'fa-location-dot',
                $locationFields,
                'Edit location',
                route('provider.profile.details', absolute: false),
            ),
            $sectionPayload(
                'verification',
                'Legal verification',
                'Registration details and proof used for administrator review.',
                'fa-shield-halved',
                $verificationFields,
                'Manage proof',
                route('provider.profile.verification', absolute: false),
            ),
        ]);
        $totalFields = $sections->sum('total');
        $completeFields = $sections->sum('complete');
        $completionPercentage = $totalFields > 0
            ? (int) round(($completeFields / $totalFields) * 100)
            : 0;
        $verificationStatus = $profile?->verification_status ?: 'pending';
        $verificationDisplayStatus = match (true) {
            $verificationStatus === 'approved' => 'approved',
            $verificationStatus === 'rejected' => 'rejected',
            $documents->isEmpty() => 'unsubmitted',
            default => 'pending',
        };
        $optionalMissing = collect([
            'Website' => $profile?->provider_website,
            'Mission' => $profile?->mission,
            'Office hours' => $profile?->office_hours,
            'Contact department' => $profile?->contact_department,
        ])->filter(fn (mixed $value): bool => blank($value))->keys()->values();
        $firstIncomplete = $sections->firstWhere('is_ready', false);
        $nextTask = match (true) {
            $verificationStatus === 'rejected' => [
                'state' => 'attention',
                'label' => 'Changes requested',
                'title' => 'Replace the rejected verification proof',
                'detail' => $profile?->verification_notes ?: 'Review the administrator note and upload corrected organization evidence.',
                'action_label' => 'Review verification',
                'action_url' => route('provider.profile.verification', absolute: false),
            ],
            ! $owner->hasVerifiedEmail() => [
                'state' => 'waiting',
                'label' => 'Owner action',
                'title' => 'Organization email is not verified',
                'detail' => 'The organization owner must use the email verification link before publishing access can be approved.',
                'action_label' => 'View verification',
                'action_url' => route('provider.profile.verification', absolute: false),
            ],
            $firstIncomplete !== null => [
                'state' => 'complete',
                'label' => 'Profile task',
                'title' => 'Complete '.$firstIncomplete['title'],
                'detail' => 'Add '.Str::lower($firstIncomplete['missing']->take(2)->implode(' and ')).'.',
                'action_label' => $firstIncomplete['action_label'],
                'action_url' => $firstIncomplete['action_url'],
            ],
            $verificationStatus === 'pending' => [
                'state' => 'waiting',
                'label' => 'Admin review',
                'title' => 'Verification proof is under review',
                'detail' => 'The profile is complete. Wait for the administrator decision before replacing submitted proof.',
                'action_label' => 'View submitted proof',
                'action_url' => route('provider.profile.verification', absolute: false),
            ],
            $optionalMissing->isNotEmpty() => [
                'state' => 'improve',
                'label' => 'Optional improvement',
                'title' => 'Add more public organization context',
                'detail' => 'Consider adding '.Str::lower($optionalMissing->take(2)->implode(' and ')).'.',
                'action_label' => 'Improve profile',
                'action_url' => route('provider.profile.details', absolute: false),
            ],
            default => null,
        };

        return response()->json([
            'workspace' => [
                'role' => 'Organization profile manager',
                'staff_name' => $manager->name,
                'organization_name' => $profile?->provider_name ?: $owner->name,
            ],
            'profile' => [
                'name' => $profile?->provider_name ?: $owner->name,
                'type' => $profile?->provider_type ? Str::headline($profile->provider_type) : 'Type not set',
                'logo_url' => filled($profile?->logo_path) ? asset(ltrim($profile->logo_path, '/')) : null,
                'updated_at' => $profile?->updated_at?->format('M d, Y h:i A'),
                'verification_status' => $verificationDisplayStatus,
                'verification_status_label' => match ($verificationDisplayStatus) {
                    'approved' => 'Verified',
                    'rejected' => 'Changes requested',
                    'unsubmitted' => 'Proof not submitted',
                    default => 'Pending review',
                },
            ],
            'summary' => [
                'completion_percentage' => $completionPercentage,
                'ready_sections' => $sections->where('is_ready', true)->count(),
                'total_sections' => $sections->count(),
                'document_count' => $documents->count(),
                'optional_missing_count' => $optionalMissing->count(),
            ],
            'next_task' => $nextTask,
            'sections' => $sections->values(),
            'recent_documents' => $documents
                ->take(3)
                ->map(fn (ProviderVerificationDocument $document): array => $this->verificationDocumentPayload($document))
                ->values(),
        ]);
    }

    public function teamAdministratorWorkspaceData(Request $request): JsonResponse
    {
        $administrator = $request->user();
        abort_unless($administrator?->isProvider(), 403);
        abort_unless($administrator->hasPortalPermission('manage_team'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['attention', 'active', 'suspended'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:30'],
        ]);
        $queue = $validated['queue'] ?? 'attention';
        $search = Str::lower(trim((string) ($validated['search'] ?? '')));
        $role = trim((string) ($validated['role'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 12);
        $owner = $administrator->providerOrganizationOwner()->loadMissing('providerProfile');
        $administratorPermissions = $administrator->effectivePortalPermissions();
        $administratorProgramIds = $administrator->assignedProviderProgramIds();
        $accounts = User::query()
            ->with(['providerProfile', 'parentAccount.providerProfile'])
            ->where('role', 'provider')
            ->where('parent_account_id', $owner->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $items = $accounts->map(function (User $account) use (
            $administrator,
            $administratorPermissions,
            $administratorProgramIds,
        ): array {
            $payload = $this->providerTeamAccountPayload($account);
            $unverified = ! $account->hasVerifiedEmail();
            $passwordReset = (bool) $account->must_reset_password;
            $suspended = $account->isSuspended();
            $broaderPermissions = array_diff($account->effectivePortalPermissions(), $administratorPermissions) !== [];
            $broaderPrograms = $administrator->hasLimitedProviderProgramAccess()
                && (! $account->hasLimitedProviderProgramAccess()
                    || array_diff($account->assignedProviderProgramIds(), $administratorProgramIds) !== []);
            $canManage = ! $broaderPermissions && ! $broaderPrograms;
            [$state, $label, $detail] = match (true) {
                $suspended => [
                    'suspended',
                    'Suspended',
                    'Sign-in is disabled until an authorized administrator reactivates this account.',
                ],
                $unverified && $passwordReset => [
                    'attention',
                    'Setup incomplete',
                    'Email verification and the temporary password replacement are still pending.',
                ],
                $unverified => [
                    'attention',
                    'Email unverified',
                    'The team member has not verified the account email.',
                ],
                $passwordReset => [
                    'attention',
                    'Password reset pending',
                    'The temporary password must be replaced before normal workspace access.',
                ],
                default => [
                    'active',
                    'Access active',
                    'Account setup is complete and assigned access is available.',
                ],
            };

            return [
                'id' => $account->id,
                'name' => $payload['name'],
                'email' => $account->email,
                'username' => $account->username,
                'team_role' => $payload['team_role'],
                'team_role_label' => $payload['team_role_label'],
                'work_state' => $state,
                'work_label' => $label,
                'detail' => $detail,
                'email_verified' => ! $unverified,
                'must_reset_password' => $passwordReset,
                'account_status' => $account->account_status ?? 'active',
                'permission_count' => count($payload['permissions'] ?? []),
                'permission_labels' => collect($payload['permissions'] ?? [])
                    ->map(fn (string $permission): string => self::PROVIDER_GOVERNANCE_RESPONSIBILITIES[$permission]['label']
                        ?? Str::headline($permission))
                    ->values(),
                'program_access_mode' => $payload['program_access_mode'],
                'assigned_program_count' => count($payload['assigned_program_ids'] ?? []),
                'assigned_programs' => $payload['assigned_programs'],
                'is_current_account' => $administrator->is($account),
                'can_manage' => $canManage,
                'protected_reason' => $canManage ? null : 'This account has access beyond your administrative scope.',
                'created_at' => $payload['created_at'],
                'action_url' => $canManage
                    ? route('provider.team.accounts.edit', $account, false)
                    : null,
            ];
        });
        $summary = [
            'attention' => $items->where('work_state', 'attention')->count(),
            'active' => $items->where('work_state', 'active')->count(),
            'suspended' => $items->where('work_state', 'suspended')->count(),
            'total' => $items->count(),
        ];
        $nextAccount = $items
            ->where('work_state', 'attention')
            ->where('can_manage', true)
            ->sortBy('id')
            ->first();
        $nextTask = $nextAccount
            ? [
                'state' => 'attention',
                'label' => $nextAccount['work_label'],
                'title' => 'Finish access setup for '.$nextAccount['name'],
                'detail' => $nextAccount['detail'],
                'action_label' => 'Review account',
                'action_url' => $nextAccount['action_url'],
            ]
            : ($items->isEmpty() ? [
                'state' => 'create',
                'label' => 'Team setup',
                'title' => 'Create the first delegated staff account',
                'detail' => 'Give each staff member a separate login and only the access needed for their role.',
                'action_label' => 'Add team member',
                'action_url' => route('provider.team.accounts.create', absolute: false),
            ] : null);
        $filtered = $items->where('work_state', $queue);

        if ($role !== '') {
            $filtered = $filtered->where('team_role', $role);
        }

        if ($search !== '') {
            $filtered = $filtered->filter(fn (array $item): bool => Str::contains(
                Str::lower(implode(' ', [
                    $item['name'],
                    $item['email'],
                    $item['username'],
                    $item['team_role_label'],
                ])),
                $search,
            ));
        }

        $filtered = $filtered->sortBy(fn (array $item): string => sprintf(
            '%s-%s-%010d',
            Str::lower($item['team_role_label']),
            Str::lower($item['name']),
            $item['id'],
        ))->values();
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'workspace' => [
                'role' => 'Team administrator',
                'staff_name' => $administrator->name,
                'organization_name' => $owner->provider_name ?: $owner->name,
                'program_access_mode' => $administrator->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
                'grantable_permission_count' => count($this->grantableProviderPermissions($administrator)),
            ],
            'summary' => $summary,
            'next_task' => $nextTask,
            'roles' => $items
                ->map(fn (array $item): array => [
                    'value' => $item['team_role'],
                    'label' => $item['team_role_label'],
                ])
                ->unique('value')
                ->sortBy('label')
                ->values(),
            'accounts' => $pageItems,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($page * $perPage, $total),
            ],
            'active_queue' => $queue,
        ]);
    }

    private function programCoordinatorAction(array $program, string $title, string $label): array
    {
        return [
            'type' => 'program',
            'title' => $title,
            'description' => $program['title'],
            'label' => $label,
            'href' => $program['action_href'],
        ];
    }

    private function applicationReviewerWorkspaceItem(ScholarshipApplication $application, User $reviewer): array
    {
        $application->loadMissing([
            'applicant.studentProfile',
            'documents',
            'assignedReviewer.providerProfile',
            'scholarship',
        ]);
        $readiness = $this->documentReadiness($application);
        $submittedAt = $application->submitted_at ?? $application->created_at;
        $latestDocumentUploadedAt = $application->documents
            ->pluck('uploaded_at')
            ->filter()
            ->sortDesc()
            ->first();
        $documentsChanged = (bool) ($latestDocumentUploadedAt
            && $application->reviewed_at
            && $latestDocumentUploadedAt->gt($application->reviewed_at));
        $required = (int) ($readiness['required'] ?? 0);
        $accepted = (int) ($readiness['accepted'] ?? 0);
        $uploaded = (int) ($readiness['uploaded'] ?? 0);
        [$evidenceState, $evidenceLabel] = match (true) {
            $application->correction_status === 'submitted' => ['returned', 'Corrections returned'],
            $documentsChanged => ['updated', 'New evidence uploaded'],
            ! empty($readiness['needs_attention']) => ['replacement', 'Replacement needed'],
            ! empty($readiness['missing']) => ['missing', 'Missing evidence'],
            $required === 0 => ['not_required', 'No files required'],
            (bool) ($readiness['ready'] ?? false) => ['ready', 'Evidence accepted'],
            default => ['pending', 'Files need review'],
        };
        $profileStatus = $application->applicant?->applicantAcademicVerificationStatus() ?? 'unsubmitted';
        $assignmentState = match (true) {
            $application->assigned_reviewer_id === $reviewer->id => 'mine',
            $application->assigned_reviewer_id === null => 'unassigned',
            default => 'team',
        };

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application, false),
            'applicant' => [
                'name' => $application->applicant?->name ?: 'Applicant record',
                'education' => collect([
                    $application->applicant?->studentProfile?->education_level,
                    $application->applicant?->studentProfile?->course_or_strand,
                    $application->applicant?->studentProfile?->year_level,
                ])->filter()->implode(' - '),
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
                'verification_status' => $profileStatus,
                'verification_label' => match ($profileStatus) {
                    'approved' => 'Profile verified',
                    'pending' => 'Profile pending',
                    'needs_replacement' => 'Profile needs update',
                    default => 'Profile not verified',
                },
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $application->scholarship?->title ?: 'Scholarship program',
            ],
            'eligibility_score' => $application->eligibility_score !== null
                ? round((float) $application->eligibility_score, 1)
                : null,
            'evidence' => [
                'state' => $evidenceState,
                'label' => $evidenceLabel,
                'required' => $required,
                'uploaded' => $uploaded,
                'accepted' => $accepted,
                'detail' => $required === 0
                    ? 'This program does not require application files.'
                    : "{$accepted} of {$required} required files accepted",
            ],
            'assignment' => [
                'state' => $assignmentState,
                'label' => match ($assignmentState) {
                    'mine' => 'Assigned to you',
                    'unassigned' => 'Unassigned',
                    default => $application->assignedReviewer?->name ?: 'Assigned to team',
                },
            ],
            'correction_status' => $application->correction_status,
            'waiting_days' => $submittedAt
                ? max(0, (int) CarbonImmutable::parse($submittedAt)->startOfDay()->diffInDays(CarbonImmutable::today()))
                : 0,
            'submitted_at' => $submittedAt?->format('M d, Y h:i A'),
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
        ];
    }

    private function applyDecisionOfficerQueueFilter(Builder $query, string $queue): void
    {
        if ($queue === 'pending') {
            $query
                ->where('workflow_stage', 'decision')
                ->whereNull('final_outcome')
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($queue === 'waitlist') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'waitlisted')
                    ->orWhere('status', 'waitlisted');
            });

            return;
        }

        $query->where(function (Builder $query): void {
            $query
                ->whereIn('final_outcome', ['selected', 'not_selected'])
                ->orWhereIn('status', [
                    'awarded',
                    'distribution_scheduled',
                    'disbursed',
                    'renewed',
                    'benefits_terminated',
                    'not_awarded',
                ]);
        });
    }

    private function decisionOfficerRemainingSlots(?Scholarship $scholarship): ?int
    {
        if (! $scholarship || $scholarship->slots_available === null) {
            return null;
        }

        $occupied = array_key_exists('awarded_slots_count', $scholarship->getAttributes())
            ? (int) $scholarship->awarded_slots_count
            : $scholarship->applications()->whereIn('status', self::AWARD_SLOT_STATUSES)->count();

        return max(0, (int) $scholarship->slots_available - $occupied);
    }

    private function decisionOfficerWorkspaceItem(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'applicant.studentProfile',
            'scholarship' => fn ($query) => $query->withCount($this->providerProgramCountRelations()),
        ]);
        $program = $application->scholarship;
        $remaining = $this->decisionOfficerRemainingSlots($program);
        $submittedAt = $application->submitted_at ?? $application->created_at;
        $outcome = $application->final_outcome;
        [$state, $label, $actionLabel] = match ($outcome) {
            'selected' => ['selected', 'Selected', 'View record'],
            'waitlisted' => ['waitlist', 'Waitlist #'.($application->waitlist_position ?: '-'), $remaining === 0 ? 'Review waitlist' : 'Review promotion'],
            'not_selected' => ['not_selected', 'Not selected', 'View record'],
            default => ['pending', 'Decision required', 'Record decision'],
        };
        $referenceAt = match ($state) {
            'waitlist' => $application->waitlisted_at,
            'selected', 'not_selected' => $application->outcome_at ?? $application->reviewed_at,
            default => $submittedAt,
        };
        $occupied = (int) ($program?->awarded_slots_count ?? 0);

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application, false),
            'applicant' => [
                'name' => $application->applicant?->name ?: 'Applicant record',
                'education' => collect([
                    $application->applicant?->studentProfile?->education_level,
                    $application->applicant?->studentProfile?->course_or_strand,
                    $application->applicant?->studentProfile?->year_level,
                ])->filter()->implode(' - '),
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $program?->title ?: 'Scholarship program',
            ],
            'decision' => [
                'state' => $state,
                'label' => $label,
                'action_label' => $actionLabel,
                'reason' => $application->decision_reason ? Str::headline($application->decision_reason) : null,
                'recorded_at' => $referenceAt?->format('M d, Y'),
            ],
            'capacity' => [
                'total' => $program?->slots_available,
                'occupied' => $occupied,
                'remaining' => $remaining,
                'state' => $remaining === null ? 'unlimited' : ($remaining === 0 ? 'full' : 'available'),
                'label' => $remaining === null
                    ? 'No slot limit'
                    : "{$occupied} of {$program->slots_available} awarded",
            ],
            'stage_status' => 'Configured stages complete',
            'waiting_days' => $referenceAt
                ? max(0, (int) CarbonImmutable::parse($referenceAt)->startOfDay()->diffInDays(CarbonImmutable::today()))
                : 0,
            'submitted_at' => $submittedAt?->format('M d, Y h:i A'),
        ];
    }

    private function applyRecipientOfficerQueueFilter(Builder $query, string $queue): void
    {
        $closed = static fn (Builder $query) => $query
            ->where('status', 'benefits_terminated')
            ->orWhereHas('supportDecisions', fn (Builder $decisionQuery) => $decisionQuery
                ->whereIn('decision', ['completed', 'terminated']));

        if ($queue === 'awaiting') {
            $query
                ->whereNull('student_response_status')
                ->whereNot($closed);

            return;
        }

        if ($queue === 'active') {
            $query
                ->where('student_response_status', 'accepted')
                ->whereNot($closed);

            return;
        }

        if ($queue === 'declined') {
            $query->where('student_response_status', 'declined');

            return;
        }

        $query->where($closed);
    }

    private function recipientOfficerWorkspaceItem(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'applicant.studentProfile',
            'scholarship',
            'supportDecisions.decider',
        ]);
        $agreement = RecipientAgreement::payload($application);
        $latestDecision = $application->supportDecisions->first();
        $isClosed = $application->status === 'benefits_terminated'
            || in_array($latestDecision?->decision, ['completed', 'terminated'], true);
        $agreementStatus = $agreement['status'] ?? 'pending';
        [$state, $label, $nextStep, $actionLabel] = match (true) {
            $isClosed => [
                'closed',
                match ($latestDecision?->decision) {
                    'completed' => 'Support completed',
                    default => 'Support ended',
                },
                'Recipient support record is closed',
                'View record',
            ],
            $agreementStatus === 'declined' => ['declined', 'Agreement declined', 'Review the applicant response', 'Review response'],
            $agreementStatus === 'accepted' => [
                'active',
                $latestDecision?->decision === 'renewed' ? 'Support renewed' : 'Onboarding complete',
                'Recipient is ready for support management',
                'Manage support',
            ],
            default => ['awaiting', 'Awaiting agreement', 'Applicant response is required', 'Review agreement'],
        };
        $referenceAt = $application->student_responded_at
            ?? $application->outcome_at
            ?? $application->reviewed_at
            ?? $application->submitted_at;
        $usesSupportRecord = in_array($state, ['active', 'closed'], true);

        return [
            'id' => $application->id,
            'action_url' => $usesSupportRecord
                ? route('provider.monitoring.outcomes', $application->scholarship_id, false)
                : route('provider.applications.show', $application, false).'?section=decision',
            'applicant' => [
                'name' => $application->applicant?->name ?: 'Recipient record',
                'education' => collect([
                    $application->applicant?->studentProfile?->education_level,
                    $application->applicant?->studentProfile?->course_or_strand,
                    $application->applicant?->studentProfile?->year_level,
                ])->filter()->implode(' - '),
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $application->scholarship?->title ?: 'Scholarship program',
                'support_period' => collect([
                    $application->scholarship?->support_starts_at?->format('M d, Y'),
                    $application->scholarship?->support_ends_at?->format('M d, Y'),
                ])->filter()->implode(' to '),
            ],
            'award' => [
                'amount' => $application->awarded_amount ?? $application->scholarship?->award_amount,
                'amount_label' => ($application->awarded_amount ?? $application->scholarship?->award_amount) !== null
                    ? 'PHP '.number_format((float) ($application->awarded_amount ?? $application->scholarship?->award_amount), 2)
                    : 'Program benefits',
            ],
            'onboarding' => [
                'state' => $state,
                'label' => $label,
                'next_step' => $nextStep,
                'action_label' => $actionLabel,
                'responded_at' => $application->student_responded_at?->format('M d, Y'),
                'response_note' => $agreement['response_note'] ?? null,
                'agreement_version' => $agreement['version'] ?? null,
            ],
            'support' => [
                'state' => $latestDecision?->decision ?? ($isClosed ? 'terminated' : 'active'),
                'latest_decision_at' => $latestDecision?->decided_at?->format('M d, Y'),
                'decided_by' => $latestDecision?->decider?->name,
            ],
            'waiting_days' => $referenceAt
                ? max(0, (int) CarbonImmutable::parse($referenceAt)->startOfDay()->diffInDays(CarbonImmutable::today()))
                : 0,
        ];
    }

    private function monitoringOfficerWorkspaceItem(
        RecipientMonitoringCycle $cycle,
        array $payload,
    ): array {
        $pendingAdjustments = (int) ($payload['pending_adjustment_count'] ?? 0);
        $pendingReviews = (int) ($payload['pending_review_count'] ?? 0);
        $actionNeeded = (int) ($payload['action_needed_count'] ?? 0);
        $openInterventions = (int) ($payload['open_intervention_count'] ?? 0);
        [$state, $label, $detail, $actionLabel, $priority] = match (true) {
            $pendingAdjustments > 0 => [
                'review',
                'Request review',
                $pendingAdjustments.' extension or exception request'.($pendingAdjustments === 1 ? '' : 's').' waiting',
                'Review requests',
                0,
            ],
            $pendingReviews > 0 => [
                'review',
                'Submission review',
                $pendingReviews.' submitted item'.($pendingReviews === 1 ? '' : 's').' waiting for review',
                'Review submissions',
                1,
            ],
            $openInterventions > 0 => [
                'followup',
                'Open follow-up',
                $openInterventions.' active intervention'.($openInterventions === 1 ? '' : 's'),
                'Open follow-ups',
                2,
            ],
            $actionNeeded > 0 => [
                'followup',
                'Follow-up needed',
                $actionNeeded.' requirement'.($actionNeeded === 1 ? '' : 's').' need correction or intervention',
                'Review recipients',
                3,
            ],
            ($payload['status'] ?? null) === 'open' => [
                'awaiting',
                'Awaiting uploads',
                ((int) ($payload['item_pending_count'] ?? 0)).' required item'.((int) ($payload['item_pending_count'] ?? 0) === 1 ? '' : 's').' still pending',
                'Track check-in',
                4,
            ],
            default => [
                'history',
                'Check-in closed',
                ((int) ($payload['reviewed_count'] ?? 0)).' item'.((int) ($payload['reviewed_count'] ?? 0) === 1 ? '' : 's').' reviewed',
                'View history',
                5,
            ],
        };
        $dueAt = $cycle->due_at?->startOfDay();
        $daysUntilDue = $dueAt
            ? (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($dueAt), false)
            : null;
        $timingState = match (true) {
            ($payload['is_past_due'] ?? false) => 'closed',
            $daysUntilDue === null => 'unscheduled',
            $daysUntilDue < 0 => 'overdue',
            $daysUntilDue <= 7 => 'due_soon',
            default => 'scheduled',
        };

        return [
            'kind' => 'cycle',
            'id' => $cycle->id,
            'title' => $cycle->title,
            'period_label' => collect([$cycle->academic_period, $cycle->school_year])
                ->filter()
                ->implode(' - ') ?: Str::headline($cycle->period_type),
            'program' => [
                'id' => $cycle->scholarship_id,
                'title' => $cycle->scholarship?->title ?: 'Scholarship program',
            ],
            'work_state' => $state,
            'work_label' => $label,
            'detail' => $detail,
            'priority' => $priority,
            'cycle_status' => $payload['status'] ?? $cycle->status,
            'due_at' => $cycle->due_at?->format('Y-m-d'),
            'due_label' => $cycle->due_at?->format('M d, Y'),
            'days_until_due' => $daysUntilDue,
            'timing_state' => $timingState,
            'recipient_count' => (int) ($payload['recipient_count'] ?? 0),
            'counts' => [
                'requirements' => $cycle->requirements->count(),
                'expected_items' => (int) ($payload['item_expected_count'] ?? 0),
                'received_items' => (int) ($payload['item_submitted_count'] ?? 0),
                'pending_items' => (int) ($payload['item_pending_count'] ?? 0),
                'pending_reviews' => $pendingReviews,
                'pending_adjustments' => $pendingAdjustments,
                'action_needed' => $actionNeeded,
                'open_interventions' => $openInterventions,
            ],
            'action_label' => $actionLabel,
            'action_url' => route('provider.monitoring.academic', $cycle->scholarship_id, false).'?cycle='.$cycle->id,
        ];
    }

    private function benefitReleaseOfficerWorkspaceItem(RecipientBenefitRelease $release): array
    {
        $payload = $this->recipientBenefitReleasePayload($release);
        $openIssues = (int) ($payload['open_issue_count'] ?? 0);
        $pending = (int) ($payload['pending_count'] ?? 0);
        $releaseAt = $release->release_at ? CarbonImmutable::parse($release->release_at) : null;
        $isDue = $releaseAt?->isPast() ?? false;
        [$state, $label, $detail, $actionLabel, $priority] = match (true) {
            $openIssues > 0 => [
                'issues',
                'Recipient issue',
                $openIssues.' reported release issue'.($openIssues === 1 ? '' : 's').' waiting for resolution',
                'Resolve issues',
                0,
            ],
            $pending > 0 && $isDue => [
                'record',
                'Record distribution',
                $pending.' recipient result'.($pending === 1 ? '' : 's').' still need proof or a final status',
                'Record results',
                1,
            ],
            $pending > 0 => [
                'upcoming',
                'Scheduled',
                $pending.' recipient'.($pending === 1 ? '' : 's').' scheduled for this distribution',
                'Open schedule',
                2,
            ],
            default => [
                'history',
                'Distribution closed',
                ((int) ($payload['released_count'] ?? 0)).' release'.((int) ($payload['released_count'] ?? 0) === 1 ? '' : 's').' recorded',
                'View record',
                3,
            ],
        };
        $daysUntilRelease = $releaseAt
            ? (int) CarbonImmutable::today()->diffInDays($releaseAt->startOfDay(), false)
            : null;
        $timingState = match (true) {
            $state === 'issues' => 'attention',
            $state === 'history' => 'completed',
            $daysUntilRelease === null => 'unscheduled',
            $daysUntilRelease < 0 => 'overdue',
            $daysUntilRelease === 0 => 'today',
            $daysUntilRelease <= 7 => 'soon',
            default => 'scheduled',
        };

        return [
            'kind' => 'release',
            'id' => $release->id,
            'title' => $release->title,
            'benefit_description' => $release->benefit_description,
            'program' => [
                'id' => $release->scholarship_id,
                'title' => $release->scholarship?->title ?: 'Scholarship program',
            ],
            'work_state' => $state,
            'work_label' => $label,
            'detail' => $detail,
            'priority' => $priority,
            'release_status' => $release->status,
            'release_status_label' => Str::headline($release->status),
            'release_at' => $releaseAt?->format('Y-m-d\TH:i'),
            'release_label' => $releaseAt?->format('M d, Y h:i A'),
            'days_until_release' => $daysUntilRelease,
            'timing_state' => $timingState,
            'amount_label' => $payload['amount_label'] ?? null,
            'release_method_label' => $payload['release_method_label'] ?? 'Release arrangement',
            'location' => $release->location,
            'requires_original_verification' => $release->requires_original_verification,
            'counts' => [
                'recipients' => (int) ($payload['recipient_count'] ?? 0),
                'pending' => $pending,
                'released' => (int) ($payload['released_count'] ?? 0),
                'exceptions' => (int) ($payload['exception_count'] ?? 0),
                'confirmed' => (int) ($payload['confirmed_count'] ?? 0),
                'open_issues' => $openIssues,
                'proof' => $release->records->whereNotNull('receipt_path')->count(),
            ],
            'action_label' => $actionLabel,
            'action_url' => route('provider.monitoring.releases', $release->scholarship_id, false).'?release='.$release->id,
        ];
    }

    private function applySelectionOfficerQueueFilter(Builder $query, string $queue): void
    {
        if ($queue === 'all') {
            $this->applyProviderApplicationFilter($query, 'active_stages');

            return;
        }

        if ($queue === 'setup') {
            $this->applyProviderApplicationFilter($query, 'waiting_activity');
            $query->where(fn (Builder $query) => $query
                ->where('workflow_stage', '!=', 'formal_application')
                ->orWhereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                    ->where('type', 'formal_application')));

            return;
        }

        $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $completedQuery): void {
                    $this->applyProviderApplicationFilter($completedQuery, 'ready_result');
                })
                ->orWhere(function (Builder $handoffQuery): void {
                    $handoffQuery
                        ->where('workflow_stage', 'formal_application')
                        ->whereNotIn('application_state', ['closed', 'withdrawn'])
                        ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                            ->where('type', 'formal_application'));
                });
        });
    }

    private function selectionOfficerWorkspaceItem(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'applicant.studentProfile',
            'schedules',
            'scholarship',
        ]);
        $stage = $application->workflow_stage ?: 'formal_application';
        $stageLabel = Str::headline($stage);
        $stageSchedules = $application->schedules
            ->where('type', $stage)
            ->sortByDesc('id')
            ->values();
        $completedSchedule = $stageSchedules->firstWhere('status', 'completed');
        $schedule = $completedSchedule ?? $stageSchedules->first();
        $submittedAt = $application->submitted_at ?? $application->created_at;
        [$workState, $workLabel, $actionLabel, $priority] = match (true) {
            $completedSchedule !== null => ['result', 'Result ready', 'Record result', 0],
            $stage === 'formal_application' && $schedule === null => ['result', 'Handoff ready', 'Record handoff result', 0],
            $schedule?->status === 'scheduled' && ! $schedule->scheduled_at?->isFuture() => ['complete', 'Activity due', 'Complete activity', 1],
            $schedule?->status === 'scheduled' => ['upcoming', 'Upcoming activity', 'View schedule', 3],
            default => ['setup', 'Needs activity setup', 'Set activity details', 2],
        };
        $waitingDays = $submittedAt
            ? max(0, (int) CarbonImmutable::parse($submittedAt)->startOfDay()->diffInDays(CarbonImmutable::today()))
            : 0;

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application, false),
            'priority' => $priority,
            'applicant' => [
                'name' => $application->applicant?->name ?: 'Applicant record',
                'education' => collect([
                    $application->applicant?->studentProfile?->education_level,
                    $application->applicant?->studentProfile?->course_or_strand,
                    $application->applicant?->studentProfile?->year_level,
                ])->filter()->implode(' - '),
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $application->scholarship?->title ?: 'Scholarship program',
            ],
            'stage' => [
                'key' => $stage,
                'label' => $stageLabel,
            ],
            'activity' => [
                'state' => $workState,
                'label' => $workLabel,
                'action_label' => $actionLabel,
                'title' => $schedule?->title ?: ($stage === 'formal_application' ? 'Provider application handoff' : "{$stageLabel} activity"),
                'scheduled_at' => $schedule?->scheduled_at?->format('M d, Y h:i A'),
                'mode' => $schedule?->mode,
                'mode_label' => $schedule?->mode ? Str::headline($schedule->mode) : null,
                'status' => $schedule?->status,
            ],
            'waiting_days' => $waitingDays,
            'submitted_at' => $submittedAt?->format('M d, Y h:i A'),
        ];
    }

}
