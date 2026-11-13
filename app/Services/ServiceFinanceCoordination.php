<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\ServiceFinanceReview;
use App\Models\User;
use App\Support\AdminWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ServiceFinanceCoordination
{
    public function payload(ProviderServicePurchase $purchase): array
    {
        $reviews = $purchase->financeReviews()->latest('id')->get();
        $active = $reviews->first(fn ($review) => $review->state !== 'checked');
        $latestCheck = $reviews->firstWhere('state', 'checked');
        $fingerprint = $this->fingerprint($purchase);

        return [
            'token' => $this->token([$fingerprint, $reviews->map->getAttributes()->all()]),
            'payment_label' => $purchase->status === 'paid' && ! $purchase->paid_at
                ? 'Paid status; confirmation date missing' : Str::headline($purchase->status),
            'delivery_label' => Str::headline($purchase->fulfillment_status),
            'refund_label' => match ($purchase->refund_status) {
                'manual_refund_pending' => 'External refund pending',
                'refunded' => 'Refund recorded',
                'not_applicable' => 'No refund required',
                default => 'No refund recorded',
            },
            'refund_reference' => $purchase->refund_reference,
            'owner' => $active?->state === 'with_billing' ? 'Billing Officer'
                : ($active ? 'Finance Officer' : (in_array($purchase->status, ['pending', 'failed'], true) ? 'Payment gateway / payer' : 'Finance Officer')),
            'active_review' => $active ? $this->review($active) : null,
            'history' => $reviews->where('state', 'checked')->take(5)->map(fn ($review) => $this->review($review))->values(),
            'check_is_current' => $latestCheck && hash_equals((string) $latestCheck->checked_fingerprint, $fingerprint),
            'source_changed' => $active && ! hash_equals($active->source_fingerprint, $fingerprint),
            'can_request_billing' => $purchase->status === 'paid' && ! $active,
            'can_respond' => auth()->user()?->isAdmin()
                && (AdminWorkspace::usesBillingOfficerWorkspace(auth()->user()) || ! auth()->user()->isManagedAccount())
                && auth()->user()->hasPortalPermission('manage_billing'),
            'notice' => 'Internal record review only. This does not confirm a payment, issue a refund, or complete service delivery.',
        ];
    }

    public function financeReview(Request $request, ProviderServicePurchase $purchase): void
    {
        $this->authorize($request->user(), 'finance');
        $data = $request->validate([
            'expected_finance_token' => ['required', 'string', 'size:64'],
            'action' => ['required', Rule::in(['request_billing', 'record_check'])],
            'note' => ['required', 'string', 'min:10', 'max:1500'],
        ]);
        DB::transaction(function () use ($request, $purchase, $data): void {
            $purchase = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertToken($purchase, $data['expected_finance_token']);
            $active = $purchase->financeReviews()->where('state', '!=', 'checked')->first();
            if ($data['action'] === 'request_billing') {
                abort_unless($purchase->status === 'paid' && ! $active, 409, 'Only a paid service without a pending review can be referred to Billing.');
                $review = $purchase->financeReviews()->create([
                    'state' => 'with_billing', 'request_note' => trim($data['note']),
                    'source_fingerprint' => $this->fingerprint($purchase), 'opened_by' => $request->user()->id,
                ]);
                $this->notify('billing', $purchase, 'Finance requested a record check', '/admin/workspaces/billing/requests/'.$purchase->id);
            } else {
                $review = $active ?: $purchase->financeReviews()->create([
                    'state' => 'checked', 'request_note' => trim($data['note']),
                    'source_fingerprint' => $this->fingerprint($purchase), 'opened_by' => $request->user()->id,
                ]);
                $review->update([
                    'state' => 'checked', 'resolution_note' => trim($data['note']),
                    'checked_fingerprint' => $this->fingerprint($purchase),
                    'closed_by' => $request->user()->id, 'closed_at' => now(),
                ]);
            }
            ActivityLog::record($request->user(), 'service_finance_'.$data['action'], 'Recorded an internal financial review; payment and service states were not changed.', $request,
                ['purchase_id' => $purchase->id, 'finance_review_id' => $review->id, 'review_status' => $review->state]);
        });
    }

    public function billingResponse(Request $request, ProviderServicePurchase $purchase): void
    {
        $this->authorize($request->user(), 'billing');
        $data = $request->validate([
            'expected_finance_token' => ['required', 'string', 'size:64'],
            'note' => ['required', 'string', 'min:10', 'max:1500'],
        ]);
        DB::transaction(function () use ($request, $purchase, $data): void {
            $purchase = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertToken($purchase, $data['expected_finance_token']);
            $review = $purchase->financeReviews()->where('state', 'with_billing')->first();
            abort_unless($purchase->status === 'paid' && $review, 409, 'There is no financial review awaiting a Billing response.');
            $review->update(['state' => 'with_finance', 'billing_response' => trim($data['note']),
                'responded_by' => $request->user()->id, 'responded_at' => now()]);
            ActivityLog::record($request->user(), 'service_finance_billing_responded', 'Returned a financial record check to Finance.', $request,
                ['purchase_id' => $purchase->id, 'finance_review_id' => $review->id]);
            $this->notify('finance', $purchase, 'Billing returned a financial review', '/admin/workspaces/finance/exceptions?search='.urlencode($purchase->reference_number));
        });
    }

    public function authorize(User $user, string $team): void
    {
        $specialist = $team === 'finance' ? AdminWorkspace::usesFinanceOfficerWorkspace($user) : AdminWorkspace::usesBillingOfficerWorkspace($user);
        abort_unless($user->isAdmin() && ! AdminWorkspace::usesPortalManagerWorkspace($user)
            && ($specialist || ! $user->isManagedAccount())
            && $user->hasPortalPermission($team === 'finance' ? 'view_finance' : 'manage_billing'), 403);
    }

    private function assertToken(ProviderServicePurchase $purchase, string $token): void
    {
        abort_unless(hash_equals($this->payload($purchase)['token'], $token), 409,
            'This financial record changed. Reload it and review the latest details before continuing.');
    }

    private function fingerprint(ProviderServicePurchase $purchase): string
    {
        return $this->token($purchase->only(['id', 'status', 'paid_at', 'amount', 'currency', 'payment_id', 'payment_intent_id',
            'checkout_session_id', 'payment_method', 'livemode', 'failed_at', 'failure_message',
            'fulfillment_status', 'cancellation_status', 'refund_status', 'refund_reference']));
    }

    private function token(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function review(ServiceFinanceReview $review): array
    {
        return [...$review->only(['id', 'state', 'request_note', 'billing_response', 'resolution_note']),
            'opened_at' => $review->created_at?->format('M d, Y h:i A'),
            'responded_at' => $review->responded_at?->format('M d, Y h:i A'),
            'closed_at' => $review->closed_at?->format('M d, Y h:i A')];
    }

    private function notify(string $team, ProviderServicePurchase $purchase, string $title, string $url): void
    {
        User::where('role', 'admin')->where('account_status', '!=', 'suspended')->get()->each(function (User $user) use ($team, $purchase, $title, $url): void {
            $specialist = $team === 'finance' ? AdminWorkspace::usesFinanceOfficerWorkspace($user) : AdminWorkspace::usesBillingOfficerWorkspace($user);
            if (($specialist || ! $user->isManagedAccount())
                && $user->hasPortalPermission($team === 'finance' ? 'view_finance' : 'manage_billing')) {
                PortalNotification::create(['user_id' => $user->id, 'type' => 'service_finance_review', 'title' => $title,
                    'message' => 'Review the internal follow-up for '.$purchase->reference_number.'.', 'action_url' => $url]);
            }
        });
    }
}
