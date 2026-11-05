<?php

namespace Tests\Unit;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use PHPUnit\Framework\TestCase;

class RecipientAgreementCaseCatalogTest extends TestCase
{
    public function test_states_cannot_skip_notice_review_or_appeal(): void
    {
        $this->assertTrue(Catalog::canTransition('draft', 'awaiting_response'));
        $this->assertTrue(Catalog::canTransition('draft', 'cancelled'));
        $this->assertTrue(Catalog::canTransition('awaiting_response', 'under_review'));
        $this->assertTrue(Catalog::canTransition('under_review', 'outcome_recorded'));
        $this->assertTrue(Catalog::canTransition('outcome_recorded', 'appeal_pending'));
        $this->assertTrue(Catalog::canTransition('appeal_pending', 'under_review'));
        $this->assertTrue(Catalog::canTransition('outcome_recorded', 'closed'));
        foreach (['under_review', 'outcome_recorded', 'closed'] as $state) {
            $this->assertFalse(Catalog::canTransition('draft', $state));
        }
        $this->assertFalse(Catalog::canTransition('awaiting_response', 'closed'));
        $this->assertFalse(Catalog::canTransition('awaiting_response', 'outcome_recorded'));
        $this->assertFalse(Catalog::canTransition('closed', 'confirmed'));
        $this->assertFalse(Catalog::canTransition('unknown', 'closed'));
        $this->assertFalse(Catalog::canTransition('cancelled', 'under_review'));
    }

    public function test_findings_are_not_workflow_states_and_labels_do_not_leak_keys(): void
    {
        $this->assertEmpty(array_intersect(Catalog::STATUSES, Catalog::FINDINGS));
        foreach (Catalog::STATUSES as $state) {
            $this->assertStringNotContainsString('_', Catalog::statusLabel($state));
        }
        $this->assertSame('Unknown status', Catalog::statusLabel('internal_machine_key'));
        $this->assertSame('manage_recipients', Catalog::providerPermission(Catalog::AGREEMENT_CONCERN));
        $this->assertSame('manage_benefit_releases', Catalog::providerPermission(Catalog::BENEFIT_DELIVERY));
        $this->assertNull(Catalog::providerPermission('unknown'));
    }

    public function test_a_database_flag_or_finding_cannot_enable_cross_provider_disclosure(): void
    {
        foreach (Catalog::STATUSES as $status) {
            foreach (Catalog::FINDINGS as $finding) {
                $case = new RecipientAgreementCase;
                $case->forceFill(['status' => $status, 'finding' => $finding, 'disclosure_status' => 'approved']);
                $this->assertFalse($case->canShareWithOtherProviders());
            }
        }
    }
}
