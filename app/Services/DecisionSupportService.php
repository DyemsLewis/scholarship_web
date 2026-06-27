<?php

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;

class DecisionSupportService
{
    private const WEIGHTS = [
        'eligibility' => 35,
        'documents' => 25,
        'academic' => 20,
        'financial_need' => 15,
        'review_status' => 5,
    ];

    public function scoreApplication(ScholarshipApplication $application): array
    {
        $application->loadMissing(['applicant.studentProfile', 'documents', 'scholarship']);

        $eligibilityScore = $this->eligibilityScore($application);
        $documentScore = $this->documentScore($application);
        $academicScore = $this->academicScore($application);
        $financialNeedScore = $this->financialNeedScore($application);
        $reviewStatusScore = $this->reviewStatusScore($application);

        $criteria = [
            $this->criterion('eligibility', 'Eligibility match', $eligibilityScore, 'How well the applicant matches structured scholarship criteria.'),
            $this->criterion('documents', 'Document readiness', $documentScore, 'Prepared, uploaded, and accepted requirements.'),
            $this->criterion('academic', 'Academic merit', $academicScore, 'GWA or average compared with the minimum requirement.'),
            $this->criterion('financial_need', 'Financial need', $financialNeedScore, 'Income bracket priority for assistance-focused scholarships.'),
            $this->criterion('review_status', 'Review progress', $reviewStatusScore, 'Current application status and reviewer signal.'),
        ];

        $score = collect($criteria)->sum(fn (array $criterion) => $criterion['weighted_score']);
        $score = (int) round($score);
        $recommendation = $this->recommendation($score, $application->status);

        return [
            'score' => $score,
            'recommendation' => $recommendation['value'],
            'label' => $recommendation['label'],
            'summary' => $recommendation['summary'],
            'weights' => self::WEIGHTS,
            'criteria' => $criteria,
        ];
    }

    public function syncApplication(ScholarshipApplication $application): array
    {
        $score = $this->scoreApplication($application);

        $application->forceFill([
            'dss_score' => $score['score'],
            'dss_recommendation' => $score['recommendation'],
            'dss_breakdown' => $score,
        ])->saveQuietly();

        return $score;
    }

    private function criterion(string $key, string $label, int $score, string $note): array
    {
        $weight = self::WEIGHTS[$key];

        return [
            'key' => $key,
            'label' => $label,
            'score' => $score,
            'weight' => $weight,
            'weighted_score' => round(($score * $weight) / 100, 2),
            'note' => $note,
        ];
    }

    private function eligibilityScore(ScholarshipApplication $application): int
    {
        if ($application->eligibility_score !== null) {
            return $this->clamp((int) round((float) $application->eligibility_score));
        }

        $breakdown = $application->eligibility_breakdown ?? [];

        return $this->clamp((int) ($breakdown['score'] ?? 60));
    }

    private function documentScore(ScholarshipApplication $application): int
    {
        $requirements = $this->documentRequirements($application->scholarship);
        $requiredCount = count($requirements);

        if ($requiredCount === 0) {
            return 100;
        }

        $confirmed = collect($application->document_checklist ?? [])
            ->map(fn (string $document) => trim($document))
            ->filter();
        $uploaded = $application->documents
            ->map(fn (ApplicationDocument $document) => $document->document_name);
        $accepted = $application->documents
            ->filter(fn (ApplicationDocument $document) => $document->status === 'accepted')
            ->map(fn (ApplicationDocument $document) => $document->document_name);

        $confirmedPercent = $this->requirementPercent($requirements, $confirmed->all());
        $uploadedPercent = $this->requirementPercent($requirements, $uploaded->all());
        $acceptedPercent = $this->requirementPercent($requirements, $accepted->all());

        return $this->clamp((int) round(($confirmedPercent * 0.15) + ($uploadedPercent * 0.45) + ($acceptedPercent * 0.40)));
    }

