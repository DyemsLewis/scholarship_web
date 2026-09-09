<?php

namespace App\Http\Controllers;

use App\Models\ProviderServicePurchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function page(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('admin-finance');
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'month', 'year', 'all'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $period = $validated['period'] ?? 'all';
        $search = trim($validated['search'] ?? '');
        $now = now();

        $paid = ProviderServicePurchase::query()
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        $receiptsQuery = ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'creator.providerProfile'])
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        $this->applyPeriod($receiptsQuery, $period, $now);
        $receiptsQuery->when($search !== '', function (Builder $query) use ($search): void {
            $query->where(function (Builder $nested) use ($search): void {
                $nested->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('plan_name', 'like', "%{$search}%")
                    ->orWhereHas('provider', function (Builder $provider) use ($search): void {
                        $provider->where('email', 'like', "%{$search}%")
                            ->orWhereHas('providerProfile', fn (Builder $profile) => $profile->where('provider_name', 'like', "%{$search}%"));
                    });
            });
        });

        $receipts = $receiptsQuery
            ->latest('paid_at')
            ->paginate(15);

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

        $serviceBreakdown = (clone $paid)
            ->selectRaw('plan_code, plan_name, currency, SUM(amount) as total_amount, COUNT(*) as transaction_count')
            ->groupBy('plan_code', 'plan_name', 'currency')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase) => [
                'plan_code' => $purchase->plan_code,
                'plan_name' => $purchase->plan_name,
                'currency' => $purchase->currency,
                'amount' => (int) $purchase->total_amount,
                'transactions' => (int) $purchase->transaction_count,
            ]);

        return response()->json([
            'currency' => config('billing.currency', 'PHP'),
            'summary' => [
                'today' => (int) (clone $paid)->whereBetween('paid_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->sum('amount'),
                'month' => (int) (clone $paid)->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('amount'),
                'lifetime' => (int) (clone $paid)->sum('amount'),
                'successful_payments' => (int) (clone $paid)->count(),
                'pending_payments' => ProviderServicePurchase::query()->where('status', 'pending')->count(),
                'failed_payments' => ProviderServicePurchase::query()->where('status', 'failed')->count(),
            ],
            'trend' => $trend,
            'service_breakdown' => $serviceBreakdown,
            'receipts' => collect($receipts->items())->map(fn (ProviderServicePurchase $purchase) => $this->receiptPayload($purchase))->values(),
            'pagination' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
            'selected_period' => $period,
        ]);
    }

    private function applyPeriod(Builder $query, string $period, $now): void
    {
        if ($period === 'today') {
            $query->whereBetween('paid_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]);
        } elseif ($period === 'month') {
            $query->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
        } elseif ($period === 'year') {
            $query->whereBetween('paid_at', [$now->copy()->startOfYear(), $now->copy()->endOfYear()]);
        }
    }

    private function receiptPayload(ProviderServicePurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'receipt_number' => $purchase->reference_number,
            'provider' => $purchase->provider?->provider_name ?: $purchase->provider?->name,
            'provider_email' => $purchase->provider?->email,
            'purchased_by' => $purchase->creator?->name,
            'service' => $purchase->plan_name,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'payment_method' => $purchase->payment_method,
            'payment_id' => $purchase->payment_id,
            'payment_intent_id' => $purchase->payment_intent_id,
            'checkout_session_id' => $purchase->checkout_session_id,
            'paid_at' => $purchase->paid_at?->toISOString(),
            'livemode' => $purchase->livemode,
            'fulfillment_status' => $purchase->fulfillment_status,
        ];
    }
}
