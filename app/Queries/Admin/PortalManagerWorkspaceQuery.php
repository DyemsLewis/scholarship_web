<?php

namespace App\Queries\Admin;

use App\Models\User;
use Illuminate\Support\Str;

final class PortalManagerWorkspaceQuery
{
    public function __construct(
        private readonly ReviewQueueQuery $reviews,
        private readonly SupportCaseQuery $support,
        private readonly BillingWorkQueue $services,
        private readonly FinanceRecordQuery $finance,
        private readonly RecordsWorkspaceQuery $records,
    ) {}

    public function overview(): array
    {
        $accounts = $this->accountSummary();
        $reviews = $this->reviews->summary();
        $support = $this->support->summary();
        $services = $this->services->summary();
        $finance = $this->finance->summary();
        $records = $this->records->activity(['period' => 'today', 'per_page' => 1])['summary'];
        $reviewTotal = collect($reviews)->sum();
        $reviewWork = $this->reviews->oversightWorkload($reviews);

        $priorities = collect([
            $this->priority('accounts', 'Account access', $accounts['actionable'], 'Account Manager', '/admin/workspaces/portal/accounts', $accounts['waiting'], 'Account-holder steps are separate from restrictions and Support referrals.'),
            $this->priority('reviews', 'Verification queues', $reviewWork['actionable'], 'Review Officer', '/admin/workspaces/portal/reviews', $reviewWork['waiting'], 'Providers without any legal proof are waiting to submit evidence.'),
            $this->priority('support', 'Support responses', $support['needs_response'], 'Support Officer', '/admin/workspaces/portal/support', $support['waiting'], 'Cases with another team are not counted as unanswered Support work.'),
            $this->priority('services', 'Service delivery and follow-up', $services['actionable'], 'Billing Officer', '/admin/workspaces/portal/billing', $services['waiting_on_provider'], 'Includes active staff-owned delivery and Finance questions, even after service closeout.'),
            $this->priority('finance', 'Payment and refund review', $finance['needs_review'], 'Finance Officer', '/admin/workspaces/portal/finance', $finance['waiting'], 'Gateway confirmation and Billing responses are waiting steps, not Finance-owned actions.'),
            $this->priority('records', 'Audit and security history', 0, 'Records Officer', '/admin/workspaces/portal/records/activity', 0, 'Failed sign-ins are recorded events, not automatically unresolved incidents.', $records['security_7_days']),
        ])->sortBy([['count', 'desc'], ['waiting', 'desc'], ['key', 'asc']])->values();

        return [
            'summary' => [
                'account_attention' => $accounts['attention_total'],
                'pending_reviews' => $reviewTotal,
                'open_reports' => $support['needs_response'],
                'active_services' => $services['active'] + $services['waiting'],
                'month_revenue' => $finance['month'],
                'activity_today' => $records['today'],
                'total_attention' => $priorities->sum('count'),
                'waiting_total' => $priorities->sum('waiting'),
                'reference_events' => $records['security_7_days'],
                'workload_note' => 'Counts are work items by team, not unique people. Waiting steps and audit history are not added to team action.',
            ],
            'priorities' => $priorities,
        ];
    }

