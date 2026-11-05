<?php

namespace Tests\Unit;

use App\Support\AcademicRequirement;
use PHPUnit\Framework\TestCase;

class AcademicEducationReviewTest extends TestCase
{
    public function test_non_numeric_records_are_reviewed_without_inventing_an_average(): void
    {
        $this->assertStringNotContainsString('%', AcademicRequirement::studentLabel(1.5, 'unfamiliar four point system'));
        foreach (['pass_fail', 'other', 'unfamiliar four point system'] as $scale) {
            foreach ([null, 1.5] as $value) {
                $result = AcademicRequirement::match($value, $scale, 85, 'percentage');
                $this->assertSame('missing', $result['status']);
                $this->assertSame('manual_review', $result['comparison_mode']);
                $this->assertFalse($result['is_comparable']);
                $this->assertStringNotContainsString('%', $result['student_value']);
                $this->assertArrayNotHasKey('equivalence', $result);
            }
        }
    }

    public function test_clear_numeric_mismatches_and_open_rules_keep_their_existing_behavior(): void
    {
        $this->assertSame('fail', AcademicRequirement::match(84, 'percentage', 85, 'percentage')['status']);
        $this->assertSame('pass', AcademicRequirement::match(1.5, 'grade_point', 1.75, 'grade_point')['status']);
        $this->assertSame('scale_mismatch', AcademicRequirement::match(90, 'percentage', 1.75, 'grade_point')['comparison_mode']);
        $this->assertSame('not_required', AcademicRequirement::match(null, 'pass_fail', null, null)['comparison_mode']);
    }
}
