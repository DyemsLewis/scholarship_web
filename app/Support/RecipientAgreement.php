<?php

namespace App\Support;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;

class RecipientAgreement
{
    public const RESPONSE_SCOPE = 'offer_confirmation';

    public const RESPONSE_NOTICE = 'This confirms your intention to proceed with the scholarship offer, subject to the final provider agreement. It is not a signed final contract and does not accept additional obligations that are not shown here. Ask the provider to explain any different or additional terms before signing or continuing.';

    public static function hasDisclosedResponsibilities(array $snapshot): bool
    {
        $expectation = $snapshot['recipient_expectation'] ?? [];
        if (! is_array($expectation)
            || in_array($expectation['commitment_type'] ?? null, ['provider_briefing', 'none'], true)) {
            return false;
        }

        foreach ([
            $expectation['responsibilities'] ?? null,
            $snapshot['return_service_contract'] ?? null,
            $snapshot['other_contract_terms'] ?? null,
            $snapshot['renewal_policy'] ?? null,
        ] as $text) {
            if (is_string($text) && trim($text) !== ''
                && trim($text) !== 'The provider will explain any final recipient commitments after the applicant is accepted.') {
                return true;
            }
        }

        return false;
    }

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
            'provider_contact' => [
                'name' => $scholarship->contact_person,
                'department' => $scholarship->contact_department,
                'email' => $scholarship->contact_email,
                'number' => $scholarship->contact_number,
            ],
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
        ];
    }

    public static function version(array $snapshot): string
    {
        return 'recipient-agreement-v2-'.substr(hash('sha256', json_encode($snapshot)), 0, 16);
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
                'accepted' => 'Intention confirmed',
                'declined' => 'Offer declined',
                default => 'Awaiting response',
            },
            'snapshot' => $snapshot,
            'version' => $application->provider_contract_terms_version,
            'responded_at' => $application->student_responded_at?->format('M d, Y h:i A'),
            'accepted_at' => $application->provider_contract_terms_accepted_at?->format('M d, Y h:i A'),
            'response_note' => $application->student_response_note,
            'requires_response' => $application->final_outcome === 'selected' && blank($application->student_response_status),
            'can_respond' => $application->final_outcome === 'selected' && blank($application->student_response_status),
            'response_scope' => self::RESPONSE_SCOPE,
            'final_contract_recorded' => false,
            'notice' => self::RESPONSE_NOTICE,
        ];
    }
}
