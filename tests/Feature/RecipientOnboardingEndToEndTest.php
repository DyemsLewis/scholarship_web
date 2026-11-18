<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitRelease;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\RecipientAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipientOnboardingEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_intention_onboarding_distribution_and_receipt_are_separate_confirmed_steps(): void
    {
        Mail::fake();
        Storage::fake('local');
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'One-time onboarding walkthrough',
            'description' => 'Integration test intake.', 'status' => 'published', 'support_mode' => 'one_time',
            'onboarding_policy' => ['final_agreement_required' => true, 'instructions' => 'Complete the final agreement at the first collection visit.']]);
        $benefit = $program->benefits()->create(['type' => 'allowance', 'title' => 'Study-start allowance', 'amount' => 5000, 'frequency' => 'one_time', 'sort_order' => 0]);
        $snapshot = RecipientAgreement::snapshot($program->fresh());
        $version = RecipientAgreement::version($snapshot);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete',
            'provider_contract_terms_snapshot' => $snapshot, 'provider_contract_terms_version' => $version]);

        $this->actingAs($applicant)->getJson("/dashboard/applications/{$application->id}/data?view=outcome")
            ->assertOk()->assertJsonPath('detail.content.agreement.snapshot.onboarding.final_agreement_required', true);
        $this->patchJson("/dashboard/applications/{$application->id}/response", [
            'response' => 'accepted', 'terms_accepted' => true, 'agreement_version' => $version,
        ])->assertOk();
        $this->assertDatabaseHas('recipient_onboarding_records', ['scholarship_application_id' => $application->id, 'status' => 'pending']);
        $this->getJson("/dashboard/monitoring/{$application->id}/data")->assertOk()
            ->assertJsonPath('detail.decision.state', 'onboarding_required');
        $this->actingAs($provider)->postJson("/provider/applications/{$application->id}/onboarding/instructions", [
            'instructions' => 'Bring your school ID to reception before collecting the allowance.', 'expected_revision' => 1, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('onboarding.status', 'pending');

        $scheduled = $this->postJson("/provider/scholarships/{$program->id}/benefit-releases", [
            'title' => 'First allowance collection', 'release_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'benefit_description' => 'Study-start allowance', 'amount' => 5000, 'benefit_ids' => [$benefit->id],
            'release_method' => 'in_person', 'location' => 'Scholarship reception', 'requires_original_verification' => false,
            'instructions' => 'Complete the final agreement with the recipient officer at this visit.', 'recipient_ids' => [$application->id],
        ])->assertCreated();
        $recordId = $scheduled->json('release.records.0.id');
        $release = RecipientBenefitRelease::findOrFail($scheduled->json('release.id'));
        $this->travelTo($release->release_at->copy()->addMinute());
        $this->postJson("/provider/benefit-release-records/{$recordId}/result", ['status' => 'prepared'])->assertOk();
        $this->postJson("/provider/benefit-release-records/{$recordId}/result", [
            'status' => 'released', 'notes' => 'Must remain blocked before agreement completion.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);

        $this->postJson("/provider/applications/{$application->id}/onboarding/complete", [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 2,
        ])->assertOk()->assertJsonPath('onboarding.status', 'completed');
        $this->assertDatabaseHas('recipient_benefit_release_records', ['id' => $recordId, 'status' => 'prepared']);
        $this->postJson("/provider/benefit-release-records/{$recordId}/result", [
            'status' => 'released', 'notes' => 'Allowance handed to the recipient after provider onboarding.',
            'receipt_proof' => UploadedFile::fake()->image('provider-acknowledgement.png'),
        ])->assertOk()->assertJsonPath('release.records.0.lifecycle_status', 'awaiting_confirmation');
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);

        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$application->id}/data")
            ->assertOk()->assertJsonPath('detail.decision.state', 'benefit_confirmation_required')
            ->assertJsonPath('detail.content.onboarding.status', 'completed');
        $this->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
            'evidence' => UploadedFile::fake()->image('applicant-receipt.png'),
        ])->assertCreated();
        $this->assertSame('fulfilled', RecipientBenefitEntitlement::where('scholarship_application_id', $application->id)->sole()->status);
        $this->assertSame('selected', $application->fresh()->final_outcome);
        $this->assertFalse(RecipientAgreement::payload($application->fresh())['final_contract_recorded']);
        $this->assertDatabaseCount('recipient_onboarding_events', 3);
        $this->getJson("/dashboard/applications/{$application->id}/data?view=outcome")->assertOk();
        $this->travelBack();
    }
}
