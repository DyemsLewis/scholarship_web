<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Queries\Admin\ReviewQueueQuery;

final class ReviewOfficerDashboardQuery
{
    public function __construct(private readonly ReviewQueueQuery $reviews) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $summary = $this->reviews->summary();
        $verification = collect([
            $this->queue('providers', 'Provider verification', 'Organization identity and legal proof', $summary['provider_pending'], '/admin/workspaces/reviews/providers'),
            $this->queue('programs', 'Program review', 'Programs waiting for publication review', $summary['program_pending'], '/admin/workspaces/reviews/programs'),
            $this->queue('applicants', 'Applicant verification', 'Academic profiles with submitted evidence', $summary['applicant_pending'], '/admin/workspaces/reviews/applicants'),
        ]);
        $oversight = collect([
            $this->queue('selection', 'Selection activities', 'Attendance and session proof exceptions', $summary['selection_activity_attention'], '/admin/workspaces/reviews/selection-activities'),
            $this->queue('benefits', 'Benefit evidence', 'Missing or disputed release evidence', $summary['benefit_attention'], '/admin/workspaces/reviews/benefits'),
            $this->queue('monitoring', 'Monitoring oversight', 'Unresolved recipient monitoring records', $summary['monitoring_attention'], '/admin/workspaces/reviews/monitoring'),
        ]);
        $priority = $verification->concat($oversight)->sortByDesc('count')->first();

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'review-officer',
                'eyebrow' => 'Platform verification',
                'title' => 'Review dashboard',
                'description' => 'Work through verification and oversight queues separately.',
                'icon' => 'fa-solid fa-clipboard-check',
                'access_scope' => 'Provider, program, applicant, and recipient review',
            ],
            primaryAction: [
                'label' => ($priority['count'] ?? 0) > 0 ? 'Open highest-priority queue' : 'Open provider reviews',
                'url' => ($priority['count'] ?? 0) > 0 ? $priority['action']['url'] : '/admin/workspaces/reviews/providers',
                'icon' => 'fa-solid fa-arrow-right',
            ],
            metrics: [
                ['key' => 'organizations', 'label' => 'Organization review', 'value' => $summary['provider_pending'] + $summary['program_pending'], 'detail' => 'Providers and programs', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/providers', 'icon' => 'fa-solid fa-building-shield'],
                ['key' => 'applicants', 'label' => 'Applicant review', 'value' => $summary['applicant_pending'], 'detail' => 'Academic verification', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/applicants', 'icon' => 'fa-solid fa-user-check'],
                ['key' => 'selection', 'label' => 'Selection activity', 'value' => $summary['selection_activity_attention'], 'detail' => 'Session exceptions', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/selection-activities', 'icon' => 'fa-solid fa-calendar-check'],
                ['key' => 'recipients', 'label' => 'Recipient oversight', 'value' => $summary['benefit_attention'] + $summary['monitoring_attention'], 'detail' => 'Benefits and monitoring', 'tone' => 'attention', 'url' => '/admin/workspaces/reviews/monitoring', 'icon' => 'fa-solid fa-chart-line'],
            ],
            sections: [
                [
                    'key' => 'verification-queues',
                    'eyebrow' => 'Verification',
                    'title' => 'Identity and program review',
                    'description' => 'Choose the record type you are ready to verify.',
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
        );
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
            'meta' => [$count > 0 ? 'Records are waiting for review' : 'No record is waiting'],
            'action' => ['label' => 'Open queue', 'url' => $url, 'icon' => 'fa-solid fa-arrow-right'],
        ];
    }
}
