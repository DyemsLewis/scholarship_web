<?php

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Support\AcademicRequirement;

class DecisionSupportService
{
    public const METHODOLOGY_VERSION = '2.0';

    private const WEIGHTS = [
        'eligibility' => 65,
        'academic' => 20,
        'financial_need' => 15,
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
            $this->criterion('academic', 'Academic merit', $academicScore, 'Academic record compared with the scholarship grading requirement.'),
            $this->criterion('financial_need', 'Financial need', $financialNeedScore, 'Income bracket priority for assistance-focused scholarships.'),
        ];

        $score = collect($criteria)->sum(fn (array $criterion) => $criterion['weighted_score']);
        $score = (int) round($score);
        $recommendation = $this->recommendation($score);

        return [
            'methodology_version' => self::METHODOLOGY_VERSION,
            'score' => $score,
            'suitability_score' => $score,
            'recommendation' => $recommendation['value'],
            'label' => $recommendation['label'],
            'summary' => $recommendation['summary'],
            'weights' => self::WEIGHTS,
            'criteria' => $criteria,
            'application_readiness' => [
                'score' => $documentScore,
                'label' => $this->readinessLabel($documentScore),
                'summary' => 'Measures required-document preparation and acceptance. It does not change applicant suitability.',
            ],
            'review_progress' => [
                'score' => $reviewStatusScore,
                'status' => $application->status,
                'label' => $this->statusLabel($application->status),
                'summary' => 'Shows where the provider is in the review workflow. It does not change applicant suitability.',
            ],
            'decision_notice' => 'This score supports screening only. The scholarship provider makes the final decision.',
        ];
    }

    public function syncApplication(ScholarshipApplication $application): array
    {
        $score = $this->scoreApplication($application);
        $eligibilitySnapshot = $this->currentEligibilitySnapshot($application);

        $updates = [
            'dss_score' => $score['score'],
            'dss_recommendation' => $score['recommendation'],
            'dss_breakdown' => $score,
        ];

        if ($eligibilitySnapshot !== null) {
            $updates['eligibility_score'] = $eligibilitySnapshot['score'];
            $updates['eligibility_breakdown'] = $eligibilitySnapshot;
        }

        $application->forceFill($updates)->saveQuietly();

        return $score;
    }

    public function explainApplication(ScholarshipApplication $application, ?array $score = null): array
    {
        $application->loadMissing(['applicant.studentProfile', 'documents', 'scholarship']);

        $score ??= $this->scoreApplication($application);
        $eligibility = $application->eligibility_breakdown ?? $this->currentEligibilitySnapshot($application) ?? [];
        $eligibilityCriteria = collect($eligibility['criteria'] ?? []);
        $requirements = $this->documentRequirements($application->scholarship);
        $uploadedDocuments = $application->documents->pluck('document_name')->all();
        $acceptedDocuments = $application->documents
            ->filter(fn (ApplicationDocument $document) => $document->status === 'accepted')
            ->pluck('document_name')
            ->all();
        $missingUploads = collect($requirements)
            ->reject(fn (string $requirement) => $this->containsRequirement($uploadedDocuments, $requirement))
            ->values();
        $missingAccepted = collect($requirements)
            ->reject(fn (string $requirement) => $this->containsRequirement($acceptedDocuments, $requirement))
            ->values();

        $strengths = collect($score['criteria'] ?? [])
            ->filter(fn (array $criterion) => (int) ($criterion['score'] ?? 0) >= 80)
            ->map(fn (array $criterion) => "{$criterion['label']} is strong.");

        $strengths = $strengths->merge(
            $eligibilityCriteria
                ->where('status', 'pass')
                ->pluck('note')
        );

        if ($requirements !== [] && $missingUploads->isEmpty()) {
            $strengths->push('All required documents have been uploaded.');
        }

        $needsAttention = collect($score['criteria'] ?? [])
            ->filter(fn (array $criterion) => (int) ($criterion['score'] ?? 0) < 60)
            ->map(fn (array $criterion) => "{$criterion['label']} needs attention.");

        $needsAttention = $needsAttention->merge(
            $eligibilityCriteria
                ->filter(fn (array $criterion) => in_array($criterion['status'] ?? '', ['fail', 'missing'], true))
                ->pluck('note')
        );

        if ($missingUploads->isNotEmpty()) {
            $needsAttention->push('Missing uploads: '.$missingUploads->take(3)->implode(', ').($missingUploads->count() > 3 ? ', and more' : '').'.');
        } elseif ($missingAccepted->isNotEmpty()) {
            $needsAttention->push('Some uploaded documents still need provider acceptance.');
        }

        return [
            'headline' => $this->explanationHeadline($score['recommendation'] ?? 'needs_review', $application->status),
            'summary' => $score['summary'] ?? 'DSS reviewed the current application data.',
            'strengths' => $strengths
                ->filter()
                ->unique()
                ->take(4)
                ->values()
                ->all(),
            'needs_attention' => $needsAttention
                ->filter()
                ->unique()
                ->take(4)
                ->values()
                ->all(),
            'next_action' => $this->recommendedNextAction($application, $score, $missingUploads->all(), $missingAccepted->all()),
        ];
    }

    public function statusProgress(ScholarshipApplication $application): array
    {
        $application->loadMissing('statusHistories');

        $status = $application->status ?: 'submitted';
        $examStatuses = ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed', 'exam_failed'];
        $usesExamFlow = in_array($status, $examStatuses, true)
            || $application->statusHistories->contains(
                fn ($history): bool => in_array($history->to_status, $examStatuses, true)
            );
        $flow = $usesExamFlow ? [
            ['key' => 'submitted', 'label' => 'Submitted'],
            ['key' => 'under_review', 'label' => 'Under review'],
            ['key' => 'exam_qualified', 'label' => 'Qualified for exam'],
            ['key' => 'exam_scheduled', 'label' => 'Exam scheduled'],
            ['key' => 'exam_taken', 'label' => 'Exam taken'],
            ['key' => 'exam_result', 'label' => 'Exam result'],
            ['key' => 'approved', 'label' => 'Approved'],
            ['key' => 'awarded', 'label' => 'Awarded'],
            ['key' => 'distribution_scheduled', 'label' => 'Distribution scheduled'],
            ['key' => 'disbursed', 'label' => 'Distributed'],
        ] : [
            ['key' => 'submitted', 'label' => 'Submitted'],
            ['key' => 'under_review', 'label' => 'Under review'],
            ['key' => 'qualified', 'label' => 'Qualified'],
            ['key' => 'shortlisted', 'label' => 'Shortlisted'],
            ['key' => 'interview', 'label' => 'Interview'],
            ['key' => 'approved', 'label' => 'Approved'],
            ['key' => 'awarded', 'label' => 'Awarded'],
            ['key' => 'distribution_scheduled', 'label' => 'Distribution scheduled'],
            ['key' => 'disbursed', 'label' => 'Distributed'],
        ];
        $stageIndex = $usesExamFlow
            ? match ($status) {
                'under_review' => 1,
                'qualified', 'shortlisted', 'exam_qualified' => 2,
                'interview', 'exam_scheduled' => 3,
                'exam_taken' => 4,
                'exam_passed', 'exam_failed' => 5,
                'approved' => 6,
                'awarded', 'not_awarded' => 7,
                'distribution_scheduled' => 8,
                'disbursed', 'renewed' => 9,
                'rejected' => 1,
                default => 0,
            }
            : match ($status) {
                'under_review' => 1,
                'qualified' => 2,
                'shortlisted' => 3,
                'interview' => 4,
                'approved' => 5,
                'awarded', 'not_awarded' => 6,
                'distribution_scheduled' => 7,
                'disbursed', 'renewed' => 8,
                'rejected' => 1,
                default => 0,
            };
        $isClosedWithoutAward = in_array($status, ['rejected', 'not_awarded', 'exam_failed'], true);
        $steps = collect($flow)
            ->map(function (array $step, int $index) use ($stageIndex, $isClosedWithoutAward): array {
                return [
                    ...$step,
                    'state' => match (true) {
                        $isClosedWithoutAward && $index > $stageIndex => 'skipped',
                        $index < $stageIndex => 'complete',
                        $index === $stageIndex => 'current',
                        default => 'upcoming',
                    },
                ];
            })
            ->values()
            ->all();

        return [
            'current' => $status,
            'label' => $this->statusLabel($status),
            'percent' => (int) round(($stageIndex / (count($flow) - 1)) * 100),
            'tone' => $this->statusTone($status),
            'next_action' => $this->statusNextAction($status),
            'steps' => $steps,
        ];
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
        $currentSnapshot = $this->currentEligibilitySnapshot($application);

        if ($currentSnapshot !== null) {
            return $this->clamp((int) $currentSnapshot['score']);
        }

        if ($application->eligibility_score !== null) {
            return $this->clamp((int) round((float) $application->eligibility_score));
        }

        $breakdown = $application->eligibility_breakdown ?? [];

        return $this->clamp((int) ($breakdown['score'] ?? 60));
    }

    private function currentEligibilitySnapshot(ScholarshipApplication $application): ?array
    {
        $profile = $application->applicant?->studentProfile;
        $scholarship = $application->scholarship;

        if (! $profile || ! $scholarship) {
            return null;
        }

        $criteria = [];
        $passed = 0;
        $applicable = 0;

        $addCriterion = function (string $key, string $label, string $status, ?string $studentValue, ?string $requirement, string $note, bool $counts = true) use (&$criteria, &$passed, &$applicable): void {
            $criteria[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'student_value' => $studentValue,
                'requirement' => $requirement,
                'note' => $note,
            ];

            if (! $counts) {
                return;
            }

            $applicable++;

            if ($status === 'pass') {
                $passed++;
            }
        };

        $addOptionCriterion = function (string $key, string $label, ?string $studentValue, ?string $requirements, string $openNote) use ($addCriterion): void {
            $options = $this->splitOptions($requirements);

            if ($this->hasOpenOption($options)) {
                $addCriterion($key, $label, 'info', $studentValue, implode(', ', $options), $openNote, false);

                return;
            }

            if ($options === []) {
                $addCriterion($key, $label, 'info', $studentValue, null, "No {$label} restriction listed.", false);

                return;
            }

            $matches = filled($studentValue) && $this->matchesAnyOption($studentValue, $options);
            $addCriterion(
                $key,
                $label,
                filled($studentValue) ? ($matches ? 'pass' : 'fail') : 'missing',
                $studentValue,
                implode(', ', $options),
                $matches ? "{$label} matches this scholarship." : "{$label} needs reviewer confirmation.",
            );
        };

        $academicMatch = AcademicRequirement::match($profile->gwa, $profile->grading_scale, $scholarship->minimum_gwa, $scholarship->minimum_grade_scale);
        $addCriterion(
            'academic',
            $academicMatch['label'],
            $academicMatch['status'],
            $academicMatch['student_value'],
            $academicMatch['requirement'],
            $academicMatch['note'],
            $academicMatch['counts'],
        );

        $addOptionCriterion('education_level', 'Education level', $profile->education_level, $scholarship->eligible_education_levels, 'This scholarship is open to all education levels.');
        $addOptionCriterion('course', 'Track / strand / course', $profile->course_or_strand, $scholarship->eligible_courses, 'This scholarship accepts any track, strand, or course.');
        $addOptionCriterion('school_type', 'School type', $profile->school_type, $scholarship->eligible_school_types, 'This scholarship accepts any school type.');
        $addOptionCriterion('year_level', 'Grade / year level', $profile->year_level, $scholarship->eligible_year_levels, 'This scholarship accepts any grade or year level.');

        $studentLocation = collect([$profile->barangay, $profile->city, $profile->province, $profile->region, $profile->address])
            ->filter()
            ->implode(', ');
        $addOptionCriterion('location', 'Location', $studentLocation ?: null, $scholarship->eligible_locations, 'This scholarship is open nationwide or has no location restriction.');

        if (filled($scholarship->income_requirement) && ! $this->isOpenOption($scholarship->income_requirement)) {
            $income = $profile->income_bracket;
            $matchesIncome = filled($income) && $this->matchesAnyOption($income, [$scholarship->income_requirement]);
            $addCriterion(
                'income',
                'Income bracket',
                filled($income) ? ($matchesIncome ? 'pass' : 'fail') : 'missing',
                $income,
                $scholarship->income_requirement,
                $matchesIncome ? 'Income bracket matches the listed preference.' : 'Income bracket needs provider review.',
            );
        } else {
            $addCriterion('income', 'Income bracket', 'info', $profile->income_bracket, $scholarship->income_requirement, 'No income restriction listed.', false);
        }

        $score = $applicable === 0 ? 100 : (int) round(($passed / $applicable) * 100);

        return [
            'score' => $score,
            'passed' => $passed,
            'applicable' => $applicable,
            'label' => $score >= 80 ? 'Strong match' : ($score >= 50 ? 'Needs review' : 'Low match'),
            'summary' => $applicable === 0
                ? 'This scholarship has no structured matching rules yet.'
                : "{$passed} of {$applicable} structured criteria match the applicant profile.",
            'criteria' => $criteria,
        ];
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
            return 100;
        }

        if ($gwa === null) {
            return 45;
        }

        if ($minimumGwa === null) {
            return 100;
        }

        $studentGwa = (float) $gwa;
        $requiredGwa = (float) $minimumGwa;
        $studentScale = AcademicRequirement::normalizeScale($application->applicant?->studentProfile?->grading_scale, $gwa);
        $requiredScale = AcademicRequirement::normalizeScale($application->scholarship?->minimum_grade_scale, $minimumGwa);

        if ($studentGwa <= 0 || $requiredGwa <= 0) {
            return 60;
        }

        if ($studentScale !== null && $requiredScale !== null && $studentScale !== $requiredScale) {
            return 60;
        }

        if ($requiredScale === AcademicRequirement::SCALE_GRADE_POINT) {
            return $this->clamp((int) round(($requiredGwa / $studentGwa) * 100));
        }

        return $this->clamp((int) round(($studentGwa / $requiredGwa) * 100));
    }

    private function financialNeedScore(ScholarshipApplication $application): int
    {
        $income = strtolower((string) $application->applicant?->studentProfile?->income_bracket);
        $requirement = strtolower((string) $application->scholarship?->income_requirement);

        if ($requirement === '' || $this->isOpenOption($requirement)) {
            return 100;
        }

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

        return $this->matchesAnyOption($income, [$requirement])
            ? min(100, $score + 10)
            : max(25, $score - 20);
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
            'disbursed', 'renewed', 'distribution_scheduled', 'awarded' => 100,
            'approved' => 100,
            'exam_passed' => 97,
            'interview' => 95,
            'shortlisted' => 92,
            'qualified' => 90,
            'exam_taken' => 88,
            'exam_scheduled' => 84,
            'exam_qualified' => 80,
            'under_review' => 75,
            'rejected', 'not_awarded', 'exam_failed' => 10,
            default => 60,
        };
    }

    private function recommendation(int $score): array
    {
        if ($score >= 85) {
            return [
                'value' => 'highly_recommended',
                'label' => 'Strong match',
                'summary' => 'Strong suitability based on eligibility, academic information, and financial need.',
            ];
        }

        if ($score >= 70) {
            return [
                'value' => 'recommended',
                'label' => 'Potential match',
                'summary' => 'Good suitability, with some criteria still needing provider confirmation.',
            ];
        }

        if ($score >= 55) {
            return [
                'value' => 'needs_review',
                'label' => 'Needs review',
                'summary' => 'Some suitability criteria are incomplete or need manual confirmation.',
            ];
        }

        return [
            'value' => 'low_priority',
            'label' => 'Limited match',
            'summary' => 'Several current profile values do not match or are still missing.',
        ];
    }

    private function readinessLabel(int $score): string
    {
        return match (true) {
            $score >= 100 => 'Documents ready',
            $score >= 60 => 'Partly ready',
            default => 'Documents needed',
        };
    }

    private function explanationHeadline(string $recommendation, string $status): string
    {
        if (in_array($status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true)) {
            return 'Award outcome is already recorded.';
        }

        if (in_array($status, ['rejected', 'not_awarded', 'exam_failed'], true)) {
            return 'Application is closed; review notes explain the final decision.';
        }

        return match ($recommendation) {
            'highly_recommended' => 'Strong candidate based on current data.',
            'recommended' => 'Good candidate with a few items to confirm.',
            'needs_review' => 'Needs manual review before a decision.',
            'low_priority' => 'Lower priority until missing or weak items improve.',
            'not_recommended' => 'Not recommended based on current review data.',
            default => 'DSS reviewed the current application data.',
        };
    }

    private function recommendedNextAction(ScholarshipApplication $application, array $score, array $missingUploads, array $missingAccepted): string
    {
        if ($application->status === 'distribution_scheduled') {
            return 'Prepare the reward for the scheduled distribution date and keep instructions current.';
        }

        if (in_array($application->status, ['awarded', 'disbursed', 'renewed'], true)) {
            return 'Keep outcome details updated for renewal or reporting.';
        }

        if (in_array($application->status, ['rejected', 'not_awarded', 'exam_failed'], true)) {
            return 'No action is required unless the provider reopens the application.';
        }

        if ($application->status === 'exam_qualified') {
            return 'Provider should schedule the scholarship exam or share exam instructions.';
        }

        if ($application->status === 'exam_scheduled') {
            return 'Wait for the applicant to complete the scheduled scholarship exam.';
        }

        if ($application->status === 'exam_taken') {
            return 'Provider should record whether the applicant passed or failed the exam.';
        }

        if ($application->status === 'exam_passed') {
            return 'Provider can move this applicant to final award review.';
        }

        if ($missingUploads !== []) {
            return 'Applicant should upload missing requirements before final review.';
        }

        if ($missingAccepted !== []) {
            return 'Provider should review and accept or return the uploaded documents.';
        }

        if (($score['recommendation'] ?? '') === 'needs_review') {
            return 'Provider should review the flagged criteria and add a decision note.';
        }

        if ((int) ($score['score'] ?? 0) >= 80) {
            return 'Provider can prioritize this application for qualification or approval review.';
        }

        return 'Continue reviewing eligibility, documents, and provider notes.';
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'approved', 'awarded', 'disbursed', 'renewed', 'exam_passed' => 'success',
            'rejected', 'not_awarded', 'exam_failed' => 'danger',
            'under_review', 'qualified', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled' => 'info',
            default => 'warning',
        };
    }

    private function statusNextAction(string $status): string
    {
        return match ($status) {
            'submitted' => 'Waiting for the provider to start review.',
            'under_review' => 'Provider is checking eligibility, documents, and notes.',
            'qualified' => 'Application is qualified; wait for shortlist or approval.',
            'shortlisted' => 'Applicant may be contacted for the next screening step.',
            'interview' => 'Applicant should complete the interview or follow-up requirement.',
            'exam_qualified' => 'Initial screening is complete; wait for the exam schedule or instructions.',
            'exam_scheduled' => 'Take the scholarship exam as instructed by the provider.',
            'exam_taken' => 'Exam is recorded as taken; wait for the provider to post the result.',
            'exam_passed' => 'Exam passed; wait for final provider award review.',
            'exam_failed' => 'Application is closed after the exam result; check review notes for context.',
            'approved' => 'Provider can now schedule reward distribution.',
            'awarded' => 'Award is recorded; provider should set the distribution schedule.',
            'distribution_scheduled' => 'Reward distribution is scheduled; follow the provider instructions.',
            'disbursed' => 'Scholarship support has been released.',
            'renewed' => 'Scholarship renewal has been recorded.',
            'rejected', 'not_awarded' => 'Application is closed; check review notes for context.',
            default => 'Continue monitoring this application.',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'exam_qualified' => 'Qualified for exam',
            'exam_scheduled' => 'Exam scheduled',
            'exam_taken' => 'Exam taken',
            'exam_passed' => 'Passed exam',
            'exam_failed' => 'Failed exam',
            'distribution_scheduled' => 'Distribution scheduled',
            'disbursed' => 'Distributed',
            'for_exam' => 'Meets exam eligibility',
            'exam_completed' => 'Exam completed',
            'passed_exam' => 'Passed exam',
            'failed_exam' => 'Failed exam',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    private function containsRequirement(array $documents, string $requirement): bool
    {
        $normalizedRequirement = str($requirement)->lower()->squish()->toString();

        return collect($documents)->contains(function (string $document) use ($normalizedRequirement) {
            $normalizedDocument = str($document)->lower()->squish()->toString();

            return $normalizedDocument === $normalizedRequirement
                || str_contains($normalizedDocument, $normalizedRequirement)
                || str_contains($normalizedRequirement, $normalizedDocument);
        });
    }

    private function documentRequirements(?Scholarship $scholarship): array
    {
        if (! $scholarship?->requirements) {
            return [];
        }

        $requirements = $this->splitOptions($scholarship->requirements);

        return $this->hasOpenOption($requirements) ? [] : $requirements;
    }

    private function splitOptions(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $value))
            ->map(fn (string $option) => trim($option))
            ->filter()
            ->values()
            ->all();
    }

    private function matchesAnyOption(string $value, array $options): bool
    {
        $normalizedValue = str($value)->lower()->squish()->toString();

        if ($this->hasOpenOption($options)) {
            return true;
        }

        return collect($options)->contains(function (string $option) use ($normalizedValue) {
            $normalizedOption = str($option)->lower()->squish()->toString();

            return str_contains($normalizedValue, $normalizedOption) || str_contains($normalizedOption, $normalizedValue);
        });
    }

    private function hasOpenOption(array $options): bool
    {
        return collect($options)->contains(fn (string $option) => $this->isOpenOption($option));
    }

    private function isOpenOption(?string $option): bool
    {
        if (! filled($option)) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim(str_replace(['.', ';', ':'], '', $option))));

        return in_array($normalized, [
            'any',
            'all',
            'none',
            'n/a',
            'na',
            'not applicable',
            'no preference',
            'no restriction',
            'no restrictions',
            'open to all',
            'all students',
            'any student',
            'all applicants',
            'any applicant',
            'all education levels',
            'any education level',
            'all levels',
            'any level',
            'all courses',
            'any course',
            'all strands',
            'any strand',
            'all tracks',
            'any track',
            'all grades',
            'any grade',
            'all years',
            'any year',
            'all school types',
            'any school type',
            'all locations',
            'any location',
            'all regions',
            'any region',
            'nationwide',
            'philippines',
            'the philippines',
            'republic of the philippines',
            'nationwide philippines',
            'philippines nationwide',
            'anywhere in the philippines',
            'within the philippines',
            'all over the philippines',
            'all philippines',
            'no income requirement',
        ], true)
            || str_starts_with($normalized, 'any ')
            || str_starts_with($normalized, 'all ')
            || str_contains($normalized, 'n/a')
            || str_contains($normalized, 'open to all')
            || str_contains($normalized, 'no restriction')
            || str_contains($normalized, 'no preference')
            || str_contains($normalized, 'not applicable')
            || (str_contains($normalized, 'nationwide') && ! str_contains($normalized, 'not nationwide'))
            || str_contains($normalized, 'anywhere in the philippines')
            || str_contains($normalized, 'within the philippines')
            || str_contains($normalized, 'all over the philippines');
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
