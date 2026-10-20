<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ActivityLog;
use App\Models\User;
use App\Queries\Admin\RecordsWorkspaceQuery;

final class RecordsOfficerDashboardQuery
{
    public function __construct(private readonly RecordsWorkspaceQuery $records) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $activity = $this->records->activity([
            'period' => 'month',
            'per_page' => 10,
        ]);
        $summary = $activity['summary'];
        $security = ActivityLog::query()
            ->where('action', 'login_failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (ActivityLog $entry): array => $this->item($this->records->record($entry), true));
        $securityIds = $security->pluck('id');
        $recent = collect($activity['items'])
            ->reject(fn (array $item): bool => $securityIds->contains($item['id']))
            ->take(5)
            ->map(fn (array $item): array => $this->item($item));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'records-officer',
                'eyebrow' => 'Platform records',
                'title' => 'Records dashboard',
                'description' => 'Review audit activity and manage authorized exports.',
                'icon' => 'fa-solid fa-box-archive',
                'access_scope' => 'Audit logs and authorized exports',
            ],
            primaryAction: [
                'label' => $summary['security_7_days'] > 0 ? 'Review security activity' : 'Open activity records',
                'url' => $summary['security_7_days'] > 0
                    ? '/admin/workspaces/records/activity?category=security&period=week'
                    : '/admin/workspaces/records/activity',
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'today', 'label' => 'Recorded today', 'value' => $summary['today'], 'detail' => 'New audit events', 'url' => '/admin/workspaces/records/activity?period=today', 'icon' => 'fa-regular fa-calendar'],
                ['key' => 'security', 'label' => 'Security events', 'value' => $summary['security_7_days'], 'detail' => 'Failed sign-ins · 7 days', 'tone' => 'attention', 'url' => '/admin/workspaces/records/activity?category=security&period=week', 'icon' => 'fa-solid fa-shield-halved'],
                ['key' => 'exports', 'label' => 'Recent exports', 'value' => $summary['exports_30_days'], 'detail' => 'Last 30 days', 'url' => '/admin/workspaces/records/exports', 'icon' => 'fa-solid fa-file-export'],
                ['key' => 'total', 'label' => 'Audit records', 'value' => $summary['total'], 'detail' => 'All recorded activity', 'url' => '/admin/workspaces/records/activity?period=all', 'icon' => 'fa-solid fa-box-archive'],
            ],
            sections: [
                [
                    'key' => 'security-events',
                    'eyebrow' => 'Current focus',
                    'title' => 'Recent failed sign-ins',
                    'description' => 'Review the latest failed sign-in events from the past seven days.',
                    'empty_message' => 'No failed sign-in was recorded in the past seven days.',
                    'icon' => 'fa-solid fa-shield-halved',
                    'action' => ['label' => 'Open security records', 'url' => '/admin/workspaces/records/activity?category=security&period=week', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $security,
                ],
                [
                    'key' => 'recent-activity',
                    'eyebrow' => 'Audit trail',
                    'title' => 'Latest recorded activity',
                    'description' => 'A concise view of recent platform changes and actions.',
                    'empty_message' => 'No recent platform activity was recorded.',
                    'icon' => 'fa-solid fa-clock-rotate-left',
                    'action' => ['label' => 'Open activity records', 'url' => '/admin/workspaces/records/activity', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $recent,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(array $entry, bool $security = false): array
    {
        return [
            'id' => (int) $entry['id'],
            'title' => $entry['description'],
            'subtitle' => ($entry['actor_name'] ?: 'System').' · '.($entry['actor_role'] ?: 'system'),
            'image_url' => null,
            'status' => [
                'label' => $security ? 'Security event' : ($entry['action_label'] ?? 'Recorded activity'),
                'tone' => $security ? 'attention' : 'neutral',
            ],
            'meta' => [
                $entry['created_at'] ?? 'Time unavailable',
                filled($entry['ip_address'] ?? null) ? 'Source '.$entry['ip_address'] : 'Source not recorded',
            ],
            'action' => [
                'label' => 'Open record',
                'url' => $entry['record_url'],
            ],
        ];
    }
}
