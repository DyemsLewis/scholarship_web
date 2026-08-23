<?php

namespace App\Services;

use App\Models\Scholarship;
use App\Support\ScholarshipSelectionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ScholarshipPublicationGuard
{
    public function assertPublishable(Scholarship $scholarship): void
    {
        $scholarship->loadMissing(['provider.providerProfile', 'benefits']);

        $provider = $scholarship->provider;
        $errors = [];

        if (! $provider?->isProvider() || ! $provider->isActive()) {
            $errors['status'] = 'The provider account must be active before this program can be published.';
        } elseif (! $provider->hasVerifiedEmail()) {
            $errors['status'] = 'The provider must verify its email address before this program can be published.';
        } elseif (! $provider->providerProfile?->isVerified()) {
            $errors['status'] = 'The provider must be approved before this program can be published.';
        } elseif (! $provider->providerVerificationDocuments()->where('status', 'approved')->exists()) {
            $errors['status'] = 'The provider must have reviewed verification proof before this program can be published.';
        }

        if (blank($scholarship->title)) {
            $errors['title'] = 'Add a program title before publication.';
        }

        if (blank($scholarship->description)) {
            $errors['description'] = 'Add a program description before publication.';
        }

        if (! $scholarship->provider_terms_accepted_at) {
            $errors['terms_accepted'] = 'The provider must accept the program terms before publication.';
        }

        if (! $scholarship->deadline) {
            $errors['deadline'] = 'Add an application deadline before publishing the program.';
        } elseif ($scholarship->deadline->isBefore(CarbonImmutable::today())) {
            $errors['deadline'] = 'The application deadline cannot be in the past.';
        }

        if (blank($scholarship->category)) {
            $errors['category'] = 'Choose a scholarship category before publication.';
        }

        if ($scholarship->benefitPayload() === []) {
            $errors['benefits'] = 'Add at least one program benefit before publication.';
        }

        if (blank($scholarship->application_mode)) {
            $errors['application_mode'] = 'Choose a verification method before publication.';
        }

        if (blank($scholarship->eligibility)) {
            $errors['eligibility'] = 'Describe who is eligible before publication.';
        }

        if (! $this->hasFinderRule($scholarship)) {
            $errors['eligible_education_levels'] = 'Add at least one applicant matching rule before publication.';
        }

        if ($scholarship->application_mode !== 'provider_review' && blank($scholarship->requirements)) {
            $errors['requirements'] = 'Add at least one required applicant document before publication.';
        }

        foreach ([
            'location_name' => 'Add the program location name before publication.',
            'location_address' => 'Add the program address before publication.',
            'latitude' => 'Set the program location pin before publication.',
            'longitude' => 'Set the program location pin before publication.',
        ] as $field => $message) {
            if (blank($scholarship->{$field})) {
                $errors[$field] = $message;
            }
        }

        if (blank($scholarship->contact_email) && blank($scholarship->contact_number)) {
            $errors['contact_email'] = 'Add an email address or contact number before publication.';
        }

        $selectionStages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);

        if (in_array('exam', $selectionStages, true)) {
            if (blank($scholarship->exam_duration_minutes)) {
                $errors['exam_duration_minutes'] = 'Add the exam duration before publication.';
            }

            if (blank($scholarship->exam_passing_score)) {
                $errors['exam_passing_score'] = 'Add the exam passing score before publication.';
            }
        }

        if (blank($scholarship->review_rubric)) {
            $errors['review_rubric'] = 'Add at least one review criterion before publication.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasFinderRule(Scholarship $scholarship): bool
    {
        return collect([
            $scholarship->eligible_education_levels,
            $scholarship->eligible_courses,
            $scholarship->eligible_school_types,
            $scholarship->eligible_year_levels,
            $scholarship->eligible_locations,
            $scholarship->minimum_gwa,
        ])->contains(fn ($field): bool => filled($field))
            || ($scholarship->income_requirement !== null && $scholarship->income_requirement !== 'Any')
            || in_array($scholarship->minimum_grade_scale, ['pass_fail', 'other'], true);
    }
}
