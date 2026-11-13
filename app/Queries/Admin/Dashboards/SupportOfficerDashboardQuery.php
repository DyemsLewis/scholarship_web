<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\SupportCaseQuery;
use App\Support\AdminWorkPriority;

final class SupportOfficerDashboardQuery
{
    public function __construct(private readonly SupportCaseQuery $support) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $summary = $this->support->summary();
        $needsResponse = collect($this->support->directory(['queue' => 'needs_response', 'per_page' => 5])['items'])
            ->map(fn (array $case): array => $this->item($case, true));
        $waiting = collect($this->support->directory(['queue' => 'waiting', 'per_page' => 5])['items'])
            ->map(fn (array $case): array => $this->item($case));

        $next = $summary['next_case'];

        return [...RoleDashboardPayload::make(
            workspace: [
                'key' => 'support-officer',
                'eyebrow' => 'Platform support',
                'title' => 'Support dashboard',
                'description' => 'Respond to open cases and track provider follow-up.',
                'icon' => 'fa-solid fa-headset',
                'access_scope' => 'Applicant, provider, and privacy cases',
            ],
            primaryAction: [
                'label' => $next ? 'Open response queue' : 'View waiting cases',
                'url' => $next ? '/admin/workspaces/support/needs-response' : '/admin/workspaces/support/waiting',
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'needs-response', 'label' => 'Need response', 'value' => $summary['needs_response'], 'detail' => 'Platform action', 'tone' => 'attention', 'url' => '/admin/workspaces/support/needs-response', 'icon' => 'fa-solid fa-inbox'],
                ['key' => 'waiting', 'label' => 'Waiting on a team', 'value' => $summary['waiting'], 'detail' => 'Account or provider follow-up', 'url' => '/admin/workspaces/support/waiting', 'icon' => 'fa-solid fa-clock'],
                ['key' => 'privacy', 'label' => 'Privacy requests', 'value' => $summary['privacy_open'], 'detail' => 'Need platform response', 'tone' => 'attention', 'url' => '/admin/workspaces/support/needs-response?category=privacy', 'icon' => 'fa-solid fa-user-shield'],
                ['key' => 'resolved', 'label' => 'Resolved', 'value' => $summary['resolved'], 'detail' => 'Closed cases', 'tone' => 'positive', 'url' => '/admin/workspaces/support/resolved', 'icon' => 'fa-solid fa-circle-check'],
            ],
            sections: [
                [
                    'key' => 'response-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Cases needing a response',
                    'description' => 'Oldest unanswered cases appear first.',
                    'empty_message' => 'No support case needs a platform response.',
                    'icon' => 'fa-solid fa-inbox',
                    'action' => ['label' => 'Open response queue', 'url' => '/admin/workspaces/support/needs-response', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $needsResponse,
                ],
                [
                    'key' => 'provider-follow-up',
                    'eyebrow' => 'Coordination',
                    'title' => 'Waiting on another team',
                    'description' => 'Cases with an account task or provider response still pending.',
                    'empty_message' => 'No case is waiting on another team.',
                    'icon' => 'fa-solid fa-clock',
                    'action' => ['label' => 'Open waiting queue', 'url' => '/admin/workspaces/support/waiting', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $waiting,
                ],
            ],
        ), 'priority' => $next ? AdminWorkPriority::make(
            $next['subject'], 'This is the oldest case still needing a platform response.', 'Support Officer',
            ['label' => 'Open case', 'url' => $next['action_url']], 'Submitted '.$next['submitted_at'],
        ) : AdminWorkPriority::make(
            $summary['waiting'] > 0 ? 'Waiting on another team' : 'No response is waiting',
            $summary['waiting'] > 0 ? 'Account Manager or provider follow-up is pending. Support replies once the account result returns.' : 'All support cases needing a platform response have been handled.',
            $summary['waiting'] > 0 ? 'Account Manager or provider' : 'Support Officer',
            state: $summary['waiting'] > 0 ? 'waiting' : 'clear',
        )];
    }

    /** @return array<string, mixed> */
    private function item(array $case, bool $attention = false): array
    {
        return [
            'id' => (int) $case['id'],
            'title' => $case['subject'],
            'subtitle' => $case['reporter']['name'].' · '.$case['category_label'],
            'image_url' => $case['reporter']['image_url'],
            'image_fit' => $case['reporter']['image_fit'] ?? 'cover',
            'status' => [
                'label' => $case['state_label'],
                'tone' => $attention ? 'attention' : 'neutral',
            ],
            'hint' => $case['coordination_label'],
            'action' => ['label' => 'Open case', 'url' => $case['action_url']],
        ];
    }
}
