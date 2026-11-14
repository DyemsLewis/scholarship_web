<?php

namespace Tests\Unit;

use App\Support\ReviewRubric;
use App\Support\SelectionScoringPolicy;
use PHPUnit\Framework\TestCase;

class SelectionScoringBuilderPreviewTest extends TestCase
{
    public function test_every_builder_option_uses_the_real_scoring_policy(): void
    {
        $presets = SelectionScoringPolicy::builderPresets(ReviewRubric::DEFAULT);
        $this->assertSame(['screening', 'exam', 'interview', 'exam+interview'], array_keys($presets));
        foreach ($presets as $policy) {
            $this->assertSame(SelectionScoringPolicy::preset($policy['stages'], ReviewRubric::DEFAULT), $policy);
            $this->assertSame(100, array_sum($policy['weights']));
            $this->assertArrayNotHasKey('formal_application', $policy['weights']);
            $this->assertSame(['academic_merit', 'financial_need'], array_column($policy['screening_criteria'], 'key'));
            $this->assertArrayNotHasKey('frozen_at', $policy);
        }
    }

    public function test_preview_preserves_custom_rubric_and_qualifying_only_policy(): void
    {
        $rubric = [
            ['key' => 'academic_merit', 'label' => 'Academic achievement', 'weight' => 60],
            ['key' => 'financial_need', 'label' => 'Financial need', 'weight' => 40],
        ];
        foreach (SelectionScoringPolicy::builderPresets($rubric) as $policy) {
            $this->assertSame($rubric, $policy['review_rubric']);
            $this->assertSame($rubric, $policy['screening_criteria']);
        }
        $empty = SelectionScoringPolicy::builderPresets([]);
        $this->assertSame([], $empty['screening']['weights']);
        $this->assertSame(['exam' => 60, 'interview' => 40], $empty['exam+interview']['weights']);
    }
}
