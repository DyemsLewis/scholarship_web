<?php

namespace Database\Seeders\Support;

use App\Support\StageEvaluationRubric;

final class DemoStageScores
{
    public static function atPercentage(?array $policy, string $stage, float $percentage): ?array
    {
        $criteria = StageEvaluationRubric::criteria($policy, $stage);
        if ($criteria === []) return null;
        $offsets = $stage === 'exam' ? [1, -1, 0] : [0.6, -0.4, -0.4];
        $scores = [];
        foreach ($criteria as $index => $criterion) {
            $value = $percentage > 1 && $percentage < 99 ? $percentage + $offsets[$index] : $percentage;
            $scores[$criterion['key']] = ['score' => round($value / 100 * $criterion['maximum_score'], 4), 'maximum_score' => $criterion['maximum_score']];
        }

        return $scores;
    }
}
