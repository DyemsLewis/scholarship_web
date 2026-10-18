<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantScholarshipDetailContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_contract_groups_the_page_into_a_single_decision_and_five_sections(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'award_amount' => 15000,
            'eligibility' => 'Applicant must remain enrolled.',
            'requirements' => "Recent school ID\nAcademic record",
            'selection_stages' => ['screening', 'formal_application', 'interview', 'decision'],
            'renewal_policy' => 'Reviewed after each school year.',
            'recipient_agreement' => [
                'responsibilities' => 'Remain enrolled and submit progress records.',
                'release_conditions' => 'Complete identity verification before the first release.',
            ],
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.version', 1)
            ->assertJsonPath('detail.identity.id', $scholarship->id)
            ->assertJsonPath('detail.identity.title', $scholarship->title)
            ->assertJsonPath('detail.decision.state', 'eligible')
            ->assertJsonPath('detail.decision.can_apply', true)
            ->assertJsonPath('detail.decision.action.label', 'Start pre-screening')
            ->assertJsonCount(4, 'detail.facts')
            ->assertJsonCount(5, 'detail.sections')
            ->assertJsonCount(3, 'detail.sections.overview.terms_preview')
            ->assertJsonPath('detail.sections.overview.has_full_terms', true)
            ->assertJsonPath('detail.sections.eligibility.profile.complete', true)
            ->assertJsonCount(0, 'detail.sections.eligibility.blockers')
            ->assertJsonPath('detail.sections.eligibility.written_conditions', 'Applicant must remain enrolled.')
            ->assertJsonPath('detail.sections.requirements.readiness.required_count', 2)
            ->assertJsonPath('detail.sections.requirements.readiness.state', 'needs_files')
            ->assertJsonPath('detail.sections.requirements.readiness.missing_count', 2)
            ->assertJsonCount(2, 'detail.sections.requirements.readiness.items')
            ->assertJsonCount(4, 'detail.sections.process.stages')
            ->assertJsonPath('detail.sections.process.stages.0.timing.label', 'First step')
            ->assertJsonPath('detail.sections.provider.name', $scholarship->provider->provider_name)
            ->assertJsonPath('detail.sections.provider.profile_href', route('dashboard.providers.show', $scholarship->provider, false))
            ->assertJsonPath('scholarship.id', $scholarship->id);
    }

    public function test_existing_application_takes_priority_over_every_other_detail_state(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'deadline' => now()->subDay()->toDateString(),
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'applied')
            ->assertJsonPath('detail.decision.application_id', $application->id)
            ->assertJsonPath('detail.decision.action.href', route('dashboard.applications.show', $application, false));
    }

    public function test_future_opening_date_produces_the_upcoming_state(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'application_opens_at' => now()->addWeek()->toDateString(),
        ]);

        $this->assertDecisionState($applicant, $scholarship, 'upcoming', false);
    }

    public function test_reached_application_limit_produces_the_full_state(): void
    {
        $applicant = $this->completeApplicant();
        $otherApplicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship(['application_limit' => 1]);
        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $otherApplicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->assertDecisionState($applicant, $scholarship, 'full', false);
    }

    public function test_past_deadline_produces_the_closed_state(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'deadline' => now()->subDay()->toDateString(),
        ]);

        $this->assertDecisionState($applicant, $scholarship, 'closed', false);
    }

    public function test_incomplete_profile_produces_the_profile_incomplete_state(): void
    {
        $applicant = User::factory()->create();
        $scholarship = $this->publishedScholarship();

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'profile_incomplete')
            ->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.sections.eligibility.profile.complete', false)
            ->assertJson(fn ($json) => $json
                ->has('detail.sections.eligibility.profile.missing', fn ($json) => $json->etc())
                ->etc());
    }

    public function test_mismatched_eligibility_produces_the_blocked_state(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'eligible_education_levels' => 'Elementary',
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'blocked')
            ->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.decision.action.target', 'eligibility')
            ->assertJsonCount(1, 'detail.decision.blockers')
            ->assertJsonCount(1, 'detail.sections.eligibility.blockers')
            ->assertJsonPath('detail.sections.eligibility.blockers.0.key', 'education_level');
    }

    public function test_matching_profile_produces_the_eligible_state(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'eligible_education_levels' => 'College',
            'eligible_year_levels' => '1st year',
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
        ]);

        $this->assertDecisionState($applicant, $scholarship, 'eligible', true);
    }

    public function test_profile_review_program_does_not_require_files_before_the_initial_application(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'application_mode' => 'provider_review',
            'requirements' => "Recent school ID\nAcademic record",
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.sections.requirements.readiness.state', 'not_required')
            ->assertJsonPath('detail.sections.requirements.readiness.required_count', 0)
            ->assertJsonCount(0, 'detail.sections.requirements.readiness.items')
            ->assertJsonPath('detail.sections.requirements.review_method', 'provider_review');
    }

    public function test_process_and_provider_contracts_explain_scheduled_activity_and_public_contact(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'selection_stages' => ['screening', 'formal_application', 'interview', 'decision'],
            'location_name' => 'Community Learning Center',
            'location_address' => 'Quezon City, Metro Manila',
        ]);
        $scholarship->events()->create([
            'type' => 'interview',
            'title' => 'Scholarship interview',
            'scheduled_at' => now()->addWeek()->setTime(9, 0),
            'mode' => 'online',
            'status' => 'scheduled',
        ]);
        $scholarship->provider->providerProfile()->update([
            'provider_contact_email' => 'scholarships@example.test',
            'contact_department' => 'Scholarship Office',
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.sections.process.stages.2.key', 'interview')
            ->assertJsonPath('detail.sections.process.stages.2.timing.state', 'scheduled')
            ->assertJsonPath('detail.sections.process.stages.2.timing.note', 'Online')
            ->assertJsonPath('detail.sections.provider.contact.email', 'scholarships@example.test')
            ->assertJsonPath('detail.sections.provider.contact.department', 'Scholarship Office')
            ->assertJsonPath('detail.sections.provider.location.has_map', true);
    }

    private function assertDecisionState(
        User $applicant,
        Scholarship $scholarship,
        string $state,
        bool $canApply,
    ): void {
        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.decision.state', $state)
            ->assertJsonPath('detail.decision.can_apply', $canApply);
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'account_managed_by' => 'learner',
            'education_level' => 'college',
            'school' => 'Test University',
            'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'household_size' => 4,
            'current_scholarship_status' => 'none',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/scholarship-detail-applicant.jpg',
        ]);

        return $applicant->fresh();
    }

    private function publishedScholarship(array $attributes = []): Scholarship
    {
        $provider = User::factory()->create(['role' => 'provider']);

        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Applicant Detail Contract Scholarship',
            'category' => 'Academic merit',
            'description' => 'A focused scholarship detail contract for applicants.',
            'application_mode' => 'online',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
            ...$attributes,
        ]);
    }
}
