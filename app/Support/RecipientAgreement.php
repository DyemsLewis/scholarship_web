<?php

namespace App\Support;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;

class RecipientAgreement
{
    public static function snapshot(Scholarship $scholarship, ?ScholarshipApplication $application = null): array
    {
        $scholarship->loadMissing(['provider.providerProfile', 'benefits']);

        return [
            'program_title' => $scholarship->title,
            'provider_name' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
            'award_amount' => $application?->awarded_amount ?? $scholarship->award_amount,
            'benefits' => $scholarship->benefitPayload(),
            'support_starts_at' => $scholarship->support_starts_at?->format('Y-m-d'),
            'support_ends_at' => $scholarship->support_ends_at?->format('Y-m-d'),
            'recipient_expectation' => $scholarship->recipient_agreement ?? [],
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
        ];
    }

    public static function version(array $snapshot): string
    {
        return 'recipient-agreement-v1-'.substr(hash('sha256', json_encode($snapshot)), 0, 16);
    }

    public static function payload(ScholarshipApplication $application): ?array
    {
        $snapshot = $application->provider_contract_terms_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        $status = $application->student_response_status ?: 'pending';

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'accepted' => 'Accepted',
                'declined' => 'Declined',
                default => 'Awaiting response',
            },
            'snapshot' => $snapshot,
            'version' => $application->provider_contract_terms_version,
            'responded_at' => $application->student_responded_at?->format('M d, Y h:i A'),
            'accepted_at' => $application->provider_contract_terms_accepted_at?->format('M d, Y h:i A'),
            'response_note' => $application->student_response_note,
            'requires_response' => $application->final_outcome === 'selected' && blank($application->student_response_status),
            'can_respond' => $application->final_outcome === 'selected' && blank($application->student_response_status),
            'notice' => 'This records the terms shown when the applicant was selected. It does not replace any formal document the provider may require.',
        ];
    }
}
