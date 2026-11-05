<?php

namespace App\Domain\Recipients;

final class RecipientAgreementCaseCatalog
{
    public const AGREEMENT_CONCERN = 'agreement_concern';

    public const BENEFIT_DELIVERY = 'benefit_delivery';

    public const TYPES = [self::AGREEMENT_CONCERN, self::BENEFIT_DELIVERY];

    public const STATUSES = [
        'draft', 'awaiting_response', 'under_review', 'outcome_recorded',
        'appeal_pending', 'closed', 'cancelled', 'awaiting_provider', 'awaiting_recipient',
    ];

    public const FINDINGS = ['not_assessed', 'not_established', 'confirmed', 'excused'];

    public const RESPONSE_KINDS = [
        'explanation' => 'Provide an explanation',
        'correction_request' => 'Request a correction',
        'exception_request' => 'Request an exception or more time',
    ];

    public const DELIVERY_ISSUES = [
        'not_received' => 'Benefit not received', 'incorrect_amount' => 'Incorrect amount',
        'incomplete_benefit' => 'Incomplete benefit', 'damaged_item' => 'Damaged item',
        'delivery_question' => 'Ask about delivery', 'other' => 'Other delivery problem',
    ];

    public const DELIVERY_REPLIES = [
        'correction_planned' => 'Plan a correction', 'delivery_proposed' => 'Submit delivery proof',
        'information_provided' => 'Provide information',
    ];

    public const OBLIGATION_LABELS = [
        'recipient_expectation.responsibilities' => 'Recipient responsibilities',
        'recipient_expectation.required_evidence' => 'Required records',
        'recipient_expectation.release_conditions' => 'Benefit release conditions',
        'return_service_contract' => 'Return-service commitment',
        'other_contract_terms' => 'Other accepted terms',
    ];

    // Only actual obligations, not the consequence or exception clauses, can be cited.
    public const OBLIGATION_KEYS = [
        'recipient_expectation.responsibilities',
        'recipient_expectation.required_evidence',
        'recipient_expectation.release_conditions',
        'return_service_contract',
        'other_contract_terms',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            'draft' => ['awaiting_response', 'cancelled'],
            'awaiting_response' => ['under_review', 'cancelled'],
            'under_review' => ['awaiting_response', 'outcome_recorded', 'cancelled'],
            'outcome_recorded' => ['appeal_pending', 'closed'],
            'appeal_pending' => ['under_review'],
            'awaiting_provider' => ['awaiting_recipient'],
            'awaiting_recipient' => ['awaiting_provider', 'closed'],
            default => [],
        }, true);
    }

    public static function providerPermission(string $type): ?string
    {
        return match ($type) {
            self::AGREEMENT_CONCERN => 'manage_recipients',
            self::BENEFIT_DELIVERY => 'manage_benefit_releases',
            default => null,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Private draft',
            'awaiting_response' => 'Awaiting response',
            'under_review' => 'Response received',
            'outcome_recorded' => 'Outcome recorded',
            'appeal_pending' => 'Appeal pending',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled draft',
            'awaiting_provider' => 'Awaiting provider',
            'awaiting_recipient' => 'Awaiting applicant confirmation',
            default => 'Unknown status',
        };
    }
}
