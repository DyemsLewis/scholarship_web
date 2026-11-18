<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientOnboardingService;
use App\Support\RecipientAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipientOnboardingReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    public function test_pending_onboarding_allows_collection_scheduling_and_shows_a_separate_distribution_hold(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $this->actingAs($provider)->getJson('/provider/workspaces/releases/schedule/data?program_id='.$program->id)
            ->assertOk()->assertJsonPath('candidates.0.eligible', true)
            ->assertJsonPath('candidates.0.onboarding.can_distribute', false);
        $response = $this->postJson("/provider/scholarships/{$program->id}/benefit-releases", [
            'title' => 'First collection and orientation', 'release_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'benefit_description' => 'School materials', 'release_method' => 'in_person', 'location' => 'Scholarship office',
            'instructions' => 'Complete the final agreement at reception before collecting your materials.',
            'requires_original_verification' => false, 'recipient_ids' => [$application->id],
        ])->assertCreated();
        $releaseId = $response->json('release.id');
        $this->getJson("/provider/workspaces/releases/distributions/{$releaseId}/data")->assertOk()
            ->assertJsonPath('release.records.0.release_readiness.eligible', false)
            ->assertJsonPath('release.records.0.release_readiness.label', 'Final agreement pending')
            ->assertJsonPath('release.records.0.release_readiness.onboarding.required', true)
            ->assertJsonPath('release.records.0.release_readiness.onboarding.can_distribute', false);
        $this->assertSame('selected', $application->fresh()->final_outcome);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
    }

    public function test_all_delivery_methods_reject_direct_release_requests_without_leaving_proof_or_notifications(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        foreach (['in_person', 'bank_transfer', 'e_wallet', 'other'] as $method) {
            $record = $this->release($program, $application, $method);
            $this->actingAs($provider)->postJson($this->resultUrl($record), [
                'status' => 'released', 'notes' => 'This must not be recorded as delivered.',
                'receipt_proof' => UploadedFile::fake()->image('attempted-proof.png'),
                ...($method === 'bank_transfer' || $method === 'e_wallet'
                    ? ['transfer_sent_on' => now()->toDateString(), 'transfer_confirmed' => true] : []),
            ])->assertUnprocessable()->assertJsonValidationErrors('status')
                ->assertJsonPath('errors.status.0', app(RecipientOnboardingService::class)->distributionReadiness($application)['block_reason']);
            $this->assertSame('scheduled', $record->fresh()->status);
            $this->assertNull($record->fresh()->released_at);
            $this->assertNull($record->fresh()->receipt_path);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('portal_notifications', ['type' => 'recipient_benefit_release_result']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'recipient_benefit_release_recorded']);
    }

    public function test_preparation_and_exception_results_remain_available_while_onboarding_is_pending(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        foreach (['prepared', 'missed', 'withheld'] as $status) {
            $record = $this->release($program, $application);
            $this->actingAs($provider)->postJson($this->resultUrl($record), [
                'status' => $status, 'notes' => 'Arrangements are still being handled with the recipient.',
            ])->assertOk()->assertJsonPath('release.records.0.status', $status);
            $this->assertNull($record->fresh()->released_at);
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertSame('selected', $application->fresh()->final_outcome);
    }

    public function test_provider_completion_unlocks_distribution_but_does_not_itself_record_delivery(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $record = $this->release($program, $application);
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $service->publishInstructions($application, $provider, 'Complete the agreement at the first collection visit.', 1);
        $this->actingAs($provider)->postJson($this->resultUrl($record), [
            'status' => 'released', 'notes' => 'Instructions alone are not completion.',
        ])->assertUnprocessable();
        $service->confirmCompletion($application, $provider, [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'note' => 'Private agreement reference.',
        ], 2);
        $this->assertSame('scheduled', $record->fresh()->status);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
        $this->getJson("/provider/workspaces/releases/distributions/{$record->recipient_benefit_release_id}/data")
            ->assertOk()->assertJsonPath('release.records.0.release_readiness.eligible', true)
            ->assertJsonPath('release.records.0.release_readiness.onboarding.can_distribute', true)
            ->assertDontSeeText('Private agreement reference.');
        $this->postJson($this->resultUrl($record), [
            'status' => 'released', 'notes' => 'Materials handed to the recipient after onboarding.',
        ])->assertOk()->assertJsonPath('release.records.0.status', 'released');
        $this->assertFalse(RecipientAgreement::payload($application->fresh())['final_contract_recorded']);
    }

    public function test_bank_transfers_need_current_payout_approval_and_cannot_predate_onboarding_completion(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $record = $this->release($program, $application, 'bank_transfer');
        $this->complete($application, $provider);
        $transfer = ['status' => 'released', 'transfer_sent_on' => now()->toDateString(), 'transfer_confirmed' => true];
        $this->actingAs($provider)->postJson($this->resultUrl($record), $transfer)->assertUnprocessable()->assertJsonValidationErrors('status');
        $record->payoutDetails()->create([
            'method' => 'bank_transfer', 'destination' => ['institution' => 'Test bank', 'account_holder' => 'Test Recipient', 'account_number' => '000012345678'],
            'submitted_at' => now()->subDays(2), 'review_status' => 'approved', 'revision' => 2,
            'reviewed_by' => $provider->id, 'reviewed_at' => now()->subDay(),
        ]);
        $this->postJson($this->resultUrl($record), [
            ...$transfer, 'transfer_sent_on' => now()->subDay()->toDateString(), 'expected_payout_revision' => 2,
            'receipt_proof' => UploadedFile::fake()->image('transfer.png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('transfer_sent_on');
        $this->assertSame('scheduled', $record->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->postJson($this->resultUrl($record), [...$transfer, 'expected_payout_revision' => 1])->assertConflict();
        $this->postJson($this->resultUrl($record), [
            ...$transfer, 'expected_payout_revision' => 2, 'receipt_proof' => UploadedFile::fake()->image('transfer.png'),
        ])->assertOk()->assertJsonPath('release.records.0.status_label', 'Sent - awaiting receipt');
    }

    public function test_completion_does_not_bypass_original_verification_or_scheduled_time(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $record = $this->release($program, $application);
        $this->complete($application, $provider);
        $record->release->update(['requires_original_verification' => true]);
        $result = ['status' => 'released', 'notes' => 'Delivery should still respect existing safeguards.'];
        $this->actingAs($provider)->postJson($this->resultUrl($record), $result)->assertUnprocessable()->assertJsonValidationErrors('originals_verified');
        $record->release->update(['release_at' => now()->addDay()]);
        $this->postJson($this->resultUrl($record), [...$result, 'originals_verified' => true])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('scheduled', $record->fresh()->status);
    }

    public function test_legacy_and_not_required_offers_keep_their_existing_release_workflow(): void
    {
        foreach ([null, ['final_agreement_required' => false]] as $policy) {
            [$provider, , $program, $application] = $this->recipient($policy);
            $record = $this->release($program, $application);
            $this->actingAs($provider)->postJson($this->resultUrl($record), [
                'status' => 'released', 'notes' => 'Delivery recorded using the existing offer.',
            ])->assertOk();
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_stale_completion_cannot_unlock_a_new_offer(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $record = $this->release($program, $application);
        $this->complete($application, $provider);
        $application->update(['provider_contract_terms_version' => 'new-offer', 'student_response_terms_version' => 'new-offer']);
        $this->actingAs($provider)->postJson($this->resultUrl($record), [
            'status' => 'released', 'notes' => 'An older completion must not unlock the new offer.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('scheduled', $record->fresh()->status);
    }

    public function test_release_staff_see_the_hold_but_cannot_confirm_onboarding_or_get_a_dead_action_link(): void
    {
        [$provider, , $program, $application] = $this->recipient();
        $record = $this->release($program, $application);
        $officer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_benefit_releases'], 'assigned_program_ids' => [$program->id]]);
        $this->actingAs($officer)->getJson("/provider/workspaces/releases/distributions/{$record->recipient_benefit_release_id}/data")
            ->assertOk()->assertJsonPath('release.records.0.release_readiness.onboarding.can_distribute', false)
            ->assertJsonPath('release.records.0.release_readiness.onboarding_url', null);
        $this->postJson("/provider/applications/{$application->id}/onboarding/complete", [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1,
        ])->assertForbidden();
        $this->postJson($this->resultUrl($record), ['status' => 'released', 'notes' => 'Cannot bypass the hold.'])->assertUnprocessable();
    }

    public function test_existing_distributions_still_allow_applicant_receipt_confirmation_without_retroactive_onboarding(): void
    {
        [, $applicant, $program, $application] = $this->recipient();
        $record = $this->release($program, $application);
        // Represents a distribution already recorded before the new guard was installed.
        $record->update(['status' => 'released', 'released_at' => now()->subHour()]);
        $this->actingAs($applicant)->postJson("/dashboard/benefit-release-records/{$record->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
            'evidence' => UploadedFile::fake()->image('received.png'),
        ])->assertCreated();
        $this->assertDatabaseHas('recipient_benefit_receipt_responses', ['recipient_benefit_release_record_id' => $record->id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    private function recipient(?array $policy = ['final_agreement_required' => true]): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Release safeguard award',
            'description' => 'Test program.', 'status' => 'published', 'onboarding_policy' => ($policy['final_agreement_required'] ?? false)
                ? [...$policy, 'instructions' => 'Complete the final agreement at orientation or the first collection visit.'] : $policy]);
        $snapshot = RecipientAgreement::snapshot($program);
        $version = RecipientAgreement::version($snapshot);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete',
            'provider_contract_terms_snapshot' => $snapshot, 'provider_contract_terms_version' => $version,
            'student_response_status' => 'accepted', 'student_response_terms_version' => $version,
            'student_responded_at' => now()->subDays(2), 'provider_contract_terms_accepted_at' => now()->subDays(2)]);

        return [$provider, $applicant, $program, $application];
    }

    private function release(Scholarship $program, ScholarshipApplication $application, string $method = 'in_person'): RecipientBenefitReleaseRecord
    {
        return $program->benefitReleases()->create(['created_by' => $program->provider_id, 'title' => 'First benefit collection',
            'release_at' => now()->subHour(), 'benefit_description' => 'School materials', 'release_method' => $method,
            'requires_original_verification' => false, 'status' => 'scheduled', 'published_at' => now()])->records()->create([
                'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id, 'status' => 'scheduled',
            ]);
    }

    private function complete(ScholarshipApplication $application, User $provider): void
    {
        $service = app(RecipientOnboardingService::class);
        $record = $service->initializeForAcceptedApplication($application);
        $service->confirmCompletion($application, $provider, ['completed_on' => now()->toDateString(), 'confirmed' => true], $record->revision);
    }

    private function resultUrl(RecipientBenefitReleaseRecord $record): string
    {
        return route('provider.benefit-release-records.result', $record, false);
    }
}
