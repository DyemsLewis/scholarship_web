<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\User;
use App\Support\ReviewRubric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_change_to_published_scholarship_returns_it_to_admin_review(): void
    {
        [$provider, $scholarship] = $this->publishedScholarship();

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", $this->scholarshipUpdatePayload($scholarship, [
                'title' => 'Materially Updated Scholarship',
            ]))
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'pending_review');

        $this->assertSame('pending_review', $scholarship->fresh()->status);
    }

    public function test_unchanged_published_scholarship_remains_published(): void
    {
        [$provider, $scholarship] = $this->publishedScholarship();

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", $this->scholarshipUpdatePayload($scholarship))
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'published');
    }

    public function test_web_applicant_cannot_apply_after_deadline(): void
    {
        $applicant = $this->completeApplicant();
        [, $scholarship] = $this->publishedScholarship([
            'deadline' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('scholarship.is_accepting_applications', false);

        $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This scholarship is no longer accepting applications.');

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_mobile_app_requires_complete_profile_before_application(): void
    {
        $applicant = User::factory()->create();
        [, $scholarship] = $this->publishedScholarship();
        $token = $this->mobileToken($applicant);

        $this->withToken($token)
            ->postJson('/api/mobile/applications', ['scholarship_id' => $scholarship->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Complete your student profile before applying.');
    }

    public function test_mobile_app_blocks_failed_eligibility_criterion(): void
    {
        $applicant = $this->completeApplicant();
        [, $scholarship] = $this->publishedScholarship([
            'eligible_education_levels' => 'elementary',
        ]);
        $token = $this->mobileToken($applicant);

        $this->withToken($token)
            ->postJson('/api/mobile/applications', ['scholarship_id' => $scholarship->id])
            ->assertUnprocessable()
            ->assertJsonPath('blocking_criteria.0.key', 'education_level');

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_mobile_applicant_cannot_apply_after_deadline(): void
    {
        $applicant = $this->completeApplicant();
        [, $scholarship] = $this->publishedScholarship([
            'deadline' => now()->subDay()->toDateString(),
        ]);
        $token = $this->mobileToken($applicant);

        $this->withToken($token)
            ->postJson('/api/mobile/applications', ['scholarship_id' => $scholarship->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This scholarship is no longer accepting applications.');

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    private function publishedScholarship(array $attributes = []): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Workflow Test Scholarship',
            'description' => 'Used to test scholarship workflow integrity.',
            'status' => 'published',
            'deadline' => now()->addWeek()->toDateString(),
            'review_rubric' => ReviewRubric::DEFAULT,
            ...$attributes,
        ]);

        return [$provider, $scholarship];
    }

    private function scholarshipUpdatePayload(Scholarship $scholarship, array $overrides = []): array
    {
        return [
            'title' => $scholarship->title,
            'description' => $scholarship->description,
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => 'published',
            'terms_accepted' => true,
            'review_rubric' => json_encode($scholarship->review_rubric),
            ...$overrides,
        ];
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Test University',
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

    private function mobileToken(User $user): string
    {
        $plainToken = 'workflow-test-token-'.$user->id;
        MobileApiToken::create([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
    }
}
