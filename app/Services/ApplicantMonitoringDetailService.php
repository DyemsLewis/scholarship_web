<?php

namespace App\Services;

use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApplicantMonitoringDetailService
{
    private const VIEWS = ['overview', 'check-ins', 'benefits', 'history'];

    public function build(
        ScholarshipApplication $application,
        User $applicant,
        array $monitoring,
        string $view = 'overview',
        ?string $recordKey = null,
    ): array {
        if ((int) $application->applicant_id !== (int) $applicant->id) {
            throw new InvalidArgumentException('The monitoring record does not belong to this applicant.');
        }

        $view = in_array($view, self::VIEWS, true) ? $view : 'overview';
        $application->loadMissing([
            'scholarship.provider.providerProfile',
            'scholarship.monitoringPlan',
        ]);

        $checkIns = $this->normalizeCheckIns($monitoring);
        $releases = collect($monitoring['benefit_releases'] ?? [])->values();
        $decisions = $this->supportDecisions(
            $application,
            collect($monitoring['support_decisions'] ?? [])->values(),
        );
        $releaseGroups = $this->releaseGroups($application, $releases);
        $decision = $this->nextAction($application, $monitoring, $checkIns, $releases, $decisions);

        return [
            'version' => 1,
            'view' => $view,
            'page' => $this->page($view),
            'identity' => $this->identity($application),
            'status' => $this->status($monitoring, $checkIns, $releases),
            'decision' => $decision,
            'summary' => $this->summary($monitoring, $checkIns, $releases),
            'navigation' => $this->navigation($application, $view, $monitoring, $checkIns, $releases, $decisions),
            'content' => $this->content(
                $view,
                $application,
                $monitoring,
                $checkIns,
                $releaseGroups,
                $decisions,
                $decision,
                $recordKey,
            ),
            'permissions' => [
                'can_submit_check_in' => $checkIns->contains(
                    fn (array $checkIn): bool => collect($checkIn['requirements'] ?? [])->contains(
                        fn (array $requirement): bool => (bool) ($requirement['can_submit'] ?? false),
                    ),
                ),
                'can_confirm_benefit' => $releases->contains(
                    fn (array $release): bool => (bool) ($release['can_respond'] ?? false),
                ),
                'can_respond_to_outcome' => $decisions->contains(
                    fn (array $supportDecision): bool => (bool) ($supportDecision['can_respond'] ?? false),
                ),
            ],
        ];
    }

    private function page(string $view): array
    {
        return match ($view) {
            'check-ins' => [
                'eyebrow' => 'Monitoring requirements',
                'title' => 'Check-ins',
                'description' => 'Submit open requirements and review provider feedback.',
            ],
            'benefits' => [
                'eyebrow' => 'Scholarship support',
                'title' => 'Benefit releases',
                'description' => 'Review release schedules and confirm completed benefits.',
            ],
            'history' => [
                'eyebrow' => 'Support record',
                'title' => 'Support history',
                'description' => 'Review meaningful monitoring and support outcomes.',
            ],
            default => [
                'eyebrow' => 'Recipient monitoring',
                'title' => 'Scholarship support',
                'description' => 'See what needs attention and what support comes next.',
            ],
        };
    }

    private function identity(ScholarshipApplication $application): array
    {
        $scholarship = $application->scholarship;
        $provider = $scholarship?->provider;

        return [
            'program' => [
                'title' => $scholarship?->title ?? 'Scholarship program',
                'image_url' => $this->publicAssetUrl(
                    $scholarship?->image_path,
                    'uploads/scholarship-default.jpg',
                ),
            ],
            'provider' => [
                'name' => $provider?->provider_name ?? $provider?->name ?? 'Scholarship provider',
                'logo_url' => $this->publicAssetUrl($provider?->providerProfile?->logo_path),
                'verified' => $provider?->providerProfile?->isVerified() ?? false,
            ],
            'application_href' => route('dashboard.applications.show', $application, false),
            'monitoring_href' => route('dashboard.monitoring.show', $application, false),
        ];
    }

    private function status(array $monitoring, Collection $checkIns, Collection $releases): array
    {
        if (! ($monitoring['eligible'] ?? false)) {
            return [
                'key' => 'not_active',
                'label' => 'Monitoring not active',
                'tone' => 'neutral',
                'mode' => 'not_active',
                'mode_label' => 'Not active',
            ];
        }

        $supportStatus = $monitoring['support_status'] ?? 'active';
        $mode = match (true) {
            $checkIns->isNotEmpty() => 'recurring',
            $releases->isNotEmpty() => 'one_time',
            default => 'support_only',
        };

        return [
            'key' => $supportStatus,
            'label' => $monitoring['support_status_label'] ?? 'Scholarship support active',
            'tone' => match ($supportStatus) {
                'terminated' => 'critical',
                'completed' => 'neutral',
                'renewed' => 'positive',
                default => 'positive',
            },
            'mode' => $mode,
            'mode_label' => match ($mode) {
                'recurring' => 'Ongoing support',
                'one_time' => 'One-time benefit',
                default => 'Support record',
            },
        ];
    }

    private function summary(array $monitoring, Collection $checkIns, Collection $releases): array
    {
        $openCheckIns = $checkIns->whereIn('state', ['action_required', 'under_review', 'open']);
        $completedCheckIns = $checkIns->where('state', 'completed');
        $nextCheckIn = $checkIns
            ->whereNotIn('state', ['completed', 'cancelled'])
            ->sortBy(fn (array $checkIn): string => $checkIn['due_at'] ?? '9999-12-31')
            ->first();
        $nextRelease = $releases
            ->filter(fn (array $release): bool => in_array(
                $release['lifecycle_status'] ?? $release['status'] ?? null,
                ['scheduled', 'prepared', 'confirmation_needed'],
                true,
            ))
            ->sortBy(fn (array $release): string => $release['release_at'] ?? '9999-12-31T23:59')
            ->first();

        return [
            'pending_actions' => (int) ($monitoring['pending_count'] ?? 0),
            'check_ins' => [
                'total' => $checkIns->count(),
                'open' => $openCheckIns->count(),
                'completed' => $completedCheckIns->count(),
                'next_due_label' => $nextCheckIn['due_label'] ?? null,
            ],
            'benefits' => [
                'total' => $releases->count(),
                'confirmation_needed' => $releases->where('can_respond', true)->count(),
                'next_release_label' => $nextRelease['release_label'] ?? null,
            ],
        ];
    }

    private function navigation(
        ScholarshipApplication $application,
        string $view,
        array $monitoring,
        Collection $checkIns,
        Collection $releases,
        Collection $decisions,
    ): array {
        $items = [
            [
                'key' => 'overview',
                'label' => 'Overview',
                'icon' => 'fa-regular fa-compass',
                'href' => route('dashboard.monitoring.show', $application, false),
                'badge' => (int) ($monitoring['pending_count'] ?? 0),
            ],
            [
                'key' => 'check-ins',
                'label' => 'Check-ins',
                'icon' => 'fa-solid fa-list-check',
                'href' => $this->viewUrl($application, 'check-ins'),
                'badge' => $checkIns->where('state', 'action_required')->count(),
            ],
            [
                'key' => 'benefits',
                'label' => 'Benefit releases',
                'icon' => 'fa-solid fa-hand-holding-heart',
                'href' => $this->viewUrl($application, 'benefits'),
                'badge' => $releases->where('can_respond', true)->count(),
            ],
            [
                'key' => 'history',
                'label' => 'Support history',
                'icon' => 'fa-solid fa-clock-rotate-left',
                'href' => $this->viewUrl($application, 'history'),
                'badge' => $decisions->where('can_respond', true)->count(),
            ],
        ];

        return collect($items)
            ->map(fn (array $item): array => [
                ...$item,
                'active' => $item['key'] === $view,
            ])
            ->values()
            ->all();
    }

    private function content(
        string $view,
        ScholarshipApplication $application,
        array $monitoring,
        Collection $checkIns,
        array $releaseGroups,
        Collection $decisions,
        array $decision,
        ?string $recordKey,
    ): array {
        return match ($view) {
            'check-ins' => [
                'type' => 'check_ins',
                'active' => $checkIns->whereIn('state', ['action_required', 'under_review', 'open'])->values(),
                'upcoming' => $checkIns->where('state', 'upcoming')->values(),
                'completed' => $checkIns->whereIn('state', ['completed', 'cancelled'])->values(),
                'directory' => [
                    'active' => $this->checkInDirectory(
                        $application,
                        $checkIns->whereIn('state', ['action_required', 'under_review', 'open']),
                    ),
                    'upcoming' => $this->checkInDirectory(
                        $application,
                        $checkIns->where('state', 'upcoming'),
                    ),
                    'completed' => $this->checkInDirectory(
                        $application,
                        $checkIns->whereIn('state', ['completed', 'cancelled']),
                    ),
                ],
                'selected' => $this->selectedCheckIn($application, $checkIns, $recordKey),
                'return_href' => $this->viewUrl($application, 'check-ins'),
                'empty' => $this->checkInEmptyState($monitoring, $checkIns),
            ],
            'benefits' => [
                'type' => 'benefits',
                ...$releaseGroups,
                'directory' => [
                    'action_required' => $this->benefitDirectory($releaseGroups['action_required']),
                    'upcoming' => $this->benefitDirectory($releaseGroups['upcoming']),
                    'in_progress' => $this->benefitDirectory($releaseGroups['in_progress']),
                    'completed' => $this->benefitDirectory($releaseGroups['completed']),
                ],
                'selected' => $this->selectedBenefit($application, $releaseGroups['all'], $recordKey),
                'return_href' => $this->viewUrl($application, 'benefits'),
            ],
            'history' => $this->historyContent(
                $application,
                $checkIns,
                $releaseGroups['completed'],
                $decisions,
                $recordKey,
            ),
            default => [
                'type' => 'overview',
                'next_action' => $decision,
                'plan' => $this->plan($application, $monitoring, $checkIns, $releaseGroups),
                'next_check_in' => $checkIns
                    ->whereNotIn('state', ['completed', 'cancelled'])
                    ->sortBy(fn (array $checkIn): string => $checkIn['due_at'] ?? '9999-12-31')
                    ->pipe(fn (Collection $records): ?array => $this->withCheckInHref(
                        $application,
                        $records->first(),
                    )),
                'next_release' => $releaseGroups['action_required']->first()
                    ?? $releaseGroups['upcoming']->first(),
                'latest_support_decision' => $decisions->first(),
            ],
        };
    }

    private function plan(
        ScholarshipApplication $application,
        array $monitoring,
        Collection $checkIns,
        array $releaseGroups,
    ): array {
        $plan = $application->scholarship?->monitoringPlan;
        $mode = match (true) {
            $checkIns->isNotEmpty() => 'recurring',
            $releaseGroups['all']->isNotEmpty() => 'one_time',
            default => 'not_scheduled',
        };

        return [
            'mode' => $mode,
            'mode_label' => match ($mode) {
                'recurring' => 'Recurring monitoring',
                'one_time' => 'One-time benefit only',
                default => 'No schedule published',
            },
            'frequency' => $plan?->frequency,
            'frequency_label' => $plan?->frequency ? Str::headline($plan->frequency) : null,
            'starts_label' => $plan?->starts_on?->format('M d, Y'),
            'ends_label' => $plan?->ends_on?->format('M d, Y'),
            'instructions' => $plan?->instructions,
            'agreement_accepted' => (bool) ($monitoring['agreement_accepted'] ?? false),
        ];
    }

    private function nextAction(
        ScholarshipApplication $application,
        array $monitoring,
        Collection $checkIns,
        Collection $releases,
        Collection $decisions,
    ): array {
        if (! ($monitoring['eligible'] ?? false)) {
            return $this->decisionRecord(
                'not_active',
                'neutral',
                'Monitoring not active',
                'Monitoring begins after scholarship support is formally activated.',
                'provider',
            );
        }

        if (! ($monitoring['agreement_accepted'] ?? false)) {
            return $this->decisionRecord(
                'agreement_required',
                'attention',
                'Review the recipient agreement',
                'Accept the scholarship terms before submitting monitoring records.',
                'applicant',
                'Review agreement',
                route('dashboard.applications.show.section', [
                    'application' => $application,
                    'section' => 'outcome',
                ], false).'?action=agreement',
            );
        }

        $requiredCheckIn = $checkIns->first(
            fn (array $checkIn): bool => collect($checkIn['requirements'] ?? [])->contains(
                fn (array $requirement): bool => (bool) ($requirement['can_submit'] ?? false)
                    && (($requirement['submission'] ?? null) === null
                        || (bool) ($requirement['correction_requested'] ?? false)),
            ),
        );

        if ($requiredCheckIn) {
            $replacement = collect($requiredCheckIn['requirements'] ?? [])->contains(
                fn (array $requirement): bool => (bool) ($requirement['correction_requested'] ?? false),
            );

            return $this->decisionRecord(
                'check_in_required',
                'attention',
                $replacement ? 'Replace a check-in record' : 'Complete the open check-in',
                $replacement
                    ? 'The provider requested an updated record before this check-in can be completed.'
                    : 'Submit the requested records before the posted deadline.',
                'applicant',
                'Open check-in',
                $this->checkInUrl($application, $requiredCheckIn['record_key']),
                $requiredCheckIn['due_label'] ?? null,
            );
        }

        $releaseToConfirm = $releases->first(
            fn (array $release): bool => (bool) ($release['can_respond'] ?? false),
        );

        if ($releaseToConfirm) {
            return $this->decisionRecord(
                'benefit_confirmation_required',
                'attention',
                'Confirm the benefit you received',
                'Confirm the release or report a problem so the provider can complete the record.',
                'applicant',
                'Review benefit',
                $this->benefitUrl($application, 'release-'.$releaseToConfirm['record_id']),
                $releaseToConfirm['release_label'] ?? null,
            );
        }

        $decisionToAnswer = $decisions->first(
            fn (array $supportDecision): bool => (bool) ($supportDecision['can_respond'] ?? false),
        );

        if ($decisionToAnswer) {
            return $this->decisionRecord(
                'outcome_response_required',
                'attention',
                'Review the latest support outcome',
                'Acknowledge the decision or ask the provider for clarification.',
                'applicant',
                'Review outcome',
                $decisionToAnswer['href'],
                $decisionToAnswer['effective_label'] ?? null,
            );
        }

        $underReview = $checkIns->firstWhere('state', 'under_review');

        if ($underReview) {
            return $this->decisionRecord(
                'provider_review',
                'waiting',
                'Wait for the provider review',
                'Your records were submitted. The provider will post the result here.',
                'provider',
                'View check-in',
                $this->checkInUrl($application, $underReview['record_key']),
                $underReview['due_label'] ?? null,
            );
        }

        $upcomingRelease = $releases
            ->filter(fn (array $release): bool => in_array(
                $release['lifecycle_status'] ?? $release['status'] ?? null,
                ['scheduled', 'prepared'],
                true,
            ))
            ->sortBy(fn (array $release): string => $release['release_at'] ?? '9999-12-31T23:59')
            ->first();

        if ($upcomingRelease) {
            return $this->decisionRecord(
                'benefit_scheduled',
                'scheduled',
                'Prepare for the next benefit release',
                'Review the schedule and provider instructions before the release date.',
                'applicant',
                'View release',
                $this->benefitUrl($application, 'release-'.$upcomingRelease['record_id']),
                $upcomingRelease['release_label'] ?? null,
            );
        }

        return $this->decisionRecord(
            'no_action',
            'positive',
            'No action needed right now',
            'Your monitoring record is up to date. New requests will appear here.',
            'provider',
        );
    }

    private function decisionRecord(
        string $state,
        string $tone,
        string $title,
        string $description,
        string $actor,
        ?string $actionLabel = null,
        ?string $actionHref = null,
        ?string $dueLabel = null,
    ): array {
        return [
            'state' => $state,
            'tone' => $tone,
            'eyebrow' => $actor === 'applicant' ? 'Your next action' : 'What happens next',
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'actor_label' => $actor === 'applicant' ? 'You' : 'Scholarship provider',
            'due_label' => $dueLabel,
            'action' => $actionLabel && $actionHref ? [
                'kind' => 'link',
                'label' => $actionLabel,
                'href' => $actionHref,
            ] : null,
        ];
    }

    private function normalizeCheckIns(array $monitoring): Collection
    {
        $structured = collect($monitoring['check_ins'] ?? [])
            ->map(function (array $checkIn): array {
                $state = $this->structuredCheckInState($checkIn);

                return [
                    ...$checkIn,
                    'record_key' => 'check-in-'.$checkIn['id'],
                    'record_type' => 'checklist',
                    'record_type_label' => 'Check-in',
                    'state' => $state,
                    'state_label' => $this->checkInStateLabel($state),
                ];
            });

        $legacy = collect($monitoring['cycles'] ?? [])
            ->map(function (array $cycle): array {
                $submission = $cycle['submission'] ?? null;
                $state = match (true) {
                    ($cycle['status'] ?? null) === 'upcoming' => 'upcoming',
                    in_array($cycle['status'] ?? null, ['closed', 'cancelled'], true) => 'completed',
                    (bool) ($cycle['can_submit'] ?? false) => 'action_required',
                    $submission !== null && ($submission['review_status'] ?? 'pending') === 'pending' => 'under_review',
                    $submission !== null => 'completed',
                    default => 'open',
                };
                $requirement = [
                    'id' => null,
                    'type' => 'academic_progress',
                    'type_label' => 'Academic progress',
                    'icon' => 'fa-solid fa-graduation-cap',
                    'title' => 'Academic record',
                    'description' => $cycle['requirement_label'] ?? null,
                    'required' => true,
                    'requires_file' => true,
                    'status' => $cycle['status'] ?? 'open',
                    'status_label' => $cycle['correction_requested'] ?? false
                        ? 'Replacement requested'
                        : ($submission ? ($submission['review_status_label'] ?? 'Submitted') : 'To submit'),
                    'can_submit' => (bool) ($cycle['can_submit'] ?? false),
                    'correction_requested' => (bool) ($cycle['correction_requested'] ?? false),
                    'locked_reason' => $cycle['locked_reason'] ?? null,
                    'submission' => $submission,
                ];

                return [
                    ...$cycle,
                    'record_key' => 'academic-'.$cycle['id'],
                    'record_type' => 'academic_record',
                    'record_type_label' => 'Academic check-in',
                    'period_label' => collect([
                        $cycle['academic_period'] ?? null,
                        $cycle['school_year'] ?? null,
                    ])->filter()->implode(' - '),
                    'state' => $state,
                    'state_label' => $this->checkInStateLabel($state),
                    'requirements' => [$requirement],
                    'required_count' => 1,
                    'submitted_count' => $submission ? 1 : 0,
                    'pending_count' => ($cycle['can_submit'] ?? false)
                        && (! $submission || ($cycle['correction_requested'] ?? false)) ? 1 : 0,
                ];
            });

        return $structured
            ->concat($legacy)
            ->sortBy([
                fn (array $left, array $right): int => $this->checkInStateRank($left['state'])
                    <=> $this->checkInStateRank($right['state']),
                fn (array $left, array $right): int => ($left['due_at'] ?? '9999-12-31')
                    <=> ($right['due_at'] ?? '9999-12-31'),
            ])
            ->values();
    }

    private function structuredCheckInState(array $checkIn): string
    {
        if (($checkIn['status'] ?? null) === 'upcoming') {
            return 'upcoming';
        }

        if (in_array($checkIn['status'] ?? null, ['closed', 'cancelled'], true)) {
            return ($checkIn['status'] ?? null) === 'cancelled' ? 'cancelled' : 'completed';
        }

        if ((int) ($checkIn['pending_count'] ?? 0) > 0) {
            return 'action_required';
        }

        $requirements = collect($checkIn['requirements'] ?? []);
        if ($requirements->contains(fn (array $requirement): bool => in_array(
            $requirement['status'] ?? null,
            ['submitted', 'not_met'],
            true,
        ))) {
            return 'under_review';
        }

        if ($requirements->isNotEmpty() && $requirements->every(fn (array $requirement): bool => in_array(
            $requirement['status'] ?? null,
            ['completed', 'provider_recorded'],
            true,
        ))) {
            return 'completed';
        }

        return 'open';
    }

    private function checkInStateLabel(string $state): string
    {
        return match ($state) {
            'action_required' => 'Action needed',
            'under_review' => 'Waiting for review',
            'upcoming' => 'Upcoming',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => 'Open',
        };
    }

    private function checkInStateRank(string $state): int
    {
        return match ($state) {
            'action_required' => 0,
            'under_review' => 1,
            'open' => 2,
            'upcoming' => 3,
            'completed' => 4,
            default => 5,
        };
    }

    private function releaseGroups(
        ScholarshipApplication $application,
        Collection $releases,
    ): array {
        $sorted = $releases
            ->map(fn (array $release): array => [
                ...$release,
                'record_key' => 'release-'.$release['record_id'],
                'href' => $this->benefitUrl($application, 'release-'.$release['record_id']),
            ])
            ->sortBy(fn (array $release): string => $release['release_at'] ?? '9999-12-31T23:59')
            ->values();
        $actionRequired = $sorted->filter(
            fn (array $release): bool => (bool) ($release['can_respond'] ?? false),
        )->values();
        $upcoming = $sorted->filter(fn (array $release): bool => ! ($release['can_respond'] ?? false)
            && in_array(
                $release['lifecycle_status'] ?? $release['status'] ?? null,
                ['scheduled', 'prepared'],
                true,
            ))->values();
        $inProgress = $sorted->filter(fn (array $release): bool => (
            ($release['lifecycle_status'] ?? null) === 'issue_open'
        ))->values();
        $completed = $sorted->reject(fn (array $release): bool => $actionRequired->contains(
            fn (array $item): bool => ($item['record_id'] ?? null) === ($release['record_id'] ?? null),
        ) || $upcoming->contains(
            fn (array $item): bool => ($item['record_id'] ?? null) === ($release['record_id'] ?? null),
        ) || $inProgress->contains(
            fn (array $item): bool => ($item['record_id'] ?? null) === ($release['record_id'] ?? null),
        ))->sortByDesc(fn (array $release): string => $release['release_at'] ?? '')->values();

        return [
            'all' => $sorted,
            'action_required' => $actionRequired,
            'upcoming' => $upcoming,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'empty' => $sorted->isEmpty(),
        ];
    }

    private function benefitDirectory(Collection $releases): Collection
    {
        return $releases->values()->map(function (array $release): array {
            $lifecycle = $release['lifecycle_status'] ?? $release['status'] ?? 'scheduled';

            return [
                'record_key' => $release['record_key'],
                'title' => $release['title'],
                'status' => $lifecycle,
                'status_label' => $release['status_label'],
                'release_label' => $release['release_label'] ?? null,
                'amount_label' => $release['amount_label'] ?? null,
                'method_label' => $release['release_method_label'] ?? null,
                'progress_label' => match ($lifecycle) {
                    'confirmation_needed' => 'Your confirmation is required',
                    'issue_open' => 'Waiting for the provider to resolve your report',
                    'fulfilled' => 'Receipt confirmed',
                    'resolved' => 'Reported issue resolved',
                    'prepared' => 'Prepared by the provider',
                    'missed' => 'Release was not completed',
                    'withheld' => 'Release is currently withheld',
                    default => 'Scheduled by the provider',
                },
                'href' => $release['href'],
            ];
        });
    }

    private function selectedBenefit(
        ScholarshipApplication $application,
        Collection $releases,
        ?string $recordKey,
    ): ?array {
        if (blank($recordKey)) {
            return null;
        }

        $release = $releases->firstWhere('record_key', $recordKey);

        if (! $release) {
            throw new InvalidArgumentException('The requested benefit release was not found.');
        }

        return [
            ...$release,
            'return_href' => $this->viewUrl($application, 'benefits'),
        ];
    }

    private function supportDecisions(
        ScholarshipApplication $application,
        Collection $decisions,
    ): Collection {
        return $decisions->values()->map(function (array $decision, int $index) use ($application): array {
            $recordKey = 'outcome-'.$decision['id'];

            return [
                ...$decision,
                'record_key' => $recordKey,
                'can_respond' => $index === 0 && (bool) ($decision['can_respond'] ?? false),
                'href' => $this->outcomeUrl($application, $recordKey),
            ];
        });
    }

    private function historyContent(
        ScholarshipApplication $application,
        Collection $checkIns,
        Collection $completedReleases,
        Collection $decisions,
        ?string $recordKey,
    ): array {
        $actionRequired = $decisions
            ->where('can_respond', true)
            ->values();
        $timeline = $this->historyTimeline(
            $application,
            $checkIns->whereIn('state', ['completed', 'cancelled'])->values(),
            $completedReleases,
            $decisions->where('can_respond', false)->values(),
        );

        return [
            'type' => 'history',
            'action_required' => $actionRequired,
            'timeline' => $timeline,
            'selected' => $this->selectedOutcome($application, $decisions, $recordKey),
            'return_href' => $this->viewUrl($application, 'history'),
            'empty' => $actionRequired->isEmpty() && $timeline->isEmpty(),
        ];
    }

    private function historyTimeline(
        ScholarshipApplication $application,
        Collection $checkIns,
        Collection $releases,
        Collection $decisions,
    ): Collection {
        $outcomes = $decisions->map(fn (array $decision): array => [
            'record_key' => $decision['record_key'],
            'kind' => 'support_outcome',
            'kind_label' => 'Support outcome',
            'title' => $decision['decision_label'],
            'description' => $decision['reason_category_label']
                ?? $decision['response_status_label']
                ?? 'Provider decision recorded',
            'date_label' => $decision['decided_at'] ?? $decision['effective_label'] ?? null,
            'sort_at' => $decision['decided_at_iso'] ?? $decision['effective_on'] ?? '',
            'icon' => match ($decision['decision'] ?? null) {
                'terminated' => 'fa-solid fa-circle-exclamation',
                'completed' => 'fa-solid fa-flag-checkered',
                default => 'fa-solid fa-rotate',
            },
            'tone' => ($decision['decision'] ?? null) === 'terminated' ? 'critical' : 'positive',
            'href' => $decision['href'],
        ]);

        $completedCheckIns = $checkIns->map(fn (array $checkIn): array => [
            'record_key' => $checkIn['record_key'],
            'kind' => 'check_in',
            'kind_label' => $checkIn['record_type_label'] ?? 'Check-in',
            'title' => $checkIn['title'],
            'description' => $checkIn['state'] === 'cancelled'
                ? 'No submission was required.'
                : ($checkIn['period_label'] ?? 'Provider review completed'),
            'date_label' => $checkIn['effective_due_label'] ?? $checkIn['due_label'] ?? null,
            'sort_at' => $checkIn['due_at'] ?? '',
            'icon' => $checkIn['state'] === 'cancelled'
                ? 'fa-solid fa-ban'
                : 'fa-solid fa-list-check',
            'tone' => $checkIn['state'] === 'cancelled' ? 'neutral' : 'positive',
            'href' => $this->checkInUrl($application, $checkIn['record_key']),
        ]);

        $completedBenefits = $releases->map(fn (array $release): array => [
            'record_key' => $release['record_key'],
            'kind' => 'benefit_release',
            'kind_label' => 'Benefit release',
            'title' => $release['title'],
            'description' => collect([
                $release['status_label'] ?? null,
                $release['amount_label'] ?? null,
            ])->filter()->implode(' - '),
            'date_label' => $release['release_label'] ?? null,
            'sort_at' => $release['release_at'] ?? '',
            'icon' => 'fa-solid fa-hand-holding-heart',
            'tone' => in_array($release['lifecycle_status'] ?? null, ['missed', 'withheld'], true)
                ? 'critical'
                : 'positive',
            'href' => $release['href'],
        ]);

        return $outcomes
            ->concat($completedCheckIns)
            ->concat($completedBenefits)
            ->sortByDesc('sort_at')
            ->values();
    }

    private function selectedOutcome(
        ScholarshipApplication $application,
        Collection $decisions,
        ?string $recordKey,
    ): ?array {
        if (blank($recordKey)) {
            return null;
        }

        $decision = $decisions->firstWhere('record_key', $recordKey);

        if (! $decision) {
            throw new InvalidArgumentException('The requested support outcome was not found.');
        }

        return [
            ...$decision,
            'return_href' => $this->viewUrl($application, 'history'),
        ];
    }

    private function checkInEmptyState(array $monitoring, Collection $checkIns): ?array
    {
        if ($checkIns->isNotEmpty()) {
            return null;
        }

        $hasBenefits = collect($monitoring['benefit_releases'] ?? [])->isNotEmpty();

        return [
            'key' => $hasBenefits ? 'one_time_benefit' : 'not_scheduled',
            'title' => $hasBenefits ? 'No recurring check-ins required' : 'No check-in scheduled',
            'description' => $hasBenefits
                ? 'This scholarship currently uses a one-time benefit record instead of recurring monitoring.'
                : 'The provider has not published a monitoring requirement yet.',
        ];
    }

    private function checkInDirectory(
        ScholarshipApplication $application,
        Collection $checkIns,
    ): Collection {
        return $checkIns->values()->map(function (array $checkIn) use ($application): array {
            $requirements = collect($checkIn['requirements'] ?? []);
            $totalItems = $requirements->count();
            $completedItems = $requirements->whereIn('status', ['completed', 'provider_recorded'])->count();
            $pendingItems = (int) ($checkIn['pending_count'] ?? 0);

            return [
                'record_key' => $checkIn['record_key'],
                'record_type' => $checkIn['record_type'],
                'record_type_label' => $checkIn['record_type_label'],
                'title' => $checkIn['title'],
                'period_label' => $checkIn['period_label'] ?? null,
                'due_label' => $checkIn['effective_due_label'] ?? $checkIn['due_label'] ?? null,
                'state' => $checkIn['state'],
                'state_label' => $checkIn['state_label'],
                'total_items' => $totalItems,
                'completed_items' => $completedItems,
                'pending_items' => $pendingItems,
                'progress_label' => match ($checkIn['state']) {
                    'action_required' => trans_choice(':count item needs action|:count items need action', $pendingItems, ['count' => $pendingItems]),
                    'under_review' => 'Submitted for provider review',
                    'completed' => $totalItems > 0 ? "{$completedItems} of {$totalItems} complete" : 'Completed',
                    'upcoming' => $checkIn['opens_label'] ?? 'Opens later',
                    'cancelled' => 'No action required',
                    default => $totalItems > 0 ? "{$completedItems} of {$totalItems} complete" : 'Ready to review',
                },
                'href' => $this->checkInUrl($application, $checkIn['record_key']),
            ];
        });
    }

    private function withCheckInHref(
        ScholarshipApplication $application,
        ?array $checkIn,
    ): ?array {
        if (! $checkIn) {
            return null;
        }

        return [
            ...$checkIn,
            'href' => $this->checkInUrl($application, $checkIn['record_key']),
        ];
    }

    private function selectedCheckIn(
        ScholarshipApplication $application,
        Collection $checkIns,
        ?string $recordKey,
    ): ?array {
        if (blank($recordKey)) {
            return null;
        }

        $record = $checkIns->firstWhere('record_key', $recordKey);

        if (! $record) {
            throw new InvalidArgumentException('The requested check-in record was not found.');
        }

        return [
            ...$record,
            'return_href' => $this->viewUrl($application, 'check-ins'),
        ];
    }

    private function viewUrl(ScholarshipApplication $application, string $view): string
    {
        return route('dashboard.monitoring.show.section', [
            'application' => $application,
            'section' => $view,
        ], false);
    }

    private function checkInUrl(ScholarshipApplication $application, string $recordKey): string
    {
        return route('dashboard.monitoring.check-ins.show', [
            'application' => $application,
            'record' => $recordKey,
        ], false);
    }

    private function benefitUrl(ScholarshipApplication $application, string $recordKey): string
    {
        return route('dashboard.monitoring.benefits.show', [
            'application' => $application,
            'record' => $recordKey,
        ], false);
    }

    private function outcomeUrl(ScholarshipApplication $application, string $recordKey): string
    {
        return route('dashboard.monitoring.history.show', [
            'application' => $application,
            'record' => $recordKey,
        ], false);
    }

    private function publicAssetUrl(?string $path, ?string $fallback = null): ?string
    {
        $path = filled($path) ? $path : $fallback;

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
