<?php

namespace App\Queries\Provider;

use App\Models\RecipientBenefitRelease;
use App\Services\BenefitDeliveryReportService;
use Illuminate\Database\Eloquent\Builder;

final class BenefitReleaseWorkspaceQueues
{
    public function __construct(private readonly BenefitDeliveryReportService $deliveryReports) {}

    public function releases(iterable $programIds): Builder
    {
        return RecipientBenefitRelease::query()
            ->whereIn('scholarship_id', $programIds)
            ->with(['scholarship', 'records.receiptResponse', 'records.allocations.entitlement'])
            ->orderBy('release_at')
            ->orderBy('id');
    }

    /** @return array{state: string, issues: int, pending: int} */
    public function classify(RecipientBenefitRelease $release): array
    {
        $issues = $this->deliveryReports->openIssueCount($release, $this->deliveryReports->openReportsByReleaseRecord($release));
        $pending = $release->records->whereIn('status', ['scheduled', 'prepared'])->count();

        return [
            'state' => match (true) {
                $issues > 0 => 'issues',
                $pending > 0 && $release->release_at?->isPast() => 'record',
                $pending > 0 => 'upcoming',
                default => 'history',
            },
            'issues' => $issues,
            'pending' => $pending,
        ];
    }
}
