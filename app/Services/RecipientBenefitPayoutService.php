<?php

namespace App\Services;

use App\Models\RecipientBenefitPayoutDetails;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class RecipientBenefitPayoutService
{
    public const BANK = 'bank_transfer';

    public const WALLET = 'e_wallet';

    public const APPLICANT_ACTIONS = ['required', 'correction_requested'];

    public const CORRECTION_REASONS = [
        'holder_name' => 'Check the account holder name.',
        'institution' => 'Check the bank or wallet provider.',
        'destination' => 'Check the account or registered mobile number.',
        'unconfirmed' => 'The provider could not confirm this account. Check the details or provide another account.',
    ];

    public const SUMMARY_COLUMNS = 'id,release_record_id,method,submitted_at,review_status,revision,reviewed_at,correction_reason';

    public function isDigital(?string $method): bool
    {
        return in_array($method, [self::BANK, self::WALLET], true);
    }

    public function isActive(RecipientBenefitReleaseRecord $record, ScholarshipApplication $application): bool
    {
        return $this->isDigital($record->release?->release_method)
            && $record->release->published_at !== null
            && $record->release->scholarship_id === $application->scholarship_id
            && $record->scholarship_application_id === $application->id
            && $record->applicant_id === $application->applicant_id
            && $application->student_response_status === 'accepted'
            && ($application->final_outcome === 'selected'
                || in_array($application->status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true))
            && $application->status !== 'benefits_terminated'
            && ! in_array($application->supportDecisions->first()?->decision, ['completed', 'terminated'], true)
            && in_array($record->status, ['scheduled', 'prepared'], true)
            && $record->receiptResponse === null;
    }

    public function currentDetails(RecipientBenefitReleaseRecord $record): ?RecipientBenefitPayoutDetails
    {
        $details = $record->payoutDetails;

        return $details?->method === $record->release?->release_method ? $details : null;
    }

    public function acceptsSubmission(?RecipientBenefitPayoutDetails $details, bool $reportOpen): bool
    {
        // A report must not prevent a missing or explicitly corrected destination from being supplied.
        return ! $details || $details->review_status === 'correction_requested'
            || ($details->review_status === 'submitted' && ! $reportOpen);
    }

    public function applicantSummary(RecipientBenefitReleaseRecord $record, ScholarshipApplication $application, bool $reportOpen): ?array
    {
        if (! $this->isActive($record, $application)) {
            return null;
        }

        $details = $this->currentDetails($record);
        $canSubmit = $this->acceptsSubmission($details, $reportOpen);

        return [
            'status' => $details?->review_status ?? 'required',
            'method' => $record->release->release_method,
            'submitted_label' => $details?->submitted_at?->format('M d, Y h:i A'),
            'revision' => $canSubmit ? $details?->revision : null,
            'correction_label' => self::CORRECTION_REASONS[$details?->correction_reason] ?? null,
            'submit_url' => $canSubmit ? route('dashboard.benefit-release-records.payout-details', $record, false) : null,
        ];
    }

    public function providerSummary(RecipientBenefitReleaseRecord $record): ?array
    {
        if (! $record->application || ! $this->isActive($record, $record->application)) {
            return null;
        }

        $details = $this->currentDetails($record);
        $status = $details?->review_status ?? 'required';

        return [
            'status' => $status,
            'label' => match ($status) {
                'approved' => 'Payout details approved',
                'submitted' => 'Ready for payout review',
                'correction_requested' => 'Correction requested',
                default => 'Awaiting payout details',
            },
            'review_url' => in_array($status, ['submitted', 'approved'], true)
                ? route('provider.benefit-release-records.payout-details.show', $record, false) : null,
            'approved_revision' => $status === 'approved' ? $details->revision : null,
            'approved_on' => $status === 'approved' ? $details->reviewed_at?->toDateString() : null,
        ];
    }

    public function requireApproval(RecipientBenefitReleaseRecord $record, ScholarshipApplication $application, ?int $revision): ?RecipientBenefitPayoutDetails
    {
        if (! $this->isDigital($record->release?->release_method)) {
            return null;
        }

        $record->loadMissing('payoutDetails:'.self::SUMMARY_COLUMNS);
        $details = $this->currentDetails($record);
        if (! $this->isActive($record, $application) || $details?->review_status !== 'approved' || ! $details->reviewed_at) {
            throw ValidationException::withMessages(['status' => 'Approve this recipient’s payout details before recording the transfer.']);
        }

        abort_unless($revision === $details->revision, 409, 'The payout approval changed. Reload the release and check the approved destination.');

        return $details;
    }

    public function requireValidReceiptDate(RecipientBenefitReleaseRecord $record, string $receivedOn): void
    {
        if ($record->transfer_sent_on && CarbonImmutable::parse($receivedOn)->toDateString() < $record->transfer_sent_on->toDateString()) {
            throw ValidationException::withMessages(['received_on' => 'The receipt date cannot be before the transfer was sent.']);
        }
    }
}
