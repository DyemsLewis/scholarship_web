<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicantMonitoringDetailService;
use App\Services\RecipientOnboardingService;
use App\Support\RecipientAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApplicantRecipientOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_the_disclosed_requirement_is_available_before_intention_without_creating_records(): void
    {
        [, $applicant, $application] = $this->recipient(false);
        $this->actingAs($applicant)->getJson($this->url($application))
            ->assertOk()->assertJsonPath('detail.decision.state', 'agreement_required')
            ->assertJsonPath('detail.content.onboarding.state', 'awaiting_intention')
            ->assertJsonPath('detail.content.onboarding.can_proceed', false)
            ->assertJsonPath('detail.content.onboarding.instructions', $this->instructions());
        $this->getJson($this->url($application).'?view=check-ins')
            ->assertOk()->assertJsonPath('detail.content.onboarding.state', 'awaiting_intention');
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_onboarding_events', 0);
    }

    public function test_pending_onboarding_is_a_direct_next_action_in_the_detail_and_support_landing(): void
    {
        [$provider, $applicant, $application] = $this->recipient();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $service->publishInstructions($application, $provider, 'Bring your school ID to the orientation desk.', 1);
        $detail = $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('detail.decision.state', 'onboarding_required')
            ->assertJsonPath('detail.decision.title', 'Complete provider onboarding')
            ->assertJsonPath('detail.content.onboarding.status', 'pending')
            ->assertJsonPath('detail.content.onboarding.can_proceed', true)
            ->assertJsonPath('detail.content.onboarding.instructions', $this->instructions())
            ->assertJsonPath('detail.content.onboarding.provider_instructions', 'Bring your school ID to the orientation desk.');
        $this->assertStringEndsWith('#final-agreement', $detail->json('detail.decision.action.href'));
        $landing = $this->getJson(route('dashboard.monitoring.data'))->assertOk();
        $this->assertSame($detail->json('detail.decision'), $landing->json('support.focus.decision'));
        $this->assertDatabaseCount('recipient_onboarding_events', 2);
        $this->assertSame('selected', $application->fresh()->final_outcome);
    }

    public function test_a_one_time_award_keeps_requirements_visible_before_and_after_completion(): void
    {
        [$provider, $applicant, $application] = $this->recipient();
        $application->scholarship->update(['support_mode' => 'one_time']);
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        foreach (['pending', 'completed'] as $status) {
            if ($status === 'completed') {
                $service->confirmCompletion($application, $provider, [
                    'completed_on' => now()->toDateString(), 'confirmed' => true, 'note' => 'Private provider reference.',
                ], 1);
            }
            $response = $this->actingAs($applicant)->getJson($this->url($application))->assertOk();
            $this->assertContains('check-ins', array_column($response->json('detail.navigation'), 'key'));
            $this->getJson($this->url($application).'?view=check-ins')->assertOk()
                ->assertJsonPath('detail.content.onboarding.status', $status)
                ->assertJsonPath('detail.content.empty', null)
                ->assertJsonMissingPath('detail.content.onboarding.confirmation_note')
                ->assertJsonMissingPath('detail.content.onboarding.history')
                ->assertJsonMissing(['Private provider reference.']);
        }
        $this->getJson($this->url($application))->assertOk()
            ->assertJsonPath('detail.decision.state', 'no_action')
            ->assertJsonPath('detail.content.onboarding.completed_label', now()->format('M d, Y'));
    }

    public function test_legacy_and_explicitly_not_required_offers_do_not_acquire_onboarding(): void
    {
        foreach ([null, ['final_agreement_required' => false]] as $policy) {
            [, $applicant, $application] = $this->recipient(true, $policy);
            $application->scholarship->update(['support_mode' => 'one_time']);
            $response = $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
                ->assertJsonPath('detail.content.onboarding', null);
            $this->assertNotContains('check-ins', array_column($response->json('detail.navigation'), 'key'));
            $this->getJson($this->url($application).'?view=check-ins')->assertOk()
                ->assertJsonPath('detail.content.onboarding', null)
                ->assertJsonPath('detail.content.empty.key', 'one_time_benefit');
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_a_new_program_policy_does_not_change_a_legacy_frozen_offer(): void
    {
        [, $applicant, $application] = $this->recipient(true, null);
        // Simulates imported legacy data without bypassing the application's frozen offer.
        \Illuminate\Support\Facades\DB::table('scholarships')->where('id', $application->scholarship_id)->update([
            'onboarding_policy' => json_encode(['version' => 1, 'final_agreement_required' => true, 'instructions' => $this->instructions()]),
        ]);
        $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('detail.content.onboarding', null);
    }

    public function test_existing_receipt_actions_and_follow_up_replies_take_priority_over_pending_onboarding(): void
    {
        [, $applicant, $application] = $this->recipient();
        $record = $application->scholarship->benefitReleases()->create([
            'created_by' => $application->scholarship->provider_id, 'title' => 'Recorded allowance',
            'release_at' => now()->subDay(), 'benefit_description' => 'Allowance', 'release_method' => 'in_person',
            'status' => 'scheduled', 'published_at' => now(),
        ])->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'released',
        ]);
        $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('detail.decision.state', 'benefit_confirmation_required')
            ->assertJsonPath('detail.content.onboarding.can_proceed', true);

        $service = app(ApplicantMonitoringDetailService::class);
        $data = $service->build($application, $applicant, $this->monitoring([
            'benefit_releases' => [[
                'record_id' => $record->id, 'title' => 'Missed collection', 'status' => 'scheduled',
                'release_at' => now()->addDay()->format('Y-m-d\TH:i'), 'release_label' => 'Tomorrow',
                'follow_up' => ['status' => 'awaiting_response', 'can_reply' => true],
            ]],
        ]));
        $this->assertSame('benefit_follow_up', $data['decision']['state']);
    }

    public function test_closed_declined_and_mismatched_offers_do_not_request_new_onboarding(): void
    {
        [, $applicant, $application] = $this->recipient();
        $service = app(ApplicantMonitoringDetailService::class);
        $closed = $service->build($application, $applicant, $this->monitoring(['support_status' => 'completed']));
        $this->assertSame('support_closed', $closed['decision']['state']);
        $this->assertSame('inactive', $closed['content']['onboarding']['state']);
        $this->assertFalse($closed['content']['onboarding']['can_proceed']);
        $application->update(['student_response_status' => 'declined']);
        $declined = $service->build($application, $applicant, $this->monitoring());
        $this->assertSame('declined', $declined['content']['onboarding']['state']);
        $this->assertFalse($declined['content']['onboarding']['can_proceed']);
        $application->update(['student_response_status' => 'accepted', 'student_response_terms_version' => 'older-offer']);
        $mismatch = $service->build($application, $applicant, $this->monitoring());
        $this->assertSame('needs_review', $mismatch['content']['onboarding']['state']);
        $this->assertFalse($mismatch['content']['onboarding']['can_proceed']);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_applicants_cannot_record_their_own_completion_or_read_someone_elses_requirement(): void
    {
        [, $applicant, $application] = $this->recipient();
        $this->actingAs($applicant)->postJson("/provider/applications/{$application->id}/onboarding/complete", [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'expected_revision' => 1,
        ])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'applicant']))
            ->getJson($this->url($application).'?view=check-ins')->assertForbidden();
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_contact_links_use_only_safe_contacts_from_the_offer(): void
    {
        [, $applicant, $application] = $this->recipient();
        $snapshot = $application->provider_contract_terms_snapshot;
        $snapshot['provider_contact'] = ['email' => 'javascript:alert(1)', 'number' => 'javascript:alert(1)'];
        $application->update(['provider_contract_terms_snapshot' => $snapshot]);
        $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('detail.content.onboarding.contact.email_href', null)
            ->assertJsonPath('detail.content.onboarding.contact.phone_href', null);
    }

    private function instructions(): string
    {
        return 'Complete the final agreement at orientation or your first collection visit. Contact the provider to confirm arrangements.';
    }

    private function recipient(bool $accepted = true, ?array $policy = ['final_agreement_required' => true]): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Onboarding guidance award', 'description' => 'Test program.',
            'status' => 'published', 'onboarding_policy' => ($policy['final_agreement_required'] ?? false)
                ? [...$policy, 'instructions' => $this->instructions()] : $policy,
            'contact_email' => 'onboarding@example.test', 'contact_number' => '09171234567',
        ]);
        $snapshot = RecipientAgreement::snapshot($program);
        $version = RecipientAgreement::version($snapshot);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'workflow_stage' => 'complete', 'application_state' => 'closed',
            'provider_contract_terms_snapshot' => $snapshot, 'provider_contract_terms_version' => $version,
            'student_response_status' => $accepted ? 'accepted' : null, 'student_response_terms_version' => $accepted ? $version : null,
            'student_responded_at' => $accepted ? now() : null, 'provider_contract_terms_accepted_at' => $accepted ? now() : null,
        ]);

        return [$provider, $applicant, $application];
    }

    private function url(ScholarshipApplication $application): string
    {
        return route('dashboard.monitoring.show.data', $application);
    }

    private function monitoring(array $overrides = []): array
    {
        return [...['eligible' => true, 'agreement_accepted' => true, 'support_status' => 'active'], ...$overrides];
    }
}
