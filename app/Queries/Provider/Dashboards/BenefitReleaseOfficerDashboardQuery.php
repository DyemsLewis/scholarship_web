<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\RecipientBenefitRelease;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;

final class BenefitReleaseOfficerDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $programIds = $this->scope->programs($officer)->pluck('id');
        $items = RecipientBenefitRelease::query()
            ->whereIn('scholarship_id', $programIds)
            ->with(['scholarship', 'records.receiptResponse'])
            ->orderBy('release_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RecipientBenefitRelease $release): array => $this->item($release));
        $counts = collect(['issues', 'record', 'upcoming', 'history'])
            ->mapWithKeys(fn (string $state): array => [$state => $items->where('work_state', $state)->count()]);
        $priorities = $items
            ->whereIn('work_state', ['issues', 'record'])
            ->sortBy(fn (array $item): string => sprintf('%02d-%s-%010d', $item['priority'], $item['release_sort'], $item['id']))
            ->take(5)
            ->values();
        $upcoming = $items
            ->where('work_state', 'upcoming')
            ->sortBy('release_sort')
            ->take(5)
            ->values();

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'benefit-release-officer',
                'eyebrow' => 'Scholarship distribution',
                'title' => 'Benefit release dashboard',
                'description' => 'Resolve recipient issues and record due distributions before planning what comes next.',
                'icon' => 'fa-solid fa-hand-holding-dollar',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: [
                'label' => $counts['issues'] > 0 ? 'Open reported issues' : 'Record distributions',
                'url' => $counts['issues'] > 0
                    ? '/provider/workspaces/releases/issues'
                    : '/provider/workspaces/releases/record',
            ],
            metrics: [
                ['key' => 'issues', 'label' => 'Reported issues', 'value' => $counts['issues'], 'detail' => 'Need resolution', 'tone' => 'attention', 'url' => '/provider/workspaces/releases/issues'],
                ['key' => 'record', 'label' => 'Due to record', 'value' => $counts['record'], 'detail' => 'Distribution date reached', 'tone' => 'attention', 'url' => '/provider/workspaces/releases/record'],
                ['key' => 'upcoming', 'label' => 'Upcoming', 'value' => $counts['upcoming'], 'detail' => 'Scheduled releases', 'url' => '/provider/workspaces/releases/upcoming'],
                ['key' => 'history', 'label' => 'Completed', 'value' => $counts['history'], 'detail' => 'Release records', 'tone' => 'positive', 'url' => '/provider/workspaces/releases/history'],
            ],
            sections: [
                [
                    'key' => 'release-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Issues and due distributions',
                    'description' => 'Recipient concerns appear before distributions that still need a result.',
                    'empty_message' => 'No benefit release currently needs action.',
                    'action' => ['label' => 'Open reported issues', 'url' => '/provider/workspaces/releases/issues'],
                    'items' => $priorities,
                ],
                [
                    'key' => 'upcoming-releases',
                    'eyebrow' => 'Next distributions',
                    'title' => 'Upcoming releases',
                    'description' => 'Scheduled distributions ordered by release date.',
                    'empty_message' => 'No upcoming distribution is scheduled.',
                    'action' => ['label' => 'View release schedule', 'url' => '/provider/workspaces/releases/upcoming'],
                    'items' => $upcoming,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(RecipientBenefitRelease $release): array
    {
        $openIssues = $release->records->filter(fn ($record) => $record->receiptResponse?->status === 'open')->count();
        $pending = $release->records->whereIn('status', ['scheduled', 'prepared'])->count();
        $released = $release->records->where('status', 'released')->count();
        $isDue = $release->release_at?->isPast() ?? false;

        [$state, $label, $detail, $priority] = match (true) {
            $openIssues > 0 => ['issues', 'Recipient issue', $openIssues.' issue'.($openIssues === 1 ? '' : 's').' waiting for resolution', 0],
            $pending > 0 && $isDue => ['record', 'Record distribution', $pending.' recipient result'.($pending === 1 ? '' : 's').' pending', 1],
            $pending > 0 => ['upcoming', 'Scheduled', $pending.' recipient'.($pending === 1 ? '' : 's').' scheduled', 2],
            default => ['history', 'Distribution closed', $released.' release'.($released === 1 ? '' : 's').' recorded', 3],
        };

        return [
            'id' => (int) $release->id,
            'title' => $release->title,
            'subtitle' => $release->scholarship?->title ?: 'Scholarship program',
            'image_url' => null,
            'work_state' => $state,
            'priority' => $priority,
            'release_sort' => $release->release_at?->format('Y-m-d H:i:s') ?: '9999-12-31 23:59:59',
            'status' => ['label' => $label, 'tone' => in_array($state, ['issues', 'record'], true) ? 'attention' : ($state === 'history' ? 'positive' : 'neutral')],
            'meta' => [
                $detail,
                $release->release_at ? $release->release_at->format('M d, Y h:i A') : 'Release date not set',
            ],
            'action' => [
                'label' => $state === 'issues' ? 'Resolve issues' : ($state === 'history' ? 'View record' : 'Open release'),
                'url' => route('provider.workspaces.releases.distributions.show', $release, false),
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
