<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\BillingWorkQueue;
use App\Support\AdminWorkPriority;

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

        $next = $summary['next_request'];

        return [...RoleDashboardPayload::make(
            workspace: [
                'key' => 'billing-officer',
                'eyebrow' => 'Service delivery',
                'title' => 'Service request dashboard',
                'description' => 'Start paid requests and track service delivery.',
                'icon' => 'fa-solid fa-file-invoice-dollar',
                'access_scope' => 'Paid provider service requests',
            ],
            primaryAction: [
                'label' => $next ? 'Continue priority request' : ($summary['waiting'] > 0 ? 'View waiting requests' : 'Browse service requests'),
                'url' => $next['action_url'] ?? ($summary['waiting'] > 0 ? '/admin/workspaces/billing/queues/waiting' : '/admin/workspaces/billing/queues/ready'),
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'ready', 'label' => 'Ready to start', 'value' => $summary['ready'], 'detail' => 'Paid requests', 'tone' => 'attention', 'url' => '/admin/workspaces/billing/queues/ready', 'icon' => 'fa-solid fa-play'],
                ['key' => 'active', 'label' => 'Active delivery', 'value' => $summary['active'], 'detail' => 'In progress', 'url' => '/admin/workspaces/billing/queues/active', 'icon' => 'fa-solid fa-list-check'],
                ['key' => 'waiting', 'label' => 'Waiting on provider', 'value' => $summary['waiting'], 'detail' => 'Information or review', 'url' => '/admin/workspaces/billing/queues/waiting', 'icon' => 'fa-solid fa-clock'],
                ['key' => 'completed', 'label' => 'Closed', 'value' => $summary['completed'], 'detail' => 'Completed or cancelled', 'tone' => 'positive', 'url' => '/admin/workspaces/billing/queues/completed', 'icon' => 'fa-solid fa-circle-check'],
            ],
            sections: [
                [
                    'key' => 'ready-requests',
                    'eyebrow' => 'Current focus',
                    'title' => 'Paid requests ready to start',
                    'description' => 'Overdue work first, then requested actions, priority, and payment date.',
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
        ), 'priority' => $next ? AdminWorkPriority::make(
            $next['plan_name'].' - '.$next['provider'],
            match (true) {
                $next['finance_follow_up'] => 'Finance requested a record check. Return a Billing response; payment and delivery decisions remain separate.',
                $next['action_overdue'] => 'The next service action is overdue. Continue this request before starting routine work.',
                $next['cancellation_requested'] => 'The provider requested cancellation. Review it before continuing delivery.',
                $next['meeting_requested'] => 'The provider requested a meeting. Record the response in this request.',
                default => 'Continue the next admin-owned service action. Provider-only waiting steps are kept separate.',
            },
            $next['finance_follow_up'] ? 'Billing Officer' : ($next['assigned_to'] ?: 'Billing Officer'),
            ['label' => 'Open request', 'url' => $next['action_url']],
            $next['action_due_label'] ?: 'Paid '.$next['paid_at'],
        ) : AdminWorkPriority::make(
            $summary['waiting'] > 0 ? 'Waiting for provider information or review' : 'No service action is waiting',
            $summary['waiting'] > 0 ? 'These requests need the provider to respond before delivery can continue.' : 'There is no outstanding admin-owned service action.',
            $summary['waiting'] > 0 ? 'Provider' : 'Billing Officer',
            state: $summary['waiting'] > 0 ? 'waiting' : 'clear',
        )];
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
            'image_fit' => 'contain',
            'status' => [
                'label' => $request['meeting_requested'] ? 'Meeting requested' : $request['state_label'],
                'tone' => $attention || $request['meeting_requested'] ? 'attention' : 'neutral',
            ],
            'hint' => $request['action_due_label'] ?? ($progress['total'] > 0
                ? $progress['complete'].' of '.$progress['total'].' milestones'
                : null),
            'action' => ['label' => 'Open request', 'url' => $request['action_url']],
        ];
    }
}
