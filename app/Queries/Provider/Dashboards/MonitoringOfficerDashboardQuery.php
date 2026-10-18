<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\RecipientMonitoringCycle;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Carbon\CarbonImmutable;

final class MonitoringOfficerDashboardQuery
{
    private const AWARD_STATUSES = ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'];

    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $programIds = $this->scope->programs($officer)->pluck('id');
        $recipientCounts = $this->scope->applications($officer)
            ->where(fn ($query) => $query
                ->where('final_outcome', 'selected')
                ->orWhereIn('status', self::AWARD_STATUSES))
            ->where('status', '!=', 'benefits_terminated')
            ->where(fn ($query) => $query
                ->whereNull('student_response_status')
                ->orWhere('student_response_status', '!=', 'declined'))
            ->selectRaw('scholarship_id, COUNT(*) as aggregate')
            ->groupBy('scholarship_id')
            ->pluck('aggregate', 'scholarship_id');

        $items = RecipientMonitoringCycle::query()
            ->whereIn('scholarship_id', $programIds)
            ->with(['scholarship', 'requirements', 'submissions', 'adjustmentRequests', 'interventions'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RecipientMonitoringCycle $cycle): array => $this->item(
                $cycle,
                (int) ($recipientCounts[$cycle->scholarship_id] ?? 0),
            ));

        $counts = collect(['review', 'followup', 'awaiting', 'history'])
            ->mapWithKeys(fn (string $state): array => [$state => $items->where('work_state', $state)->count()]);
        $priorities = $items
            ->whereIn('work_state', ['review', 'followup'])
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
            primaryAction: [
                'label' => 'Open review queue',
                'url' => '/provider/workspaces/monitoring/review',
            ],
            metrics: [
                ['key' => 'review', 'label' => 'Needs review', 'value' => $counts['review'], 'detail' => 'Submitted items or requests', 'tone' => 'attention', 'url' => '/provider/workspaces/monitoring/review'],
                ['key' => 'followup', 'label' => 'Follow-ups', 'value' => $counts['followup'], 'detail' => 'Recipient support actions', 'tone' => 'attention', 'url' => '/provider/workspaces/monitoring/follow-ups'],
                ['key' => 'awaiting', 'label' => 'Awaiting uploads', 'value' => $counts['awaiting'], 'detail' => 'Open check-ins', 'url' => '/provider/workspaces/monitoring/awaiting'],
                ['key' => 'history', 'label' => 'Closed check-ins', 'value' => $counts['history'], 'detail' => 'Available for reference', 'url' => '/provider/workspaces/monitoring/history'],
            ],
            sections: [
                [
                    'key' => 'monitoring-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Review and follow-up',
                    'description' => 'Requests and submitted records appear before recipient follow-ups.',
                    'empty_message' => 'No monitoring item currently needs provider action.',
                    'action' => ['label' => 'Open review queue', 'url' => '/provider/workspaces/monitoring/review'],
                    'items' => $priorities,
                ],
                [
                    'key' => 'open-check-ins',
                    'eyebrow' => 'In progress',
                    'title' => 'Awaiting recipient uploads',
                    'description' => 'Open check-ins ordered by the nearest deadline.',
                    'empty_message' => 'No check-in is currently waiting for uploads.',
                    'action' => ['label' => 'View awaiting uploads', 'url' => '/provider/workspaces/monitoring/awaiting'],
                    'items' => $awaiting,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(RecipientMonitoringCycle $cycle, int $recipientCount): array
    {
        $pendingAdjustments = $cycle->adjustmentRequests->where('status', 'pending')->count();
        $submittedReviews = $cycle->submissions->filter(fn ($submission) => $submission->reviewed_at === null)->count();
        $providerRequirements = $cycle->requirements->where('required', true)->where('requires_file', false);
        $providerRecordsExpected = $providerRequirements->count() * $recipientCount;
        $providerRecordsSaved = $cycle->submissions
            ->whereIn('recipient_monitoring_cycle_requirement_id', $providerRequirements->pluck('id'))
            ->count();
        $pendingReviews = $submittedReviews + max(0, $providerRecordsExpected - $providerRecordsSaved);
        $openInterventions = $cycle->interventions->where('status', 'open')->count();
        $actionNeeded = $cycle->submissions->whereIn('review_status', ['not_met', 'needs_correction'])->count();
        $requiredUploads = $cycle->requirements->where('required', true)->where('requires_file', true)->count();
        $expectedUploads = $requiredUploads * $recipientCount;
        $submittedUploads = $cycle->submissions
            ->whereNotNull('recipient_monitoring_cycle_requirement_id')
            ->filter(fn ($submission) => filled($submission->path))
            ->count();
        $pendingUploads = max(0, $expectedUploads - $submittedUploads);
        $pastDue = $cycle->due_at?->isBefore(CarbonImmutable::today()) ?? false;

        [$state, $label, $detail, $priority] = match (true) {
            $pendingAdjustments > 0 => ['review', 'Request review', $pendingAdjustments.' adjustment request'.($pendingAdjustments === 1 ? '' : 's').' waiting', 0],
            $pendingReviews > 0 => ['review', 'Monitoring review', $pendingReviews.' submitted item'.($pendingReviews === 1 ? '' : 's').' waiting', 1],
            $openInterventions > 0 => ['followup', 'Open follow-up', $openInterventions.' active follow-up'.($openInterventions === 1 ? '' : 's'), 2],
            $actionNeeded > 0 => ['followup', 'Follow-up needed', $actionNeeded.' requirement'.($actionNeeded === 1 ? '' : 's').' need attention', 3],
            $cycle->status === 'open' && ! $pastDue => ['awaiting', 'Awaiting uploads', $pendingUploads.' required upload'.($pendingUploads === 1 ? '' : 's').' pending', 4],
            default => ['history', 'Check-in closed', 'Review record available', 5],
        };

        return [
            'id' => (int) $cycle->id,
            'title' => $cycle->title,
            'subtitle' => $cycle->scholarship?->title ?: 'Scholarship program',
            'image_url' => null,
            'work_state' => $state,
            'priority' => $priority,
            'due_sort' => $cycle->due_at?->format('Y-m-d') ?: '9999-12-31',
            'status' => ['label' => $label, 'tone' => in_array($state, ['review', 'followup'], true) ? 'attention' : 'neutral'],
            'meta' => [
                $detail,
                $cycle->due_at ? 'Due '.$cycle->due_at->format('M d, Y') : 'Deadline not set',
            ],
            'action' => [
                'label' => $state === 'history' ? 'View record' : 'Open check-in',
                'url' => route('provider.workspaces.monitoring.check-ins.show', $cycle, false),
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
