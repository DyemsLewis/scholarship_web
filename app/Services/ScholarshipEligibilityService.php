<?php

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\StudentDocument;
use App\Models\User;
use App\Support\AcademicRequirement;

class ScholarshipEligibilityService
{
    public function evaluate(Scholarship $scholarship, ?User $user): array
    {
        $profile = $user?->studentProfile;
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

        $academicMatch = AcademicRequirement::match($profile?->gwa, $profile?->grading_scale, $scholarship->minimum_gwa, $scholarship->minimum_grade_scale);
        $addCriterion(
            'academic',
            $academicMatch['label'],
            $academicMatch['status'],
            $academicMatch['student_value'],
            $academicMatch['requirement'],
            $academicMatch['note'],
            $academicMatch['counts'],
        );

        $this->addOptionCriterion(
            $addCriterion,
            'education_level',
            'Education level',
            $profile?->education_level,
            $scholarship->eligible_education_levels,
            'This program is open to all education levels.',
            'Your education level matches this program.',
            'Confirm if your education level is eligible.',
            'No education level restriction listed.',
        );
        $this->addOptionCriterion(
            $addCriterion,
            'course',
            'Track / strand / course',
            $profile?->course_or_strand,
            $scholarship->eligible_courses,
            'This program accepts any track, strand, or course.',
            'Your track, strand, or course matches the listed target.',
            'Check if your grade-level track, strand, or course is accepted by the provider.',
            'No track, strand, or course restriction listed.',
        );
        $this->addOptionCriterion(
            $addCriterion,
            'school_type',
            'School type',
            $profile?->school_type,
            $scholarship->eligible_school_types,
            'This program accepts any school type.',
            'Your school type matches this program.',
            'Confirm if your school type is eligible.',
            'No school type restriction listed.',
        );
        $this->addOptionCriterion(
            $addCriterion,
            'year_level',
            'Grade / year level',
            $profile?->year_level,
            $scholarship->eligible_year_levels,
            'This program accepts any grade or year level.',
            'Your grade or year level matches this program.',
            'Confirm if your grade or year level is eligible.',
            'No grade or year level restriction listed.',
        );

        $studentLocation = collect([
            $profile?->barangay,
            $profile?->city,
            $profile?->province,
            $profile?->region,
            $profile?->address,
        ])->filter()->implode(', ');
        $this->addOptionCriterion(
            $addCriterion,
            'location',
            'Location',
            $studentLocation ?: null,
            $scholarship->eligible_locations,
            'This program is open to all listed locations.',
            'Your location matches the scholarship coverage.',
            'Your location may be outside the listed coverage.',
            'No location restriction listed.',
        );

        if (filled($scholarship->income_requirement) && ! $this->isOpenOption($scholarship->income_requirement)) {
            $income = $profile?->income_bracket;
            $matchesIncome = filled($income) && $this->matchesAnyOption($income, [$scholarship->income_requirement]);
            $addCriterion(
                'income',
                'Income bracket',
                filled($income) ? ($matchesIncome ? 'pass' : 'fail') : 'missing',
                $income,
                $scholarship->income_requirement,
                $matchesIncome ? 'Your income bracket matches the listed preference.' : 'Review the income requirement before applying.',
            );
        } else {
            $addCriterion('income', 'Income bracket', 'info', $profile?->income_bracket, $scholarship->income_requirement, 'No income restriction listed.', false);
        }

        $documentReadiness = $this->preparedDocumentReadiness($scholarship, $user);
        if ($documentReadiness['required'] > 0) {
            $documentsReady = $documentReadiness['uploaded'] >= $documentReadiness['required'];
            $addCriterion(
                'documents',
                'Prepared documents',
                $documentsReady ? 'pass' : 'missing',
                "{$documentReadiness['uploaded']} of {$documentReadiness['required']} uploaded",
                implode(', ', $documentReadiness['required_documents']),
                $documentsReady
                    ? 'Your document library already covers this program requirement.'
                    : 'Upload matching documents in Documents to improve readiness before applying.',
            );
        } else {
            $addCriterion('documents', 'Prepared documents', 'info', null, null, 'No document requirements listed.', false);
        }

        $score = $applicable === 0 ? 100 : (int) round(($passed / $applicable) * 100);
        $blockingCriteria = $this->blockers(['criteria' => $criteria]);

        return [
            'score' => $score,
            'passed' => $passed,
            'applicable' => $applicable,
            'is_eligible' => $blockingCriteria === [],
            'blocking_criteria' => $blockingCriteria,
            'label' => $score >= 80 ? 'Strong match' : ($score >= 50 ? 'Needs review' : 'Low match'),
            'summary' => $applicable === 0
                ? 'This scholarship has no structured matching rules yet.'
                : "{$passed} of {$applicable} structured criteria match your profile.",
            'criteria' => $criteria,
        ];
    }

