<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class RecipientMonitoringReminderService
{
    /** @var array<string, Collection<int, User>> */
    private array $monitoringTeams = [];

    /**
     * @return array{applicant:int, provider:int, overdue:int, total:int}
     */
    public function send(): array
    {
        $counts = [
            'applicant' => 0,
            'provider' => 0,
            'overdue' => 0,
            'total' => 0,
        ];

        RecipientMonitoringCase::query()
            ->with([
                'cycle.requirements',
                'cycle.scholarship.provider',
                'application.applicant',
                'application.monitoringSubmissions',
                'application.monitoringAdjustmentRequests',
                'application.monitoringInterventions',
            ])
            ->whereNotIn('status', ['closed', 'waived', 'cancelled'])
            ->whereHas('cycle', fn ($query) => $query->whereNotIn('status', ['closed', 'cancelled']))
            ->orderBy('id')
            ->chunkById(100, function (Collection $cases) use (&$counts): void {
                foreach ($cases as $case) {
                    $this->processCase($case, $counts);
                }
            });

        $counts['total'] = $counts['applicant'] + $counts['provider'] + $counts['overdue'];

        return $counts;
    }

    public function markCaseResolved(RecipientMonitoringCase $case): void
    {
        PortalNotification::query()
            ->whereNull('read_at')
            ->where('deduplication_key', 'like', $this->key($case, '').'%')
            ->update(['read_at' => now()]);
    }

    /** @param array{applicant:int, provider:int, overdue:int, total:int} $counts */
    private function processCase(RecipientMonitoringCase $case, array &$counts): void
    {
        $cycle = $case->cycle;
        $application = $case->application;
        $program = $cycle?->scholarship;
        $applicant = $application?->applicant;

        if (! $cycle || ! $application || ! $program || ! $applicant) {
            return;
        }

        $case = app(RecipientMonitoringCaseService::class)->sync($cycle, $application);
        if ($case->isClosed()) {
            $this->markAudienceRemindersRead($case, $applicant->id, null);

            return;
        }

        if ($case->due_status === 'overdue') {
            $this->sendOverdueAlerts($case, $program, $applicant, $counts);

            return;
        }

        if ($case->next_action_role === 'applicant') {
            $currentKey = $case->due_status === 'grace_period'
                ? $this->sendGraceReminder($case, $program, $applicant, $counts)
                : $this->sendApplicantDueReminder($case, $program, $applicant, $counts);
            $this->markAudienceRemindersRead($case, $applicant->id, $currentKey);
            $this->clearProviderReminders($case, $program);

            return;
        }

        $this->markAudienceRemindersRead($case, $applicant->id, null);

        if ($case->next_action_role === 'provider') {
            $this->sendProviderActionReminder($case, $program, $counts);

            return;
        }

        $this->clearProviderReminders($case, $program);
    }

    /** @param array{applicant:int, provider:int, overdue:int, total:int} $counts */
    private function sendApplicantDueReminder(
        RecipientMonitoringCase $case,
        Scholarship $program,
        User $applicant,
        array &$counts,
    ): ?string {
        if (! in_array($case->due_status, ['open', 'extended'], true) || ! $case->effective_due_at) {
            return null;
        }

        $daysRemaining = (int) CarbonImmutable::today()->diffInDays(
            CarbonImmutable::parse($case->effective_due_at),
            false,
        );
        $band = match (true) {
            $daysRemaining === 0 => 'today',
            $daysRemaining >= 1 && $daysRemaining <= 2 => 'soon',
            $daysRemaining >= 3 && $daysRemaining <= 7 => 'week',
            default => null,
        };
        if ($band === null) {
            return null;
        }

        $dueLabel = $case->effective_due_at->format('M d, Y');
        $key = $this->key($case, "applicant:due:{$band}:{$case->effective_due_at->format('Y-m-d')}");
        $title = match ($band) {
            'today' => 'Monitoring check-in due today',
            'soon' => 'Monitoring check-in due soon',
            default => 'Upcoming monitoring deadline',
        };
        $message = "{$case->cycle->title} for {$program->title} is due {$dueLabel}. Open the check-in to complete your next step.";
        $counts['applicant'] += $this->createNotification(
            $applicant->id,
            'monitoring_due_reminder',
            $title,
            $message,
            route('dashboard.monitoring.show', $case->application, false),
            $key,
        );

        return $key;
    }

    /** @param array{applicant:int, provider:int, overdue:int, total:int} $counts */
    private function sendGraceReminder(
        RecipientMonitoringCase $case,
        Scholarship $program,
        User $applicant,
        array &$counts,
    ): ?string {
        if (! $case->effective_due_at || ($case->cycle->grace_period_days ?? 0) < 1) {
            return null;
        }

        $graceEndsAt = CarbonImmutable::parse($case->effective_due_at)
            ->addDays((int) $case->cycle->grace_period_days);
        $daysRemaining = (int) CarbonImmutable::today()->diffInDays($graceEndsAt, false);
        $phase = $daysRemaining <= 1 ? 'closing' : 'open';
        $key = $this->key($case, "applicant:grace:{$phase}:{$graceEndsAt->format('Y-m-d')}");
        $counts['applicant'] += $this->createNotification(
            $applicant->id,
            'monitoring_grace_reminder',
            $phase === 'closing' ? 'Monitoring request window closes soon' : 'Monitoring request window open',
            "If you cannot complete {$case->cycle->title}, request an extension or exception by {$graceEndsAt->format('M d, Y')}.",
            route('dashboard.monitoring.show', $case->application, false),
            $key,
        );

        return $key;
    }

    /** @param array{applicant:int, provider:int, overdue:int, total:int} $counts */
    private function sendProviderActionReminder(
        RecipientMonitoringCase $case,
        Scholarship $program,
        array &$counts,
    ): void {
        $anchor = $this->providerActionAnchor($case);
        if (! $anchor) {
            $this->clearProviderReminders($case, $program);

            return;
        }

        $waitingDays = max(0, (int) $anchor->startOfDay()->diffInDays(CarbonImmutable::today(), false));
        $checkpoint = match (true) {
            $waitingDays >= 5 => 5,
            $waitingDays >= 2 => 2,
            default => null,
        };
        $team = $this->monitoringTeam($program);
        if ($checkpoint === null) {
            $team->each(fn (User $user) => $this->markAudienceRemindersRead($case, $user->id, null));

            return;
        }

        foreach ($team as $user) {
            $key = $this->key($case, "provider:{$user->id}:action:{$case->next_action}:{$checkpoint}:{$anchor->format('Y-m-d')}");
            $this->markAudienceRemindersRead($case, $user->id, $key);
            $counts['provider'] += $this->createNotification(
                $user->id,
                'monitoring_action_reminder',
                $checkpoint >= 5 ? 'Monitoring action has been waiting' : 'Monitoring action reminder',
                "{$case->cycle->title} for {$program->title} has provider work waiting. Open the check-in to {$this->providerActionPhrase($case->next_action)}.",
                route('provider.workspaces.monitoring.check-ins.show', $case->cycle, false),
                $key,
            );
        }
    }

    /** @param array{applicant:int, provider:int, overdue:int, total:int} $counts */
    private function sendOverdueAlerts(
        RecipientMonitoringCase $case,
        Scholarship $program,
        User $applicant,
        array &$counts,
    ): void {
        $graceEndsAt = CarbonImmutable::parse($case->effective_due_at ?? $case->cycle->due_at)
            ->addDays((int) ($case->cycle->grace_period_days ?? 0));
        $overdueDays = max(1, (int) $graceEndsAt->diffInDays(CarbonImmutable::today(), false));
        $checkpoint = $overdueDays >= 7 ? 7 : 1;
        $team = $this->monitoringTeam($program, includeOwner: $checkpoint >= 7);

        foreach ($team as $user) {
            $key = $this->key($case, "provider:{$user->id}:overdue:{$checkpoint}:{$graceEndsAt->format('Y-m-d')}");
            $this->markAudienceRemindersRead($case, $user->id, $key);
            $counts['overdue'] += $this->createNotification(
                $user->id,
                'monitoring_overdue_escalation',
                $checkpoint >= 7 ? 'Urgent monitoring escalation' : 'Monitoring check-in is overdue',
                "{$case->cycle->title} for {$program->title} remains unresolved {$overdueDays} day".($overdueDays === 1 ? '' : 's').' after its request window. Record a resolution or documented follow-up.',
                route('provider.workspaces.monitoring.check-ins.show', $case->cycle, false),
                $key,
            );
        }

        $applicantKey = $this->key($case, "applicant:overdue:{$graceEndsAt->format('Y-m-d')}");
        $this->markAudienceRemindersRead($case, $applicant->id, $applicantKey);
        $counts['applicant'] += $this->createNotification(
            $applicant->id,
            'monitoring_window_closed',
            $case->next_action_role === 'applicant' ? 'Monitoring follow-up is overdue' : 'Monitoring request window closed',
            $case->next_action_role === 'applicant'
                ? "{$case->cycle->title} still needs your follow-up. Open the check-in for the current instructions."
                : "The request window for {$case->cycle->title} has ended. The provider will record the monitoring outcome.",
            route('dashboard.monitoring.show', $case->application, false),
            $applicantKey,
        );
    }

    private function providerActionAnchor(RecipientMonitoringCase $case): ?CarbonImmutable
    {
        $application = $case->application;
        $cycleId = $case->recipient_monitoring_cycle_id;
        $value = match ($case->next_action) {
            'decide_adjustment' => $application->monitoringAdjustmentRequests
                ->where('recipient_monitoring_cycle_id', $cycleId)
                ->where('status', 'pending')
                ->sortByDesc('created_at')
                ->first()?->created_at,
            'review_requirements' => $application->monitoringSubmissions
                ->where('recipient_monitoring_cycle_id', $cycleId)
                ->sortByDesc('submitted_at')
                ->first()?->submitted_at,
            'record_requirement' => $case->cycle->published_at ?? $case->opened_at,
            'close_check_in' => $case->ready_to_close_at,
            'manage_follow_up' => $application->monitoringInterventions
                ->where('recipient_monitoring_cycle_id', $cycleId)
                ->where('status', 'open')
                ->sortByDesc('created_at')
                ->first()?->created_at,
            default => null,
        };

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** @return Collection<int, User> */
    private function monitoringTeam(Scholarship $program, bool $includeOwner = false): Collection
    {
        $cacheKey = ($includeOwner ? 'owner:' : 'team:').$program->id;
        if (isset($this->monitoringTeams[$cacheKey])) {
            return $this->monitoringTeams[$cacheKey];
        }

        $owner = $program->provider;
        if (! $owner) {
            return $this->monitoringTeams[$cacheKey] = collect();
        }

        $candidates = User::query()
            ->where('role', 'provider')
            ->where('account_status', 'active')
            ->where(function ($query) use ($owner): void {
                $query->whereKey($owner->id)
                    ->orWhere('parent_account_id', $owner->id);
            })
            ->get();
        $team = $candidates
            ->filter(fn (User $user): bool => $user->hasPortalPermission('manage_monitoring')
                && $user->canAccessProviderProgram($program));

        if ($includeOwner && $owner->isActive()) {
            $team->push($owner);
        }
        if ($team->isEmpty() && $owner->isActive()) {
            $team->push($owner);
        }

        return $this->monitoringTeams[$cacheKey] = $team
            ->unique('id')
            ->values();
    }

    private function clearProviderReminders(RecipientMonitoringCase $case, Scholarship $program): void
    {
        $this->monitoringTeam($program, includeOwner: true)
            ->each(fn (User $user) => $this->markAudienceRemindersRead($case, $user->id, null));
    }

    private function markAudienceRemindersRead(
        RecipientMonitoringCase $case,
        int $userId,
        ?string $currentKey,
    ): void {
        PortalNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->where('deduplication_key', 'like', $this->key($case, '').'%')
            ->when($currentKey, fn ($query) => $query->where('deduplication_key', '!=', $currentKey))
            ->update(['read_at' => now()]);
    }

    private function createNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
        string $deduplicationKey,
    ): int {
        $notification = PortalNotification::query()->firstOrCreate([
            'deduplication_key' => $deduplicationKey,
        ], [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
        ]);

        return $notification->wasRecentlyCreated ? 1 : 0;
    }

    private function providerActionPhrase(?string $action): string
    {
        return match ($action) {
            'decide_adjustment' => 'decide the recipient request',
            'review_requirements' => 'review the submitted records',
            'record_requirement' => 'record the provider-confirmed result',
            'close_check_in' => 'record the check-in outcome',
            'manage_follow_up' => 'record the follow-up outcome',
            default => 'resolve the current action',
        };
    }

    private function key(RecipientMonitoringCase $case, string $suffix): string
    {
        return "monitoring-case:{$case->id}:{$suffix}";
    }
}
