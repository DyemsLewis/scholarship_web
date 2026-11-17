<?php

namespace Tests\Unit;

use App\Models\RecipientAgreementCase;
use App\Services\RecipientCaseReviewService;
use App\Support\RecipientAgreement;
use Tests\TestCase;

class RecipientOfferDisclosureTest extends TestCase
{
    public function test_vague_or_missing_commitments_cannot_supply_a_noncompliance_consequence(): void
    {
        foreach ([
            [],
            ['noncompliance_consequence' => 'End support.'],
            ['commitment_type' => 'none', 'responsibilities' => 'None.', 'noncompliance_consequence' => 'End support.'],
            ['commitment_type' => 'provider_briefing', 'responsibilities' => 'Terms will be provided later.', 'noncompliance_consequence' => 'End support.'],
            ['responsibilities' => 'The provider will explain any final recipient commitments after the applicant is accepted.', 'noncompliance_consequence' => 'End support.'],
        ] as $expectation) {
            $case = new RecipientAgreementCase;
            $case->forceFill(['agreement_snapshot' => ['recipient_expectation' => $expectation]]);
            $this->assertFalse(RecipientAgreement::hasDisclosedResponsibilities($case->agreement_snapshot));
            $this->assertNull(app(RecipientCaseReviewService::class)->acceptedConsequences($case));
        }
    }

    public function test_only_specific_recorded_responsibilities_can_supply_the_disclosed_consequence(): void
    {
        $case = new RecipientAgreementCase;
        $case->forceFill(['agreement_snapshot' => ['recipient_expectation' => [
            'commitment_type' => 'reporting',
            'responsibilities' => 'Present official semester grades at the scheduled check-in.',
            'noncompliance_consequence' => 'Support may end only after notice and independent review.',
        ]]]);

        $this->assertSame('Support may end only after notice and independent review.', app(RecipientCaseReviewService::class)->acceptedConsequences($case));
        $this->assertFalse(RecipientAgreement::hasDisclosedResponsibilities(['recipient_expectation' => 'unreadable']));
    }
}