    public function blockers(array $eligibilityMatch): array
    {
        return collect($eligibilityMatch['criteria'] ?? [])
            ->filter(fn (array $criterion) => ($criterion['status'] ?? null) === 'fail' && ($criterion['key'] ?? null) !== 'documents')
            ->map(fn (array $criterion) => [
                'key' => $criterion['key'] ?? null,
                'label' => $criterion['label'] ?? 'Eligibility requirement',
                'student_value' => $criterion['student_value'] ?? null,
                'requirement' => $criterion['requirement'] ?? null,
                'note' => $criterion['note'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function applicationDocumentReadiness(ScholarshipApplication $application): array
    {
        $requiredDocuments = $this->documentRequirements($application->scholarship);
        $confirmedDocuments = collect($application->document_checklist ?? [])
            ->map(fn (string $document) => trim($document))
            ->filter()
            ->values();
        $requiredCount = count($requiredDocuments);
        $confirmedRequiredCount = collect($requiredDocuments)
            ->filter(fn (string $document) => $confirmedDocuments->contains($document))
            ->count();
        $uploadedDocuments = $application->documents
            ->map(fn (ApplicationDocument $document) => $document->document_name)
            ->values();
        $uploadedRequiredCount = collect($requiredDocuments)
            ->filter(fn (string $document) => $uploadedDocuments->contains($document))
            ->count();
        $acceptedRequiredCount = $application->documents
            ->filter(fn (ApplicationDocument $document) => $document->status === 'accepted' && collect($requiredDocuments)->contains($document->document_name))
            ->count();

        return [
            'required' => $requiredCount,
            'confirmed' => $confirmedRequiredCount,
            'percent' => $requiredCount === 0 ? 100 : (int) round(($confirmedRequiredCount / $requiredCount) * 100),
            'uploaded' => $uploadedRequiredCount,
            'uploaded_percent' => $requiredCount === 0 ? 100 : (int) round(($uploadedRequiredCount / $requiredCount) * 100),
            'accepted' => $acceptedRequiredCount,
            'accepted_percent' => $requiredCount === 0 ? 100 : (int) round(($acceptedRequiredCount / $requiredCount) * 100),
            'missing' => collect($requiredDocuments)
                ->reject(fn (string $document) => $confirmedDocuments->contains($document))
                ->values()
                ->all(),
        ];
    }

    public function preparedDocumentReadiness(Scholarship $scholarship, ?User $user): array
    {
        $requiredDocuments = $this->documentRequirements($scholarship);

        if (! $user) {
            return [
                'required' => count($requiredDocuments),
                'uploaded' => 0,
                'percent' => $requiredDocuments === [] ? 100 : 0,
                'required_documents' => $requiredDocuments,
                'matched' => [],
                'missing' => $requiredDocuments,
            ];
        }

        $user->loadMissing('studentDocuments');
        $preparedNames = $user->studentDocuments
            ->map(fn (StudentDocument $document) => $document->document_name)
            ->values();
        $matched = collect($requiredDocuments)
            ->filter(fn (string $requirement) => $preparedNames->contains($requirement))
            ->values()
            ->all();
        $missing = collect($requiredDocuments)
            ->reject(fn (string $requirement) => in_array($requirement, $matched, true))
            ->values()
            ->all();
        $requiredCount = count($requiredDocuments);
        $uploadedCount = count($matched);

        return [
            'required' => $requiredCount,
            'uploaded' => $uploadedCount,
            'percent' => $requiredCount === 0 ? 100 : (int) round(($uploadedCount / $requiredCount) * 100),
            'required_documents' => $requiredDocuments,
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    public function documentRequirements(?Scholarship $scholarship): array
    {
        return $this->splitDocumentRequirements($scholarship?->requirements);
    }

    public function splitDocumentRequirements(?string $requirements): array
    {
        $requirements = $this->splitOptions($requirements);

        return $this->hasOpenOption($requirements) ? [] : $requirements;
    }

    public function splitOptions(?string $value): array
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

    public function matchesAnyOption(string $value, array $options): bool
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

    public function hasOpenOption(array $options): bool
    {
        return collect($options)->contains(fn (string $option) => $this->isOpenOption($option));
    }

    public function isOpenOption(?string $option): bool
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

    private function addOptionCriterion(
        callable $addCriterion,
        string $key,
        string $label,
        ?string $studentValue,
        ?string $requirements,
        string $openNote,
        string $matchNote,
        string $mismatchNote,
        string $unrestrictedNote,
    ): void {
        $options = $this->splitOptions($requirements);

        if ($this->hasOpenOption($options)) {
            $addCriterion($key, $label, 'info', $studentValue, implode(', ', $options), $openNote, false);

            return;
        }

        if ($options === []) {
            $addCriterion($key, $label, 'info', $studentValue, null, $unrestrictedNote, false);

            return;
        }

        $matches = filled($studentValue) && $this->matchesAnyOption($studentValue, $options);
        $addCriterion(
            $key,
            $label,
            filled($studentValue) ? ($matches ? 'pass' : 'fail') : 'missing',
            $studentValue,
            implode(', ', $options),
            $matches ? $matchNote : $mismatchNote,
        );
    }
}
