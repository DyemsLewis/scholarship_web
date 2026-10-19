<?php

namespace App\Queries\Admin;

use App\Models\ProviderServicePurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BillingWorkQueue
{
    public function summary(): array
    {
        $next = $this->forQueue('ready')
            ->with(['provider.providerProfile', 'assignee.adminProfile'])
            ->orderByRaw('CASE WHEN action_due_at IS NOT NULL AND action_due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('action_due_at')
            ->oldest('paid_at')
            ->first();

        return [
            'ready' => $this->forQueue('ready')->count(),
            'active' => $this->forQueue('active')->count(),
            'waiting' => $this->forQueue('waiting')->count(),
            'completed' => $this->forQueue('completed')->count(),
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

        $paginator = $query
            ->orderByRaw('CASE WHEN action_due_at IS NOT NULL AND action_due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw("CASE WHEN meeting_status = 'requested' THEN 0 ELSE 1 END")
            ->orderBy('action_due_at')
            ->orderBy($queue === 'completed' ? 'fulfilled_at' : 'paid_at', $queue === 'completed' ? 'desc' : 'asc')
            ->latest('id')
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
            'active' => $query->where('fulfillment_status', 'in_progress'),
            'waiting' => $query->whereIn('fulfillment_status', ['needs_information', 'provider_review']),
            'completed' => $query->whereIn('fulfillment_status', ['completed', 'cancelled']),
            default => $query->whereIn('fulfillment_status', ['queued', 'ready']),
        };
    }

    private function item(ProviderServicePurchase $purchase): array
    {
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
