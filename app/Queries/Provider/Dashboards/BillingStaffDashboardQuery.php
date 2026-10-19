<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class BillingStaffDashboardQuery
{
    /** @return array<string, mixed> */
    public function get(User $staff): array
    {
        $owner = $staff->providerOrganizationOwner()->loadMissing('providerProfile');
        $purchaseBase = ProviderServicePurchase::query()->where('provider_id', $owner->id);
        $requestBase = ProviderServiceRequest::query()
            ->with(['scholarship', 'purchase'])
            ->where('provider_id', $owner->id)
            ->whereIn('status', ['ready_for_review', 'checkout_started']);
        $legacyPaymentBase = ProviderServicePurchase::query()
            ->where('provider_id', $owner->id)
            ->whereNull('service_request_id')
            ->whereIn('status', ['pending', 'failed']);
        $needsAction = $this->queue(clone $purchaseBase, 'needs_action');
        $active = $this->queue(clone $purchaseBase, 'active');
        $waiting = $this->queue(clone $purchaseBase, 'waiting');
        $completed = $this->queue(clone $purchaseBase, 'completed');
        $unfinishedRequests = (clone $requestBase)
            ->orderByRaw("CASE WHEN status = 'checkout_started' THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServiceRequest $serviceRequest): array => $this->requestItem($serviceRequest))
            ->merge((clone $legacyPaymentBase)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (ProviderServicePurchase $purchase): array => $this->purchaseItem($purchase)))
            ->take(5)
            ->values();
        $providerActions = (clone $needsAction)
            ->orderByRaw("CASE WHEN fulfillment_status = 'provider_review' THEN 0 WHEN fulfillment_status = 'needs_information' THEN 1 ELSE 2 END")
            ->oldest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase): array => $this->purchaseItem($purchase));
        $inProgress = (clone $active)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase): array => $this->purchaseItem($purchase));

        $primaryAction = match (true) {
            $unfinishedRequests->isNotEmpty() => ['label' => 'Finish a request', 'url' => '/provider/workspaces/billing/requests'],
            $providerActions->isNotEmpty() => ['label' => 'Open action needed', 'url' => '/provider/workspaces/billing/action'],
            default => ['label' => 'Browse services', 'url' => '/provider/workspaces/billing/services'],
        };

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'billing-staff',
                'eyebrow' => 'Platform services',
                'title' => 'Service dashboard',
                'description' => 'Finish new requests, respond to support, and track delivery.',
                'icon' => 'fa-solid fa-receipt',
                'organization_name' => $owner->providerProfile?->provider_name ?: $owner->name,
                'access_scope' => 'Organization service requests',
            ],
            primaryAction: $primaryAction,
            metrics: [
                ['key' => 'requests', 'label' => 'Requests to finish', 'value' => (clone $requestBase)->count() + (clone $legacyPaymentBase)->count(), 'detail' => 'Review or payment', 'tone' => 'attention', 'url' => '/provider/workspaces/billing/requests'],
                ['key' => 'needs_action', 'label' => 'Action needed', 'value' => (clone $needsAction)->count(), 'detail' => 'Paid services only', 'tone' => 'attention', 'url' => '/provider/workspaces/billing/action'],
                ['key' => 'active', 'label' => 'In progress', 'value' => (clone $active)->count(), 'detail' => (clone $waiting)->count().' waiting to start', 'url' => '/provider/workspaces/billing/active'],
                ['key' => 'completed', 'label' => 'Closed', 'value' => (clone $completed)->count(), 'detail' => 'Completed or cancelled', 'tone' => 'positive', 'url' => '/provider/workspaces/billing/completed'],
            ],
            sections: [
                [
                    'key' => 'unfinished-requests',
                    'eyebrow' => 'Before payment',
                    'title' => 'Requests to finish',
                    'description' => 'Review the scope and complete payment when ready.',
                    'empty_message' => 'No unfinished service requests.',
                    'action' => ['label' => 'View requests', 'url' => '/provider/workspaces/billing/requests'],
                    'items' => $unfinishedRequests,
                ],
                [
                    'key' => 'provider-actions',
                    'eyebrow' => 'Paid services',
                    'title' => 'Action needed',
                    'description' => 'Respond to a question or review delivered work.',
                    'empty_message' => 'No paid service needs your response.',
                    'action' => ['label' => 'View actions', 'url' => '/provider/workspaces/billing/action'],
                    'items' => $providerActions,
                ],
                [
                    'key' => 'active-services',
                    'eyebrow' => 'Delivery',
                    'title' => 'In progress',
                    'description' => 'Services currently handled by platform support.',
                    'empty_message' => 'No service is currently in progress.',
                    'action' => ['label' => 'View active', 'url' => '/provider/workspaces/billing/active'],
                    'items' => $inProgress,
                ],
            ],
        );
    }

    private function queue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'needs_action' => $query->where(fn (Builder $builder) => $builder
                ->where('status', 'paid')
                ->whereIn('fulfillment_status', ['needs_information', 'provider_review'])),
            'active' => $query->where('status', 'paid')->where('fulfillment_status', 'in_progress'),
            'waiting' => $query->where('status', 'paid')->whereIn('fulfillment_status', ['queued', 'ready']),
            'completed' => $query->where('status', 'paid')->whereIn('fulfillment_status', ['completed', 'cancelled']),
            default => $query,
        };
    }

    /** @return array<string, mixed> */
    private function requestItem(ProviderServiceRequest $serviceRequest): array
    {
        $purchase = $serviceRequest->purchase;
        [$label, $detail] = match (true) {
            $purchase?->status === 'failed' => ['Payment unsuccessful', 'Review the request and try payment again'],
            $serviceRequest->status === 'checkout_started' => ['Payment pending', 'Continue checkout or confirm payment'],
            default => ['Ready for review', 'Confirm the scope and total before payment'],
        };

        return [
            'id' => 'request-'.$serviceRequest->id,
            'title' => $serviceRequest->plan_name,
            'subtitle' => $serviceRequest->scholarship?->title ?: 'Organization-wide request',
            'image_url' => null,
            'status' => ['label' => $label, 'tone' => 'attention'],
            'meta' => [
                $detail,
                $serviceRequest->updated_at ? 'Updated '.$serviceRequest->updated_at->format('M d, Y') : 'Update date unavailable',
            ],
            'action' => [
                'label' => 'Review request',
                'url' => '/provider/workspaces/billing/service-requests/'.$serviceRequest->id.'/review',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseItem(ProviderServicePurchase $purchase): array
    {
        [$label, $detail, $actionLabel, $actionUrl, $tone] = match (true) {
            $purchase->status === 'pending' => ['Payment pending', 'Complete or refresh payment', 'Complete payment', $purchase->checkout_url ?: '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->status === 'failed' => ['Payment failed', 'Start a new service request when ready', 'Browse services', '/provider/workspaces/billing/services', 'attention'],
            $purchase->fulfillment_status === 'needs_information' => ['Response needed', 'Platform support requested more information', 'Respond', '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->fulfillment_status === 'provider_review' => ['Review delivery', 'Confirm the result or request changes', 'Review result', '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->fulfillment_status === 'in_progress' => ['In progress', 'Platform support is working on this request', 'Open request', '/provider/billing/'.$purchase->id, 'neutral'],
            $purchase->fulfillment_status === 'completed' => ['Completed', 'Service delivery is closed', 'View record', '/provider/billing/'.$purchase->id, 'positive'],
            $purchase->fulfillment_status === 'cancelled' => ['Cancelled', 'Cancellation and refund details are recorded', 'View record', '/provider/billing/'.$purchase->id.'/closeout', 'neutral'],
            default => ['Waiting for support', 'Payment confirmed and queued', 'Open request', '/provider/billing/'.$purchase->id, 'neutral'],
        };

        return [
            'id' => (int) $purchase->id,
            'title' => $purchase->plan_name,
            'subtitle' => $purchase->reference_number ?: 'Service request',
            'image_url' => null,
            'status' => ['label' => $label, 'tone' => $tone],
            'meta' => [
                $detail,
                $purchase->updated_at ? 'Updated '.$purchase->updated_at->format('M d, Y') : 'Update date unavailable',
            ],
            'action' => ['label' => $actionLabel, 'url' => $actionUrl],
        ];
    }
}
