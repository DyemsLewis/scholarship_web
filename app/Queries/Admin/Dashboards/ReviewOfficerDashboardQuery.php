<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\ReviewQueueQuery;
use App\Support\AdminWorkPriority;
use Illuminate\Support\Carbon;

final class ReviewOfficerDashboardQuery
{
    public function __construct(private readonly ReviewQueueQuery $reviews) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        // Counts and recommendations come from the same filtered queues as the directory.
        $queues = [
            'providers' => $this->reviews->providers(['per_page' => 1]),
            'programs' => $this->reviews->programs(['per_page' => 1]),
            'applicants' => $this->reviews->applicants(['per_page' => 1]),
            'selection-activities' => $this->reviews->selectionActivities(['per_page' => 1]),
            'benefits' => $this->reviews->benefits(['per_page' => 1]),
            'monitoring' => $this->reviews->monitoring(['per_page' => 1]),
        ];
        $counts = collect($queues)->map(fn (array $queue): int => $queue['pagination']['total']);
        $verification = collect([
            $this->queue('providers', 'Provider verification', 'Organization identity and legal proof', $counts['providers'], '/admin/workspaces/reviews/providers'),
            $this->queue('programs', 'Program review', 'Programs waiting for publication review', $counts['programs'], '/admin/workspaces/reviews/programs'),
            $this->queue('applicants', 'Applicant verification', 'Profiles with submitted evidence', $counts['applicants'], '/admin/workspaces/reviews/applicants'),
        ]);
        $oversight = collect([
            $this->queue('selection', 'Selection activities', 'Attendance and session proof exceptions', $counts['selection-activities'], '/admin/workspaces/reviews/selection-activities'),
            $this->queue('benefits', 'Benefit evidence', 'Missing or disputed release evidence', $counts['benefits'], '/admin/workspaces/reviews/benefits'),
            $this->queue('monitoring', 'Monitoring oversight', 'Unresolved recipient monitoring records', $counts['monitoring'], '/admin/workspaces/reviews/monitoring'),
        ]);
        $next = collect($queues)->map(function (array $queue, string $key): ?array {
            $item = collect($queue['items'])->first();

            return $item ? [...$item, 'queue_key' => $key] : null;
        })->filter()->sortBy([
            ['priority_level', 'asc'],
            fn (array $a, array $b): int => Carbon::parse($a['priority_at'])->getTimestamp() <=> Carbon::parse($b['priority_at'])->getTimestamp(),
            ['queue_key', 'asc'],
        ])->first();
        $nextAction = $next ? [
            'label' => match (true) {
                $next['priority_level'] === 30 => 'View provider record',
                empty($next['review_url']) => 'Open exception queue',
                default => 'Open recommended record',
            },
            'url' => $next['review_url'] ?? '/admin/workspaces/reviews/'.$next['queue_key'].'?status=attention',
        ] : null;

        return [...RoleDashboardPayload::make(
            workspace: [
                'key' => 'review-officer',
                'eyebrow' => 'Platform verification',
                'title' => 'Review dashboard',
                'description' => 'Start with a time-sensitive exception, then the oldest submitted review.',
                'icon' => 'fa-solid fa-clipboard-check',
                'access_scope' => 'Provider, program, applicant, and recipient review',
            ],
            primaryAction: null,
            metrics: [
                ['key' => 'providers', 'label' => 'Provider verification', 'value' => $counts['providers'], 'detail' => 'Pending organizations', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/providers?status=pending'],
                ['key' => 'programs', 'label' => 'Program publication', 'value' => $counts['programs'], 'detail' => 'Waiting for a decision', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/programs?status=pending_review'],
                ['key' => 'applicants', 'label' => 'Applicant verification', 'value' => $counts['applicants'], 'detail' => 'Submitted profile evidence', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/applicants?status=pending'],
                ['key' => 'oversight', 'label' => 'Oversight exceptions', 'value' => $counts['benefits'] + $counts['monitoring'] + $counts['selection-activities'], 'detail' => 'Breakdown in the three queues below', 'tone' => 'attention'],
            ],
            sections: [
                [
                    'key' => 'verification-queues',
                    'eyebrow' => 'Verification',
                    'title' => 'Identity and program review',
                    'description' => 'Evidence received first is reviewed first. Program dates can raise priority.',
                    'empty_message' => 'All verification queues are clear.',
                    'icon' => 'fa-solid fa-clipboard-check',
                    'items' => $verification,
                ],
                [
                    'key' => 'oversight-queues',
                    'eyebrow' => 'Operational oversight',
                    'title' => 'Selection and recipient records',
                    'description' => 'Review exceptions without mixing them into identity verification.',
                    'empty_message' => 'No operational record needs review.',
                    'icon' => 'fa-solid fa-binoculars',
                    'items' => $oversight,
                ],
            ],
        ), 'priority' => $next ? AdminWorkPriority::make(
            title: $next['name'] ?? $next['title'] ?? $next['recipient'],
            reason: $next['priority_reason'],
            owner: $next['priority_level'] === 30 ? 'Provider' : 'Review Officer',
            action: $nextAction,
            timing: $next['priority_timing'],
            state: $next['priority_level'] === 30 ? 'waiting' : 'actionable',
        ) : AdminWorkPriority::make('No reviews are waiting', 'All verification and oversight queues are clear.', 'Review Officer', state: 'clear')];
    }

    /** @return array<string, mixed> */
    private function queue(string $key, string $title, string $subtitle, int $count, string $url): array
    {
        return [
            'id' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'image_url' => null,
            'count' => $count,
            'status' => [
                'label' => $count > 0 ? $count.' open' : 'Clear',
                'tone' => $count > 0 ? 'attention' : 'positive',
            ],
            'action' => ['label' => 'Open queue', 'url' => $url, 'icon' => 'fa-solid fa-arrow-right'],
        ];
    }
}
