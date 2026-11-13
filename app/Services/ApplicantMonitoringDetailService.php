<?php

namespace App\Services;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApplicantMonitoringDetailService
{
    private const VIEWS = ['overview', 'check-ins', 'benefits', 'history'];

    private const ACTIVE_CHECK_INS = ['action_required', 'under_review', 'open'];

    private const PAST_CHECK_INS = ['completed', 'incomplete', 'waived', 'excused', 'closed', 'cancelled'];

    public function build(
        ScholarshipApplication $application,
        User $applicant,
        array $monitoring,
        string $view = 'overview',
        ?string $recordKey = null,
    ): array {
        $view = in_array($view, self::VIEWS, true) ? $view : 'overview';
        [$checkIns, $releases, $decisions] = $this->supportContext($application, $applicant, $monitoring);
        $releaseGroups = $this->releaseGroups($application, $releases, $this->canShowSchedule($application, $monitoring));
        $decision = $this->nextAction($application, $monitoring, $checkIns, $releases, $decisions);

        return [
            'version' => 1,
            'view' => $view,
            'page' => $this->page($view),
            'identity' => $this->identity($application),
            'status' => $this->status($application, $monitoring, $checkIns, $releases),
            'decision' => $decision,
            'summary' => $this->summary($monitoring, $checkIns, $releases),
            'navigation' => $this->navigation($application, $view, $checkIns, $releases, $decisions),
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

    public function landing(User $applicant, Collection $applications, callable $monitoringPayload): array
    {
        $records = $applications->map(function (ScholarshipApplication $application) use ($applicant, $monitoringPayload): array {
            $monitoring = $monitoringPayload($application);
            [$checkIns, $releases, $decisions] = $this->supportContext($application, $applicant, $monitoring);
            $identity = $this->identity($application);
            $status = $this->status($application, $monitoring, $checkIns, $releases);
            $past = in_array($status['key'], ['declined', 'completed', 'terminated'], true);

            return [
                'title' => $identity['program']['title'],
                'logo_url' => $identity['program']['logo_url'],
                'provider_name' => $identity['provider']['name'],
                'href' => $identity['monitoring_href'],
                'history_href' => $this->viewUrl($application, 'history'),
                'status' => $status,
                'decision' => $this->nextAction($application, $monitoring, $checkIns, $releases, $decisions),
                'past' => $past,
                'schedule' => $this->canShowSchedule($application, $monitoring)
                    ? $this->upcomingSchedule($application, $checkIns, $releases)->all()
                    : [],
            ];
        });
        $active = $records->where('past', false)->values();
        $past = $records->where('past', true)->values();
        $focus = $records->filter(fn (array $record): bool => $record['decision']['action'] !== null)
            ->sortBy([
                fn (array $left, array $right): int => $left['decision']['priority'] <=> $right['decision']['priority'],
                fn (array $left, array $right): int => ($left['decision']['sort_at'] ?? '9999-12-31') <=> ($right['decision']['sort_at'] ?? '9999-12-31'),
            ])->first() ?? $active->first();
        $focusedHref = $focus['decision']['action']['href'] ?? null;
        $schedule = $records->flatMap(function (array $record): array {
            return array_map(fn (array $item): array => [...$item, 'program_title' => $record['title']], $record['schedule']);
        })->reject(fn (array $item): bool => $item['href'] === $focusedHref)
            ->sortBy('scheduled_at')->take(2)->values();
        $presentRecord = fn (array $record): array => collect($record)->except(['schedule', 'past'])->all();

        return [
            'focus' => $focus ? $presentRecord($focus) : null,
            'schedule' => $schedule,
            'active' => $active->map($presentRecord),
            'past' => $past->map($presentRecord),
        ];
    }

    private function supportContext(ScholarshipApplication $application, User $applicant, array $monitoring): array
    {
        if ((int) $application->applicant_id !== (int) $applicant->id) {
            throw new InvalidArgumentException('The monitoring record does not belong to this applicant.');
        }

        $application->loadMissing(['scholarship.provider.providerProfile', 'scholarship.monitoringPlan']);

        return [
            $this->normalizeCheckIns($monitoring),
            collect($monitoring['benefit_releases'] ?? [])->values(),
            $this->supportDecisions($application, collect($monitoring['support_decisions'] ?? [])->values()),
        ];
    }

    private function canShowSchedule(ScholarshipApplication $application, array $monitoring): bool
    {
        return ($monitoring['eligible'] ?? false)
            && ($monitoring['agreement_accepted'] ?? false)
            && $application->student_response_status !== 'declined'
            && ! in_array($monitoring['support_status'] ?? null, ['completed', 'terminated'], true);
    }

    private function upcomingSchedule(ScholarshipApplication $application, Collection $checkIns, Collection $releases): Collection
    {
        $dates = $this->scheduledCheckIns($checkIns)
            ->take(2)
            ->map(fn (array $item): array => [
                'section' => 'check-ins',
                'kind' => match (true) {
                    ($item['handoff']['rescheduled'] ?? false) => 'Rescheduled appointment',
                    isset($item['handoff']) => 'Appointment',
                    default => 'Check-in deadline',
                },
                'title' => $item['title'],
                'date_label' => $item['handoff']['scheduled_label'] ?? $item['effective_due_label'] ?? $item['due_label'],
                'scheduled_at' => $this->checkInDate($item),
                'context' => $item['handoff']['location'] ?? null,
                'href' => $this->checkInUrl($application, $item['record_key']),
            ]);

        return $dates->concat($this->scheduledReleases($releases)->take(2)->map(fn (array $item): array => [
            'section' => 'benefits',
            'kind' => 'Benefit release',
            'title' => $item['title'],
            'date_label' => $item['release_label'],
            'scheduled_at' => $item['release_at'],
            'context' => $item['release_method_label'] ?? null,
            'href' => $this->benefitUrl($application, 'release-'.$item['record_id']),
        ]))->sortBy('scheduled_at')->values();
    }

    private function page(string $view): array
    {
        return match ($view) {
            'check-ins' => [
                'eyebrow' => 'Scholarship Support',
                'title' => 'Check-ins',
                'description' => 'Review check-in instructions, due dates, and provider feedback.',
            ],
            'benefits' => [
                'eyebrow' => 'Scholarship Support',
                'title' => 'Benefit releases',
                'description' => 'See what your award covers, how it is delivered, and what you need to do.',
            ],
            'history' => [
                'eyebrow' => 'Support record',
                'title' => 'Support history',
                'description' => 'Review past check-ins and provider outcomes.',
            ],
            default => [
                'eyebrow' => 'Support record',
                'title' => 'Scholarship Support',
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
                'image_url' => $scholarship?->logoUrl() ?? asset('uploads/scholarship-default.jpg'),
                'logo_url' => $scholarship?->logoUrl() ?? asset('uploads/scholarship-default.jpg'),
                'cover_image_url' => $scholarship?->coverImageUrl(),
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

    private function status(ScholarshipApplication $application, array $monitoring, Collection $checkIns, Collection $releases): array
    {
        if (! ($monitoring['eligible'] ?? false)) {
            return [
                'key' => 'not_active',
                'label' => 'Scholarship support not active',
                'tone' => 'neutral',
                'mode' => 'not_active',
                'mode_label' => 'Not active',
            ];
        }

        $supportStatus = $application->student_response_status === 'declined' ? 'declined' : ($monitoring['support_status'] ?? 'active');
        $mode = match (true) {
            $this->hasRecurringCheckIns($application, $checkIns) => 'recurring',
            $application->scholarship?->monitoring_policy === RecipientSupportCatalog::MONITORING_ONE_TIME_FOLLOW_UP => 'follow_up',
            $this->isOneTimeBenefit($application, $checkIns, $releases) => 'one_time',
            default => 'support_only',
        };

        return [
            'key' => $supportStatus,
            'label' => match (true) {
                $supportStatus === 'declined' => 'Award declined',
                $supportStatus === 'active' && ! ($monitoring['agreement_accepted'] ?? false) => 'Agreement needed',
                default => $monitoring['support_status_label'] ?? 'Scholarship support active',
            },
            'tone' => match ($supportStatus) {
                'terminated' => ($monitoring['support_withdrawal_approved'] ?? false) ? 'neutral' : 'critical',
                'declined', 'completed' => 'neutral',
                'renewed' => 'positive',
                default => ($monitoring['agreement_accepted'] ?? false) ? 'positive' : 'attention',
            },
            'mode' => $mode,
            'mode_label' => match ($mode) {
                'recurring' => 'Ongoing support',
                'follow_up' => 'One-time follow-up',
                'one_time' => 'One-time benefit',
                default => 'Support record',
            },
        ];
    }

    private function summary(array $monitoring, Collection $checkIns, Collection $releases): array
    {
        $openCheckIns = $checkIns->whereIn('state', self::ACTIVE_CHECK_INS);
        $completedCheckIns = $checkIns->where('state', 'completed');
        $nextCheckIn = $this->scheduledCheckIns($checkIns)->first();
        $nextRelease = $this->scheduledReleases($releases)->first();

        return [
            'pending_actions' => (int) ($monitoring['pending_count'] ?? 0),
            'check_ins' => [
                'total' => $checkIns->count(),
                'open' => $openCheckIns->count(),
                'completed' => $completedCheckIns->count(),
                'next_due_label' => $nextCheckIn['handoff']['scheduled_label'] ?? $nextCheckIn['effective_due_label'] ?? $nextCheckIn['due_label'] ?? null,
            ],
            'benefits' => [
                'total' => $releases->count(),
                'confirmation_needed' => $releases->where('can_respond', true)->count(),
                'payout_details_needed' => $releases->whereIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)->count(),
                'next_release_label' => $nextRelease['release_label'] ?? null,
            ],
        ];
    }

    private function scheduledCheckIns(Collection $checkIns): Collection
    {
        return $checkIns
            ->whereIn('state', ['action_required', 'upcoming'])
            ->filter(fn (array $checkIn): bool => isset($checkIn['handoff'])
                ? ! $checkIn['handoff']['started']
                : ($checkIn['effective_due_at'] ?? $checkIn['due_at'] ?? '') >= now()->toDateString())
            ->sortBy($this->checkInDate(...));
    }

    private function checkInDate(array $checkIn): string
    {
        return $checkIn['handoff']['scheduled_at'] ?? ($checkIn['effective_due_at'] ?? $checkIn['due_at'] ?? '9999-12-31').'T23:59';
    }

    private function scheduledReleases(Collection $releases): Collection
    {
        return $releases
            ->filter(fn (array $release): bool => in_array(
                $release['lifecycle_status'] ?? $release['status'] ?? null,
                ['scheduled', 'prepared'],
                true,
            ) && ($release['release_at'] ?? '') >= now()->format('Y-m-d\TH:i'))
            ->sortBy('release_at');
    }

    private function navigation(
        ScholarshipApplication $application,
        string $view,
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
                'badge' => 0,
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
                'badge' => $releases->where('can_respond', true)->count() + $releases->whereIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)->count(),
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
            ->reject(fn (array $item): bool => $item['key'] === 'check-ins'
                && $view !== 'check-ins'
                && $this->isOneTimeBenefit($application, $checkIns, $releases))
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
                'active' => $checkIns->whereIn('state', self::ACTIVE_CHECK_INS)->values(),
                'upcoming' => $checkIns->where('state', 'upcoming')->values(),
                'completed' => $checkIns->whereIn('state', self::PAST_CHECK_INS)->values(),
                'directory' => [
                    'next_appointment' => $this->canShowSchedule($application, $monitoring)
                        ? $this->nextAppointment($application, $checkIns)
                        : null,
                    'active' => $this->checkInDirectory(
                        $application,
                        $checkIns->whereIn('state', self::ACTIVE_CHECK_INS),
                    ),
                    'upcoming' => $this->checkInDirectory(
                        $application,
                        $checkIns->where('state', 'upcoming'),
                    ),
                    'completed' => $this->checkInDirectory(
                        $application,
                        $checkIns->whereIn('state', self::PAST_CHECK_INS),
                    ),
                ],
                'selected' => $this->selectedCheckIn($application, $checkIns, $recordKey, $this->canShowSchedule($application, $monitoring)),
                'return_href' => $this->viewUrl($application, 'check-ins'),
                'empty' => $this->checkInEmptyState($application, $monitoring, $checkIns),
            ],
            'benefits' => [
                'type' => 'benefits',
                ...$releaseGroups,
                'one_time' => $this->isOneTimeBenefit($application, $checkIns, $releaseGroups['all']),
                'directory' => [
                    'action_required' => $this->benefitDirectory($releaseGroups['action_required']->where('can_respond', true)),
                    'follow_up' => $this->benefitDirectory($releaseGroups['action_required']->where('follow_up.can_reply', true)),
                    'payout_required' => $this->benefitDirectory($releaseGroups['all']->whereIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)),
                    'upcoming' => $this->benefitDirectory($releaseGroups['upcoming']->whereNotIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)),
                    'in_progress' => $this->benefitDirectory($releaseGroups['in_progress']->whereNotIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)),
                    'completed' => $this->benefitDirectory($releaseGroups['completed']),
                ],
                'selected' => $this->selectedBenefit($application, $releaseGroups['all'], $recordKey),
                'return_href' => $this->viewUrl($application, 'benefits'),
            ],
            'history' => $this->historyContent(
                $application,
                $checkIns,
                $releaseGroups['completed']->reject(fn (array $release): bool => $release['delivery']['key'] === 'inactive'),
                $decisions,
                $recordKey,
            ),
            default => [
                'type' => 'overview',
                'next_action' => $decision,
                'closeout' => in_array($monitoring['support_status'] ?? null, ['completed', 'terminated'], true)
                    && $application->student_response_status !== 'declined'
                    ? $this->closeoutRecord($application, $decisions->first() ?? ['decision' => $monitoring['support_status']])
                    : null,
                'plan' => $this->plan($application, $monitoring, $checkIns, $releaseGroups),
                'schedule' => $this->canShowSchedule($application, $monitoring)
                    ? $this->upcomingSchedule($application, $checkIns, $releaseGroups['upcoming'])
                        ->reject(fn (array $item): bool => $item['href'] === ($decision['action']['href'] ?? null))
                        ->unique('section')->values()
                    : [],
                'next_check_in' => $this->canShowSchedule($application, $monitoring) ? $checkIns
                    ->whereNotIn('state', self::PAST_CHECK_INS)
                    ->pipe(fn (Collection $records): ?array => $this->withCheckInHref(
                        $application,
                        $records->first(),
                    )) : null,
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
        $savedPlan = $application->scholarship?->monitoringPlan;
        $plan = $savedPlan?->status === 'active' ? $savedPlan : null;
        $mode = match (true) {
            $this->hasRecurringCheckIns($application, $checkIns) => 'recurring',
            $application->scholarship?->monitoring_policy === RecipientSupportCatalog::MONITORING_ONE_TIME_FOLLOW_UP => 'follow_up',
            $this->isOneTimeBenefit($application, $checkIns, $releaseGroups['all']) => 'one_time',
            default => 'not_scheduled',
        };

        return [
            'mode' => $mode,
            'mode_label' => match ($mode) {
                'recurring' => 'Recurring check-ins',
                'follow_up' => 'One-time follow-up',
                'one_time' => 'One-time benefit only',
                default => 'No schedule published',
            },
            'frequency' => $plan?->frequency,
            'frequency_label' => $plan?->frequency ? Str::headline($plan->frequency) : null,
            'starts_label' => $plan?->starts_on?->format('M d, Y'),
            'ends_label' => $plan?->ends_on?->format('M d, Y'),
            'instructions' => $plan?->instructions,
            'agreement_accepted' => (bool) ($monitoring['agreement_accepted'] ?? false),
            'agreement' => [
                'label' => match ($application->student_response_status) {
                    'accepted' => 'Accepted',
                    'declined' => 'Declined',
                    default => filled($application->provider_contract_terms_snapshot) ? 'Awaiting response' : 'No agreement on file',
                },
                'href' => filled($application->provider_contract_terms_snapshot)
                    ? route('dashboard.applications.show.section', ['application' => $application, 'section' => 'outcome'], false).'?action=view-agreement'
                    : null,
            ],
        ];
    }

