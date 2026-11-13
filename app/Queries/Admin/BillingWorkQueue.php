<?php

namespace App\Queries\Admin;

use App\Models\ProviderServicePurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BillingWorkQueue
{
    public function summary(): array
    {
        $next = $this->prioritize($this->filteredQuery('actionable', ''))
            ->first();

        return [
            'ready' => $this->forQueue('ready')->count(),
            'active' => $this->forQueue('active')->count(),
            'waiting' => $this->forQueue('waiting')->count(),
            'completed' => $this->forQueue('completed')->count(),
            'finance' => $this->forQueue('finance')->count(),
            'actionable' => $this->forQueue('actionable')->count(),
            'waiting_on_provider' => $this->forQueue('waiting')
                ->whereNotIn('id', $this->forQueue('actionable')->select('id'))->count(),
            'meeting_requests' => ProviderServicePurchase::query()
                ->where('status', 'paid')
                ->where('meeting_status', 'requested')
                ->count(),
            'next_request' => $next ? $this->item($next) : null,
        ];
    }

    public function directory(array $filters): array
    {
        $queue = $filters['queue'] ?? 'ready';
        $query = $this->filteredQuery($queue, trim((string) ($filters['search'] ?? '')));

        $paginator = ($queue === 'completed' ? $query->latest('fulfilled_at')->latest('id') : $this->prioritize($query))
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->withQueryString();

        return [
            'items' => collect($paginator->items())->map(fn (ProviderServicePurchase $purchase): array => $this->item($purchase))->values(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function exportRows(array $filters): iterable
    {
        $queue = $filters['queue'] ?? 'ready';

        return $this->filteredQuery($queue, trim((string) ($filters['search'] ?? '')))
            ->orderByRaw('CASE WHEN action_due_at IS NOT NULL AND action_due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('action_due_at')
            ->latest('id')
            ->cursor();
    }

    private function filteredQuery(string $queue, string $search): Builder
    {
        return $this->forQueue($queue)
            ->with(['provider.providerProfile', 'assignee.adminProfile'])
            ->withExists(['financeReviews as finance_follow_up' => fn (Builder $query) => $query->where('state', 'with_billing')])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('plan_name', 'like', "%{$search}%")
                        ->orWhere('request_summary', 'like', "%{$search}%")
                        ->orWhereHas('provider', fn (Builder $provider) => $provider
                            ->where('email', 'like', "%{$search}%")
                            ->orWhereHas('providerProfile', fn (Builder $profile) => $profile
                                ->where('provider_name', 'like', "%{$search}%")));
                });
            });
    }

    private function forQueue(string $queue): Builder
    {
        $query = ProviderServicePurchase::query()->where('status', 'paid');

        return match ($queue) {
            'finance' => $query->whereHas('financeReviews', fn (Builder $review) => $review->where('state', 'with_billing')),
            'provider_waiting' => $query->whereIn('fulfillment_status', ['needs_information', 'provider_review'])
                ->whereNotIn('id', $this->forQueue('actionable')->select('id')),
            'actionable' => $query->where(function (Builder $work): void {
                $work->whereHas('financeReviews', fn (Builder $review) => $review->where('state', 'with_billing'))
                    ->orWhere(fn (Builder $delivery) => $delivery->whereNotIn('fulfillment_status', ['completed', 'cancelled'])
                        ->where(function (Builder $work): void {
                            $work->where('meeting_status', 'requested')
                                ->orWhere('cancellation_status', 'requested')
                                ->orWhere('current_action_owner', 'platform')
                                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('current_action_owner')
                                    ->whereIn('fulfillment_status', ['queued', 'ready', 'in_progress']));
                        }));
            }),
            'active' => $query->where('fulfillment_status', 'in_progress'),
            'waiting' => $query->whereIn('fulfillment_status', ['needs_information', 'provider_review']),
            'completed' => $query->whereIn('fulfillment_status', ['completed', 'cancelled']),
            default => $query->whereIn('fulfillment_status', ['queued', 'ready']),
        };
    }

    private function item(ProviderServicePurchase $purchase): array
    {
        $financeFollowUp = (bool) $purchase->finance_follow_up;
        $state = match ($purchase->fulfillment_status) {
            'in_progress' => 'active',
            'needs_information', 'provider_review' => 'waiting',
            'completed', 'cancelled' => 'completed',
            default => 'ready',
        };
        $milestones = collect($purchase->milestones ?? []);

        return [
            'id' => $purchase->id,
            'reference_number' => $purchase->reference_number,
            'plan_name' => $purchase->plan_name,
            'provider' => $purchase->provider?->provider_name ?: $purchase->provider?->name,
            'provider_email' => $purchase->provider?->email,
            'provider_logo_url' => $this->providerLogoUrl($purchase),
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'state' => $state,
            'state_label' => match ($purchase->fulfillment_status) {
                'needs_information' => 'Needs provider information',
                'provider_review' => 'Provider review',
                'in_progress' => 'In progress',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default => 'Ready to start',
            },
            'priority' => $purchase->priority ?? 'normal',
            'next_action_owner' => $purchase->current_action_owner,
            'finance_follow_up' => $financeFollowUp,
            'cancellation_requested' => $purchase->cancellation_status === 'requested',
            'assigned_to' => $purchase->assignee?->name,
            'target_due_at' => $purchase->target_due_at?->format('M d, Y'),
            'action_due_label' => $this->actionDueLabel($purchase),
            'action_overdue' => $purchase->action_due_at
                ? $purchase->action_due_at->copy()->timezone(config('app.timezone'))->startOfDay()
                    ->isBefore(now(config('app.timezone'))->startOfDay())
                : false,
            'paid_at' => $purchase->paid_at?->format('M d, Y h:i A'),
            'meeting_requested' => $purchase->meeting_status === 'requested',
            'milestone_progress' => [
                'complete' => $milestones->where('completed', true)->count(),
                'total' => $milestones->count(),
            ],
            'action_url' => route('admin.workspaces.billing.requests.show', $purchase, false),
        ];
    }

    private function prioritize(Builder $query): Builder
    {
        // Do not let a request without a deadline outrank overdue active work.
        return $query->orderByDesc('finance_follow_up')
            ->orderByRaw('CASE WHEN action_due_at IS NOT NULL AND action_due_at < ? THEN 0 ELSE 1 END', [today()])
            ->orderByRaw("CASE WHEN cancellation_status = 'requested' THEN 0 WHEN meeting_status = 'requested' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 WHEN priority = 'high' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN action_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('action_due_at')->oldest('paid_at')->orderBy('id');
    }

    private function actionDueLabel(ProviderServicePurchase $purchase): ?string
    {
        if (! $purchase->action_due_at || ! $purchase->current_action_owner) {
            return null;
        }

        $days = (int) now(config('app.timezone'))->startOfDay()->diffInDays(
            $purchase->action_due_at->copy()->timezone(config('app.timezone'))->startOfDay(),
            false,
        );

        return match (true) {
            $days < 0 => 'Overdue '.abs($days).'d',
            $days === 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            default => 'Due '.$purchase->action_due_at->format('M d'),
        };
    }

    private function providerLogoUrl(ProviderServicePurchase $purchase): ?string
    {
        $path = $purchase->provider?->providerProfile?->logo_path;

        return filled($path) ? asset(ltrim($path, '/')) : null;
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
