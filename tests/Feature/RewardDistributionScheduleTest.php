<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardDistributionScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_schedules_and_completes_reward_distribution_without_applicant_acceptance(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Scheduled Reward Scholarship',
            'description' => 'Used to verify provider-managed reward distribution.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'approved',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'distribution_scheduled',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('distribution_scheduled_for');

        $futureDate = now()->addWeek()->toDateString();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'distribution_scheduled',
                'decision_reason' => 'distribution_scheduled',
                'awarded_amount' => 40000,
                'distribution_scheduled_for' => $futureDate,
                'distribution_instructions' => 'Claim the reward at the provider office with a valid school ID.',
                'review_notes' => 'Reward distribution schedule published.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'distribution_scheduled')
            ->assertJsonPath('application.distribution_scheduled_for', $futureDate)
            ->assertJsonPath('application.distribution_instructions', 'Claim the reward at the provider office with a valid school ID.');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'distribution_scheduled',
            'awarded_amount' => 40000,
            'distribution_scheduled_for' => $futureDate.' 00:00:00',
            'student_response_status' => null,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'title' => 'Reward distribution scheduled',
        ]);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.status', 'distribution_scheduled')
            ->assertJsonPath('application.requires_student_response', false)
            ->assertJsonPath('application.can_respond', false)
            ->assertJsonPath('application.distribution_scheduled_for', now()->addWeek()->format('M d, Y'));

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'accepted',
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No in-platform acceptance is required. The scholarship provider manages confirmation and reward distribution directly.');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'disbursed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $today = now()->toDateString();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'distribution_scheduled',
                'decision_reason' => 'distribution_scheduled',
                'distribution_scheduled_for' => $today,
                'distribution_instructions' => 'Reward is ready for release today.',
                'review_notes' => 'Distribution moved to today.',
            ])
            ->assertOk()
            ->assertJsonPath('application.distribution_scheduled_for', $today);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'disbursed',
                'decision_reason' => 'award_released',
                'review_notes' => 'Scholarship reward distributed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'disbursed');

        $application->refresh();

        $this->assertSame($today, $application->distribution_scheduled_for?->toDateString());
        $this->assertSame($today, $application->outcome_at?->toDateString());
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('title', 'Scholarship reward distributed')
            ->exists());
    }
}
