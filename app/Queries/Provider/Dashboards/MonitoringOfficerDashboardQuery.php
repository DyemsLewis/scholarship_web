<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Illuminate\Support\Collection;

final class MonitoringOfficerDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer, Collection $checkIns): array
    {
        $context = $this->scope->context($officer);
        $items = $checkIns->map(fn (array $checkIn): array => $this->item($checkIn));

        $counts = collect(['review', 'followup', 'awaiting', 'history'])
            ->mapWithKeys(fn (string $state): array => [$state => $items->where('work_state', $state)->count()]);
        $focusQueue = $counts['review'] > 0 || $counts['followup'] === 0 ? 'review' : 'followup';
        $focusAction = $focusQueue === 'review'
            ? ['label' => 'Open review queue', 'url' => '/provider/workspaces/monitoring/review']
            : ['label' => 'Open follow-ups', 'url' => '/provider/workspaces/monitoring/follow-ups'];
        $priorities = $items
            ->where('work_state', $focusQueue)
            ->sortBy(fn (array $item): string => sprintf('%02d-%s-%010d', $item['priority'], $item['due_sort'], $item['id']))
            ->take(5)
            ->values();
        $awaiting = $items
            ->where('work_state', 'awaiting')
            ->sortBy('due_sort')
            ->take(5)
            ->values();

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'monitoring-officer',
                'eyebrow' => 'Recipient progress',
                'title' => 'Monitoring dashboard',
                'description' => 'Review submitted check-ins first, then handle follow-ups and upcoming deadlines.',
                'icon' => 'fa-solid fa-chart-line',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: $focusAction,
            metrics: [
                ['key' => 'review', 'label' => 'Needs review', 'value' => $counts['review'], 'detail' => 'Submitted items or requests', 'tone' => 'attention', 'url' => '/provider/workspaces/monitoring/review'],
                ['key' => 'followup', 'label' => 'Follow-ups', 'value' => $counts['followup'], 'detail' => 'Recipient support actions', 'tone' => 'attention', 'url' => '/provider/workspaces/monitoring/follow-ups'],
                ['key' => 'awaiting', 'label' => 'Scheduled or waiting', 'value' => $counts['awaiting'], 'detail' => 'Upcoming and open check-ins', 'url' => '/provider/workspaces/monitoring/awaiting'],
                ['key' => 'history', 'label' => 'Closed check-ins', 'value' => $counts['history'], 'detail' => 'Available for reference', 'url' => '/provider/workspaces/monitoring/history'],
            ],
            sections: [
                [
                    'key' => 'monitoring-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => $focusQueue === 'review' ? 'Check-ins to review' : 'Check-in follow-ups',
                    'description' => $focusQueue === 'review'
                        ? 'Check-ins with requests, results, or outcomes to record.'
                        : 'Check-ins with overdue work or open follow-ups.',
                    'empty_message' => 'No monitoring item currently needs provider action.',
                    'action' => $focusAction,
                    'items' => $priorities,
                ],
                [
                    'key' => 'open-check-ins',
                    'eyebrow' => 'In progress',
                    'title' => 'Upcoming and waiting check-ins',
                    'description' => 'Scheduled handoffs and check-ins still waiting on a recipient.',
                    'empty_message' => 'No check-in is currently scheduled or waiting.',
                    'action' => ['label' => 'View check-ins', 'url' => '/provider/workspaces/monitoring/awaiting'],
                    'items' => $awaiting,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(array $checkIn): array
    {
        $state = $checkIn['work_state'];

        return [
            'id' => $checkIn['id'],
            'title' => $checkIn['title'],
            'subtitle' => $checkIn['program']['title'],
            'image_url' => null,
            'work_state' => $state,
            'priority' => $checkIn['priority'],
            'due_sort' => $checkIn['due_at'] ?: '9999-12-31',
            'status' => ['label' => $checkIn['work_label'], 'tone' => in_array($state, ['review', 'followup'], true) ? 'attention' : 'neutral'],
            'hint' => $checkIn['handoff']['scheduled_label'] ?? $checkIn['workload_label'],
            'action' => [
                'label' => $checkIn['action_label'],
                'url' => $checkIn['action_url'],
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    private function accessScope(array $context): string
    {
        return $context['program_access_mode'] === 'selected'
            ? $context['assigned_program_count'].' assigned programs'
            : 'All organization programs';
    }
}