    public function accounts(): array
    {
        $summary = $this->accountSummary();
        $users = AccountDirectoryQuery::prioritize(AccountDirectoryQuery::needsAttention(User::query()
            ->with(['studentProfile', 'providerProfile', 'parentAccount.providerProfile', 'adminProfile'])
            ->where('role', '!=', 'admin')))
            ->limit(12)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => Str::headline($user->account_title ?: $user->role),
                'image_url' => match (true) {
                    $user->isApplicant() && filled($user->studentProfile?->profile_photo_path) => route('admin.workspaces.accounts.photo', $user, false),
                    $user->isProvider() => $user->publicPayload()['provider_logo_url'],
                    default => null,
                },
                'image_fit' => $user->isProvider() ? 'contain' : 'cover',
                'issue' => match (true) {
                    (bool) $user->pending_support_handoff => 'Referred by Support',
                    $user->isSuspended() => 'Access restricted',
                    ! $user->hasVerifiedEmail() => 'Email verification needed',
                    default => 'Password reset required',
                },
                'updated_at' => $user->updated_at?->format('M d, Y'),
            ])->values();

        return compact('summary', 'users');
    }

    public function reviews(): array
    {
        $summary = $this->reviews->summary();
        $groups = collect([
            ['key' => 'providers', 'label' => 'Provider verification', 'count' => $summary['provider_pending'], 'items' => $this->reviews->providers(['status' => 'pending', 'per_page' => 3])['items']],
            ['key' => 'programs', 'label' => 'Program review', 'count' => $summary['program_pending'], 'items' => $this->reviews->programs(['status' => 'pending_review', 'per_page' => 3])['items']],
            ['key' => 'applicants', 'label' => 'Applicant verification', 'count' => $summary['applicant_pending'], 'items' => $this->reviews->applicants(['status' => 'pending', 'per_page' => 3])['items']],
            ['key' => 'selection-activities', 'label' => 'Selection activities', 'count' => $summary['selection_activity_attention'], 'items' => $this->reviews->selectionActivities(['status' => 'attention', 'per_page' => 3])['items']],
            ['key' => 'benefits', 'label' => 'Benefit evidence', 'count' => $summary['benefit_attention'], 'items' => $this->reviews->benefits(['status' => 'attention', 'per_page' => 3])['items']],
            ['key' => 'monitoring', 'label' => 'Monitoring oversight', 'count' => $summary['monitoring_attention'], 'items' => $this->reviews->monitoring(['status' => 'attention', 'per_page' => 3])['items']],
        ])->map(function (array $group): array {
            $group['items'] = collect($group['items'])->map(function (array $item): array {
                unset($item['review_url'], $item['recipient_url'], $item['receipt_url']);

                return $item;
            })->values();

            return $group;
        })->values();

        return compact('summary', 'groups');
    }

    public function support(): array
    {
        $summary = $this->support->summary();
        if (isset($summary['next_case'])) {
            unset($summary['next_case']['action_url']);
        }
        $cases = $this->readOnlyCases('needs_response');
        $waiting = $this->readOnlyCases('waiting');

        return compact('summary', 'cases', 'waiting');
    }

    public function services(): array
    {
        $summary = $this->services->summary();
        if (isset($summary['next_request'])) {
            unset($summary['next_request']['action_url']);
        }
        $queues = collect(['ready', 'active', 'waiting', 'actionable', 'provider_waiting', 'finance'])->mapWithKeys(function (string $queue): array {
            $payload = $this->services->directory(['queue' => $queue, 'per_page' => 5]);
            $payload['items'] = collect($payload['items'])->map(function (array $item): array {
                unset($item['action_url']);

                return $item;
            })->values();

            return [$queue => $payload];
        });

        return compact('summary', 'queues');
    }

    public function finance(): array
    {
        $summary = $this->finance->summary();
        $payments = $this->finance->payments(['period' => 'month', 'per_page' => 8]);
        $exceptions = $this->finance->exceptions(['status' => 'all', 'per_page' => 8]);
        $payments['items'] = collect($payments['items'])->map(function (array $item): array {
            unset($item['receipt_url']);

            return $item;
        })->values();

        return compact('summary', 'payments', 'exceptions');
    }

    public function records(): array
    {
        $activity = $this->records->activity(['period' => 'month', 'per_page' => 12]);
        $activity['items'] = collect($activity['items'])->map(function (array $item): array {
            unset($item['record_url']);

            return $item;
        })->values();
        $exports = $this->records->exportDesk();
        $exports['datasets'] = collect($exports['datasets'])->map(function (array $dataset): array {
            unset($dataset['download_url']);

            return $dataset;
        })->values();
        $exports['recent_exports'] = collect($exports['recent_exports'])->map(function (array $item): array {
            unset($item['record_url']);

            return $item;
        })->values();

        return compact('activity', 'exports');
    }

    private function accountSummary(): array
    {
        $accounts = User::query();
        $operationalAccounts = (clone $accounts)->where('role', '!=', 'admin');
        $flags = AccountDirectoryQuery::needsAttention(clone $operationalAccounts);
        $actionable = AccountDirectoryQuery::needsStaffAction(clone $operationalAccounts)->count();

        return [
            'total' => (clone $accounts)->count(),
            'unverified' => (clone $operationalAccounts)->whereNull('email_verified_at')->count(),
            'password_reset' => (clone $operationalAccounts)->where('must_reset_password', true)->count(),
            'suspended' => (clone $operationalAccounts)->where('account_status', 'suspended')->count(),
            'attention_total' => (clone $flags)->count(),
            'actionable' => $actionable,
            'waiting' => (clone $flags)->count() - $actionable,
        ];
    }

    private function readOnlyCases(string $queue): array
    {
        $cases = $this->support->directory(['queue' => $queue, 'per_page' => 10]);
        $cases['items'] = collect($cases['items'])->map(function (array $item): array {
            unset($item['action_url']);

            return $item;
        })->values();

        return $cases;
    }

    private function priority(string $key, string $label, int $count, string $owner, string $url, int $waiting, string $description, int $reference = 0): array
    {
        return [...compact('key', 'label', 'count', 'owner', 'url', 'waiting', 'description', 'reference'),
            'state' => $count > 0 ? 'actionable' : ($waiting > 0 ? 'waiting' : ($reference > 0 ? 'reference' : 'clear'))];
    }
}
