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

    public function test_the_overview_distinguishes_application_intake_from_planned_award_places(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship(['application_limit' => 25, 'slots_available' => 4, 'award_amount' => 2500.50]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()
            ->assertJsonPath('detail.snapshot.2.label', 'Application intake')
            ->assertJsonPath('detail.snapshot.2.value', '25 applications remaining')
            ->assertJsonPath('detail.snapshot.2.note', '4 award places planned.')
            ->assertJsonPath('detail.snapshot.0.value', 'Cash grant - PHP 2,500.50 | One-time');
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

    public function test_preferred_mismatch_has_an_attention_brief_but_allows_starting(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'eligible_courses' => 'BS Nursing', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()->assertJsonPath('detail.decision.can_apply', true)
            ->assertJsonPath('detail.decision.tone', 'attention')
            ->assertJsonPath('detail.content.readiness.0.status', 'Preferences differ');
        $response = $this->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()->assertJsonCount(0, 'detail.content.blockers')
            ->assertJsonPath('detail.content.guidance.label', 'Preferences differ')
            ->assertJsonPath('detail.content.preferred_mismatches.0.key', 'course');
        $this->assertStringNotContainsString('Your profile meets', $response->json('detail.decision.description'));
    }

    public function test_required_rule_names_explain_why_applying_is_blocked_even_with_a_preference(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'eligible_courses' => 'BS Nursing', 'eligible_locations' => 'Cebu City',
            'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $response = $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.content.guidance.label', 'Required rules not met')
            ->assertJsonCount(1, 'detail.content.blockers')
            ->assertJsonPath('detail.content.blockers.0.key', 'location')
            ->assertJsonPath('detail.content.preferred_mismatches.0.key', 'course');
        $this->assertStringContainsString('Location', $response->json('detail.decision.description'));
        $this->assertStringContainsString('required rules', $response->json('detail.decision.description'));
    }

    public function test_different_grade_scales_are_not_reported_as_missing_profile_data(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship(['minimum_gwa' => 1.75, 'minimum_grade_scale' => 'grade_point']);
        $response = $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()->assertJsonPath('detail.decision.can_apply', true)
            ->assertJsonPath('detail.content.guidance.label', 'Needs review')
            ->assertJsonCount(0, 'detail.content.missing_checks')
            ->assertJsonPath('detail.content.review_criteria.0.key', 'academic');
        $academic = collect($response->json('detail.content.criteria'))->firstWhere('key', 'academic');
        $this->assertStringContainsString('different grading scales', $academic['comparison']);
        $this->assertStringNotContainsString('missing from your profile', $academic['comparison']);
        $this->getJson(route('dashboard.scholarships.show.data', $scholarship))
            ->assertOk()->assertJsonPath('detail.content.readiness.0.status', 'Needs review');
    }

    public function test_preference_guidance_does_not_claim_application_permission_for_an_unverified_profile(): void
    {
        $applicant = $this->completeApplicant();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $scholarship = $this->publishedScholarship([
            'eligible_courses' => 'BS Nursing', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $response = $this->actingAs($applicant->fresh())->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.decision.state', 'profile_unverified')
            ->assertJsonPath('detail.content.guidance.label', 'Preferences differ');
        $this->assertStringNotContainsString('You can apply', $response->json('detail.content.guidance.description'));
    }

    public function test_missing_essential_information_still_requires_profile_completion(): void
    {
        $applicant = $this->completeApplicant();
        $applicant->studentProfile()->update(['gwa' => null]);
        $scholarship = $this->publishedScholarship(['minimum_gwa' => 85, 'minimum_grade_scale' => 'percentage']);
        $this->actingAs($applicant->fresh())->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=eligibility')
            ->assertOk()->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.decision.state', 'profile_incomplete')
            ->assertJsonPath('detail.content.missing_checks.0.key', 'academic')
            ->assertJsonPath('detail.content.guidance.label', 'Profile information needed');
    }

    public function test_how_to_apply_exposes_published_formal_instructions_without_assigned_session_details(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'application_mode' => 'hybrid',
            'handoff_mode' => 'onsite',
            'handoff_deadline' => '2027-01-15',
            'handoff_instructions' => 'Present the original enrollment records.',
            'handoff_location_name' => 'Student desk',
            'handoff_location_address' => 'Antipolo City',
            'handoff_latitude' => 14.5861,
            'handoff_longitude' => 121.1756,
            'post_qualification_requirements' => "School ID\nOriginal enrollment record",
        ]);

        $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()
            ->assertJsonPath('detail.content.process.formal_application.deadline_label', 'Jan 15, 2027')
            ->assertJsonPath('detail.content.process.formal_application.mode_label', 'In person')
            ->assertJsonPath('detail.content.process.formal_application.instructions', 'Present the original enrollment records.')
            ->assertJsonPath('detail.content.process.formal_application.location.name', 'Student desk')
            ->assertJsonCount(2, 'detail.content.process.formal_application.requirements')
            ->assertJsonPath('detail.content.process.formal_application.is_assigned_appointment', false)
            ->assertJsonMissingPath('detail.content.process.formal_application.meeting_url')
            ->assertJsonMissingPath('detail.content.process.formal_application.applicants');
    }

    public function test_online_formal_instructions_do_not_expose_stale_onsite_details_or_unsafe_links(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship([
            'application_mode' => 'hybrid', 'handoff_mode' => 'online',
            'handoff_url' => 'javascript:alert(1)', 'handoff_location_name' => 'Stale location',
        ]);

        $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()
            ->assertJsonPath('detail.content.process.formal_application.location', null)
            ->assertJsonPath('detail.content.process.formal_application.url', null)
            ->assertJsonPath('detail.content.process.formal_application.deadline_label', null);

        $scholarship->update(['handoff_url' => 'https://example.org/official-application']);
        $this->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()->assertJsonPath('detail.content.process.formal_application.url', 'https://example.org/official-application');
    }

    public function test_missing_published_formal_details_do_not_invent_an_appointment(): void
    {
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship(['selection_stages' => ['screening', 'decision']]);
        $this->actingAs($applicant)->getJson(route('dashboard.scholarships.show.data', $scholarship).'?section=application')
            ->assertOk()
            ->assertJsonPath('detail.content.process.formal_application.deadline_label', null)
            ->assertJsonPath('detail.content.process.formal_application.location', null)
            ->assertJsonPath('detail.content.process.formal_application.instructions', null)
            ->assertJsonCount(0, 'detail.content.process.formal_application.requirements')
            ->assertJsonPath('detail.content.process.formal_application.is_assigned_appointment', false);
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
