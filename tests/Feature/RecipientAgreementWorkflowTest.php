<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientAgreementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_selection_creates_a_fixed_agreement_that_the_applicant_can_accept(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationReadyForDecision();

        $selection = $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/final-outcome", [
                'outcome' => 'selected',
                'awarded_amount' => 18000,
                'notes' => 'Selected for the current program cycle.',
            ])
            ->assertOk()
            ->assertJsonPath('application.recipient_agreement.status', 'pending')
            ->assertJsonPath('application.recipient_agreement.can_respond', true)
            ->assertJsonPath('application.recipient_agreement.snapshot.program_title', $scholarship->title)
            ->assertJsonPath('application.recipient_agreement.snapshot.award_amount', '18000.00');

        $version = $selection->json('application.recipient_agreement.version');
        $this->assertStringStartsWith('recipient-agreement-v1-', $version);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_agreement.status', 'pending')
            ->assertJsonPath('application.recipient_agreement.snapshot.benefits.0.title', 'Learning allowance');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'accepted',
                'terms_accepted' => true,
                'note' => 'I reviewed the support and responsibilities.',
            ])
            ->assertOk()
            ->assertJsonPath('application.recipient_agreement.status', 'accepted')
            ->assertJsonPath('application.recipient_agreement.can_respond', false);

        $application->refresh();
        $this->assertSame('accepted', $application->student_response_status);
        $this->assertNotNull($application->provider_contract_terms_accepted_at);
        $this->assertSame($version, $application->student_response_terms_version);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'recipient_agreement_response')
            ->exists());
    }

    public function test_declining_requires_a_note_and_does_not_change_the_selection_result(): void
    {
        [$provider, $applicant, , $application] = $this->applicationReadyForDecision();
        app(ApplicationWorkflowService::class)->recordFinalOutcome($application, 'selected', $provider);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'declined',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'declined',
                'note' => 'I cannot complete the listed activity this school year.',
            ])
            ->assertOk()
            ->assertJsonPath('application.recipient_agreement.status', 'declined');

        $application->refresh();
        $this->assertSame('selected', $application->final_outcome);
        $this->assertSame('awarded', $application->status);
        $this->assertNull($application->provider_contract_terms_accepted_at);
    }

    private function applicationReadyForDecision(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Tulay Aral Community Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Continuing Scholar Grant',
            'description' => 'A test program with recipient responsibilities.',
            'award_amount' => 15000,
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'support_starts_at' => now()->addMonth()->toDateString(),
            'support_ends_at' => now()->addYear()->toDateString(),
            'recipient_agreement' => [
                'commitment_type' => 'reporting',
                'duration' => 'Submit one academic update each semester.',
                'noncompliance_consequence' => 'Future support may be held after provider review.',
                'exit_or_exception_process' => 'Contact the provider to explain exceptional circumstances.',
            ],
            'renewal_policy' => 'Renewal depends on the disclosed academic requirement.',
            'status' => 'published',
        ]);
        $scholarship->benefits()->create([
            'type' => 'allowance',
            'title' => 'Learning allowance',
            'amount' => 15000,
            'frequency' => 'annual',
            'sort_order' => 1,
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);

        return [$provider, $applicant, $scholarship, $application];
    }
}
