<?php

namespace App\Support;

class EligibilityReviewSummary
{
    public static function needsReview(array $criterion): bool
    {
        return ($criterion['needs_review'] ?? false)
            || in_array($criterion['comparison_mode'] ?? null, ['scale_mismatch', 'manual_review'], true)
            || (($criterion['key'] ?? null) === 'academic'
                && ($criterion['status'] ?? null) === 'info'
                && filled($criterion['requirement'] ?? null));
    }

    public static function from(array $breakdown): array
    {
        $criteria = collect($breakdown['criteria'] ?? [])
            ->filter(fn (array $item): bool => ($item['category'] ?? 'eligibility') === 'eligibility'
                && ($item['key'] ?? null) !== 'documents');
        $required = $criteria->filter(fn (array $item): bool => EligibilityRulePolicy::isBlocking($item));
        $preferred = $criteria->filter(fn (array $item): bool => ($item['status'] ?? null) === 'fail'
            && ($item['rule_mode'] ?? 'required') === 'preferred');
        $review = $criteria->filter(fn (array $item): bool => self::needsReview($item));
        $missing = $criteria->filter(fn (array $item): bool => ($item['status'] ?? null) === 'missing' && ! self::needsReview($item));
        $met = $criteria->filter(fn (array $item): bool => ($item['status'] ?? null) === 'pass' && ! self::needsReview($item))->count();
        $open = $criteria->filter(fn (array $item): bool => ($item['status'] ?? null) === 'info' && ! self::needsReview($item))->count();
        $notMet = $required->count() + $preferred->count();
        $applicable = $met + $notMet + $missing->count() + $review->count();
        $names = fn ($items): string => $items->pluck('label')->filter()->implode(', ');
        [$state, $label, $interpretation] = match (true) {
            $required->isNotEmpty() => ['required_mismatch', 'Required rules not met', 'Required discrepancies: '.$names($required).'. Verify the saved values and supporting evidence before deciding. A high advisory score does not resolve these discrepancies.'],
            $missing->isNotEmpty() => ['provisional', 'Missing information', 'Missing profile information: '.$names($missing).'. Treat the score as provisional and confirm these items before deciding.'],
            $review->isNotEmpty() => ['manual_review', 'Manual review needed', 'Evidence review needed: '.$names($review).'. These checks are not automatic matches or failures; do not use estimated grade conversions as proof.'],
            $preferred->isNotEmpty() => ['preferred_difference', 'Preferences differ', 'Preferences not matched: '.$names($preferred).'. These differences did not block submission. Use them as review context, not an automatic rejection.'],
            $criteria->isEmpty() => ['unavailable', 'Comparison unavailable', 'No saved eligibility checks are available. Review the application evidence rather than treating an empty comparison as a match.'],
            $applicable === 0 => ['open_criteria', 'Open criteria', 'The program has no restrictive structured criteria. A high score means no listed mismatch; it is not an applicant ranking or selection decision.'],
            default => ['complete', 'Comparison complete', 'All applicable structured criteria have enough profile data for comparison. Confirm the values against uploaded proof before deciding.'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'interpretation' => $interpretation,
            'completeness' => $applicable === 0 ? 100 : (int) round(($met + $notMet) / $applicable * 100),
            'met' => $met,
            'not_met' => $notMet,
            'required_not_met' => $required->count(),
            'preferred_not_met' => $preferred->count(),
            'missing' => $missing->count(),
            'manual_review' => $review->count(),
            'not_applicable' => $open,
            'applicable' => $applicable,
        ];
    }
}