    private function academicScore(ScholarshipApplication $application): int
    {
        $gwa = $application->applicant?->studentProfile?->gwa;
        $minimumGwa = $application->scholarship?->minimum_gwa;

        if ($gwa === null && $minimumGwa === null) {
            return 70;
        }

        if ($gwa === null) {
            return 45;
        }

        if ($minimumGwa === null) {
            return 85;
        }

        $studentGwa = (float) $gwa;
        $requiredGwa = (float) $minimumGwa;
        $gradingScale = $application->applicant?->studentProfile?->grading_scale;

        if ($studentGwa <= 0 || $requiredGwa <= 0) {
            return 60;
        }

        if ($gradingScale === 'grade_point' || ($gradingScale !== 'percentage' && $studentGwa <= 5 && $requiredGwa <= 5)) {
            return $this->clamp((int) round(($requiredGwa / $studentGwa) * 100));
        }

        return $this->clamp((int) round(($studentGwa / $requiredGwa) * 100));
    }

    private function financialNeedScore(ScholarshipApplication $application): int
    {
        $income = strtolower((string) $application->applicant?->studentProfile?->income_bracket);
        $requirement = strtolower((string) $application->scholarship?->income_requirement);

        if ($income === '') {
            return 50;
        }

        $score = match (true) {
            str_contains($income, 'below') => 100,
            str_contains($income, '10,000 - 20,000') => 90,
            str_contains($income, '20,001 - 40,000') => 75,
            str_contains($income, '40,001 - 60,000') => 55,
            str_contains($income, 'above') => 35,
            default => 60,
        };

        if ($requirement !== '' && ! in_array($requirement, ['any', 'none', 'no preference'], true)) {
            return str_contains($income, $requirement) || str_contains($requirement, $income)
                ? min(100, $score + 10)
                : max(25, $score - 20);
        }

        return $score;
    }

    private function reviewStatusScore(ScholarshipApplication $application): int
    {
        if ($application->decision_reason === 'missing_documents') {
            return 35;
        }

        if ($application->decision_reason === 'approved_for_award') {
            return 100;
        }

        return match ($application->status) {
            'disbursed', 'renewed', 'awarded' => 100,
            'approved' => 100,
            'interview' => 95,
            'shortlisted' => 92,
            'qualified' => 90,
            'under_review' => 75,
            'rejected', 'not_awarded' => 10,
            default => 60,
        };
    }

    private function recommendation(int $score, string $status): array
    {
        if (in_array($status, ['rejected', 'not_awarded'], true)) {
            return [
                'value' => 'not_recommended',
                'label' => 'Not recommended',
                'summary' => 'The application has been closed without an award, so it is not recommended unless reopened.',
            ];
        }

        if (in_array($status, ['awarded', 'disbursed', 'renewed'], true)) {
            return [
                'value' => 'highly_recommended',
                'label' => 'Award outcome recorded',
                'summary' => 'The provider has recorded a successful scholarship outcome for this application.',
            ];
        }

        if ($score >= 85) {
            return [
                'value' => 'highly_recommended',
                'label' => 'Highly recommended',
                'summary' => 'Strong candidate based on eligibility, documents, merit, and need.',
            ];
        }

        if ($score >= 70) {
            return [
                'value' => 'recommended',
                'label' => 'Recommended',
                'summary' => 'Good candidate, with only minor items needing reviewer confirmation.',
            ];
        }

        if ($score >= 55) {
            return [
                'value' => 'needs_review',
                'label' => 'Needs review',
                'summary' => 'Requires manual review because some criteria are incomplete or unclear.',
            ];
        }

        return [
            'value' => 'low_priority',
            'label' => 'Low priority',
            'summary' => 'Lower priority based on the current application data.',
        ];
    }

    private function documentRequirements(?Scholarship $scholarship): array
    {
        if (! $scholarship?->requirements) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $scholarship->requirements))
            ->map(fn (string $requirement) => trim($requirement))
            ->filter()
            ->values()
            ->all();
    }

    private function requirementPercent(array $requirements, array $completed): int
    {
        if ($requirements === []) {
            return 100;
        }

        $completed = collect($completed)
            ->map(fn (string $document) => trim($document))
            ->filter();
        $count = collect($requirements)
            ->filter(fn (string $requirement) => $completed->contains($requirement))
            ->count();

        return (int) round(($count / count($requirements)) * 100);
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
