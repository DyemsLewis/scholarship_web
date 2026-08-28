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
                'status' => 'submitted',
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
        $this->assertDatabaseCount('portal_notifications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_provider_cannot_score_a_criterion_outside_the_application_snapshot(): void
    {
        Mail::fake();
        [$provider, $application] = $this->applicationWithRubric();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'submitted',
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

    public function test_provider_must_score_every_rubric_criterion_before_saving_review(): void
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rubric_scores');

        $this->assertDatabaseCount('portal_notifications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertNull($application->fresh()->rubric_total_score);
    }

    public function test_provider_cannot_make_a_decision_without_scoring_the_rubric(): void
    {
        Mail::fake();
        [$provider, $application] = $this->applicationWithRubric();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rubric_scores');

        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_applicant_can_see_their_completed_provider_rubric_score(): void
    {
        [$provider, $application] = $this->applicationWithRubric();

        $application->update([
            'rubric_scores' => [
                'eligibility_fit' => 80,
                'academic_merit' => 90,
                'financial_need' => 70,
                'document_quality' => 100,
            ],
            'rubric_total_score' => 84.5,
            'rubric_scored_by' => $provider->id,
            'rubric_scored_at' => now(),
        ]);

        $this->actingAs($application->applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.rubric_review.is_complete', true)
            ->assertJsonPath('application.rubric_review.total_score', 84.5)
            ->assertJsonPath('application.rubric_review.criteria.0.label', 'Eligibility fit')
            ->assertJsonPath('application.rubric_review.criteria.0.score', 80)
            ->assertJsonMissingPath('application.rubric_review.scored_by');
    }

    public function test_applicant_does_not_see_an_incomplete_provider_rubric(): void
    {
        [, $application] = $this->applicationWithRubric();

        $this->actingAs($application->applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.rubric_review', null)
            ->assertJsonPath('application.rubric_scored_at', null);
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
