<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\BillingWorkQueue;

final class BillingOfficerDashboardQuery
{
    public function __construct(private readonly BillingWorkQueue $billing) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $summary = $this->billing->summary();
        $ready = collect($this->billing->directory(['queue' => 'ready', 'per_page' => 5])['items'])
            ->map(fn (array $request): array => $this->item($request, true));
        $active = collect($this->billing->directory(['queue' => 'active', 'per_page' => 5])['items'])
            ->map(fn (array $request): array => $this->item($request));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'billing-officer',
                'eyebrow' => 'Service delivery',
                'title' => 'Service request dashboard',
                'description' => 'Start paid requests and track service delivery.',
                'icon' => 'fa-solid fa-file-invoice-dollar',
                'access_scope' => 'Paid provider service requests',
            ],
            primaryAction: [
                'label' => $summary['ready'] > 0 ? 'Open ready requests' : 'Open active delivery',
                'url' => $summary['ready'] > 0 ? '/admin/workspaces/billing/queues/ready' : '/admin/workspaces/billing/queues/active',
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'ready', 'label' => 'Ready to start', 'value' => $summary['ready'], 'detail' => 'Paid requests', 'tone' => 'attention', 'url' => '/admin/workspaces/billing/queues/ready', 'icon' => 'fa-solid fa-play'],
                ['key' => 'active', 'label' => 'Active delivery', 'value' => $summary['active'], 'detail' => 'In progress', 'url' => '/admin/workspaces/billing/queues/active', 'icon' => 'fa-solid fa-list-check'],
                ['key' => 'waiting', 'label' => 'Waiting on provider', 'value' => $summary['waiting'], 'detail' => 'Information or review', 'tone' => 'attention', 'url' => '/admin/workspaces/billing/queues/waiting', 'icon' => 'fa-solid fa-clock'],
                ['key' => 'completed', 'label' => 'Closed', 'value' => $summary['completed'], 'detail' => 'Completed or cancelled', 'tone' => 'positive', 'url' => '/admin/workspaces/billing/queues/completed', 'icon' => 'fa-solid fa-circle-check'],
            ],
            sections: [
                [
                    'key' => 'ready-requests',
                    'eyebrow' => 'Current focus',
                    'title' => 'Paid requests ready to start',
                    'description' => 'Oldest requests appear first, with meeting requests prioritized.',
                    'empty_message' => 'No paid request is waiting to start.',
                    'icon' => 'fa-solid fa-play',
                    'action' => ['label' => 'Open ready queue', 'url' => '/admin/workspaces/billing/queues/ready', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $ready,
                ],
                [
                    'key' => 'active-delivery',
                    'eyebrow' => 'In progress',
                    'title' => 'Active service delivery',
                    'description' => 'A short view of requests currently being fulfilled.',
                    'empty_message' => 'No service request is currently in progress.',
                    'icon' => 'fa-solid fa-list-check',
                    'action' => ['label' => 'Open active queue', 'url' => '/admin/workspaces/billing/queues/active', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $active,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(array $request, bool $attention = false): array
    {
        $progress = $request['milestone_progress'];

        return [
            'id' => (int) $request['id'],
            'title' => $request['plan_name'],
            'subtitle' => $request['provider'],
            'image_url' => $request['provider_logo_url'],
            'status' => [
                'label' => $request['meeting_requested'] ? 'Meeting requested' : $request['state_label'],
                'tone' => $attention || $request['meeting_requested'] ? 'attention' : 'neutral',
            ],
            'meta' => [
                $request['reference_number'],
                $progress['total'] > 0
                    ? $progress['complete'].' of '.$progress['total'].' milestones complete'
                    : ($request['paid_at'] ? 'Paid '.$request['paid_at'] : 'Payment date unavailable'),
            ],
            'action' => ['label' => 'Open request', 'url' => $request['action_url']],
        ];
    }
}
