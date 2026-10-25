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

    public function test_summary_contract_returns_one_decision_and_a_focused_readiness_brief(): void
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
            ->assertJsonPath('detail.version', 3)
            ->assertJsonPath('detail.view', 'summary')
            ->assertJsonPath('detail.identity.id', $scholarship->id)
            ->assertJsonPath('detail.identity.title', $scholarship->title)
            ->assertJsonPath('detail.decision.state', 'eligible')
            ->assertJsonPath('detail.decision.can_apply', true)
            ->assertJsonPath('detail.decision.action.label', 'Start pre-screening')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.applications.start', $scholarship, false))
            ->assertJsonCount(3, 'detail.snapshot')
            ->assertJsonCount(5, 'detail.navigation')
            ->assertJsonCount(3, 'detail.content.readiness')
            ->assertJsonPath('detail.content.readiness.0.key', 'eligibility')
            ->assertJsonPath('detail.content.readiness.0.state', 'ready')
            ->assertJsonPath('detail.content.readiness.1.key', 'files')
            ->assertJsonPath('detail.content.readiness.1.state', 'attention')
            ->assertJsonPath('detail.content.additional_benefit_count', 0)
            ->assertJsonMissingPath('detail.content.benefits')
            ->assertJsonMissingPath('scholarship')
            ->assertJsonMissingPath('detail.sections');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=award')
            ->assertOk()
            ->assertJsonPath('detail.view', 'award')
            ->assertJsonPath('detail.content.has_full_terms', true)
            ->assertJsonCount(3, 'detail.content.terms');
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
            ->assertJsonPath('detail.content.readiness.0.state', 'attention');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()
            ->assertJsonPath('detail.content.profile.complete', false)
            ->assertJson(fn ($json) => $json
                ->has('detail.content.profile.missing', fn ($json) => $json->etc())
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
            ->assertJsonPath('detail.decision.action.href', route('dashboard.scholarships.show.section', [
                'scholarship' => $scholarship,
                'section' => 'eligibility',
            ], false))
            ->assertJsonCount(1, 'detail.decision.blockers')
            ->assertJsonPath('detail.content.readiness.0.state', 'blocked');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()
            ->assertJsonCount(1, 'detail.content.blockers')
            ->assertJsonPath('detail.content.blockers.0.key', 'education_level');
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
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()
            ->assertJsonPath('detail.content.requirements.readiness.state', 'not_required')
            ->assertJsonPath('detail.content.requirements.readiness.required_count', 0)
            ->assertJsonCount(0, 'detail.content.requirements.readiness.items')
            ->assertJsonPath('detail.content.requirements.review_method', 'provider_review');
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
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()
            ->assertJsonPath('detail.content.process.stages.2.key', 'interview')
            ->assertJsonPath('detail.content.process.stages.2.timing.state', 'scheduled')
            ->assertJsonPath('detail.content.process.stages.2.timing.note', 'Online');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=provider')
            ->assertOk()
            ->assertJsonPath('detail.content.verified', true)
            ->assertJsonPath('detail.content.contact.email', 'scholarships@example.test')
            ->assertJsonPath('detail.content.contact.department', 'Scholarship Office')
            ->assertJsonPath('detail.content.location.has_map', true);
    }

    public function test_each_detail_view_has_its_own_page_route(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship();

        foreach (['eligibility', 'award', 'application', 'provider'] as $section) {
            $this->actingAs($applicant)
                ->get(route('dashboard.scholarships.show.section', [
                    'scholarship' => $scholarship,
                    'section' => $section,
                ]))
                ->assertOk()
                ->assertViewHas('section', $section);
        }
    }

    public function test_application_start_route_opens_the_wizard_with_the_scholarship_selected(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship();

        $this->actingAs($applicant)
            ->get(route('dashboard.applications.start', $scholarship))
            ->assertOk()
            ->assertViewIs('dashboard-applications')
            ->assertViewHas('scholarship', fn (Scholarship $selected): bool => $selected->is($scholarship));
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
        $applicant = User::factory()->verifiedProfile()->create();
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
