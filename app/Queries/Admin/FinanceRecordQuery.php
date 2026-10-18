<?php

namespace App\Queries\Admin;

use App\Models\ProviderServicePurchase;
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
            'exceptions' => ProviderServicePurchase::query()->whereIn('status', ['pending', 'failed'])->count(),
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
        $paginator = $query->latest('paid_at')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        return $this->page($paginator, fn (ProviderServicePurchase $purchase): array => $this->payment($purchase));
    }

    public function exceptions(array $filters): array
    {
        $status = $filters['status'] ?? 'all';
        $query = ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'creator.providerProfile'])
            ->whereIn('status', ['pending', 'failed']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $this->applySearch($query, $filters['search'] ?? null);
        $paginator = $query->latest()->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

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
            'reason' => $purchase->failure_message,
            'created_at' => $purchase->created_at?->format('M d, Y h:i A'),
            'failed_at' => $purchase->failed_at?->format('M d, Y h:i A'),
        ]);
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
