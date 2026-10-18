<?php

namespace App\Services;

use App\Models\ApplicationSchedule;
use App\Models\ApplicationStageAssignment;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\RecipientAgreement;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ApplicantDashboardService
{
    public function __construct(
        private readonly ApplicationWorkflowService $workflowService,
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    public function build(User $user): array
    {
        $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile']);
        $profile = $user->applicantProfileReadiness();
        $records = $this->applicationRecords($user);
        $mode = $this->dashboardMode($records, (bool) $profile['complete']);
        $actions = $records
            ->flatMap(fn (array $record): array => $this->applicationActions(
                $record['application'],
                $record['workflow'],
            ))
            ->when(
                ! $profile['complete'],
                fn (Collection $items): Collection => $items->push($this->profileAction($profile)),
            );
        $actions = $this->sortActions($actions);
        $visibleActions = $actions->take(3);
        $usedResourceKeys = $visibleActions->pluck('resource_key')->filter()->values();
        $upcoming = $this->upcomingItems($records)
            ->reject(fn (array $item): bool => $usedResourceKeys->contains($item['resource_key']))
            ->take(3)
            ->map(fn (array $item): array => $this->publicUpcomingItem($item))
            ->values();
        $recommendation = $profile['complete'] && $actions->isEmpty() && $mode !== 'recipient'
            ? $this->recommendation($user)
            : null;
        $primaryAction = $actions->first()
            ?? $this->fallbackAction($records, $mode, $recommendation);
        $applicationCards = $this->applicationCards($records, $actions);

        return [
            'user' => $user->publicPayload(),
            'dashboard' => [
                'mode' => $mode,
                'profile' => [
                    'complete' => (bool) $profile['complete'],
                    'percent' => (int) ($profile['percent'] ?? 0),
                    'missing_count' => count($profile['missing'] ?? []),
                ],
                'summary' => [
                    'applications' => $records->count(),
                    'active_applications' => $records
                        ->filter(fn (array $record): bool => $this->isActiveApplication($record))
                        ->count(),
                    'actions' => $actions->count(),
                    'upcoming' => $upcoming->count(),
                ],
                'primary_action' => $this->publicAction($primaryAction),
                'secondary_actions' => $actions
                    ->slice(1, 2)
                    ->map(fn (array $action): array => $this->publicAction($action))
                    ->values(),
                'applications' => $applicationCards,
                'upcoming' => $upcoming,
                'recommendation' => $recommendation,
                'onboarding' => $records->isEmpty() && ! $profile['complete']
                    ? $this->onboardingSteps((bool) $profile['complete'])
                    : [],
            ],
        ];
    }

    private function applicationRecords(User $user): Collection
    {
        return ScholarshipApplication::query()
            ->with([
                'documents',
                'schedules',
                'stageAssignments.session',
                'stageAssignments.attendance',
                'stageProgresses',
                'scholarship.provider.providerProfile',
                'scholarship.monitoringCycles.requirements',
                'monitoringSubmissions',
                'monitoringAdjustmentRequests',
                'benefitReleaseRecords.release',
                'benefitReleaseRecords.receiptResponse',
                'supportDecisions',
            ])
            ->where('applicant_id', $user->id)
            ->latest('submitted_at')
            ->get()
            ->map(function (ScholarshipApplication $application): array {
                return [
                    'application' => $application,
                    'workflow' => $this->workflowService->payload($application),
                ];
            });
    }

    private function dashboardMode(Collection $records, bool $profileComplete): string
    {
        if ($records->isEmpty()) {
            return $profileComplete ? 'discovery' : 'onboarding';
        }

        if ($records->contains(fn (array $record): bool => $this->isRecipient($record['application']))) {
            return 'recipient';
        }

        if ($records->contains(fn (array $record): bool => ! ($record['workflow']['is_closed'] ?? false))) {
            return 'applying';
        }

        return 'discovery';
    }

    private function applicationActions(ScholarshipApplication $application, array $workflow): array
    {
        $actions = [];
        $title = $application->scholarship?->title ?: 'Scholarship application';
        $detailUrl = route('dashboard.applications.show', $application, false);
        $monitoringUrl = route('dashboard.monitoring.show', $application, false);
        $correctionRequested = $application->correction_status === 'requested';

        if ($correctionRequested) {
            $actions[] = $this->action(
                key: "application-correction-{$application->id}",
                kind: 'correction',
                title: 'Update your application',
                description: $application->correction_message ?: 'The provider requested corrected information or supporting files.',
                context: $title,
                href: $detailUrl,
                cta: 'Review request',
                priority: 100,
                applicationId: $application->id,
                resourceKey: "application-{$application->id}",
                tone: 'urgent',
                dueAt: $application->correction_requested_at?->toISOString(),
                dueLabel: $application->correction_requested_at
                    ? 'Requested '.$application->correction_requested_at->format('M d, Y')
                    : null,
            );
        }

        $documentIssues = $application->documents
            ->whereIn('status', ['rejected', 'needs_replacement'])
            ->values();

        if (! $correctionRequested && $documentIssues->isNotEmpty()) {
            $count = $documentIssues->count();
            $actions[] = $this->action(
                key: "document-replacement-{$application->id}",
                kind: 'document',
                title: $count === 1 ? 'Replace a requested file' : "Replace {$count} requested files",
                description: $documentIssues->first()?->review_notes ?: 'Open the provider note and upload the requested replacement.',
                context: $title,
                href: $detailUrl,
                cta: 'Review files',
                priority: 96,
                applicationId: $application->id,
                resourceKey: "application-{$application->id}",
                tone: 'urgent',
            );
        }

        $agreement = RecipientAgreement::payload($application);

        if (($agreement['can_respond'] ?? false) === true) {
            $actions[] = $this->action(
                key: "recipient-agreement-{$application->id}",
                kind: 'agreement',
                title: 'Review your recipient agreement',
                description: 'Confirm the support, responsibilities, and provider terms before continuing.',
                context: $title,
                href: $detailUrl.'?action=agreement',
                cta: 'Review agreement',
                priority: 94,
                applicationId: $application->id,
                resourceKey: "agreement-{$application->id}",
                tone: 'attention',
            );
        }

        if ($this->isRecipient($application)) {
            $latestDecision = $application->supportDecisions->first();

            if ($latestDecision && blank($latestDecision->applicant_response_type)) {
                $decisionLabel = Str::headline((string) $latestDecision->decision);
                $actions[] = $this->action(
                    key: "support-decision-{$latestDecision->id}",
                    kind: 'support_decision',
                    title: 'Review your support update',
                    description: "The provider recorded a {$decisionLabel} update. Read the decision and respond if needed.",
                    context: $title,
                    href: $monitoringUrl,
                    cta: 'Review update',
                    priority: 92,
                    applicationId: $application->id,
                    resourceKey: "support-decision-{$latestDecision->id}",
                    tone: 'attention',
                    dueAt: $latestDecision->effective_on?->toDateString(),
                    dueLabel: $latestDecision->effective_on
                        ? 'Effective '.$latestDecision->effective_on->format('M d, Y')
                        : null,
                );
            }

            $actions = [
                ...$actions,
                ...$this->monitoringActions($application, $monitoringUrl),
            ];

            foreach ($application->benefitReleaseRecords as $record) {
                if ($record->status !== 'released' || $record->receiptResponse !== null) {
                    continue;
                }

                $release = $record->release;
                $actions[] = $this->action(
                    key: "benefit-receipt-{$record->id}",
                    kind: 'benefit',
                    title: 'Confirm your benefit release',
                    description: 'Confirm that you received the recorded support or report a problem.',
                    context: $release?->title ?: $title,
                    href: $monitoringUrl,
                    cta: 'Review release',
                    priority: 86,
                    applicationId: $application->id,
                    resourceKey: "benefit-{$record->id}",
                    tone: 'attention',
                    dueAt: $record->released_at?->toISOString() ?? $release?->release_at?->toISOString(),
                    dueLabel: ($record->released_at ?? $release?->release_at)
                        ? 'Released '.($record->released_at ?? $release?->release_at)->format('M d, Y')
                        : null,
                );
            }
        }

        $nextAction = $workflow['next_action'] ?? [];
        $nextKey = (string) ($nextAction['key'] ?? '');

        if (($nextAction['actor'] ?? null) === 'applicant'
            && in_array($nextKey, ['formal_application', 'exam', 'interview'], true)) {
            $schedule = $this->currentActivity($application, $nextKey);
            $actions[] = $this->action(
                key: "workflow-{$nextKey}-{$application->id}",
                kind: $schedule ? 'schedule' : 'application',
                title: $schedule['title'] ?? (string) ($nextAction['label'] ?? 'Continue your application'),
                description: $schedule
                    ? 'Review the date, access details, and instructions before attending.'
                    : (string) ($nextAction['description'] ?? 'Open the application for the next instruction.'),
                context: $title,
                href: $schedule
                    ? $detailUrl.'?section=schedule'
                    : (string) ($nextAction['url'] ?? $detailUrl),
                cta: $schedule ? 'View schedule' : 'Open application',
                priority: 74,
                applicationId: $application->id,
                resourceKey: $schedule['resource_key'] ?? "application-stage-{$application->id}",
                tone: 'attention',
                dueAt: $schedule['scheduled_at']?->toISOString(),
                dueLabel: $schedule['scheduled_at']?->format('M d, Y h:i A'),
            );
        }

        if (! ($workflow['is_closed'] ?? false) && ! $correctionRequested && $documentIssues->isEmpty()) {
            $readiness = $this->eligibilityService->applicationDocumentReadiness($application);
            $missing = $readiness['missing'] ?? [];

            if ($missing !== []) {
                $count = count($missing);
                $actions[] = $this->action(
                    key: "missing-documents-{$application->id}",
                    kind: 'document',
                    title: $count === 1 ? 'Prepare one missing requirement' : "Prepare {$count} missing requirements",
                    description: 'Open the application to review the required files before the provider needs them.',
                    context: $title,
                    href: $detailUrl,
                    cta: 'Review requirements',
                    priority: 62,
                    applicationId: $application->id,
                    resourceKey: "application-{$application->id}",
                    tone: 'attention',
                );
            }
        }

        return $actions;
    }

    private function monitoringActions(ScholarshipApplication $application, string $href): array
    {
        if (! $this->hasActiveSupport($application) || $application->student_response_status !== 'accepted') {
            return [];
        }

        $actions = [];
        $today = now()->startOfDay();

        foreach ($application->scholarship?->monitoringCycles ?? collect() as $cycle) {
            if ($cycle->status !== 'open') {
                continue;
            }

            $submissions = $application->monitoringSubmissions
                ->where('recipient_monitoring_cycle_id', $cycle->id);
            $correctionCount = 0;
            $pendingCount = 0;
            $effectiveDueDates = collect();

            if ($cycle->requirements->isEmpty()) {
                $submission = $submissions->firstWhere('recipient_monitoring_cycle_requirement_id', null);
                $correctionCount = $submission?->review_status === 'needs_correction' ? 1 : 0;
                $effectiveDueDates->push($cycle->due_at);

                if ($submission === null && $this->cycleIsOpenForSubmission($cycle, $cycle->due_at)) {
                    $pendingCount = 1;
                }
            } else {
                foreach ($cycle->requirements->where('required', true)->where('requires_file', true) as $requirement) {
                    $submission = $submissions->firstWhere(
                        'recipient_monitoring_cycle_requirement_id',
                        $requirement->id,
                    );
                    $effectiveDueAt = $this->effectiveDueAt($application, $cycle, $requirement);
                    $effectiveDueDates->push($effectiveDueAt);

                    if ($submission?->review_status === 'needs_correction') {
                        $correctionCount++;
                    } elseif ($submission === null && $this->cycleIsOpenForSubmission($cycle, $effectiveDueAt)) {
                        $pendingCount++;
                    }
                }
            }

            if ($correctionCount === 0 && $pendingCount === 0) {
                continue;
            }

            $dueAt = $effectiveDueDates
                ->filter()
                ->sortBy(fn ($date): int => $date->getTimestamp())
                ->first() ?? $cycle->due_at;
            $needsCorrection = $correctionCount > 0;
            $itemCount = $needsCorrection ? $correctionCount : $pendingCount;
            $actions[] = $this->action(
                key: "monitoring-{$application->id}-{$cycle->id}",
                kind: $needsCorrection ? 'monitoring_correction' : 'monitoring',
                title: $needsCorrection ? "Update {$cycle->title}" : "Complete {$cycle->title}",
                description: $needsCorrection
                    ? ($itemCount === 1 ? 'One submitted item needs an update.' : "{$itemCount} submitted items need updates.")
                    : ($itemCount === 1 ? 'One requirement is ready for submission.' : "{$itemCount} requirements are ready for submission."),
                context: $application->scholarship?->title ?: 'Scholarship monitoring',
                href: $href,
                cta: 'Open check-in',
                priority: $needsCorrection ? 90 : 82,
                applicationId: $application->id,
                resourceKey: "monitoring-{$application->id}-{$cycle->id}",
                tone: $needsCorrection ? 'urgent' : 'attention',
                dueAt: $dueAt?->toDateString(),
                dueLabel: $dueAt?->isBefore($today)
                    ? 'Update requested'
                    : ($dueAt ? 'Due '.$dueAt->format('M d, Y') : null),
            );
        }

        return $actions;
    }

    private function cycleIsOpenForSubmission(
        RecipientMonitoringCycle $cycle,
        ?CarbonInterface $effectiveDueAt,
    ): bool {
        $today = now()->startOfDay();

        return ! ($cycle->opens_at?->isAfter($today) ?? false)
            && ! ($effectiveDueAt?->isBefore($today) ?? false);
    }

    private function effectiveDueAt(
        ScholarshipApplication $application,
        RecipientMonitoringCycle $cycle,
        RecipientMonitoringCycleRequirement $requirement,
    ): ?CarbonInterface {
        return $application->monitoringAdjustmentRequests
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->where('recipient_monitoring_cycle_requirement_id', $requirement->id)
            ->where('request_type', 'extension')
            ->where('status', 'approved')
            ->whereNotNull('approved_due_at')
            ->sortByDesc(fn ($request): int => $request->approved_due_at->getTimestamp())
            ->first()?->approved_due_at ?? $cycle->due_at;
    }

    private function profileAction(array $profile): array
    {
        $missingCount = count($profile['missing'] ?? []);

        return $this->action(
            key: 'complete-profile',
            kind: 'profile',
            title: 'Complete your applicant profile',
            description: $missingCount === 1
                ? 'One required profile detail is still missing.'
                : "{$missingCount} required profile details are still missing.",
            context: ((int) ($profile['percent'] ?? 0)).'% complete',
            href: route('dashboard.profile', [], false),
            cta: 'Continue profile',
            priority: 58,
            resourceKey: 'profile',
            tone: 'attention',
        );
    }

    private function sortActions(Collection $actions): Collection
    {
        return $actions
            ->unique('key')
            ->sort(function (array $first, array $second): int {
                $priority = $second['_priority'] <=> $first['_priority'];

                if ($priority !== 0) {
                    return $priority;
                }

                $firstDate = filled($first['due_at'] ?? null) ? strtotime($first['due_at']) : PHP_INT_MAX;
                $secondDate = filled($second['due_at'] ?? null) ? strtotime($second['due_at']) : PHP_INT_MAX;

                return ($firstDate <=> $secondDate) ?: strcmp($first['key'], $second['key']);
            })
            ->values();
    }

    private function fallbackAction(Collection $records, string $mode, ?array $recommendation): array
    {
        if ($mode === 'recipient') {
            $record = $records->first(fn (array $item): bool => $this->hasActiveSupport($item['application']));

            if ($record) {
                $application = $record['application'];

                return $this->action(
                    key: 'support-up-to-date',
                    kind: 'up_to_date',
                    title: 'Your scholarship requirements are up to date',
                    description: 'There is nothing you need to submit right now.',
                    context: $application->scholarship?->title ?: 'Active scholarship support',
                    href: route('dashboard.monitoring.show', $application, false),
                    cta: 'Open monitoring',
                    priority: 0,
                    resourceKey: "application-{$application->id}",
                    tone: 'calm',
                );
            }
        }

        $active = $records->first(fn (array $record): bool => ! ($record['workflow']['is_closed'] ?? false));

        if ($active) {
            $application = $active['application'];

            return $this->action(
                key: 'provider-review',
                kind: 'waiting',
                title: 'No action is needed right now',
                description: 'Your application is with the scholarship provider. We will show a task here when something changes.',
                context: $application->scholarship?->title ?: 'Application under review',
                href: route('dashboard.applications.show', $application, false),
                cta: 'View application',
                priority: 0,
                resourceKey: "application-{$application->id}",
                tone: 'calm',
            );
        }

        return $this->action(
            key: 'browse-scholarships',
            kind: 'browse',
            title: $recommendation ? 'A strong scholarship match is ready' : 'Find a scholarship that fits you',
            description: $recommendation
                ? 'Review the recommended program below or compare all available scholarships.'
                : 'Your dashboard is clear. Explore available programs when you are ready.',
            context: $recommendation['title'] ?? 'Scholarship finder',
            href: route('dashboard.scholarships', [], false),
            cta: 'Browse scholarships',
            priority: 0,
            resourceKey: 'scholarship-finder',
            tone: 'calm',
        );
    }

    private function applicationCards(Collection $records, Collection $actions): Collection
    {
        return $records
            ->sort(function (array $first, array $second) use ($actions): int {
                $firstPriority = (int) $actions
                    ->where('_application_id', $first['application']->id)
                    ->max('_priority');
                $secondPriority = (int) $actions
                    ->where('_application_id', $second['application']->id)
                    ->max('_priority');

                if ($firstPriority !== $secondPriority) {
                    return $secondPriority <=> $firstPriority;
                }

                $firstActive = $this->isActiveApplication($first) ? 1 : 0;
                $secondActive = $this->isActiveApplication($second) ? 1 : 0;

                if ($firstActive !== $secondActive) {
                    return $secondActive <=> $firstActive;
                }

                return ($second['application']->submitted_at?->getTimestamp() ?? 0)
                    <=> ($first['application']->submitted_at?->getTimestamp() ?? 0);
            })
            ->take(3)
            ->map(function (array $record) use ($actions): array {
                $application = $record['application'];
                $workflow = $record['workflow'];
                $action = $actions->firstWhere('_application_id', $application->id);
                $isRecipient = $this->isRecipient($application);
                $statusLabel = $isRecipient && $this->hasActiveSupport($application)
                    ? 'Support active'
                    : ($workflow['final_outcome_label']
                        ?? $workflow['current_stage_label']
                        ?? $workflow['application_state_label']
                        ?? 'Submitted');

                return [
                    'id' => $application->id,
                    'title' => $application->scholarship?->title ?: 'Scholarship application',
                    'provider' => $application->scholarship?->provider?->provider_name
                        ?? $application->scholarship?->provider?->name
                        ?? 'Scholarship provider',
                    'image_url' => $this->scholarshipImageUrl($application->scholarship),
                    'status_label' => $statusLabel,
                    'status_tone' => $action
                        ? 'attention'
                        : ($isRecipient ? 'positive' : (($workflow['is_closed'] ?? false) ? 'muted' : 'neutral')),
                    'needs_action' => $action !== null,
                    'next_step' => $action['title']
                        ?? data_get($workflow, 'next_action.label')
                        ?? 'Review application',
                    'submitted_label' => $application->submitted_at?->format('M d, Y'),
                    'href' => route('dashboard.applications.show', $application, false),
                ];
            })
            ->values();
    }

    private function upcomingItems(Collection $records): Collection
    {
        $items = collect();
        $now = now();
        $today = $now->copy()->startOfDay();

        foreach ($records as $record) {
            $application = $record['application'];
            $title = $application->scholarship?->title ?: 'Scholarship application';
            $detailUrl = route('dashboard.applications.show', $application, false);
            $monitoringUrl = route('dashboard.monitoring.show', $application, false);

            $stageActivities = $application->stageAssignments
                ->filter(fn (ApplicationStageAssignment $assignment): bool => in_array($assignment->status, ['assigned', 'acknowledged'], true)
                    && in_array($assignment->session?->status, ['scheduled', 'ongoing'], true));

            if ($stageActivities->isNotEmpty()) {
                foreach ($stageActivities as $assignment) {
                    $session = $assignment->session;

                    if (! $session?->starts_at?->greaterThanOrEqualTo($now)) {
                        continue;
                    }

                    $items->push([
                        'key' => "stage-activity-{$assignment->id}",
                        'kind' => 'schedule',
                        'title' => $session->title,
                        'detail' => $this->activityDetail($session->mode, $session->venue),
                        'context' => $title,
                        'date_label' => $session->starts_at->format('M d, Y h:i A'),
                        'href' => $detailUrl.'?section=schedule',
                        'resource_key' => "stage-activity-{$assignment->id}",
                        '_sort_at' => $session->starts_at->getTimestamp(),
                    ]);
                }
            } else {
                foreach ($application->schedules as $schedule) {
                    if ($schedule->status !== 'scheduled' || ! $schedule->scheduled_at?->greaterThanOrEqualTo($now)) {
                        continue;
                    }

                    $items->push([
                        'key' => "schedule-{$schedule->id}",
                        'kind' => 'schedule',
                        'title' => $schedule->title,
                        'detail' => $this->scheduleDetail($schedule),
                        'context' => $title,
                        'date_label' => $schedule->scheduled_at->format('M d, Y h:i A'),
                        'href' => $detailUrl,
                        'resource_key' => "schedule-{$schedule->id}",
                        '_sort_at' => $schedule->scheduled_at->getTimestamp(),
                    ]);
                }
            }

            if (! $this->hasActiveSupport($application) || $application->student_response_status !== 'accepted') {
                continue;
            }

            foreach ($application->scholarship?->monitoringCycles ?? collect() as $cycle) {
                if ($cycle->status !== 'open' || ! $cycle->opens_at?->isAfter($today)) {
                    continue;
                }

                $items->push([
                    'key' => "monitoring-opens-{$application->id}-{$cycle->id}",
                    'kind' => 'monitoring',
                    'title' => $cycle->title,
                    'detail' => 'Monitoring check-in opens',
                    'context' => $title,
                    'date_label' => $cycle->opens_at->format('M d, Y'),
                    'href' => $monitoringUrl,
                    'resource_key' => "monitoring-{$application->id}-{$cycle->id}",
                    '_sort_at' => $cycle->opens_at->getTimestamp(),
                ]);
            }

            foreach ($application->benefitReleaseRecords as $releaseRecord) {
                $release = $releaseRecord->release;

                if (! $release?->release_at?->greaterThanOrEqualTo($now)
                    || in_array($releaseRecord->status, ['released', 'missed', 'withheld'], true)) {
                    continue;
                }

                $items->push([
                    'key' => "benefit-{$releaseRecord->id}",
                    'kind' => 'benefit',
                    'title' => $release->title,
                    'detail' => $this->benefitMethodLabel($release->release_method),
                    'context' => $title,
                    'date_label' => $release->release_at->format('M d, Y h:i A'),
                    'href' => $monitoringUrl,
                    'resource_key' => "benefit-{$releaseRecord->id}",
                    '_sort_at' => $release->release_at->getTimestamp(),
                ]);
            }
        }

        return $items
            ->unique('key')
            ->sortBy('_sort_at')
            ->values();
    }

    private function recommendation(User $user): ?array
    {
        $recommendation = Scholarship::query()
            ->with(['provider.providerProfile', 'benefits'])
            ->withCount('applications')
            ->discoverable()
            ->whereDoesntHave('applications', fn ($query) => $query->where('applicant_id', $user->id))
            ->get()
            ->map(function (Scholarship $scholarship) use ($user): array {
                return [
                    'scholarship' => $scholarship,
                    'match' => $this->eligibilityService->evaluate($scholarship, $user),
                ];
            })
            ->filter(fn (array $item): bool => (bool) ($item['match']['is_eligible'] ?? false))
            ->sortByDesc(fn (array $item): int => (int) ($item['match']['score'] ?? 0))
            ->first();

        if (! $recommendation) {
            return null;
        }

        $scholarship = $recommendation['scholarship'];

        return [
            'id' => $scholarship->id,
            'title' => $scholarship->title,
            'provider' => $scholarship->provider?->provider_name
                ?? $scholarship->provider?->name
                ?? 'Scholarship provider',
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'match_score' => (int) ($recommendation['match']['score'] ?? 0),
            'match_label' => $recommendation['match']['label'] ?? 'Profile match',
            'benefit_summary' => $scholarship->benefitSummary(),
            'deadline_label' => $scholarship->deadline
                ? 'Applications close '.$scholarship->deadline->format('M d, Y')
                : 'No closing date listed',
            'href' => route('dashboard.scholarships.show', $scholarship, false),
        ];
    }

    private function onboardingSteps(bool $profileComplete): array
    {
        return [
            [
                'label' => 'Complete your profile',
                'description' => 'Add the required personal and academic details.',
                'status' => $profileComplete ? 'complete' : 'current',
                'href' => route('dashboard.profile', [], false),
            ],
            [
                'label' => 'Review scholarship matches',
                'description' => 'Compare programs after your profile is ready.',
                'status' => $profileComplete ? 'current' : 'upcoming',
                'href' => route('dashboard.scholarships', [], false),
            ],
            [
                'label' => 'Submit an application',
                'description' => 'Open a program and follow its application requirements.',
                'status' => 'upcoming',
                'href' => route('dashboard.scholarships', [], false),
            ],
        ];
    }

    private function action(
        string $key,
        string $kind,
        string $title,
        string $description,
        string $context,
        string $href,
        string $cta,
        int $priority,
        ?int $applicationId = null,
        ?string $resourceKey = null,
        string $tone = 'attention',
        ?string $dueAt = null,
        ?string $dueLabel = null,
    ): array {
        return [
            'key' => $key,
            'kind' => $kind,
            'tone' => $tone,
            'title' => $title,
            'description' => $description,
            'context' => $context,
            'due_at' => $dueAt,
            'due_label' => $dueLabel,
            'href' => $href,
            'cta' => $cta,
            'resource_key' => $resourceKey,
            '_priority' => $priority,
            '_application_id' => $applicationId,
        ];
    }

    private function publicAction(array $action): array
    {
        return Arr::except($action, ['resource_key', '_priority', '_application_id']);
    }

    private function publicUpcomingItem(array $item): array
    {
        return Arr::except($item, ['resource_key', '_sort_at']);
    }

    private function isActiveApplication(array $record): bool
    {
        return ! ($record['workflow']['is_closed'] ?? false)
            || $this->hasActiveSupport($record['application'])
            || (RecipientAgreement::payload($record['application'])['can_respond'] ?? false);
    }

    private function isRecipient(ScholarshipApplication $application): bool
    {
        return $application->final_outcome === 'selected'
            || in_array($application->status, [
                'awarded',
                'distribution_scheduled',
                'disbursed',
                'renewed',
                'benefits_terminated',
            ], true);
    }

    private function hasActiveSupport(ScholarshipApplication $application): bool
    {
        if (! $this->isRecipient($application) || $application->student_response_status === 'declined') {
            return false;
        }

        $latestDecision = $application->supportDecisions->first();

        return $application->status !== 'benefits_terminated'
            && ! in_array($latestDecision?->decision, ['completed', 'terminated'], true);
    }

    private function currentActivity(ScholarshipApplication $application, string $type): ?array
    {
        $assignment = $application->stageAssignments
            ->filter(fn (ApplicationStageAssignment $assignment): bool => in_array($assignment->status, ['assigned', 'acknowledged'], true)
                && $assignment->session?->stage_key === $type
                && in_array($assignment->session?->status, ['scheduled', 'ongoing'], true))
            ->sortByDesc(fn (ApplicationStageAssignment $assignment): int => $assignment->session?->starts_at?->getTimestamp() ?? 0)
            ->first();

        if ($assignment?->session) {
            return [
                'title' => $assignment->session->title,
                'scheduled_at' => $assignment->session->starts_at,
                'resource_key' => "stage-activity-{$assignment->id}",
            ];
        }

        $schedule = $application->schedules
            ->where('type', $type)
            ->where('status', 'scheduled')
            ->sortByDesc('id')
            ->first();

        return $schedule ? [
            'title' => $schedule->title,
            'scheduled_at' => $schedule->scheduled_at,
            'resource_key' => "schedule-{$schedule->id}",
        ] : null;
    }

    private function scheduleDetail(ApplicationSchedule $schedule): string
    {
        return $this->activityDetail($schedule->mode, $schedule->venue);
    }

    private function activityDetail(?string $activityMode, ?string $venue): string
    {
        $mode = match ($activityMode) {
            'online' => 'Online activity',
            'hybrid' => 'Online and on-site',
            'onsite' => 'On-site activity',
            default => 'Provider-managed activity',
        };

        return collect([$mode, $venue])->filter()->implode(' - ');
    }

    private function benefitMethodLabel(?string $method): string
    {
        return match ($method) {
            'bank_transfer' => 'Bank transfer',
            'e_wallet' => 'E-wallet',
            'other' => 'Provider arrangement',
            default => 'In-person release',
        };
    }

    private function scholarshipImageUrl(?Scholarship $scholarship): string
    {
        return filled($scholarship?->image_path)
            ? asset(ltrim($scholarship->image_path, '/'))
            : asset('uploads/scholarship-default.jpg');
    }
}
