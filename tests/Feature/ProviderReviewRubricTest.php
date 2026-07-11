<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ReviewRubric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderReviewRubricTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_save_a_complete_weighted_rubric_review(): void
    {
        Mail::fake();
        [$provider, $application] = $this->applicationWithRubric();

        $response = $this->actingAs($provider)->patchJson(
            "/provider/applications/{$application->id}/status",
            [
                'status' => 'under_review',
                'rubric_scores' => [
                    'eligibility_fit' => 80,
                    'academic_merit' => 90,
                    'financial_need' => 70,
                    'document_quality' => 100,
                ],
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('application.rubric_review.is_complete', true)
            ->assertJsonPath('application.rubric_review.total_score', 84.5);

        $this->assertSame('84.50', $application->fresh()->rubric_total_score);
        $this->assertSame($provider->id, $application->fresh()->rubric_scored_by);
        $this->assertDatabaseCount('portal_notifications', 1);
        $this->assertDatabaseCount('application_status_histories', 1);
    }

    public function test_provider_cannot_score_a_criterion_outside_the_application_snapshot(): void
    {
        Mail::fake();
        [$provider, $application] = $this->applicationWithRubric();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'under_review',
                'rubric_scores' => ['unlisted_criterion' => 95],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rubric_scores');
    }

    public function test_scholarship_rubric_weights_must_total_one_hundred(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $invalidRubric = ReviewRubric::DEFAULT;
        $invalidRubric[0]['weight'] = 30;

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Invalid Rubric Scholarship',
                'description' => 'The weights intentionally do not total one hundred.',
                'status' => 'draft',
                'terms_accepted' => true,
                'review_rubric' => json_encode($invalidRubric),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_rubric');
    }

    public function test_rubric_only_save_does_not_notify_applicant_or_add_status_history(): void
    {
        Mail::fake();
        [$provider, $application] = $this->applicationWithRubric();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'submitted',
                'rubric_scores' => [
                    'eligibility_fit' => 80,
                    'academic_merit' => 90,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Provider review saved.');

        $this->assertDatabaseCount('portal_notifications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    private function applicationWithRubric(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Rubric Test Scholarship',
            'description' => 'Used to verify consistent provider scoring.',
            'status' => 'published',
            'review_rubric' => ReviewRubric::DEFAULT,
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'review_rubric_snapshot' => ReviewRubric::DEFAULT,
            'submitted_at' => now(),
        ]);

        return [$provider, $application];
    }
}
