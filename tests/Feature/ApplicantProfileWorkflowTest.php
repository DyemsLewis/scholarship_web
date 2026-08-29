<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_minor_applicant_requires_an_account_manager_and_complete_guardian_contact(): void
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2012-05-10',
            'education_level' => 'college',
            'school' => 'Sample University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);

        $readiness = $applicant->fresh()->applicantProfileReadiness();
        $missingFields = collect($readiness['missing'])->pluck('key');

        $this->assertTrue($readiness['is_minor']);
        $this->assertTrue($readiness['requires_guardian']);
        $this->assertContains('account_managed_by', $missingFields);
        $this->assertContains('guardian_name', $missingFields);
        $this->assertContains('guardian_relationship', $missingFields);
        $this->assertContains('guardian_contact', $missingFields);

        $applicant->studentProfile()->update([
            'account_managed_by' => 'parent_guardian',
            'guardian_name' => 'Maria Applicant',
            'guardian_relationship' => 'Mother',
            'guardian_contact' => '09171234567',
        ]);

        $this->assertTrue($applicant->fresh()->applicantProfileReadiness()['complete']);
    }

    public function test_profile_birthdate_must_represent_an_age_between_five_and_one_hundred(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', ['birthdate' => now()->subYears(4)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birthdate');

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', ['birthdate' => now()->subYears(101)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birthdate');
    }

    public function test_profile_endpoint_returns_catalog_matches_and_saved_preferences(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeAdultApplicant();
        $applicant->studentProfile()->update([
            'preferred_categories' => 'Academic merit',
        ]);
        $academic = $this->publishedScholarship($provider, 'Academic Opportunity', 'Academic merit');
        $financial = $this->publishedScholarship($provider, 'Financial Opportunity', 'Financial assistance');

        $response = $this->actingAs($applicant)
            ->getJson('/dashboard/profile/data')
            ->assertOk()
            ->assertJsonPath('profile_readiness.complete', true)
            ->assertJsonPath('match_summary.available_programs', 2)
            ->assertJsonPath('match_summary.eligible_programs', 2)
            ->assertJsonPath('match_summary.strong_matches', 2)
            ->assertJsonPath('match_summary.preference_matches', 1);

        $this->assertFalse($response->json('user.is_minor'));

        $catalog = $this->actingAs($applicant)->getJson('/dashboard/data')->assertOk()->json('scholarships');
        $academicPayload = collect($catalog)->firstWhere('id', $academic->id);
        $financialPayload = collect($catalog)->firstWhere('id', $financial->id);

        $this->assertSame(100, $academicPayload['preference_match']['score']);
        $this->assertSame(0, $financialPayload['preference_match']['score']);
    }

    public function test_dashboard_recommendations_only_include_eligible_scholarships(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeAdultApplicant();
        $eligible = $this->publishedScholarship($provider, 'College Opportunity', 'Academic merit');
        $ineligible = $this->publishedScholarship($provider, 'Elementary Opportunity', 'Academic merit');
        $ineligible->update(['eligible_education_levels' => 'elementary']);

        $scholarships = $this->actingAs($applicant)
            ->getJson('/dashboard/data')
            ->assertOk()
            ->json('scholarships');
        $scholarshipIds = collect($scholarships)->pluck('id');

        $this->assertTrue($scholarshipIds->contains($eligible->id));
        $this->assertFalse($scholarshipIds->contains($ineligible->id));
        $this->assertTrue(collect($scholarships)->every(
            fn (array $scholarship): bool => $scholarship['eligibility_match']['is_eligible'] === true,
        ));
    }

    public function test_dashboard_returns_only_unread_action_alerts(): void
    {
        $applicant = User::factory()->create();
        $actionAlert = PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'program_announcement',
            'title' => 'Program update',
            'message' => 'The provider shared a new instruction.',
            'action_url' => '/dashboard/applications/1',
        ]);
        PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'application_status',
            'title' => 'Old update',
            'message' => 'This update was already read.',
            'read_at' => now(),
        ]);
        PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'profile_reminder',
            'title' => 'Profile reminder',
            'message' => 'This is handled by dashboard readiness instead.',
        ]);

        $this->actingAs($applicant)
            ->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonCount(1, 'action_alerts')
            ->assertJsonPath('action_alerts.0.id', $actionAlert->id)
            ->assertJsonPath('action_alerts.0.type', 'program_announcement')
            ->assertJsonPath('action_alerts.0.action_url', '/dashboard/applications/1');
    }

    public function test_scholarship_finder_includes_eligible_and_ineligible_published_programs(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeAdultApplicant();
        $eligible = $this->publishedScholarship($provider, 'College Opportunity', 'Academic merit');
        $ineligible = $this->publishedScholarship($provider, 'Elementary Opportunity', 'Academic merit');
        $ineligible->update(['eligible_education_levels' => 'elementary']);

        $scholarships = $this->actingAs($applicant)
            ->getJson('/dashboard/scholarships/data')
            ->assertOk()
            ->json('scholarships');
        $scholarshipsById = collect($scholarships)->keyBy('id');

        $this->assertCount(2, $scholarships);
        $this->assertTrue($scholarshipsById[$eligible->id]['eligibility_match']['is_eligible']);
        $this->assertFalse($scholarshipsById[$ineligible->id]['eligibility_match']['is_eligible']);
    }

    public function test_upcoming_program_is_discoverable_before_applications_open(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = $this->completeAdultApplicant();
        $scholarship = $this->publishedScholarship($provider, 'Upcoming Opportunity', 'Academic merit');
        $scholarship->update([
            'program_cycle' => 'SY 2026-2027',
            'application_opens_at' => now()->addWeek()->toDateString(),
            'expected_results_at' => now()->addMonths(2)->toDateString(),
            'deadline' => now()->addMonth()->toDateString(),
        ]);

        $program = collect($this->actingAs($applicant)
            ->getJson('/dashboard/data')
            ->assertOk()
            ->json('scholarships'))
            ->firstWhere('id', $scholarship->id);

        $this->assertNotNull($program);
        $this->assertSame('SY 2026-2027', $program['program_cycle']);
        $this->assertFalse($program['is_accepting_applications']);
        $this->assertFalse($program['can_start_application']);
        $this->assertTrue($scholarship->fresh()->isDiscoverable());
        $this->assertFalse($scholarship->fresh()->isAcceptingApplications());
    }

    public function test_grade_point_profile_rejects_values_outside_the_supported_scale(): void
    {
        $applicant = User::factory()->create();
        $payload = [
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'contact_number' => $applicant->contact_number,
            'grading_scale' => 'grade_point',
            'gwa' => 5.5,
        ];

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gwa');

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', [...$payload, 'gwa' => 1.75])
            ->assertOk()
            ->assertJsonStructure(['profile_readiness', 'match_summary']);
    }

    private function completeAdultApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2000-05-10',
            'education_level' => 'college',
            'school' => 'Sample University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);

        return $applicant->fresh();
    }

    private function publishedScholarship(User $provider, string $title, string $category): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => $title,
            'category' => $category,
            'description' => 'A scholarship used to verify applicant profile matching.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
    }
}
