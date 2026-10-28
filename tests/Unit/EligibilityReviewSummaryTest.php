<?php

namespace Tests\Unit;

use App\Support\EligibilityReviewSummary;
use Tests\TestCase;

class EligibilityReviewSummaryTest extends TestCase
{
    public function test_legacy_failures_remain_required_despite_a_nonblocking_flag(): void
    {
        $summary = EligibilityReviewSummary::from(['criteria' => [
            ['key' => 'course', 'label' => 'Course', 'status' => 'fail', 'is_blocking' => false],
            ['key' => 'income', 'label' => 'Income', 'status' => 'fail', 'rule_mode' => 'preferred'],
            ['key' => 'documents', 'status' => 'missing'],
            ['key' => 'file', 'category' => 'readiness', 'status' => 'fail'],
        ]]);
        $this->assertSame('required_mismatch', $summary['state']);
        $this->assertSame(1, $summary['required_not_met']);
        $this->assertSame(1, $summary['preferred_not_met']);
        $this->assertSame(0, $summary['missing']);
        $this->assertStringContainsString('Course', $summary['interpretation']);
    }

    public function test_grade_scale_review_is_distinct_from_missing_information_and_open_criteria(): void
    {
        $summary = EligibilityReviewSummary::from(['criteria' => [
            ['key' => 'academic', 'label' => 'Grades', 'status' => 'missing', 'comparison_mode' => 'scale_mismatch'],
            ['key' => 'course', 'status' => 'pass'],
        ]]);
        $this->assertSame('manual_review', $summary['state']);
        $this->assertSame(1, $summary['manual_review']);
        $this->assertSame(0, $summary['missing']);
        $this->assertSame(50, $summary['completeness']);
        $this->assertStringContainsString('not automatic matches or failures', $summary['interpretation']);
    }

    public function test_preferences_are_not_automatic_rejections_and_empty_checks_are_not_a_match(): void
    {
        $summary = EligibilityReviewSummary::from(['criteria' => [
            ['key' => 'income', 'label' => 'Income', 'status' => 'fail', 'rule_mode' => 'preferred'],
        ]]);
        $this->assertSame('preferred_difference', $summary['state']);
        $this->assertSame(0, $summary['required_not_met']);
        $this->assertStringContainsString('not an automatic rejection', $summary['interpretation']);
        $this->assertSame('unavailable', EligibilityReviewSummary::from([])['state']);
        $this->assertSame('open_criteria', EligibilityReviewSummary::from(['criteria' => [
            ['key' => 'course', 'status' => 'info'],
        ]])['state']);
    }
}
