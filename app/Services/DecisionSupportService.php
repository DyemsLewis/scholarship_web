<?php

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\DssCalculationSnapshot;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Support\AcademicRequirement;
use App\Support\ScholarshipSelectionPlan;

class DecisionSupportService
{
    public const METHODOLOGY_VERSION = '2.1';

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

    public function syncApplication(ScholarshipApplication $application, string $source = 'system'): array
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
        $this->captureSnapshot($application->fresh(), $score, $eligibilitySnapshot, $source);

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
        $application->loadMissing(['statusHistories', 'scholarship']);

        $status = $application->status ?: 'submitted';
        $selectionStages = ScholarshipSelectionPlan::normalize($application->scholarship?->selection_stages);
        $flow = collect([[
            'key' => 'submitted',
            'label' => 'Submitted',
            'description' => 'Application received',
        ]])->concat(collect($selectionStages)->map(fn (string $stage): array => [
            'key' => $stage,
            'label' => match ($stage) {
                'screening' => 'Screening',
                'exam' => 'Exam',
                'interview' => 'Interview',
                'distribution' => 'Reward distribution',
                default => str($stage)->headline()->toString(),
            },
            'description' => match ($stage) {
                'screening' => 'Eligibility and file review',
            'exam' => 'Provider-managed exam',
                'interview' => 'Provider conversation',
                'distribution' => 'Scholarship release',
                default => 'Provider-managed stage',
            },
        ]))->values();
        $currentStage = $this->progressStage($application, $selectionStages);
        $stageIndex = $flow->search(fn (array $step): bool => $step['key'] === $currentStage);
        $stageIndex = $stageIndex === false ? 0 : $stageIndex;
        $isClosedWithoutAward = in_array($status, ['rejected', 'not_awarded', 'exam_failed'], true);
        $isComplete = in_array($status, ['disbursed', 'renewed'], true);
        $steps = $flow
            ->map(function (array $step, int $index) use ($stageIndex, $isClosedWithoutAward, $isComplete): array {
                return [
                    ...$step,
                    'state' => match (true) {
                        $isComplete => 'complete',
                        $isClosedWithoutAward && $index > $stageIndex => 'skipped',
                        $isClosedWithoutAward && $index === $stageIndex => 'stopped',
                        $index < $stageIndex => 'complete',
                        $index === $stageIndex => 'current',
                        default => 'upcoming',
                    },
                ];
            })
            ->values()
            ->all();
        $completedSteps = collect($steps)->where('state', 'complete')->count();
        $currentStageLabel = collect($steps)->firstWhere('key', $currentStage)['label'] ?? 'Submitted';

