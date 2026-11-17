<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StageEvaluationRubric
{
    public static function presets(): array
    {
        return [
            'exam' => [
                ['key' => 'knowledge', 'label' => 'Subject knowledge', 'weight' => 40, 'maximum_score' => 40, 'description' => 'Accuracy in the knowledge section of the examination.'],
                ['key' => 'reasoning', 'label' => 'Reasoning and analysis', 'weight' => 40, 'maximum_score' => 40, 'description' => 'Logical reasoning and interpretation of the examination questions.'],
                ['key' => 'application', 'label' => 'Applied problem solving', 'weight' => 20, 'maximum_score' => 20, 'description' => 'Applying concepts to the practical problems on the examination.'],
            ],
            'interview' => [
                ['key' => 'motivation', 'label' => 'Motivation and commitment', 'weight' => 40, 'maximum_score' => 5, 'description' => 'Clear reasons for studying and realistic commitment to the program.'],
                ['key' => 'communication', 'label' => 'Clarity of responses', 'weight' => 30, 'maximum_score' => 5, 'description' => 'Relevant, understandable answers; do not score accent or appearance.'],
                ['key' => 'readiness', 'label' => 'Readiness and planning', 'weight' => 30, 'maximum_score' => 5, 'description' => 'Practical plans for study, participation, and the disclosed responsibilities.'],
            ],
        ];
    }

    public static function criteria(?array $policy, string $stage): array
    {
        return $policy['stage_criteria'][$stage] ?? [];
    }

    public static function labels(?array $policy = null): array
    {
        $labels = [];
        foreach (self::presets() as $stage => $criteria) {
            foreach ($criteria as $criterion) {
                $labels[$stage][$criterion['key']] = $policy['stage_criterion_labels'][$stage][$criterion['key']] ?? $criterion['label'];
            }
            foreach ($policy['stage_criteria'][$stage] ?? [] as $criterion) {
                if (isset($labels[$stage][$criterion['key']])) {
                    $labels[$stage][$criterion['key']] = $criterion['label'];
                }
            }
        }

        return $labels;
    }

    public static function labelRules(): array
    {
        $rules = ['stage_criterion_labels' => ['sometimes', 'required', 'array:exam,interview']];
        foreach (self::presets() as $stage => $criteria) {
            $rules["stage_criterion_labels.$stage"] = ['sometimes', 'required', 'array:'.implode(',', array_column($criteria, 'key'))];
            foreach ($criteria as $criterion) {
                $rules["stage_criterion_labels.$stage.{$criterion['key']}"] = ['sometimes', 'required', 'string', 'max:80', 'regex:/^[^\p{C}]+$/u'];
            }
        }

        return $rules;
    }

    public static function normalizeLabels(array $labels, ?array $policy = null): array
    {
        $result = self::labels($policy);
        foreach ($result as $stage => &$criteria) {
            $used = [];
            foreach ($criteria as $key => &$label) {
                $label = trim($labels[$stage][$key] ?? $label);
                $comparison = Str::lower(preg_replace('/\s+/u', ' ', $label));
                if ($comparison === '' || in_array($comparison, $used, true)) {
                    throw ValidationException::withMessages(["stage_criterion_labels.$stage.$key" => 'Give each criterion in this stage a different, non-empty label.']);
                }
                $used[] = $comparison;
            }
            unset($label);
        }
        unset($criteria);

        return $result;
    }

    public static function calculate(array $criteria, mixed $scores, string $stage): array
    {
        $keys = array_column($criteria, 'key');
        if (! is_array($scores) || count($scores) !== count($keys) || array_diff($keys, array_keys($scores)) || array_diff(array_keys($scores), $keys)) {
            throw ValidationException::withMessages(['criteria_scores' => 'Complete every criterion in the fixed evaluation plan.']);
        }
        $breakdown = [];
        $total = 0.0;
        foreach ($criteria as $criterion) {
            $key = $criterion['key'];
            $row = $scores[$key];
            $data = Validator::make(['criteria_scores' => [$key => $row]], [
                "criteria_scores.$key" => ['required', 'array:score,maximum_score'],
                "criteria_scores.$key.score" => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
                "criteria_scores.$key.maximum_score" => ['required', 'numeric', 'min:0.0001', 'max:1000000', 'decimal:0,4'],
            ])->validate()['criteria_scores'][$key];
            $score = (float) $data['score'];
            $maximum = (float) $data['maximum_score'];
            if (! is_finite($score) || ! is_finite($maximum) || $score > $maximum) {
                throw ValidationException::withMessages(["criteria_scores.$key.score" => "{$criterion['label']}: the score must be between zero and its maximum."]);
            }
            if ($stage === 'interview' && $maximum !== (float) $criterion['maximum_score']) {
                throw ValidationException::withMessages(["criteria_scores.$key.maximum_score" => 'Interview criteria use the fixed 0 to 5 rating scale.']);
            }
            $percentage = $score / $maximum * 100;
            $contribution = $percentage * $criterion['weight'] / 100;
            $total += $contribution;
            $breakdown[] = [...$criterion, 'score' => $score, 'maximum_score' => $maximum,
                'percentage' => round($percentage, 4), 'contribution' => round($contribution, 4)];
        }
        if (array_sum(array_column($criteria, 'weight')) !== 100) {
            throw ValidationException::withMessages(['evaluation' => 'The fixed criteria weights need review.']);
        }

        return ['score' => round($total, 4), 'maximum_score' => 100, 'breakdown' => $breakdown];
    }
}
