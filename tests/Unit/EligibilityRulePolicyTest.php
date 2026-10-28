<?php

namespace Tests\Unit;

use App\Support\EligibilityRulePolicy;
use PHPUnit\Framework\TestCase;

class EligibilityRulePolicyTest extends TestCase
{
    public function test_missing_and_invalid_modes_are_required_by_default(): void
    {
        foreach ([null, [], 'preferred', ['academic' => 'optional']] as $modes) {
            $this->assertSame(array_fill_keys(EligibilityRulePolicy::KEYS, 'required'), EligibilityRulePolicy::normalize($modes));
        }
        $this->assertSame('preferred', EligibilityRulePolicy::normalize(['academic' => 'preferred'])['academic']);
        $this->assertArrayNotHasKey('documents', EligibilityRulePolicy::normalize(['documents' => 'preferred']));
    }

    public function test_only_explicit_preferred_rules_are_non_blocking(): void
    {
        $legacy = ['key' => 'course', 'status' => 'fail'];
        $this->assertTrue(EligibilityRulePolicy::isBlocking($legacy));
        $this->assertTrue(EligibilityRulePolicy::isBlocking([...$legacy, 'rule_mode' => 'optional', 'is_blocking' => false]));
        $this->assertFalse(EligibilityRulePolicy::isBlocking([...$legacy, 'rule_mode' => 'preferred']));
        $this->assertFalse(EligibilityRulePolicy::isBlocking([...$legacy, 'status' => 'missing']));
        $this->assertFalse(EligibilityRulePolicy::isBlocking(['key' => 'documents', 'status' => 'fail']));
    }
}
