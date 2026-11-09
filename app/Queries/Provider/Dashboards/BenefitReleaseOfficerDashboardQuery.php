<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\RecipientBenefitRelease;
use App\Models\User;
use App\Queries\Provider\BenefitReleaseWorkspaceQueues;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientAgreementCaseAccess;

final class BenefitReleaseOfficerDashboardQuery
{
    public function __construct(
        private readonly ProviderWorkspaceScope $scope,
        private readonly BenefitReleaseWorkspaceQueues $queues,
    ) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $deliveryReportCount = app(RecipientAgreementCaseAccess::class)->visibleTo($officer)
            ->where('case_type', 'benefit_delivery')->whereIn('status', BenefitDeliveryReportService::OPEN)->count();
        $programIds = $this->scope->programs($officer)->pluck('id');
        $items = $this->queues->releases($programIds)
            ->get()
            ->map(fn (RecipientBenefitRelease $release): array => $this->item($release));
        $counts = collect(['issues', 'record', 'upcoming', 'history'])
            ->mapWithKeys(fn (string $state): array => [$state => $items->where('work_state', $state)->count()]);
        $focusQueue = $counts['issues'] > 0 || $counts['record'] === 0 ? 'issues' : 'record';
        $focusAction = $focusQueue === 'issues'
            ? ['label' => 'Open reported issues', 'url' => '/provider/workspaces/releases/issues']
            : ['label' => 'Record distributions', 'url' => '/provider/workspaces/releases/record'];
        $priorities = $items
            ->where('work_state', $focusQueue)
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
                'label' => $deliveryReportCount > 0 ? 'Open delivery reports' : ($counts['issues'] > 0 ? 'Open reported issues' : 'Record distributions'),
                'url' => $deliveryReportCount > 0 ? '/provider/workspaces/releases/delivery-reports' : ($counts['issues'] > 0
                    ? '/provider/workspaces/releases/issues'
                    : '/provider/workspaces/releases/record'),
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
                    'title' => $focusQueue === 'issues' ? 'Reported distribution issues' : 'Distributions due to record',
                    'description' => $focusQueue === 'issues'
                        ? 'Distributions with open recipient reports.'
                        : 'Due distributions that still need a recipient outcome.',
                    'empty_message' => 'No benefit release currently needs action.',
                    'action' => $focusAction,
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
        ['state' => $state, 'issues' => $openIssues, 'pending' => $pending] = $this->queues->classify($release);

        [$label, $detail, $priority] = match ($state) {
            'issues' => ['Recipient issue', $openIssues.' open issue'.($openIssues === 1 ? '' : 's'), 0],
            'record' => ['Record distribution', $pending.' result'.($pending === 1 ? '' : 's').' to record', 1],
            'upcoming' => ['Scheduled', $release->release_at?->format('M d, Y h:i A') ?: 'Release date not set', 2],
            default => ['Distribution closed', $release->release_at?->format('M d, Y'), 3],
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
            'hint' => $detail,
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
