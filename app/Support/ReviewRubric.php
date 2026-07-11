<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class ReviewRubric
{
    public const DEFAULT = [
        [
            'key' => 'eligibility_fit',
            'label' => 'Eligibility fit',
            'weight' => 35,
            'guidance' => 'Confirm that the applicant meets the program-specific target and restrictions.',
        ],
        [
            'key' => 'academic_merit',
            'label' => 'Academic merit',
            'weight' => 25,
            'guidance' => 'Review grades using the scale and education level required by this program.',
        ],
        [
            'key' => 'financial_need',
            'label' => 'Financial need',
            'weight' => 20,
            'guidance' => 'Review declared need and supporting income documents where applicable.',
        ],
        [
            'key' => 'document_quality',
            'label' => 'Document quality',
            'weight' => 20,
            'guidance' => 'Check whether required documents are complete, readable, current, and valid.',
        ],
    ];

    public static function fromJson(?string $json): array
    {
        if (! filled($json)) {
            return [];
        }

        $rubric = json_decode($json, true);

        if (! is_array($rubric)) {
            throw ValidationException::withMessages([
                'review_rubric' => 'The review rubric must be valid JSON.',
            ]);
        }

        return self::validate($rubric);
    }

    public static function validate(array $rubric): array
    {
        if (count($rubric) > 6) {
            throw ValidationException::withMessages([
                'review_rubric' => 'Use no more than six review criteria.',
            ]);
        }

        if ($rubric === []) {
            return [];
        }

        $normalized = [];
        $keys = [];
        $labels = [];

        foreach ($rubric as $index => $criterion) {
            $key = trim((string) ($criterion['key'] ?? ''));
            $label = trim((string) ($criterion['label'] ?? ''));
            $guidance = trim((string) ($criterion['guidance'] ?? ''));
            $weight = filter_var($criterion['weight'] ?? null, FILTER_VALIDATE_INT);

            if (! preg_match('/^[A-Za-z0-9_-]{2,50}$/', $key)) {
                throw ValidationException::withMessages([
                    "review_rubric.{$index}.key" => 'Each criterion needs a stable key using letters, numbers, dashes, or underscores.',
                ]);
            }

            if ($label === '' || mb_strlen($label) > 100) {
                throw ValidationException::withMessages([
                    "review_rubric.{$index}.label" => 'Each criterion needs a label of 100 characters or fewer.',
                ]);
            }

            if ($weight === false || $weight < 1 || $weight > 100) {
                throw ValidationException::withMessages([
                    "review_rubric.{$index}.weight" => 'Each criterion weight must be between 1 and 100.',
                ]);
            }

            if (mb_strlen($guidance) > 300) {
                throw ValidationException::withMessages([
                    "review_rubric.{$index}.guidance" => 'Criterion guidance must be 300 characters or fewer.',
                ]);
            }

            $lowerKey = strtolower($key);
            $lowerLabel = mb_strtolower($label);

            if (in_array($lowerKey, $keys, true) || in_array($lowerLabel, $labels, true)) {
                throw ValidationException::withMessages([
                    'review_rubric' => 'Review criterion labels and keys must be unique.',
                ]);
            }

            $keys[] = $lowerKey;
            $labels[] = $lowerLabel;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'weight' => $weight,
                'guidance' => $guidance,
            ];
        }

        if (array_sum(array_column($normalized, 'weight')) !== 100) {
            throw ValidationException::withMessages([
                'review_rubric' => 'Review criterion weights must total exactly 100%.',
            ]);
        }

        return $normalized;
    }

    public static function result(array $rubric, array $scores = []): array
    {
        $rubric = self::validate($rubric);
        $allowedKeys = array_column($rubric, 'key');
        $unknownKeys = array_diff(array_keys($scores), $allowedKeys);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'rubric_scores' => 'One or more review scores do not belong to this scholarship rubric.',
            ]);
        }

        $normalizedScores = collect($scores)
            ->filter(fn ($score) => $score !== null && $score !== '')
            ->map(fn ($score) => round((float) $score, 2))
            ->all();
        $criteria = collect($rubric)->map(function (array $criterion) use ($normalizedScores): array {
            $score = $normalizedScores[$criterion['key']] ?? null;

            return [
                ...$criterion,
                'score' => $score,
                'weighted_score' => $score === null
                    ? null
                    : round(($score * $criterion['weight']) / 100, 2),
            ];
        })->values();
        $completed = $criteria->whereNotNull('score')->count();
        $isComplete = $rubric !== [] && $completed === count($rubric);

        return [
            'criteria' => $criteria->all(),
            'scores' => $normalizedScores,
            'completed' => $completed,
            'total_criteria' => count($rubric),
            'completion_percent' => $rubric === [] ? 0 : (int) round(($completed / count($rubric)) * 100),
            'is_complete' => $isComplete,
            'total_score' => $isComplete ? round((float) $criteria->sum('weighted_score'), 2) : null,
            'decision_notice' => 'The rubric supports consistent provider review. It does not automatically approve or reject an applicant.',
        ];
    }
}