    private function hasRecurringCheckIns(ScholarshipApplication $application, Collection $checkIns): bool
    {
        return match ($application->scholarship?->monitoring_policy) {
            RecipientSupportCatalog::MONITORING_RECURRING => true,
            RecipientSupportCatalog::MONITORING_ONE_TIME_FOLLOW_UP => false,
            default => $checkIns->isNotEmpty() || $application->scholarship?->monitoringPlan?->status === 'active',
        };
    }

    private function isOneTimeBenefit(ScholarshipApplication $application, Collection $checkIns, Collection $releases): bool
    {
        return $checkIns->isEmpty()
            && ! $this->hasRecurringCheckIns($application, $checkIns)
            && $application->scholarship?->monitoring_policy !== RecipientSupportCatalog::MONITORING_ONE_TIME_FOLLOW_UP
            && ($application->scholarship?->support_mode === RecipientSupportCatalog::SUPPORT_ONE_TIME
                || (blank($application->scholarship?->support_mode) && $releases->isNotEmpty()));
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
                'Scholarship support not active',
                'Check-ins and benefits appear here once scholarship support is active.',
                'provider',
            );
        }

        if ($application->student_response_status === 'declined') {
            return $this->decisionRecord('no_action', 'neutral', 'Award declined', 'You declined this award. The record is available in past support.', 'provider');
        }

        $supportEnded = in_array($monitoring['support_status'] ?? null, ['completed', 'terminated'], true);
        if (! $supportEnded && ! ($monitoring['agreement_accepted'] ?? false)) {
            return $this->decisionRecord(
                'agreement_required',
                'attention',
                'Review the recipient agreement',
                'Accept the scholarship terms to continue with check-ins and benefits.',
                'applicant',
                'Review agreement',
                route('dashboard.applications.show.section', [
                    'application' => $application,
                    'section' => 'outcome',
                ], false).'?action=agreement',
            );
        }

        $requiredCheckIn = $supportEnded ? null : $checkIns->first(
            fn (array $checkIn): bool => ! in_array($checkIn['state'], self::PAST_CHECK_INS, true)
                && collect($checkIn['requirements'] ?? [])->contains(
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
                $requiredCheckIn['effective_due_label'] ?? $requiredCheckIn['due_label'] ?? null,
                $this->checkInDate($requiredCheckIn),
            );
        }

        $followUp = $releases->first(fn (array $release): bool => ($release['follow_up']['status'] ?? null) === 'awaiting_response' && ($release['follow_up']['can_reply'] ?? false));
        if ($followUp) {
            return $this->decisionRecord('benefit_follow_up', 'attention', 'Reply about your missed benefit',
                'Ask the provider for a new arrangement, or decline only this benefit. Your scholarship stays unchanged.',
                'applicant', 'Open benefit follow-up', $this->benefitUrl($application, 'release-'.$followUp['record_id']),
                $followUp['follow_up']['deadline_label'] ?? null);
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
                $releaseToConfirm['transfer_sent_label'] ?? $releaseToConfirm['release_label'] ?? null,
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
                ($decisionToAnswer['case_review_url'] ?? null)
                    ? 'Acknowledge the decision or open the independent case review for response and appeal options.'
                    : 'Acknowledge the decision or ask the provider for clarification.',
                'applicant',
                'Review outcome',
                $decisionToAnswer['href'],
                $decisionToAnswer['effective_label'] ?? null,
            );
        }

        $payoutNeeded = $supportEnded ? null : $releases->whereIn('payout.status', RecipientBenefitPayoutService::APPLICANT_ACTIONS)->sortBy('release_at')->first();
        if ($payoutNeeded) {
            $correctionRequested = $payoutNeeded['payout']['status'] === 'correction_requested';

            return $this->decisionRecord(
                'benefit_payout_required',
                'attention',
                $correctionRequested ? 'Correct your payout details' : 'Provide your payout details',
                $correctionRequested
                    ? 'The provider requested a correction. Open the release to see what to update.'
                    : 'The provider scheduled a transfer. Enter your bank or e-wallet details privately on the release record.',
                'applicant',
                'Open benefit release',
                $this->benefitUrl($application, 'release-'.$payoutNeeded['record_id']),
                $payoutNeeded['release_label'] ?? null,
                $payoutNeeded['release_at'] ?? null,
            );
        }

        $appointment = $supportEnded ? null : $this->scheduledCheckIns($checkIns)
            ->first(fn (array $checkIn): bool => isset($checkIn['handoff']) && $checkIn['state'] === 'upcoming');
        if ($appointment) {
            return $this->decisionRecord(
                'check_in_scheduled',
                'scheduled',
                ($appointment['handoff']['rescheduled'] ?? false) ? 'Attend your rescheduled check-in' : 'Attend your scheduled check-in',
                'Bring the listed documents to the provider in person. Do not upload them here.',
                'applicant',
                'View appointment',
                $this->checkInUrl($application, $appointment['record_key']),
                $appointment['handoff']['scheduled_label'],
                $this->checkInDate($appointment),
            );
        }

        $current = $supportEnded ? null : $checkIns->first(fn (array $checkIn): bool => in_array($checkIn['state'], self::ACTIVE_CHECK_INS, true)
            && filled($checkIn['guidance']));

        if ($current) {
            $guidance = $current['guidance'];
            return $this->decisionRecord(
                $guidance['key'],
                $guidance['actor'] === 'applicant' ? 'attention' : 'waiting',
                $guidance['title'],
                $guidance['description'],
                $guidance['actor'],
                'View check-in',
                $this->checkInUrl($application, $current['record_key']),
                $current['handoff']['scheduled_label'] ?? $current['due_label'] ?? null,
            );
        }

        if ($reported = $releases->firstWhere('lifecycle_status', 'issue_open')) {
            return $this->decisionRecord(
                'benefit_issue_open',
                'waiting',
                'Check the reported benefit issue',
                'Open the release to see the latest update and whether a reply is needed.',
                'provider',
                'View release',
                $this->benefitUrl($application, 'release-'.$reported['record_id']),
            );
        }

        if ($supportEnded) {
            $latest = $decisions->first();
            if (($latest['response_status'] ?? null) === 'open') {
                return $this->decisionRecord('outcome_response_pending', 'waiting', 'Your response is with the provider',
                    'The provider will post its reply on the outcome record.', 'provider', 'View your response', $latest['href']);
            }

            return $this->decisionRecord('support_closed', 'neutral', 'No response needed right now',
                'Your decision, benefit records, and support history remain available.', 'provider');
        }

        $upcomingRelease = $this->scheduledReleases($releases)->first();
        if ($upcomingRelease) {
            return $this->decisionRecord(
                'benefit_scheduled',
                'scheduled',
                'Prepare for the next benefit release',
                'Review the schedule and provider instructions before the release date.',
                'applicant',
                'View release',
                $this->benefitUrl($application, 'release-'.$upcomingRelease['record_id']),
                $upcomingRelease['release_label'],
                $upcomingRelease['release_at'],
            );
        }

        $pendingRelease = $supportEnded ? null : $releases->first(fn (array $release): bool => in_array(
            $release['lifecycle_status'] ?? $release['status'] ?? null, ['scheduled', 'prepared'], true,
        ));
        if ($pendingRelease) {
            return $this->decisionRecord(
                'benefit_pending',
                'waiting',
                'Check for a benefit release update',
                'The release has not been marked received. Open the record for the latest provider update.',
                'provider',
                'View release',
                $this->benefitUrl($application, 'release-'.$pendingRelease['record_id']),
            );
        }

        return $this->decisionRecord(
            'no_action',
            'positive',
            'No action needed right now',
            'Your support record is up to date. New requests will appear here.',
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
        ?string $sortAt = null,
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
            'sort_at' => $sortAt,
            'priority' => match ($tone) {
                'attention' => 0,
                'scheduled' => 1,
                'waiting' => 2,
                default => 3,
            },
            'action' => $actionLabel && $actionHref ? [
                'kind' => 'link',
                'label' => $actionLabel,
                'href' => $actionHref,
            ] : null,
        ];
    }

    private function normalizeCheckIns(array $monitoring): Collection
    {
        $supportEnded = in_array($monitoring['support_status'] ?? null, ['completed', 'terminated'], true);
        $structured = collect($monitoring['check_ins'] ?? [])
            ->map(fn (array $checkIn): array => [
                ...$checkIn,
                ...$this->checkInPresentation($checkIn, $supportEnded),
                'record_key' => 'check-in-'.$checkIn['id'],
                'record_type' => 'checklist',
                'record_type_label' => 'Check-in',
            ]);

        $legacy = collect($monitoring['cycles'] ?? [])
            ->map(function (array $cycle) use ($supportEnded): array {
                $submission = $cycle['submission'] ?? null;
                $requirementStatus = match ($submission['review_status'] ?? null) {
                    'met', 'excused' => 'completed',
                    'not_met' => 'not_met',
                    'needs_correction' => 'action_needed',
                    'pending' => 'submitted',
                    default => $cycle['monitoring_case']['is_final'] ? 'closed' : $cycle['status'],
                };
                if (in_array($requirementStatus, ['action_needed', 'submitted'], true) && $cycle['monitoring_case']['is_final']) {
                    $requirementStatus = 'closed';
                }
                $requirement = [
                    'id' => null,
                    'type' => 'academic_progress',
                    'type_label' => 'Academic progress',
                    'icon' => 'fa-solid fa-graduation-cap',
                    'title' => 'Academic record',
                    'description' => $cycle['requirement_label'] ?? null,
                    'required' => true,
                    'requires_file' => true,
                    'status' => $requirementStatus,
                    'status_label' => match ($requirementStatus) {
                        'closed' => 'Submission closed',
                        'action_needed' => 'Replacement requested',
                        'upcoming' => 'Opens later',
                        default => $submission['review_status_label'] ?? 'To submit',
                    },
                    'can_submit' => (bool) ($cycle['can_submit'] ?? false),
                    'correction_requested' => (bool) ($cycle['correction_requested'] ?? false),
                    'locked_reason' => $cycle['locked_reason'] ?? null,
                    'submission' => $submission,
                ];

                $record = [
                    ...$cycle,
                    'record_key' => 'academic-'.$cycle['id'],
                    'record_type' => 'academic_record',
                    'record_type_label' => 'Academic check-in',
                    'period_label' => collect([
                        $cycle['academic_period'] ?? null,
                        $cycle['school_year'] ?? null,
                    ])->filter()->implode(' - '),
                    'requirements' => [$requirement],
                    'required_count' => 1,
                    'submitted_count' => $submission ? 1 : 0,
                    'pending_count' => ($cycle['can_submit'] ?? false)
                        && (! $submission || ($cycle['correction_requested'] ?? false)) ? 1 : 0,
                ];

                return [...$record, ...$this->checkInPresentation($record, $supportEnded)];
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

    private function checkInPresentation(array $record, bool $supportEnded): array
    {
        $case = $record['monitoring_case'];
        $status = $case['display_status'];
        $action = $case['next_action'];
        $isUpcoming = in_array($status, ['scheduled', 'upcoming'], true);
        $needsApplicant = $action && $action['role'] === 'applicant' && $action['key'] !== 'accept_agreement';
        $state = match (true) {
            $case['is_final'] => $status,
            $supportEnded => 'closed',
            $isUpcoming => 'upcoming',
            $needsApplicant => 'action_required',
            ($action['role'] ?? null) === 'provider' => 'under_review',
            default => 'open',
        };

        return [
            'state' => $state,
            'state_label' => $supportEnded && ! $case['is_final'] ? 'Support ended' : $case['status_label'],
            'state_tone' => $supportEnded && ! $case['is_final'] ? 'neutral' : $case['status_tone'],
            'guidance' => in_array($state, self::PAST_CHECK_INS, true) ? null : $this->checkInGuidance($record, $action),
        ];
    }

    private function checkInGuidance(array $record, ?array $action): ?array
    {
        if (! $action) {
            return null;
        }

        [$title, $description] = match ($action['key']) {
            'accept_agreement' => ['Review your recipient agreement', 'Accept the terms before completing this check-in.'],
            'submit_requirements' => ['Submit the remaining records', 'Open the required items to add the requested files.'],
            'replace_record' => ['Replace a check-in record', 'Read the provider feedback and upload the requested replacement.'],
            'attend_handoff' => ['Attend your check-in', 'Bring the listed documents to the appointment. No online upload is needed.'],
            'record_attendance' => ['Wait for attendance to be recorded', 'The provider will confirm whether you attended. No upload is needed.'],
            'record_requirement' => ['Wait for the provider to verify your records', 'The provider will record the result here. No upload is needed.'],
            'resolve_missed_appointment' => ['Missed appointment', 'Contact the provider if you need to arrange another visit. Any new appointment will appear here.'],
            'decide_adjustment' => ['Wait for a decision on your request', 'The provider will post the decision here.'],
            'review_requirements' => ['Wait for the provider review', 'The provider will post the review result here.'],
            'close_check_in' => ['Wait for the final check-in outcome', 'The required items have been verified. The provider still needs to close the check-in.'],
            'resolve_overdue' => ($record['monitoring_case']['attendance_status'] ?? null) === 'no_show'
                ? ['Missed appointment', 'Contact the provider if you need to arrange another visit. Any new appointment will appear here.']
                : ['Overdue check-in', 'The provider will review the overdue record. Contact the provider if you still need help.'],
            'complete_follow_up' => ['Complete the provider follow-up', 'Open the relevant item to read the provider’s instructions.'],
            'manage_follow_up' => ['Wait for the provider follow-up', 'The provider will record the next step here.'],
            'request_adjustment' => ['Request more time or an exception', 'Open an outstanding item to see whether you can still send a request.'],
            'wait_for_opening' => ['Check back when this period opens', 'The check-in will show the requirements you can submit.'],
            default => ['Review your check-in', 'Open the record to see what still needs attention.'],
        };

        return [
            'key' => $action['key'] === 'review_requirements' ? 'provider_review' : $action['key'],
            'title' => $title,
            'description' => $description,
            'actor' => $action['role'] === 'applicant' ? 'applicant' : 'provider',
            'actor_label' => $action['role'] === 'applicant' ? 'You' : 'Scholarship provider',
        ];
    }

    private function checkInStateRank(string $state): int
    {
        return match ($state) {
            'action_required' => 0,
            'under_review' => 1,
            'open' => 2,
            'upcoming' => 3,
            default => 4,
        };
    }

    private function releaseGroups(
        ScholarshipApplication $application,
        Collection $releases,
        bool $canShowSchedule,
    ): array {
        $scheduled = $canShowSchedule ? $this->scheduledReleases($releases)->keyBy('record_id') : collect();
        $sorted = $releases
            ->map(fn (array $release): array => [
                ...$release,
                'record_key' => 'release-'.$release['record_id'],
                'href' => $this->benefitUrl($application, 'release-'.$release['record_id']),
                'delivery' => $this->releasePresentation($release, $scheduled->has($release['record_id']), $canShowSchedule),
            ])
            ->sortBy(fn (array $release): string => $release['release_at'] ?? '9999-12-31T23:59')
            ->values();
        $actionRequired = $sorted->filter(
            fn (array $release): bool => ($release['can_respond'] ?? false) || (($release['follow_up']['status'] ?? null) === 'awaiting_response' && ($release['follow_up']['can_reply'] ?? false)),
        )->values();
        $upcoming = $sorted->filter(fn (array $release): bool => $scheduled->has($release['record_id']))->values();
        $inProgress = $sorted->filter(fn (array $release): bool => in_array(
            $release['delivery']['key'], ['ready', 'waiting', 'issue_open', 'responded'], true,
        ))->values();
        $completed = $sorted->filter(fn (array $release): bool => in_array(
            $release['delivery']['key'], ['fulfilled', 'resolved', 'missed', 'withheld', 'inactive', 'unclaimed', 'declined', 'rescheduled'], true,
        ))->whereNotIn('record_id', $actionRequired->pluck('record_id'))->sortByDesc(fn (array $release): string => $release['release_at'] ?? '')->values();

        return [
            'all' => $sorted,
            'action_required' => $actionRequired,
            'upcoming' => $upcoming,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'empty' => $sorted->isEmpty(),
        ];
    }

    private function releasePresentation(array $release, bool $upcoming, bool $canShowSchedule): array
    {
        $followUp = $release['follow_up'] ?? null;
        if ($followUp && $followUp['status'] !== 'not_started' && ($release['lifecycle_status'] ?? '') === 'missed') {
            return [
                'key' => $followUp['status'] === 'awaiting_response' ? 'missed' : $followUp['status'],
                'label' => $followUp['label'], 'tone' => 'attention',
                'title' => ($followUp['can_reply'] ?? false) ? 'Reply about this benefit' : 'Review the follow-up',
                'description' => $followUp['resolution_note'] ?: ($followUp['instructions'] ?: 'The benefit has not been confirmed as received.'),
                'date_label' => $followUp['deadline_label'],
                'date_caption' => 'Reply deadline on record',
            ];
        }
        $lifecycle = $release['lifecycle_status'] ?? $release['status'] ?? 'scheduled';
        $inPerson = ($release['release_method'] ?? null) === 'in_person';
        $sent = $release['transfer_sent_label'] ?? null;
        $key = match (true) {
            ! in_array($lifecycle, ['scheduled', 'prepared'], true) => $lifecycle,
            ! $canShowSchedule => 'inactive',
            $upcoming => 'scheduled',
            $lifecycle === 'prepared' && $inPerson && filled($release['release_at'] ?? null) => 'ready',
            default => 'waiting',
        };

        [$label, $tone, $title, $description] = match ($key) {
            'confirmation_needed' => $sent
                ? ['Sent - awaiting receipt', 'attention', 'Check your account before confirming', 'The provider recorded a transfer. If it did not arrive, or the amount is different, report a problem instead.']
                : ['Confirmation needed', 'attention', 'Confirm only after you receive it', 'The provider recorded this release. If it is missing, incomplete, or different from what is listed, report a problem instead.'],
            'fulfilled' => ['Received', 'positive', 'No action needed', 'Receipt has been confirmed for this release.'],
            'issue_open' => ['Report open', 'info', 'Check your report', 'Open the delivery report below to see the latest update and whether you need to reply.'],
            'resolved' => ['Issue resolved', 'positive', 'Review the resolution', 'The provider recorded an outcome. Read the resolution for details.'],
            'ready' => ['Ready for collection', 'info', 'Check the collection instructions', 'Follow the provider’s instructions before going to the collection venue.'],
            'missed' => [$release['status_label'] ?? 'Appointment missed', 'critical', 'Ask about a new arrangement', 'Check the provider’s note for any follow-up. The benefit is not recorded as received.'],
            'withheld' => ['Release withheld', 'critical', 'Review the provider’s update', 'Check the provider’s note for the reason and any next step.'],
            'scheduled' => ['Scheduled', 'neutral', $inPerson ? 'Prepare for collection' : 'Check the delivery instructions', $inPerson ? 'Review the date, venue, and any documents to bring.' : 'Review how the benefit will be sent. Confirm receipt only after it arrives.'],
            'inactive' => ['No active release', 'neutral', 'No action needed', 'This is a previous schedule, not an active collection or delivery instruction.'],
            default => ['Awaiting release update', 'neutral', 'Wait for a release update', 'The provider has not recorded delivery. Check for new instructions or report a delivery problem if needed.'],
        };
        $received = $key === 'fulfilled' ? ($release['receipt_response']['received_label'] ?? null) : null;
        $released = $release['released_at'] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'title' => $title,
            'description' => $description,
            'date_label' => $received ?? $sent ?? $released ?? $release['release_label'] ?? null,
            'date_caption' => match (true) {
                $received !== null => 'Received on',
                $sent !== null => 'Sent on',
                $released !== null => 'Provider recorded release',
                in_array($key, ['scheduled', 'ready'], true) => 'Scheduled for',
                default => 'Scheduled date on record',
            },
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
                'status_label' => $release['delivery']['label'],
                'release_label' => $release['release_label'] ?? null,
                'amount_label' => $release['amount_label'] ?? null,
                'method_label' => $release['release_method_label'] ?? null,
                'delivery' => $release['delivery'],
                'payout_status' => $release['payout']['status'] ?? null,
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
            $canRespond = $index === 0 && (bool) ($decision['can_respond'] ?? false);

            return [
                ...$decision,
                'record_key' => $recordKey,
                'can_respond' => $canRespond,
                'response_status_label' => match ($decision['response_status'] ?? null) {
                    'open' => 'Awaiting provider reply',
                    'resolved' => 'Response resolved',
                    'acknowledged' => 'Acknowledged',
                    default => $canRespond ? 'Response needed' : 'No response recorded',
                },
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
            $checkIns->whereIn('state', self::PAST_CHECK_INS)->where('monitoring_case.is_final', true)->values(),
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
            'description' => $decision['reason_category_label'] ?? null,
            'status_label' => $decision['response_status_label'],
            'status_tone' => match ($decision['response_status'] ?? null) {
                'open' => 'info',
                'resolved', 'acknowledged' => 'positive',
                default => 'neutral',
            },
            ...$this->historyDate(
                $decision['decided_at_iso'] ?? $decision['effective_on'] ?? null,
                filled($decision['decided_at_iso'] ?? null) ? 'Decided' : 'Effective',
            ),
            'icon' => match ($decision['decision'] ?? null) {
                'terminated' => 'fa-solid fa-circle-exclamation',
                'completed' => 'fa-solid fa-flag-checkered',
                default => 'fa-solid fa-rotate',
            },
            'tone' => ($decision['decision'] ?? null) === 'terminated' ? 'critical' : 'positive',
            'href' => $decision['href'],
        ]);

        $pastCheckIns = $checkIns->map(fn (array $checkIn): array => [
            'record_key' => $checkIn['record_key'],
            'kind' => 'check_in',
            'kind_label' => $checkIn['record_type_label'] ?? 'Check-in',
            'title' => $checkIn['title'],
            'description' => $checkIn['state_label'],
            'period_label' => $checkIn['period_label'] ?? null,
            'status_label' => $checkIn['state_label'],
            'status_tone' => $checkIn['state_tone'],
            ...$this->historyDate($checkIn['monitoring_case']['closed_at_iso'] ?? null, 'Closed'),
            'icon' => match ($checkIn['state']) {
                'incomplete' => 'fa-solid fa-circle-exclamation',
                'cancelled' => 'fa-solid fa-ban',
                default => 'fa-solid fa-list-check',
            },
            'tone' => $checkIn['state_tone'],
            'href' => $this->checkInUrl($application, $checkIn['record_key']),
        ]);

        $completedBenefits = $releases->map(fn (array $release): array => [
            'record_key' => $release['record_key'],
            'kind' => 'benefit_release',
            'kind_label' => 'Benefit release',
            'title' => $release['title'],
            'description' => $release['amount_label'] ?? null,
            'status_label' => $release['delivery']['label'],
            'status_tone' => $release['delivery']['tone'],
            ...$this->releaseHistoryDate($release),
            'icon' => 'fa-solid fa-hand-holding-heart',
            'tone' => in_array($release['lifecycle_status'] ?? null, ['missed', 'withheld'], true)
                ? 'critical'
                : 'positive',
            'href' => $release['href'],
        ]);

        return $outcomes
            ->concat($pastCheckIns)
            ->concat($completedBenefits)
            ->sortByDesc(fn (array $item): string => $item['sort_at'] ?? '')
            ->values();
    }

    private function releaseHistoryDate(array $release): array
    {
        if ($release['delivery']['key'] === 'fulfilled' && filled($release['receipt_response']['received_on'] ?? null)) {
            return $this->historyDate($release['receipt_response']['received_on'], 'Received');
        }

        if ($release['delivery']['key'] === 'resolved' && filled($release['receipt_response']['resolved_at_iso'] ?? null)) {
            return $this->historyDate($release['receipt_response']['resolved_at_iso'], 'Resolved');
        }

        return $this->historyDate($release['recorded_at_iso'] ?? $release['released_at_iso'] ?? null, 'Recorded');
    }

    private function historyDate(?string $value, string $caption): array
    {
        $date = $value ? Carbon::parse($value, config('app.timezone'))->setTimezone(config('app.timezone')) : null;

        return [
            'sort_at' => $date?->format('Y-m-d\TH:i:s'),
            'date_label' => $date?->format('M d, Y'),
            'date_caption' => $date ? $caption : null,
            'month_key' => $date?->format('Y-m'),
            'month_label' => $date?->format('F Y'),
        ];
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
            'closeout' => $this->closeoutRecord($application, $decision, current: $decision['id'] === $decisions->first()['id']),
        ];
    }

    private function closeoutRecord(ScholarshipApplication $application, array $decision, bool $current = true): ?array
    {
        if (! in_array($decision['decision'] ?? null, ['completed', 'terminated'], true)) {
            return null;
        }

        $withdrawn = (bool) ($decision['withdrawal_approved'] ?? false);
        [$kind, $title, $description] = match (true) {
            $withdrawn => ['withdrawn', 'Withdrawal approved', 'Your withdrawal request was approved. Earlier benefits and check-ins remain on record.'],
            $decision['decision'] === 'completed' => ['completed', 'Support period completed', 'The provider recorded completion of your scholarship support period.'],
            default => ['ended_early', 'Support ended early', 'The provider recorded an early end to your scholarship support.'],
        };

        return [
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'current' => $current,
            'effective_label' => $decision['effective_label'] ?? null,
            'reason_label' => $decision['reason_category_label'] ?? null,
            'note' => $decision['reason'] ?? null,
            'counts' => $decision['closeout_counts'] ?? null,
            'benefits_href' => $this->viewUrl($application, 'benefits'),
            'outcome_href' => $decision['href'] ?? null,
            'withdrawal_href' => $withdrawn ? route('dashboard.applications.show.section', [
                'application' => $application,
                'section' => 'outcome',
            ], false).'#scholarship-withdrawal' : null,
        ];
    }

    private function checkInEmptyState(ScholarshipApplication $application, array $monitoring, Collection $checkIns): ?array
    {
        if ($checkIns->isNotEmpty()) {
            return null;
        }

        $isOneTime = $this->isOneTimeBenefit($application, $checkIns, collect($monitoring['benefit_releases'] ?? []));

        return [
            'key' => $isOneTime ? 'one_time_benefit' : 'not_scheduled',
            'title' => $isOneTime ? 'No recurring check-ins required' : 'No check-in scheduled',
            'description' => $isOneTime
                ? 'This scholarship currently uses a one-time benefit record instead of recurring check-ins.'
                : 'The provider has not scheduled a check-in for you yet.',
        ];
    }

    private function checkInDirectory(
        ScholarshipApplication $application,
        Collection $checkIns,
    ): Collection {
        return $checkIns->values()->map(function (array $checkIn) use ($application): array {
            $requirements = collect($checkIn['requirements'] ?? []);
            $totalItems = $requirements->count();
            $completedItems = $requirements->where('status', 'completed')->count();
            $pendingItems = (int) ($checkIn['pending_count'] ?? 0);

            return [
                'record_key' => $checkIn['record_key'],
                'record_type' => $checkIn['record_type'],
                'record_type_label' => $checkIn['record_type_label'],
                'title' => $checkIn['title'],
                'period_label' => $checkIn['period_label'] ?? null,
                'due_label' => $checkIn['effective_due_label'] ?? $checkIn['due_label'] ?? null,
                'handoff' => $checkIn['handoff'] ?? null,
                'state' => $checkIn['state'],
                'state_label' => $checkIn['state_label'],
                'state_tone' => $checkIn['state_tone'],
                'next_actor_label' => $checkIn['guidance']['actor_label'] ?? null,
                'closed_at' => $checkIn['monitoring_case']['closed_at'] ?? null,
                'total_items' => $totalItems,
                'completed_items' => $completedItems,
                'pending_items' => $pendingItems,
                'progress_label' => match ($checkIn['state']) {
                    'action_required' => $pendingItems > 0
                        ? trans_choice(':count item needs action|:count items need action', $pendingItems, ['count' => $pendingItems])
                        : ($checkIn['guidance']['title'] ?? 'Review the next step'),
                    'under_review' => $checkIn['guidance']['description'] ?? 'Waiting for the provider.',
                    'completed' => $totalItems > 0 ? "{$completedItems} of {$totalItems} complete" : 'Completed',
                    'upcoming' => isset($checkIn['handoff']) ? 'In-person appointment' : ($checkIn['opens_label'] ?? 'Opens later'),
                    'cancelled' => 'No action required',
                    default => $totalItems > 0 ? "{$completedItems} of {$totalItems} complete" : 'Ready to review',
                },
                'href' => $this->checkInUrl($application, $checkIn['record_key']),
            ];
        });
    }

    private function nextAppointment(ScholarshipApplication $application, Collection $checkIns): ?array
    {
        $appointment = $checkIns->whereNotIn('state', self::PAST_CHECK_INS)
            ->filter(fn (array $record): bool => isset($record['handoff']) && ! $record['handoff']['started'])
            ->sortBy($this->checkInDate(...))->first();
        if (! $appointment) {
            return null;
        }

        return [
            ...$this->checkInDirectory($application, collect([$appointment]))->first(),
            'preparation' => $this->appointmentPreparation($appointment),
        ];
    }

    private function appointmentPreparation(array $record): ?array
    {
        if (! isset($record['handoff']) || $record['handoff']['started'] || in_array($record['state'], self::PAST_CHECK_INS, true)) {
            return null;
        }

        $documents = collect($record['requirements'])
            ->filter(fn (array $requirement): bool => $requirement['requires_original_verification']
                && $requirement['status'] !== 'completed')
            ->values();

        return [
            'document_ids' => $documents->pluck('id')->all(),
            'document_count' => $documents->count(),
            'preview_titles' => $documents->take(2)->pluck('title')->all(),
        ];
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
        bool $canPrepare,
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
            'preparation' => $canPrepare ? $this->appointmentPreparation($record) : null,
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
