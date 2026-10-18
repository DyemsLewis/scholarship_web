<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\SupportCaseQuery;

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

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'support-officer',
                'eyebrow' => 'Platform support',
                'title' => 'Support dashboard',
                'description' => 'Respond to open cases first and track provider follow-up separately.',
                'icon' => 'fa-solid fa-headset',
                'access_scope' => 'Applicant, provider, and privacy cases',
            ],
            primaryAction: [
                'label' => 'Open cases needing response',
                'url' => '/admin/workspaces/support/needs-response',
            ],
            metrics: [
                ['key' => 'needs-response', 'label' => 'Need response', 'value' => $summary['needs_response'], 'detail' => 'Platform action', 'tone' => 'attention', 'url' => '/admin/workspaces/support/needs-response'],
                ['key' => 'waiting', 'label' => 'Waiting for provider', 'value' => $summary['waiting'], 'detail' => 'Platform response complete', 'url' => '/admin/workspaces/support/waiting'],
                ['key' => 'privacy', 'label' => 'Privacy requests', 'value' => $summary['privacy_open'], 'detail' => 'Open cases', 'tone' => 'attention', 'url' => '/admin/workspaces/support/needs-response?category=privacy'],
                ['key' => 'resolved', 'label' => 'Resolved', 'value' => $summary['resolved'], 'detail' => 'Closed cases', 'tone' => 'positive', 'url' => '/admin/workspaces/support/resolved'],
            ],
            sections: [
                [
                    'key' => 'response-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Cases needing a response',
                    'description' => 'Oldest unanswered cases appear first.',
                    'empty_message' => 'No support case needs a platform response.',
                    'action' => ['label' => 'Open response queue', 'url' => '/admin/workspaces/support/needs-response'],
                    'items' => $needsResponse,
                ],
                [
                    'key' => 'provider-follow-up',
                    'eyebrow' => 'Coordination',
                    'title' => 'Waiting for provider follow-up',
                    'description' => 'Cases where platform work is complete and provider action remains.',
                    'empty_message' => 'No case is waiting for provider follow-up.',
                    'action' => ['label' => 'Open waiting queue', 'url' => '/admin/workspaces/support/waiting'],
                    'items' => $waiting,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(array $case, bool $attention = false): array
    {
        return [
            'id' => (int) $case['id'],
            'title' => $case['subject'],
            'subtitle' => $case['reporter']['name'].' · '.$case['category_label'],
            'image_url' => $case['reporter']['image_url'],
            'status' => [
                'label' => $case['state_label'],
                'tone' => $attention ? 'attention' : 'neutral',
            ],
            'meta' => [
                $case['program']['title'] ?? $case['coordination_label'],
                $case['submitted_at'] ?? 'Submitted time unavailable',
            ],
            'action' => ['label' => 'Open case', 'url' => $case['action_url']],
        ];
    }
}
