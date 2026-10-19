<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ProviderServiceReminderService
{
    private ?Collection $billingAdmins = null;

    /** @var array<int, Collection<int, User>> */
    private array $providerTeams = [];

    /**
     * @return array{provider:int, platform:int, meeting:int, overdue:int, total:int}
     */
    public function send(): array
    {
        $counts = [
            'provider' => 0,
            'platform' => 0,
            'meeting' => 0,
            'overdue' => 0,
            'total' => 0,
        ];

        ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'assignee.adminProfile'])
            ->where('status', 'paid')
            ->whereNotIn('fulfillment_status', ['completed', 'cancelled'])
            ->where(function ($query): void {
                $query->whereNotNull('action_due_at')
                    ->orWhere(function ($meeting): void {
                        $meeting->where('meeting_status', 'confirmed')
                            ->whereNotNull('meeting_scheduled_for');
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function (Collection $purchases) use (&$counts): void {
                foreach ($purchases as $purchase) {
                    $this->processAction($purchase, $counts);
                    $this->processMeeting($purchase, $counts);
                }
            });

        $counts['total'] = $counts['provider'] + $counts['platform'] + $counts['meeting'];

        return $counts;
    }

    /** @param array{provider:int, platform:int, meeting:int, overdue:int, total:int} $counts */
    private function processAction(ProviderServicePurchase $purchase, array &$counts): void
    {
        $allRecipients = $this->providerTeam($purchase)
            ->concat($this->billingAdminTeam())
            ->unique('id')
            ->values();
        $prefix = "provider-service:{$purchase->id}:action:";

        if (! $purchase->current_action_owner || ! $purchase->current_action || ! $purchase->action_due_at) {
            $allRecipients->each(fn (User $user) => $this->markStale($prefix, $user->id));

            return;
        }

        [$band, $days] = $this->dueBand($purchase->action_due_at);
        if ($band === null) {
            $allRecipients->each(fn (User $user) => $this->markStale($prefix, $user->id));

            return;
        }

        $audience = $purchase->current_action_owner === 'provider'
            ? $this->providerTeam($purchase)
            : $this->platformAudience($purchase);
        $audienceIds = $audience->pluck('id')->all();

        foreach ($allRecipients as $recipient) {
            if (! in_array($recipient->id, $audienceIds, true)) {
                $this->markStale($prefix, $recipient->id);
            }
        }

        foreach ($audience as $recipient) {
            $key = $prefix."{$purchase->current_action_owner}:{$band}:{$purchase->action_due_at->format('Y-m-d')}:{$recipient->id}";
            $this->markStale($prefix, $recipient->id, $key);
            $created = $this->createNotification(
                $recipient->id,
                str_starts_with($band, 'overdue') ? 'provider_service_action_overdue' : 'provider_service_action_reminder',
                $this->actionTitle($purchase, $band),
                $this->actionMessage($purchase, $band, $days),
                $this->actionUrl($purchase),
                $key,
            );

            if ($created) {
                $counts[$purchase->current_action_owner]++;
                if (str_starts_with($band, 'overdue')) {
                    $counts['overdue']++;
                }
            }
        }
    }

    /** @param array{provider:int, platform:int, meeting:int, overdue:int, total:int} $counts */
    private function processMeeting(ProviderServicePurchase $purchase, array &$counts): void
    {
        if ($purchase->meeting_status !== 'confirmed' || ! $purchase->meeting_scheduled_for) {
            return;
        }

        $today = CarbonImmutable::today(config('app.timezone'));
        $meetingDay = CarbonImmutable::instance($purchase->meeting_scheduled_for)
            ->setTimezone(config('app.timezone'))
            ->startOfDay();
        $days = (int) $today->diffInDays($meetingDay, false);

        if (! in_array($days, [0, 1], true)) {
            return;
        }

        $band = $days === 0 ? 'today' : 'tomorrow';
        $recipients = $this->providerTeam($purchase)
            ->concat($this->platformAudience($purchase))
            ->unique('id')
            ->values();
        $prefix = "provider-service:{$purchase->id}:meeting:";
        $meetingLabel = $purchase->meeting_scheduled_for
            ->timezone(config('app.timezone'))
            ->format('M j, Y g:i A');
        $modeNote = $purchase->meeting_mode === 'online'
            ? ' Open the meeting page for the joining link.'
            : ' Open the meeting page for the on-site details.';

        foreach ($recipients as $recipient) {
            $key = $prefix."{$band}:{$purchase->meeting_scheduled_for->format('Y-m-d-H-i')}:{$recipient->id}";
            $this->markStale($prefix, $recipient->id, $key);
            $counts['meeting'] += $this->createNotification(
                $recipient->id,
                'provider_service_meeting_reminder',
                $days === 0 ? 'Service meeting today' : 'Service meeting tomorrow',
                "{$purchase->plan_name} has a confirmed meeting on {$meetingLabel}.{$modeNote}",
                $recipient->role === 'admin'
                    ? "/admin/workspaces/billing/requests/{$purchase->id}/meeting"
                    : "/provider/billing/{$purchase->id}/meeting",
                $key,
            );
        }
    }

    /** @return array{0:?string,1:int} */
    private function dueBand($dueAt): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $dueDay = CarbonImmutable::instance($dueAt)
            ->setTimezone(config('app.timezone'))
            ->startOfDay();
        $days = (int) $today->diffInDays($dueDay, false);

        return [match (true) {
            $days >= 1 && $days <= 2 => 'upcoming',
            $days === 0 => 'today',
            $days <= -7 => 'overdue_7',
            $days <= -3 => 'overdue_3',
            $days < 0 => 'overdue_1',
            default => null,
        }, $days];
    }

    private function actionTitle(ProviderServicePurchase $purchase, string $band): string
    {
        return match (true) {
            str_starts_with($band, 'overdue') => 'Provider service action overdue',
            $band === 'today' => 'Provider service action due today',
            default => 'Provider service action due soon',
        };
    }

    private function actionMessage(ProviderServicePurchase $purchase, string $band, int $days): string
    {
        $timing = match (true) {
            str_starts_with($band, 'overdue') => 'is overdue by '.abs($days).' day'.(abs($days) === 1 ? '' : 's'),
            $band === 'today' => 'is due today',
            $days === 1 => 'is due tomorrow',
            default => "is due in {$days} days",
        };

        return "{$purchase->current_action} for {$purchase->plan_name} {$timing}. Open the service workspace to continue.";
    }

    private function actionUrl(ProviderServicePurchase $purchase): string
    {
        if ($purchase->current_action_owner === 'provider') {
            return $purchase->fulfillment_status === 'needs_information'
                ? "/provider/billing/{$purchase->id}/updates"
                : "/provider/billing/{$purchase->id}";
        }

        return match ($purchase->current_action) {
            'Review the meeting request' => "/admin/workspaces/billing/requests/{$purchase->id}/meeting",
            'Review the cancellation request' => "/admin/workspaces/billing/requests/{$purchase->id}/closeout",
            default => "/admin/workspaces/billing/requests/{$purchase->id}/delivery",
        };
    }

    /** @return Collection<int, User> */
    private function providerTeam(ProviderServicePurchase $purchase): Collection
    {
        return $this->providerTeams[$purchase->provider_id] ??= User::query()
            ->where('role', 'provider')
            ->where(function ($query) use ($purchase): void {
                $query->whereKey($purchase->provider_id)
                    ->orWhere('parent_account_id', $purchase->provider_id);
            })
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get()
            ->filter(fn (User $user) => ! $user->isManagedAccount() || $user->hasPortalPermission('manage_billing'))
            ->values();
    }

    /** @return Collection<int, User> */
    private function billingAdminTeam(): Collection
    {
        return $this->billingAdmins ??= User::query()
            ->where('role', 'admin')
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get()
            ->filter(fn (User $user) => $user->hasPortalPermission('manage_billing'))
            ->values();
    }

    /** @return Collection<int, User> */
    private function platformAudience(ProviderServicePurchase $purchase): Collection
    {
        $assigned = $purchase->assignee;

        if ($assigned
            && ($assigned->account_status === null || $assigned->account_status === 'active')
            && $assigned->hasPortalPermission('manage_billing')) {
            return collect([$assigned]);
        }

        return $this->billingAdminTeam();
    }

    private function markStale(string $prefix, int $userId, ?string $currentKey = null): void
    {
        PortalNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->where('deduplication_key', 'like', $prefix.'%')
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
}
