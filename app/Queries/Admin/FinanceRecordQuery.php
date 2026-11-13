<?php

namespace App\Queries\Admin;

use App\Models\ProviderServicePurchase;
use App\Services\ServiceFinanceCoordination;
use App\Support\AdminWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class FinanceRecordQuery
{
    public function summary(): array
    {
        $now = now();
        $paid = ProviderServicePurchase::query()->where('status', 'paid')->whereNotNull('paid_at');

        $trend = collect(range(6, 0))->map(function (int $daysAgo) use ($paid, $now): array {
            $date = $now->copy()->subDays($daysAgo);

            return [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
                'amount' => (int) (clone $paid)->whereBetween('paid_at', [
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay(),
                ])->sum('amount'),
            ];
        })->values();

        $services = (clone $paid)
            ->selectRaw('plan_code, plan_name, currency, SUM(amount) as total_amount, COUNT(*) as transaction_count')
            ->groupBy('plan_code', 'plan_name', 'currency')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase): array => [
                'plan_code' => $purchase->plan_code,
                'plan_name' => $purchase->plan_name,
                'currency' => $purchase->currency,
                'amount' => (int) $purchase->total_amount,
                'transactions' => (int) $purchase->transaction_count,
            ])
            ->values();

        return [
            'currency' => config('billing.currency', 'PHP'),
            'today' => (int) (clone $paid)->whereBetween('paid_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->sum('amount'),
            'month' => (int) (clone $paid)->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('amount'),
            'lifetime' => (int) (clone $paid)->sum('amount'),
            'successful_payments' => (int) (clone $paid)->count(),
            'pending_payments' => ProviderServicePurchase::query()->where('status', 'pending')->count(),
            'failed_payments' => ProviderServicePurchase::query()->where('status', 'failed')->count(),
            'exceptions' => $this->exceptionQuery()->count(),
            'pending_refunds' => ProviderServicePurchase::where('status', 'paid')->where('refund_status', 'manual_refund_pending')->count(),
            'needs_review' => $this->staffActionQuery()->count(),
            'waiting' => $this->exceptionQuery()->whereNotIn('id', $this->staffActionQuery()->select('id'))->count(),
            'trend' => $trend,
            'services' => $services,
        ];
    }

    public function payments(array $filters): array
    {
        $query = ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'creator.providerProfile'])
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        $this->applyPeriod($query, $filters['period'] ?? 'all');
        $this->applySearch($query, $filters['search'] ?? null);
        $paginator = $query->latest('paid_at')->latest('id')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        return $this->page($paginator, fn (ProviderServicePurchase $purchase): array => $this->payment($purchase));
    }

    public function exceptions(array $filters): array
    {
        $status = $filters['status'] ?? 'all';
        $query = $this->exceptionQuery()
            ->with(['provider.providerProfile', 'creator.providerProfile', 'financeReviews' => fn ($query) => $query->where('state', '!=', 'checked')]);

        match ($status) {
            'needs_review' => $query->whereIn('id', $this->staffActionQuery()->select('id')),
            'pending', 'failed' => $query->where('status', $status),
            'refund' => $query->where('status', 'paid')->where('refund_status', 'manual_refund_pending'),
            'unconfirmed' => $query->where('status', 'paid')->whereNull('paid_at'),
            'follow_up' => $query->whereHas('financeReviews', fn ($query) => $query->where('state', '!=', 'checked')),
            default => null,
        };
        $this->applySearch($query, $filters['search'] ?? null);
        $paginator = $query->orderByRaw("CASE WHEN status = 'failed' THEN 0 ELSE 1 END")
            ->oldest('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        return $this->page($paginator, fn (ProviderServicePurchase $purchase): array => [
            'id' => $purchase->id,
            'reference_number' => $purchase->reference_number,
            'provider' => $purchase->provider?->provider_name ?: $purchase->provider?->name,
            'provider_email' => $purchase->provider?->email,
            'provider_logo_url' => $this->providerLogoUrl($purchase),
            'service' => $purchase->plan_name,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'exception_label' => $this->exceptionLabel($purchase),
            'reason' => $purchase->failure_message,
            'created_at' => $purchase->created_at?->format('M d, Y h:i A'),
            'failed_at' => $purchase->failed_at?->format('M d, Y h:i A'),
        ]);
    }

    private function staffActionQuery(): Builder
    {
        return $this->exceptionQuery()->where('status', '!=', 'pending')
            ->whereDoesntHave('financeReviews', fn (Builder $review) => $review->where('state', 'with_billing'));
    }

    private function exceptionQuery(): Builder
    {
        return ProviderServicePurchase::query()->where(function (Builder $query): void {
            $query->whereIn('status', ['pending', 'failed'])
                ->orWhere(fn (Builder $paid) => $paid->where('status', 'paid')->where(fn (Builder $issue) => $issue
                    ->whereNull('paid_at')->orWhere('refund_status', 'manual_refund_pending')))
                ->orWhereHas('financeReviews', fn (Builder $review) => $review->where('state', '!=', 'checked'));
        });
    }

    private function exceptionLabel(ProviderServicePurchase $purchase): string
    {
        $review = $purchase->financeReviews->first(fn ($item) => $item->state !== 'checked');

        return match (true) {
            $review?->state === 'with_billing' => 'With Billing',
            $review?->state === 'with_finance' => 'Returned to Finance',
            $purchase->status === 'paid' && ! $purchase->paid_at => 'Confirmation incomplete',
            $purchase->status === 'paid' && $purchase->refund_status === 'manual_refund_pending' => 'Refund pending',
            default => ucfirst($purchase->status),
        };
    }

    public function reviewRecord(ProviderServicePurchase $purchase): array
    {
        $purchase->loadMissing(['provider.providerProfile', 'creator.providerProfile']);

        return [...$this->payment($purchase),
            'reference_number' => $purchase->reference_number,
            'payment_status' => $purchase->status,
            'failure_message' => $purchase->failure_message,
            'created_at' => $purchase->created_at?->format('M d, Y h:i A'),
            'receipt_url' => $purchase->status === 'paid' && $purchase->paid_at ? route('admin.workspaces.finance.receipts.show', $purchase, false) : null,
            'coordination' => app(ServiceFinanceCoordination::class)->payload($purchase),
            'can_review' => auth()->user()?->isAdmin() && auth()->user()->hasPortalPermission('view_finance')
                && (AdminWorkspace::usesFinanceOfficerWorkspace(auth()->user()) || ! auth()->user()->isManagedAccount()),
        ];
    }

    public function receipt(ProviderServicePurchase $purchase): array
    {
        $purchase->loadMissing(['provider.providerProfile', 'creator.providerProfile']);

        return [
            ...$this->payment($purchase),
            'payment_intent_id' => $purchase->payment_intent_id,
            'checkout_session_id' => $purchase->checkout_session_id,
            'purchased_by' => $purchase->creator?->name,
            'purchased_by_email' => $purchase->creator?->email,
            'service_terms_accepted_at' => $purchase->service_terms_accepted_at?->format('M d, Y h:i A'),
            'created_at' => $purchase->created_at?->format('M d, Y h:i A'),
        ];
    }

    private function payment(ProviderServicePurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'receipt_number' => $purchase->reference_number,
            'provider' => $purchase->provider?->provider_name ?: $purchase->provider?->name,
            'provider_email' => $purchase->provider?->email,
            'provider_logo_url' => $this->providerLogoUrl($purchase),
            'service' => $purchase->plan_name,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'payment_method' => $purchase->payment_method,
            'payment_id' => $purchase->payment_id,
            'paid_at' => $purchase->paid_at?->format('M d, Y h:i A'),
            'livemode' => (bool) $purchase->livemode,
            'fulfillment_status' => $purchase->fulfillment_status,
            'receipt_url' => route('admin.workspaces.finance.receipts.show', $purchase, false),
        ];
    }

    private function providerLogoUrl(ProviderServicePurchase $purchase): ?string
    {
        $path = $purchase->provider?->providerProfile?->logo_path;

        return filled($path) ? asset(ltrim($path, '/')) : null;
    }

    private function applyPeriod(Builder $query, string $period): void
    {
        $now = now();
        if ($period === 'today') {
            $query->whereBetween('paid_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]);
        } elseif ($period === 'month') {
            $query->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
        } elseif ($period === 'year') {
            $query->whereBetween('paid_at', [$now->copy()->startOfYear(), $now->copy()->endOfYear()]);
        }
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference_number', 'like', "%{$search}%")
                ->orWhere('plan_name', 'like', "%{$search}%")
                ->orWhere('payment_id', 'like', "%{$search}%")
                ->orWhereHas('provider', function (Builder $provider) use ($search): void {
                    $provider->where('email', 'like', "%{$search}%")
                        ->orWhereHas('providerProfile', fn (Builder $profile) => $profile->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                        ->orWhereHas('providerProfile', fn (Builder $profile) => $profile->where('provider_name', 'like', "%{$search}%"));
                });
        });
    }

    private function page(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'items' => collect($paginator->items())->map($map)->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
