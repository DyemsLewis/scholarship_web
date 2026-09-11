<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderBenefitTerminationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_stop_future_benefits_for_a_selected_recipient_with_an_audit_trail(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Recipient Support Program',
            'description' => 'Used to verify post-award recipient controls.',
            'award_amount' => 10000,
            'slots' => 1,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'application_state' => 'closed',
            'workflow_stage' => 'complete',
            'final_outcome' => 'selected',
            'decision_reason' => 'approved_for_award',
            'outcome_at' => now()->subDay(),
            'submitted_at' => now()->subWeek(),
        ]);
        $originalOutcomeAt = $application->outcome_at;

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'benefits_terminated',
                'decision_reason' => 'procedure_not_followed',
                'outcome_notes' => 'The recipient did not complete the required release procedure after written reminders.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'benefits_terminated')
            ->assertJsonPath('application.workflow.final_outcome', 'selected');

        $application->refresh();

        $this->assertSame('selected', $application->final_outcome);
        $this->assertTrue($originalOutcomeAt->equalTo($application->outcome_at));
        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'from_status' => 'awarded',
            'to_status' => 'benefits_terminated',
            'decision_reason' => 'procedure_not_followed',
            'review_notes' => 'The recipient did not complete the required release procedure after written reminders.',
        ]);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('title', 'Scholarship benefits stopped')
            ->exists());

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.status', 'benefits_terminated')
            ->assertJsonPath('application.status_progress.label', 'Benefits stopped')
            ->assertJsonPath('application.outcome_notes', 'The recipient did not complete the required release procedure after written reminders.');
    }

    public function test_benefits_cannot_be_stopped_without_a_reason_and_explanation(): void
    {
        [$provider, $application] = $this->makeApplication('awarded', 'selected');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'benefits_terminated',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision_reason', 'outcome_notes']);
    }

    public function test_benefits_cannot_be_stopped_for_an_applicant_who_was_not_selected(): void
    {
        [$provider, $application] = $this->makeApplication('under_review');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'benefits_terminated',
                'decision_reason' => 'procedure_not_followed',
                'outcome_notes' => 'The required provider procedure was not completed.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'to_status' => 'benefits_terminated',
        ]);
    }

    private function makeApplication(string $status, ?string $finalOutcome = null): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Provider Test Scholarship',
            'description' => 'Used to verify benefit termination validation.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'application_state' => $finalOutcome ? 'closed' : 'under_review',
            'workflow_stage' => $finalOutcome ? 'complete' : 'screening',
            'final_outcome' => $finalOutcome,
            'submitted_at' => now(),
        ]);

        return [$provider, $application];
    }
}
