<?php

namespace App\Queries\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

        $priorities = collect([
            $this->priority('accounts', 'Account access', $accounts['attention_total'], 'Account Manager', '/admin/workspaces/portal/accounts'),
            $this->priority('reviews', 'Verification queues', $reviewTotal, 'Review Officer', '/admin/workspaces/portal/reviews'),
            $this->priority('support', 'Support responses', $support['needs_response'], 'Support Officer', '/admin/workspaces/portal/support'),
            $this->priority('services', 'Service delivery', $services['ready'] + $services['waiting'], 'Billing Officer', '/admin/workspaces/portal/billing'),
            $this->priority('finance', 'Payment exceptions', $finance['exceptions'], 'Finance Officer', '/admin/workspaces/portal/finance'),
            $this->priority('records', 'Security events', $records['security_7_days'], 'Records Officer', '/admin/workspaces/portal/records/activity'),
        ])->sortByDesc('count')->values();

        return [
            'summary' => [
                'account_attention' => $accounts['attention_total'],
                'pending_reviews' => $reviewTotal,
                'open_reports' => $support['needs_response'],
                'active_services' => $services['active'] + $services['waiting'],
                'month_revenue' => $finance['month'],
                'activity_today' => $records['today'],
                'total_attention' => $priorities->sum('count'),
            ],
            'priorities' => $priorities,
        ];
    }

    public function accounts(): array
    {
        $summary = $this->accountSummary();
        $users = User::query()
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->where(function (Builder $query): void {
                $query->whereNull('email_verified_at')
                    ->orWhere('must_reset_password', true)
                    ->orWhere('account_status', 'suspended');
            })
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => Str::headline($user->account_title ?: $user->role),
                'issue' => collect([
                    $user->email_verified_at ? null : 'Email not verified',
                    $user->must_reset_password ? 'Password reset required' : null,
                    $user->account_status === 'suspended' ? 'Account suspended' : null,
                ])->filter()->implode(' · '),
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
        $cases = $this->support->directory(['queue' => 'needs_response', 'per_page' => 10]);
        $cases['items'] = collect($cases['items'])->map(function (array $item): array {
            unset($item['action_url']);

            return $item;
        })->values();

        return compact('summary', 'cases');
    }

    public function services(): array
    {
        $summary = $this->services->summary();
        $queues = collect(['ready', 'active', 'waiting'])->mapWithKeys(function (string $queue): array {
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

        return compact('activity', 'exports');
    }

    private function accountSummary(): array
    {
        $accounts = User::query();

        return [
            'total' => (clone $accounts)->count(),
            'unverified' => (clone $accounts)->whereNull('email_verified_at')->count(),
            'password_reset' => (clone $accounts)->where('must_reset_password', true)->count(),
            'suspended' => (clone $accounts)->where('account_status', 'suspended')->count(),
            'attention_total' => (clone $accounts)->where(function (Builder $query): void {
                $query->whereNull('email_verified_at')
                    ->orWhere('must_reset_password', true)
                    ->orWhere('account_status', 'suspended');
            })->count(),
        ];
    }

    private function priority(string $key, string $label, int $count, string $owner, string $url): array
    {
        return compact('key', 'label', 'count', 'owner', 'url');
    }
}
