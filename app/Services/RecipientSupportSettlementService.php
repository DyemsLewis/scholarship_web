<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class RecipientSupportSettlementService
{
    public function summary(ScholarshipApplication $application, ?User $actor = null): array
    {
        $records = $application->benefitReleaseRecords()->with(['release', 'receiptResponse', 'followUp', 'allocations.entitlement'])->get();
        $followUps = app(RecipientBenefitFollowUpService::class);
        $blockers = [];
        $totals = ['received' => 0, 'unclaimed' => 0, 'declined' => 0, 'pending' => 0];
        foreach ($records as $record) {
            $followUp = $record->followUp;
            $received = $record->receiptResponse?->response_type === 'confirmed' || $record->receiptResponse?->recipient_resolution_confirmed_at;
            if ($record->status === 'released' && $received) {
                $totals['received']++;
                continue;
            }
            if ($record->status === 'missed') {
                if (in_array($followUp?->status, ['unclaimed', 'declined'], true)) {
                    $totals[$followUp->status]++;
                    continue;
                }
                if (! $followUps->requiresAttention($record)) {
                    continue;
                }
            } elseif ($record->status === 'cancelled') {
                continue;
            }
            $totals['pending']++;
            $blockers[] = [
                'title' => $record->release?->title ?: 'Benefit release',
                'message' => match ($record->status) {
                    'missed' => $followUp?->status === 'responded' ? 'The recipient requested a new arrangement. Schedule the replacement release.' : 'Follow up the missed release; it is not confirmed receipt.',
                    'withheld' => 'Resolve the withheld benefit before marking support complete.',
                    'released' => 'The recipient has not confirmed receipt or a delivery issue is unresolved.',
                    default => 'A scheduled or prepared release still needs a result, even if its date is in the future.',
                },
                'url' => '/provider/workspaces/releases/distributions/'.$record->recipient_benefit_release_id,
            ];
        }
        $entitlements = $application->benefitEntitlements()->withCount('allocations')->get();
        foreach ($entitlements->where('frequency', 'one_time')->whereNotIn('status', ['fulfilled', 'unclaimed', 'declined']) as $entitlement) {
            if ($entitlement->allocations_count === 0) {
                $totals['pending']++;
                $blockers[] = ['title' => $entitlement->title, 'message' => 'This agreed one-time benefit has not been scheduled or received.', 'url' => '/provider/workspaces/releases/schedule'];
            }
        }
        foreach ($application->scholarship->benefits->whereIn('frequency', [null, 'one_time'])->whereNotIn('id', $entitlements->pluck('scholarship_benefit_id')) as $benefit) {
            $totals['pending']++;
            $blockers[] = ['title' => $benefit->title, 'message' => 'This agreed one-time benefit has not been scheduled or received.', 'url' => '/provider/workspaces/releases/schedule'];
        }
        $openReports = $application->recipientAgreementCases()->where('case_type', RecipientAgreementCaseCatalog::BENEFIT_DELIVERY)
            ->whereIn('status', BenefitDeliveryReportService::OPEN)->count();
        if ($openReports > 0) {
            $blockers[] = ['title' => 'Open delivery reports', 'message' => "Resolve {$openReports} open delivery report(s) before normal completion.", 'url' => '/provider/workspaces/releases/delivery-reports'];
        }

        return ['can_complete' => $blockers === [], 'counts' => $totals, 'blockers' => $blockers,
            'can_open_releases' => (bool) $actor?->hasPortalPermission('manage_benefit_releases')];
    }

    public function closeoutSnapshot(ScholarshipApplication $application, bool $requireSettled = false): array
    {
        if ($requireSettled) {
            app(RecipientBenefitEntitlementService::class)->ensureForApplication($application);
        }

        $summary = $this->summary($application);
        if ($requireSettled && ! $summary['can_complete']) {
            throw ValidationException::withMessages(['decision' => 'Settle the outstanding benefits before completing support. '.$summary['blockers'][0]['message']]);
        }

        return $summary['counts'];
    }
}
