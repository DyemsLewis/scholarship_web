<?php

namespace Tests\Feature;

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
