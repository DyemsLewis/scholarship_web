<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientOnboardingService;
use App\Support\RecipientAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderRecipientOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_reading_is_side_effect_free_and_the_provider_can_publish_and_complete_with_confirmation(): void
    {
        [$provider, $applicant, $application] = $this->recipient();
        $this->actingAs($provider)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('onboarding.status', 'pending')->assertJsonPath('onboarding.revision', 1)
            ->assertJsonPath('onboarding.can_manage', true);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->postJson($this->url($application).'/instructions', [
            'instructions' => 'Complete the agreement during your first collection visit. Bring your school ID.',
            'expected_revision' => 1, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('onboarding.revision', 2)->assertJsonPath('onboarding.status', 'pending');
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'recipient_onboarding_instructions']);
        $this->postJson($this->url($application).'/complete', [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'note' => 'Private office reference 18.', 'expected_revision' => 2,
        ])->assertOk()->assertJsonPath('onboarding.status', 'completed')->assertJsonPath('onboarding.revision', 3)
            ->assertJsonPath('onboarding.actions.complete', null)->assertJsonPath('onboarding.confirmed_by_name', $provider->name)
            ->assertJsonPath('onboarding.history.0.action', 'completion_confirmed')
            ->assertJsonPath('onboarding.history.1.action', 'instructions_published');
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'recipient_onboarding_completion']);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_benefit_release_records', 0);
        $this->assertDatabaseCount('application_schedules', 0);
        $summary = RecipientAgreement::payload($application->fresh());
        $this->assertFalse($summary['final_contract_recorded']);
        $this->assertStringNotContainsString('Private office reference', json_encode($summary));
    }

    public function test_stale_posts_do_not_duplicate_notifications_or_overwrite_audit_history(): void
    {
        [$provider, , $application] = $this->recipient();
        $payload = ['instructions' => 'Report to the scholarship office for your first collection.', 'expected_revision' => 1, 'confirmed' => true];
        $this->actingAs($provider)->postJson($this->url($application).'/instructions', $payload)->assertOk();
        $this->postJson($this->url($application).'/instructions', $payload)->assertConflict();
        $this->assertSame(1, PortalNotification::where('type', 'recipient_onboarding_instructions')->count());
        $this->assertDatabaseCount('recipient_onboarding_events', 2);
    }

    public function test_confirmation_and_date_validation_reject_attendance_and_contract_uploads(): void
    {
        [$provider, , $application] = $this->recipient();
        $this->actingAs($provider);
        foreach ([
            ['completed_on' => now()->toDateString(), 'expected_revision' => 1],
            ['completed_on' => now()->addDay()->toDateString(), 'confirmed' => true, 'expected_revision' => 1],
            ['completed_on' => now()->subDay()->toDateString(), 'confirmed' => true, 'expected_revision' => 1],
            ['completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1, 'attendance' => 'present'],
            ['completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1, 'contract_file' => UploadedFile::fake()->create('contract.pdf', 5)],
        ] as $input) {
            $this->postJson($this->url($application).'/complete', $input)->assertUnprocessable();
        }
        $this->postJson($this->url($application).'/instructions', ['instructions' => 'Contact the office.', 'expected_revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_onboarding_events', 0);
    }

    public function test_applicants_other_providers_and_unassigned_recipient_staff_cannot_access_or_mutate(): void
    {
        [$provider, $applicant, $application] = $this->recipient();
        $this->getJson($this->url($application))->assertUnauthorized();
        $other = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $other->providerProfile()->update(['verification_status' => 'approved']);
        $unassigned = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_recipients'], 'assigned_program_ids' => []]);
        foreach ([$applicant, $other, $unassigned] as $actor) {
            $this->actingAs($actor)->getJson($this->url($application))->assertForbidden();
            $this->postJson($this->url($application).'/complete', [
                'completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1,
            ])->assertForbidden();
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_release_staff_can_read_status_but_not_private_notes_history_or_mutation_actions(): void
    {
        [$provider, , $application] = $this->recipient();
        $service = app(RecipientOnboardingService::class);
        $service->confirmCompletion($application, $provider, [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'note' => 'Private completion reference.',
        ], 1);
        $releaseOfficer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_benefit_releases'], 'assigned_program_ids' => [$application->scholarship_id]]);
        $this->actingAs($releaseOfficer)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('onboarding.status', 'completed')->assertJsonPath('onboarding.can_manage', false)
            ->assertJsonPath('onboarding.confirmation_note', null)->assertJsonPath('onboarding.history', [])
            ->assertJsonPath('onboarding.actions.publish', null)->assertJsonPath('onboarding.actions.complete', null);
        $this->postJson($this->url($application).'/instructions', [
            'instructions' => 'Must not replace instructions.', 'expected_revision' => 2, 'confirmed' => true,
        ])->assertForbidden();
    }

    public function test_pending_declined_legacy_and_ended_offers_are_read_only(): void
    {
        foreach (['pending', 'declined', 'legacy', 'ended'] as $state) {
            [$provider, , $application] = $this->recipient();
            if ($state === 'legacy') {
                $snapshot = $application->provider_contract_terms_snapshot;
                unset($snapshot['onboarding']);
                $application->update(['provider_contract_terms_snapshot' => $snapshot]);
            } elseif ($state === 'ended') {
                $application->update(['status' => 'benefits_terminated']);
            } else {
                $application->update(['student_response_status' => $state === 'pending' ? null : 'declined']);
            }
            $this->actingAs($provider)->getJson($this->url($application))->assertOk()
                ->assertJsonPath('onboarding.actions.complete', null)->assertJsonPath('onboarding.actions.publish', null);
            $this->postJson($this->url($application).'/complete', [
                'completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    private function url(ScholarshipApplication $application): string
    {
        return "/provider/applications/{$application->id}/onboarding";
    }

    private function recipient(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Recipient onboarding test',
            'description' => 'Test program.', 'status' => 'published', 'onboarding_policy' => [
                'final_agreement_required' => true, 'instructions' => 'Complete the agreement during orientation or your first collection visit.',
            ]]);
        $snapshot = RecipientAgreement::snapshot($program);
        $version = RecipientAgreement::version($snapshot);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'workflow_stage' => 'complete', 'application_state' => 'closed',
            'provider_contract_terms_snapshot' => $snapshot, 'provider_contract_terms_version' => $version,
            'student_response_status' => 'accepted', 'student_response_terms_version' => $version, 'student_responded_at' => now()]);

        return [$provider, $applicant, $application];
    }
}