        return [
            'current' => $status,
            'label' => $this->statusLabel($status),
            'current_stage' => $currentStage,
            'current_stage_label' => $currentStageLabel,
            'configured_stages' => $selectionStages,
            'completed_steps' => $completedSteps,
            'total_steps' => count($steps),
            'percent' => (int) round(($completedSteps / max(count($steps), 1)) * 100),
            'tone' => $this->statusTone($status),
            'next_action' => $this->statusNextAction($status, $selectionStages),
            'steps' => $steps,
        ];
    }

    private function progressStage(ScholarshipApplication $application, array $selectionStages): string
    {
        $status = $application->status ?: 'submitted';

        if ($status === 'rejected') {
            $rejectionHistory = $application->statusHistories
                ->sortByDesc('changed_at')
                ->first(fn ($history): bool => $history->to_status === 'rejected');
            $stage = $this->stageForStatus($rejectionHistory?->from_status ?? 'under_review');

            return in_array($stage, $selectionStages, true) ? $stage : 'screening';
        }

        if ($status === 'not_awarded') {
            return collect($selectionStages)->reject(fn (string $stage): bool => $stage === 'distribution')->last()
                ?? 'screening';
        }

        $stage = $this->stageForStatus($status);

        if ($stage === 'submitted' || in_array($stage, $selectionStages, true)) {
            return $stage;
        }

        return collect($selectionStages)->reject(fn (string $candidate): bool => $candidate === 'distribution')->last()
            ?? 'screening';
    }

    private function stageForStatus(?string $status): string
    {
        return match ($status) {
            null, '', 'submitted' => 'submitted',
            'under_review', 'qualified', 'shortlisted', 'other' => 'screening',
            'exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed', 'exam_failed' => 'exam',
            'interview' => 'interview',
            'approved', 'awarded', 'distribution_scheduled', 'disbursed', 'renewed' => 'distribution',
            default => 'screening',
        };
    }

    private function captureSnapshot(
        ScholarshipApplication $application,
        array $score,
        ?array $eligibilitySnapshot,
        string $source,
    ): void {
        $application->loadMissing(['applicant.studentProfile', 'documents', 'scholarship']);
        $profile = $application->applicant?->studentProfile;
        $scholarship = $application->scholarship;

        if (! $profile || ! $scholarship) {
            return;
        }

        $applicantInputs = [
            'profile_updated_at' => $profile->updated_at?->toISOString(),
            'education_level' => $profile->education_level,
            'school_type' => $profile->school_type,
            'course_or_strand' => $profile->course_or_strand,
            'year_level' => $profile->year_level,
            'gwa' => $profile->gwa,
            'grading_scale' => AcademicRequirement::normalizeScale($profile->grading_scale, $profile->gwa),
            'income_bracket' => $profile->income_bracket,
            'barangay' => $profile->barangay,
            'city' => $profile->city,
            'province' => $profile->province,
            'region' => $profile->region,
        ];
        $scholarshipInputs = [
            'scholarship_updated_at' => $scholarship->updated_at?->toISOString(),
            'status' => $scholarship->status,
            'deadline' => $scholarship->deadline?->toDateString(),
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_courses' => $scholarship->eligible_courses,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
            'requirements' => $scholarship->requirements,
        ];
        $documentInputs = $application->documents
            ->sortBy('id')
            ->map(fn (ApplicationDocument $document): array => [
                'id' => $document->id,
                'name' => $document->document_name,
                'status' => $document->status,
                'updated_at' => $document->updated_at?->toISOString(),
            ])
            ->values()
            ->all();
        $eligibility = $eligibilitySnapshot ?? $application->eligibility_breakdown ?? [];
        $academicEvaluation = collect($eligibility['criteria'] ?? [])->firstWhere('key', 'academic');
        $fingerprint = hash('sha256', json_encode([
            'methodology_version' => self::METHODOLOGY_VERSION,
            'application_status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'documents' => $documentInputs,
            'applicant' => $applicantInputs,
            'scholarship' => $scholarshipInputs,
            'eligibility' => $eligibility,
            'score' => $score,
        ], JSON_THROW_ON_ERROR));

        DssCalculationSnapshot::query()->firstOrCreate([
            'scholarship_application_id' => $application->id,
            'input_hash' => $fingerprint,
        ], [
            'applicant_id' => $application->applicant_id,
            'scholarship_id' => $application->scholarship_id,
            'methodology_version' => self::METHODOLOGY_VERSION,
            'source' => $source,
            'eligibility_score' => $eligibility['score'] ?? $application->eligibility_score,
            'suitability_score' => $score['score'] ?? $application->dss_score,
            'recommendation' => $score['recommendation'] ?? $application->dss_recommendation,
            'eligibility_breakdown' => $eligibility,
            'dss_breakdown' => $score,
            'applicant_inputs' => $applicantInputs,
            'scholarship_inputs' => $scholarshipInputs,
            'academic_evaluation' => $academicEvaluation,
            'calculated_at' => now(),
        ]);
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

        $addCriterion = function (
            string $key,
            string $label,
            string $status,
            ?string $studentValue,
            ?string $requirement,
            string $note,
            bool $counts = true,
            array $metadata = [],
        ) use (&$criteria, &$passed, &$applicable): void {
            $criteria[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'student_value' => $studentValue,
                'requirement' => $requirement,
                'note' => $note,
                ...$metadata,
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
            [
                'student_scale' => $academicMatch['student_scale'],
                'requirement_scale' => $academicMatch['requirement_scale'],
                'comparison_mode' => $academicMatch['comparison_mode'],
                'is_comparable' => $academicMatch['is_comparable'],
            ],
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
        $studentScale = AcademicRequirement::normalizeScale($application->applicant?->studentProfile?->grading_scale, $gwa);
        $requiredScale = AcademicRequirement::normalizeScale($application->scholarship?->minimum_grade_scale, $minimumGwa);

        if (in_array($requiredScale, [AcademicRequirement::SCALE_PASS_FAIL, AcademicRequirement::SCALE_OTHER], true)) {
            return 60;
        }

        if ($gwa === null && $minimumGwa === null) {
            return 100;
        }

        if (in_array($studentScale, [AcademicRequirement::SCALE_PASS_FAIL, AcademicRequirement::SCALE_OTHER], true)
            && AcademicRequirement::requiresNumeric($requiredScale)) {
            return 60;
        }

        if ($gwa === null) {
            return 45;
        }

        if ($minimumGwa === null) {
            return 100;
        }

        $studentGwa = (float) $gwa;
        $requiredGwa = (float) $minimumGwa;

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

    private function statusNextAction(string $status, array $selectionStages = []): string
    {
        $nextAfterScreening = $this->nextStageLabel('screening', $selectionStages);
        $nextAfterExam = $this->nextStageLabel('exam', $selectionStages);

        return match ($status) {
            'submitted' => 'Your application is waiting for the provider to check eligibility and required files.',
            'under_review', 'qualified', 'shortlisted', 'other' => "The provider is reviewing your application. If approved, you will move to {$nextAfterScreening}.",
            'interview' => 'Follow the shared interview details when posted. The provider will approve or reject after review.',
            'exam_qualified' => 'You passed screening. Wait for the provider to post the shared exam schedule.',
            'exam_scheduled' => 'Review and confirm the posted exam schedule, then follow its instructions.',
            'exam_taken' => "Your exam participation is recorded. Wait for the provider decision for {$nextAfterExam}.",
            'exam_passed' => "You passed the exam. Wait for the provider decision for {$nextAfterExam}.",
            'exam_failed' => 'This application did not advance after the exam. Check the provider note for context.',
            'approved' => 'You passed the configured selection stages. Wait for the shared reward distribution schedule.',
            'awarded' => 'Your award is recorded. Wait for the provider to post reward distribution details.',
            'distribution_scheduled' => 'Reward distribution is scheduled; follow the provider instructions.',
            'disbursed' => 'Scholarship support has been released.',
            'renewed' => 'Scholarship renewal has been recorded.',
            'rejected', 'not_awarded' => 'This application did not advance. Check the provider note for the decision.',
            default => 'Continue monitoring this application.',
        };
    }

    private function nextStageLabel(string $currentStage, array $selectionStages): string
    {
        $stages = ScholarshipSelectionPlan::normalize($selectionStages);
        $currentIndex = array_search($currentStage, $stages, true);
        $nextStage = $currentIndex === false ? null : ($stages[$currentIndex + 1] ?? null);

        return match ($nextStage) {
            'exam' => 'the exam stage',
            'interview' => 'the interview stage',
            'distribution' => 'final approval and reward distribution',
            default => 'the next provider review',
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
