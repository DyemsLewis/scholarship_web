<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class BillingStaffDashboardQuery
{
    /** @return array<string, mixed> */
    public function get(User $staff): array
    {
        $owner = $staff->providerOrganizationOwner()->loadMissing('providerProfile');
        $base = ProviderServicePurchase::query()->where('provider_id', $owner->id);
        $needsAction = $this->queue(clone $base, 'needs_action');
        $active = $this->queue(clone $base, 'active');
        $waiting = $this->queue(clone $base, 'waiting');
        $completed = $this->queue(clone $base, 'completed');
        $priorities = (clone $needsAction)
            ->orderByRaw("CASE WHEN fulfillment_status = 'provider_review' THEN 0 WHEN fulfillment_status = 'needs_information' THEN 1 WHEN status = 'pending' THEN 2 ELSE 3 END")
            ->oldest()
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase): array => $this->item($purchase));
        $inProgress = (clone $active)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ProviderServicePurchase $purchase): array => $this->item($purchase));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'billing-staff',
                'eyebrow' => 'Optional services',
                'title' => 'Service request dashboard',
                'description' => 'Complete payment, information, and delivery reviews before tracking active requests.',
                'icon' => 'fa-solid fa-receipt',
                'organization_name' => $owner->providerProfile?->provider_name ?: $owner->name,
                'access_scope' => 'Organization service requests',
            ],
            primaryAction: [
                'label' => $priorities->isNotEmpty() ? 'Open requests needing action' : 'Browse services',
                'url' => $priorities->isNotEmpty() ? '/provider/workspaces/billing/action' : '/provider/workspaces/billing/services',
            ],
            metrics: [
                ['key' => 'needs_action', 'label' => 'Need action', 'value' => (clone $needsAction)->count(), 'detail' => 'Payment, response, or review', 'tone' => 'attention', 'url' => '/provider/workspaces/billing/action'],
                ['key' => 'active', 'label' => 'In progress', 'value' => (clone $active)->count(), 'detail' => 'Platform work underway', 'url' => '/provider/workspaces/billing/active'],
                ['key' => 'waiting', 'label' => 'Waiting to start', 'value' => (clone $waiting)->count(), 'detail' => 'Queued or ready', 'url' => '/provider/workspaces/billing/waiting'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => (clone $completed)->count(), 'detail' => 'Delivered services', 'tone' => 'positive', 'url' => '/provider/workspaces/billing/completed'],
            ],
            sections: [
                [
                    'key' => 'service-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Requests needing action',
                    'description' => 'Delivery reviews and information requests appear before payment follow-up.',
                    'empty_message' => 'No service request needs organization action.',
                    'action' => ['label' => 'Open action queue', 'url' => '/provider/workspaces/billing/action'],
                    'items' => $priorities,
                ],
                [
                    'key' => 'active-services',
                    'eyebrow' => 'Delivery',
                    'title' => 'Services in progress',
                    'description' => 'Current optional services being delivered by platform support.',
                    'empty_message' => 'No service is currently in progress.',
                    'action' => ['label' => 'View active requests', 'url' => '/provider/workspaces/billing/active'],
                    'items' => $inProgress,
                ],
            ],
        );
    }

    private function queue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'needs_action' => $query->where(fn (Builder $builder) => $builder
                ->whereIn('status', ['pending', 'failed'])
                ->orWhere(fn (Builder $paid) => $paid->where('status', 'paid')->whereIn('fulfillment_status', ['needs_information', 'provider_review']))),
            'active' => $query->where('status', 'paid')->where('fulfillment_status', 'in_progress'),
            'waiting' => $query->where('status', 'paid')->whereIn('fulfillment_status', ['queued', 'ready']),
            'completed' => $query->where('status', 'paid')->where('fulfillment_status', 'completed'),
            default => $query,
        };
    }

    /** @return array<string, mixed> */
    private function item(ProviderServicePurchase $purchase): array
    {
        [$label, $detail, $actionLabel, $actionUrl, $tone] = match (true) {
            $purchase->status === 'pending' => ['Payment pending', 'Complete or refresh payment', 'Complete payment', $purchase->checkout_url ?: '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->status === 'failed' => ['Payment failed', 'Start a new service request when ready', 'Browse services', '/provider/workspaces/billing/services', 'attention'],
            $purchase->fulfillment_status === 'needs_information' => ['Response needed', 'Platform support requested more information', 'Respond', '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->fulfillment_status === 'provider_review' => ['Review delivery', 'Confirm the result or request changes', 'Review result', '/provider/billing/'.$purchase->id, 'attention'],
            $purchase->fulfillment_status === 'in_progress' => ['In progress', 'Platform support is working on this request', 'Open request', '/provider/billing/'.$purchase->id, 'neutral'],
            $purchase->fulfillment_status === 'completed' => ['Completed', 'Service delivery is closed', 'View record', '/provider/billing/'.$purchase->id, 'positive'],
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
                $purchase->created_at ? 'Requested '.$purchase->created_at->format('M d, Y') : 'Request date unavailable',
            ],
            'action' => ['label' => $actionLabel, 'url' => $actionUrl],
        ];
    }
}
