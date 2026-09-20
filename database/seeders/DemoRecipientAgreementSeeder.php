<?php

namespace Database\Seeders;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoRecipientAgreementSeeder extends Seeder
{
    public function run(): void
    {
        $provider = User::query()
            ->where('email', env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'))
            ->first();

        if (! $provider) {
            $this->command?->warn('Tulay Aral demo provider is missing. Recipient agreement demo was not seeded.');

            return;
        }

        DB::transaction(function () use ($provider): void {
            $applicant = $this->seedApplicant();
            $scholarship = $this->seedScholarship($provider);
            $application = $this->seedSelectedApplication($provider, $applicant, $scholarship);

            PortalNotification::query()->updateOrCreate([
                'deduplication_key' => 'demo-recipient-agreement-'.$application->id,
            ], [
                'user_id' => $applicant->id,
                'type' => 'application_outcome',
                'title' => 'Application outcome: Selected',
                'message' => "You were selected for {$scholarship->title}. Review the recipient agreement in your application.",
                'action_url' => route('dashboard.applications.show', $application, false),
                'read_at' => null,
            ]);
        });

        $this->command?->info('Recipient agreement demo is ready for the Tulay Aral provider and recipient demo applicant.');
    }

    private function seedApplicant(): User
    {
        $password = env('AGREEMENT_DEMO_PASSWORD', env('DEMO_PASSWORD', 'password123'));
        $applicant = User::query()->updateOrCreate([
            'email' => env('AGREEMENT_DEMO_EMAIL', 'recipientdemo@scholarship.test'),
        ], [
            'username' => env('AGREEMENT_DEMO_USERNAME', 'recipientdemo'),
            'role' => 'applicant',
            'password' => $password,
            'account_status' => 'active',
            'must_reset_password' => false,
            'password_reset_required_at' => null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);
        $applicant->forceFill(['email_verified_at' => now()])->save();
        $applicant->studentProfile()->updateOrCreate([
            'user_id' => $applicant->id,
        ], [
            'first_name' => 'Sofia',
            'last_name' => 'Navarro',
            'middle_initial' => 'D',
            'gender' => 'female',
            'contact_number' => '09175550001',
            'account_managed_by' => 'learner',
            'citizenship_status' => 'filipino',
            'education_level' => 'senior_high_school',
            'school' => 'Antipolo City Senior High School',
            'school_type' => 'public',
            'learner_reference_number' => '987654321012',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'enrollment_status' => 'Enrolled',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => 91,
            'grading_scale' => 'percentage',
            'income_bracket' => 'PHP 10,000 - 20,000',
            'household_size' => 5,
            'current_scholarship_status' => 'none',
            'current_scholarship_details' => null,
            'scholarship_goal' => 'Maintain strong grades and prepare for a college program in science or technology.',
            'achievements' => 'Consistent honor student and school science fair participant.',
            'activities_and_responsibilities' => 'Science club member who also helps care for younger siblings.',
            'address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'barangay' => 'San Isidro',
            'city' => 'Antipolo City',
            'province' => 'Rizal',
            'region' => 'CALABARZON',
            'latitude' => 14.6255000,
            'longitude' => 121.1245000,
            'birthdate' => '2008-04-18',
            'guardian_name' => 'Elena Navarro',
            'guardian_relationship' => 'Parent',
            'guardian_contact' => '09175550002',
            'guardian_email' => 'elena.navarro@scholarship.test',
            'guardian_is_account_owner' => false,
            'verification_status' => 'approved',
            'verification_notes' => 'Fictional verified profile for the recipient agreement demonstration.',
            'verified_at' => now(),
        ]);

        return $applicant->fresh(['studentProfile']);
    }

    private function seedScholarship(User $provider): Scholarship
    {
        $deadline = now()->addDays(45)->startOfDay();
        $scholarship = Scholarship::query()->updateOrCreate([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Continuing Scholar Grant',
        ], [
            'image_path' => '/images/programs/tulay-aral-logo.png',
            'category' => 'Financial assistance',
            'program_cycle' => 'School Year '.$deadline->year.'-'.($deadline->year + 1),
            'description' => 'A continuing learner support package with an allowance, mentoring, and a clear recipient agreement for the current school year.',
            'provider_objectives' => ['education_access', 'community_development'],
            'provider_objective_notes' => 'The program supports school continuity and allows the provider to follow the progress of selected learners.',
            'eligibility' => 'Enrolled Grade 12 learner with at least an 85% general average and household income within the listed bracket.',
            'eligible_education_levels' => 'senior_high_school',
            'eligible_courses' => 'STEM',
            'eligible_school_types' => "public\nprivate",
            'eligible_year_levels' => 'Grade 12',
            'eligible_locations' => null,
            'income_requirement' => 'PHP 10,000 - 20,000',
            'exclude_current_scholarship_recipients' => true,
            'location_name' => 'Tulay Aral Community Desk',
            'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'latitude' => 14.6255000,
            'longitude' => 121.1245000,
            'requirements' => null,
            'optional_requirements' => null,
            'post_qualification_requirements' => "Original certificate of enrollment\nOriginal latest report card\nRecent school ID",
            'handoff_mode' => 'onsite',
            'handoff_instructions' => 'Bring the listed originals for provider verification and orientation before support begins.',
            'handoff_deadline' => $deadline->copy()->addDays(10)->toDateString(),
            'handoff_location_name' => 'Tulay Aral Community Desk',
            'handoff_location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'review_rubric' => ReviewRubric::DEFAULT,
            'award_amount' => 12000,
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
            'slots_available' => 20,
            'application_mode' => 'provider_review',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'renewal_policy' => 'Support covers the current school year. Renewal requires a new provider review and available funding.',
            'return_service_contract' => null,
            'other_contract_terms' => 'Recipients attend orientation and provide one short academic progress update each semester.',
            'recipient_agreement' => [
                'commitment_type' => 'reporting',
                'duration' => 'One academic progress update at the end of each semester during the support period.',
                'noncompliance_consequence' => 'The provider contacts the recipient and reviews the circumstances before holding any unreleased support.',
                'exit_or_exception_process' => 'The recipient may contact the Community Scholarship Desk to report academic, health, transfer, or family circumstances and request an adjusted arrangement.',
            ],
            'contact_email' => 'tulayaral@scholarship.test',
            'contact_number' => '09171234567',
            'contact_person' => 'Mara L. Reyes',
            'contact_department' => 'Community Scholarship Desk',
            'application_opens_at' => now()->startOfDay()->toDateString(),
            'expected_results_at' => $deadline->copy()->addDays(7)->toDateString(),
            'support_starts_at' => $deadline->copy()->addDays(30)->toDateString(),
            'support_ends_at' => $deadline->copy()->addYear()->toDateString(),
            'deadline' => $deadline->toDateString(),
            'status' => 'published',
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
        ]);

        $scholarship->benefits()->delete();
        $scholarship->benefits()->createMany([
            [
                'type' => 'allowance',
                'title' => 'Learning allowance',
                'amount' => 12000,
                'frequency' => 'per_term',
                'duration' => 'Current school year',
                'description' => 'Support for transportation, learning materials, and connectivity.',
                'sort_order' => 1,
            ],
            [
                'type' => 'mentorship',
                'title' => 'Academic mentoring',
                'frequency' => 'per_term',
                'duration' => 'Current school year',
                'description' => 'Short provider check-ins focused on school continuity and college preparation.',
                'sort_order' => 2,
            ],
        ]);

        return $scholarship->fresh(['provider.providerProfile', 'benefits']);
    }

    private function seedSelectedApplication(User $provider, User $applicant, Scholarship $scholarship): ScholarshipApplication
    {
        $eligibility = app(ScholarshipEligibilityService::class)->evaluate($scholarship, $applicant);
        $scores = collect(ReviewRubric::DEFAULT)->mapWithKeys(fn (array $criterion): array => [
            $criterion['key'] => 92,
        ])->all();
        $rubric = ReviewRubric::result(ReviewRubric::DEFAULT, $scores);
        $application = ScholarshipApplication::query()->updateOrCreate([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
        ], [
            'status' => 'under_review',
            'application_state' => 'submitted',
            'workflow_stage' => 'screening',
            'final_outcome' => null,
            'document_checklist' => [],
            'optional_document_checklist' => [],
            'eligibility_score' => $eligibility['score'],
            'eligibility_breakdown' => $eligibility,
            'review_rubric_snapshot' => ReviewRubric::DEFAULT,
            'rubric_scores' => $scores,
            'rubric_total_score' => $rubric['total_score'],
            'rubric_scored_by' => $provider->id,
            'rubric_scored_at' => now()->subDay(),
            'review_notes' => 'Profile, eligibility, and formal provider requirements were reviewed for the demo.',
            'assigned_reviewer_id' => $provider->id,
            'reviewed_by' => $provider->id,
            'reviewed_at' => now()->subDay(),
            'submitted_at' => now()->subDays(5),
            'terms_accepted_at' => now()->subDays(5),
            'terms_version' => Terms::VERSION,
            'provider_contract_terms_snapshot' => null,
            'provider_contract_terms_accepted_at' => null,
            'provider_contract_terms_version' => null,
            'provider_contract_acceptance_ip' => null,
            'provider_contract_acceptance_user_agent' => null,
            'student_response_status' => null,
            'student_responded_at' => null,
            'student_response_terms_accepted_at' => null,
            'student_response_terms_version' => null,
            'student_response_note' => null,
        ]);
        $application->schedules()->delete();
        $application->stageProgresses()->delete();
        $application->statusHistories()->delete();

        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application->fresh());
        $application = $workflow->recordStageResult(
            $application,
            'screening',
            'passed',
            $provider,
            'The applicant profile meets the published requirements.',
            'passed_prescreening',
        );
        $application = $workflow->recordStageResult(
            $application,
            'formal_application',
            'passed',
            $provider,
            'Original records and provider requirements were reviewed for the demonstration.',
        );
        $application = $workflow->recordFinalOutcome(
            $application,
            'selected',
            $provider,
            'Selected for the continuing scholar support package.',
        );
        app(DecisionSupportService::class)->syncApplication(
            $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']),
            'demo_recipient_agreement_seed',
        );

        return $application->fresh();
    }
}
