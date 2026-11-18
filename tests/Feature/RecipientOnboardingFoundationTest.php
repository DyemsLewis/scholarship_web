<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\RecipientOnboardingService;
use App\Support\RecipientAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecipientOnboardingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_program_creation_and_unrelated_edits_preserve_a_normalized_policy(): void
    {
        $provider = $this->provider();
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Onboarding test intake', 'status' => 'draft',
            'onboarding_policy' => json_encode($this->policy()),
        ])->assertCreated()->assertJsonPath('scholarship.onboarding_policy.final_agreement_required', true)
            ->assertJsonPath('scholarship.onboarding_policy.version', 1);
        $program = Scholarship::findOrFail($response->json('scholarship.id'));
        $before = $program->onboarding_policy;
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => 'Revised test title', 'status' => 'draft',
        ])->assertOk()->assertJsonPath('scholarship.onboarding_policy', $before);
        $this->assertSame($before, $program->fresh()->onboarding_policy);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_an_explicit_no_requirement_policy_has_no_invented_instructions(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Optional agreement intake', 'status' => 'draft',
            'onboarding_policy' => ['final_agreement_required' => false, 'instructions' => 'Unused draft instructions.'],
        ])->assertCreated()->assertJsonPath('scholarship.onboarding_policy.final_agreement_required', false)
            ->assertJsonPath('scholarship.onboarding_policy.instructions', null);
    }

    public function test_public_program_data_includes_the_requirement_before_offer_confirmation(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider, $this->policy());
        $applicant = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($applicant)->getJson('/dashboard/scholarships/data')
            ->assertOk()->assertJsonPath('scholarships.0.onboarding_policy.final_agreement_required', true)
            ->assertJsonPath('scholarships.0.onboarding_policy.instructions', $this->policy()['instructions']);
        $this->getJson("/dashboard/scholarships/{$program->id}/data?section=award")
            ->assertOk()->assertJsonPath('detail.content.recipient_terms.onboarding_policy.final_agreement_required', true);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_invalid_policy_types_missing_instructions_and_undisclosed_fields_are_rejected(): void
    {
        $provider = $this->provider();
        foreach ([
            '{broken', [], true, ['version' => 2, ...$this->policy()],
            ['final_agreement_required' => 'yes'],
            ['final_agreement_required' => true],
            ['final_agreement_required' => true, 'instructions' => '   '],
            ['final_agreement_required' => true, 'instructions' => str_repeat('x', 3001)],
            [...$this->policy(), 'signed_contract' => 'contract.pdf'],
            [...$this->policy(), 'status' => 'completed'],
        ] as $policy) {
            $this->actingAs($provider)->postJson('/provider/scholarships', [
                'title' => 'Must not be saved', 'status' => 'draft', 'onboarding_policy' => $policy,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_required_policy_and_instructions_are_locked_after_the_first_application(): void
    {
        [$provider, , $program] = $this->offer(false);
        $before = $program->onboarding_policy;
        foreach ([['final_agreement_required' => false], [...$this->policy(), 'instructions' => 'Different obligations.']] as $policy) {
            $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
                'title' => 'Must not replace the saved title', 'status' => 'draft', 'onboarding_policy' => $policy,
            ])->assertUnprocessable()->assertJsonValidationErrors('onboarding_policy');
            $this->assertSame($before, $program->fresh()->onboarding_policy);
            $this->assertSame('Onboarding test program', $program->fresh()->title);
        }
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft', 'onboarding_policy' => $before,
        ])->assertOk();
        $this->assertRejected(fn () => $program->fresh()->update(['onboarding_policy' => ['final_agreement_required' => false]]));
    }

    public function test_a_legacy_intake_cannot_silently_acquire_a_new_requirement(): void
    {
        [$provider, , $program, $application] = $this->offer(true, null);
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft', 'onboarding_policy' => $this->policy(),
        ])->assertUnprocessable()->assertJsonValidationErrors('onboarding_policy');
        $this->assertNull($program->fresh()->onboarding_policy);
        $this->assertSame('accepted', $application->fresh()->student_response_status);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_another_organization_cannot_edit_the_program_onboarding_policy(): void
    {
        $program = $this->program($this->provider(), $this->policy());
        $this->actingAs($this->provider())->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft',
            'onboarding_policy' => ['final_agreement_required' => false],
        ])->assertForbidden();
        $this->assertTrue($program->fresh()->onboarding_policy['final_agreement_required']);
    }

    public function test_selection_freezes_the_requirement_and_offer_acceptance_does_not_complete_it(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = $this->program($provider, $this->policy());
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $this->assertSame(true, $application->provider_contract_terms_snapshot['onboarding']['final_agreement_required']);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);

        $this->accept($application, $applicant)->assertOk()
            ->assertJsonPath('application.recipient_agreement.status_label', 'Intention confirmed')
            ->assertJsonPath('application.recipient_agreement.final_contract_recorded', false)
            ->assertJsonPath('application.recipient_agreement.onboarding.status', 'pending')
            ->assertJsonPath('application.recipient_agreement.onboarding.provider_confirmation_recorded', false);
        $record = $application->recipientOnboarding()->sole();
        $this->assertSame('pending', $record->status);
        $this->assertNull($record->confirmed_by);
        $this->assertNull($record->confirmed_at);
        $this->assertSame($application->provider_contract_terms_snapshot['onboarding'], $record->policy_snapshot);
        $this->assertSame($applicant->id, $record->events()->sole()->actor_id);
        $this->assertSame('selected', $application->fresh()->final_outcome);
    }

    public function test_reads_do_not_create_records_and_legacy_offers_never_read_current_program_requirements(): void
    {
        [, , $program, $application] = $this->offer(true, null);
        DB::table('scholarships')->where('id', $program->id)->update(['onboarding_policy' => json_encode($this->policy())]);
        $summary = app(RecipientOnboardingService::class)->summary($application->fresh());
        $this->assertSame('legacy_offer', $summary['source']);
        $this->assertSame('not_required', $summary['status']);
        $this->assertFalse($summary['provider_confirmation_recorded']);
        $this->assertNull(app(RecipientOnboardingService::class)->initializeForAcceptedApplication($application));
        $snapshot = RecipientAgreement::snapshot($program->fresh(), $application->fresh());
        $this->assertArrayNotHasKey('onboarding', $snapshot);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_onboarding_events', 0);
    }

    public function test_initialization_is_idempotent_and_uses_the_offer_not_changed_program_data(): void
    {
        [, , $program, $application] = $this->offer();
        DB::table('scholarships')->where('id', $program->id)->update(['onboarding_policy' => json_encode(['final_agreement_required' => false])]);
        $service = app(RecipientOnboardingService::class);
        $first = $service->initializeForAcceptedApplication($application);
        $second = $service->initializeForAcceptedApplication($application);
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->policy_snapshot['final_agreement_required']);
        $this->assertSame($second->policy_snapshot, RecipientAgreement::snapshot($program->fresh(), $application)['onboarding']);
        $this->assertDatabaseCount('recipient_onboarding_records', 1);
        $this->assertDatabaseCount('recipient_onboarding_events', 1);
    }

    public function test_initialization_distinguishes_not_required_from_provider_confirmed_completion(): void
    {
        [, , , $application] = $this->offer(true, ['final_agreement_required' => false]);
        $record = app(RecipientOnboardingService::class)->initializeForAcceptedApplication($application);
        $this->assertSame('not_required', $record->status);
        $this->assertNull($record->completed_on);
        $this->assertNull($record->confirmed_at);
        $this->assertFalse(app(RecipientOnboardingService::class)->summary($application)['provider_confirmation_recorded']);
    }

    public function test_the_applicant_can_decline_without_starting_onboarding_or_losing_the_selection_record(): void
    {
        [, $applicant, , $application] = $this->offer(false);
        $this->actingAs($applicant)->patchJson("/dashboard/applications/{$application->id}/response", [
            'response' => 'declined', 'note' => 'I cannot proceed with the provider onboarding.',
        ])->assertOk();
        $this->assertSame('selected', $application->fresh()->final_outcome);
        $this->assertSame('declined', $application->fresh()->student_response_status);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_instruction_publication_and_completion_have_separate_audited_revisions(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $record = $service->initializeForAcceptedApplication($application);
        $record = $service->publishInstructions($application, $provider, 'Sign during the first collection visit. No separate appointment is needed.', $record->revision);
        $this->assertSame(2, $record->revision);
        $this->assertSame('pending', $record->status);
        $record = $service->confirmCompletion($application, $provider, [
            'completed_on' => now()->toDateString(), 'confirmed' => true, 'note' => 'Private provider record reference 42.',
        ], $record->revision);
        $this->assertSame(3, $record->revision);
        $this->assertSame($provider->id, $record->confirmed_by);
        $this->assertNotNull($record->confirmed_at);
        $this->assertSame(['initialized', 'instructions_published', 'completion_confirmed'], $record->events->pluck('action')->all());
        $this->assertSame([1, 2, 3], $record->events->pluck('revision')->all());
        $this->assertSame($provider->name, $record->events->last()->actor_name);
        $payload = RecipientAgreement::payload($application->fresh());
        $this->assertSame('completed', $payload['onboarding']['status']);
        $this->assertTrue($payload['onboarding']['provider_confirmation_recorded']);
        $this->assertFalse($payload['final_contract_recorded']);
        $this->assertStringNotContainsString('Private provider record', json_encode($payload));
        $this->assertSame($this->policy()['instructions'], $payload['onboarding']['instructions']);
        $this->assertSame($record->instructions, $payload['onboarding']['provider_instructions']);
        $this->assertSame('selected', $application->fresh()->final_outcome);
        $this->assertSame('awarded', $application->fresh()->status);
    }

    public function test_stale_revisions_and_repeat_confirmation_cannot_replace_history(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $service->publishInstructions($application, $provider, 'Contact the scholarship office before your collection visit.', 1);
        $this->assertRejected(fn () => $service->publishInstructions($application, $provider, 'Stale instructions must not replace this.', 1), 409);
        $record = $service->confirmCompletion($application, $provider, ['completed_on' => now()->toDateString(), 'confirmed' => true], 2);
        $this->assertRejected(fn () => $service->confirmCompletion($application, $provider, ['completed_on' => now()->toDateString(), 'confirmed' => true], 3));
        $this->assertSame(3, $record->fresh()->revision);
        $this->assertDatabaseCount('recipient_onboarding_events', 3);
    }

    public function test_applicants_other_organizations_release_staff_and_unassigned_staff_cannot_confirm(): void
    {
        [$provider, $applicant, $program, $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        foreach ([
            $applicant, $this->provider(), User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_benefit_releases']]),
            User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_recipients'], 'assigned_program_ids' => []]),
        ] as $actor) {
            $this->assertRejected(fn () => $service->confirmCompletion($application, $actor, ['completed_on' => now()->toDateString(), 'confirmed' => true], 1), 403);
        }
        $authorized = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_recipients'], 'assigned_program_ids' => [$program->id]]);
        $this->assertSame($authorized->id, $service->confirmCompletion($application, $authorized, ['completed_on' => now()->toDateString(), 'confirmed' => true], 1)->confirmed_by);
    }

    public function test_inactive_staff_and_unapproved_organizations_cannot_publish_instructions(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $provider->update(['account_status' => 'suspended']);
        $this->assertRejected(fn () => $service->publishInstructions($application, $provider, 'New instructions.', 1), 403);
        $provider->update(['account_status' => 'active']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $this->assertRejected(fn () => $service->publishInstructions($application, $provider, 'New instructions.', 1), 403);
        $this->assertDatabaseCount('recipient_onboarding_events', 1);
    }

    public function test_completion_requires_intention_confirmation_and_an_explicit_provider_confirmation(): void
    {
        [$provider, , , $application] = $this->offer(false);
        $service = app(RecipientOnboardingService::class);
        $this->assertRejected(fn () => $service->initializeForAcceptedApplication($application));
        $this->assertRejected(fn () => $service->confirmCompletion($application, $provider, ['completed_on' => now()->toDateString(), 'confirmed' => true], 1));
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
    }

    public function test_legacy_and_not_required_offers_cannot_receive_a_fabricated_completion(): void
    {
        foreach ([null, ['final_agreement_required' => false]] as $policy) {
            [$provider, , , $application] = $this->offer(true, $policy);
            $this->assertRejected(fn () => app(RecipientOnboardingService::class)->confirmCompletion($application, $provider, [
                'completed_on' => now()->toDateString(), 'confirmed' => true,
            ], 1));
        }
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_onboarding_events', 0);
    }

    public function test_ended_support_leaves_onboarding_read_only(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $application->update(['status' => 'benefits_terminated']);
        $this->assertRejected(fn () => $service->confirmCompletion($application, $provider, [
            'completed_on' => now()->toDateString(), 'confirmed' => true,
        ], 1));
        $this->assertSame('pending', $application->recipientOnboarding()->sole()->status);
        $this->assertDatabaseCount('recipient_onboarding_events', 1);
    }

    public function test_invalid_dates_attendance_and_contract_files_cannot_complete_the_requirement(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        foreach ([
            ['completed_on' => now()->addDay()->toDateString(), 'confirmed' => true],
            ['completed_on' => now()->subDays(2)->toDateString(), 'confirmed' => true],
            ['completed_on' => now()->toDateString(), 'confirmed' => false],
            ['completed_on' => now()->toDateString(), 'attendance' => 'present'],
            ['completed_on' => now()->toDateString(), 'confirmed' => true, 'contract_file' => 'contract.pdf'],
        ] as $data) {
            $this->assertRejected(fn () => $service->confirmCompletion($application, $provider, $data, 1));
        }
        $this->assertSame('pending', $application->recipientOnboarding()->sole()->status);
        $this->assertDatabaseCount('recipient_onboarding_events', 1);
    }

    public function test_a_mismatched_offer_cannot_reuse_a_completion(): void
    {
        [$provider, , , $application] = $this->offer();
        $service = app(RecipientOnboardingService::class);
        $service->initializeForAcceptedApplication($application);
        $service->confirmCompletion($application, $provider, ['completed_on' => now()->toDateString(), 'confirmed' => true], 1);
        $application->update(['student_response_terms_version' => 'unconfirmed-offer-version']);
        $this->assertSame('pending', $service->summary($application->fresh())['status']);
        $this->assertRejected(fn () => $service->publishInstructions($application, $provider, 'Do not use this outdated confirmation.', 2), 409);
        $application->update(['provider_contract_terms_version' => 'different-offer', 'student_response_terms_version' => 'different-offer']);
        $this->assertSame('pending', $service->summary($application->fresh())['status']);
        $this->assertRejected(fn () => $service->initializeForAcceptedApplication($application), 409);
        $this->assertDatabaseCount('recipient_onboarding_events', 2);
    }

    public function test_failed_initialization_rolls_back_the_applicants_offer_response(): void
    {
        [, $applicant, , $application] = $this->offer(false);
        $snapshot = $application->provider_contract_terms_snapshot;
        $snapshot['onboarding'] = ['final_agreement_required' => true];
        $application->update(['provider_contract_terms_snapshot' => $snapshot]);
        $this->accept($application, $applicant)->assertUnprocessable();
        $this->assertNull($application->fresh()->student_response_status);
        $this->assertNull($application->fresh()->provider_contract_terms_accepted_at);
        $this->assertDatabaseCount('recipient_onboarding_records', 0);
        $this->assertDatabaseCount('recipient_onboarding_events', 0);
    }

    private function policy(): array
    {
        return ['final_agreement_required' => true, 'instructions' => 'Complete the final agreement with the provider before the first benefit distribution.'];
    }

    private function provider(): User
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => User::PROVIDER_PERMISSIONS]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);

        return $provider;
    }

    private function program(User $provider, ?array $policy): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Onboarding test program', 'description' => 'A fictional test intake.',
            'status' => 'published', 'selection_stages' => ['screening', 'formal_application', 'decision'],
            'onboarding_policy' => $policy,
        ]);
    }

    private function offer(bool $accepted = true, ?array $policy = ['final_agreement_required' => true, 'instructions' => 'Complete the final agreement with the provider before the first benefit distribution.']): array
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = $this->program($provider, $policy);
        $snapshot = RecipientAgreement::snapshot($program);
        $version = RecipientAgreement::version($snapshot);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete',
            'provider_contract_terms_snapshot' => $snapshot, 'provider_contract_terms_version' => $version,
            'student_response_status' => $accepted ? 'accepted' : null,
            'student_response_terms_version' => $accepted ? $version : null,
            'student_responded_at' => $accepted ? now() : null,
            'provider_contract_terms_accepted_at' => $accepted ? now() : null,
        ]);

        return [$provider, $applicant, $program, $application];
    }

    private function accept(ScholarshipApplication $application, User $applicant): TestResponse
    {
        return $this->actingAs($applicant)->patchJson("/dashboard/applications/{$application->id}/response", [
            'response' => 'accepted', 'terms_accepted' => true, 'agreement_version' => $application->provider_contract_terms_version,
        ]);
    }

    private function assertRejected(callable $action, int $expectedStatus = 422): void
    {
        try {
            $action();
            $this->fail('The operation should have been rejected.');
        } catch (ValidationException $error) {
            $this->assertSame($expectedStatus, $error->status);
        } catch (HttpException $error) {
            $this->assertSame($expectedStatus, $error->getStatusCode());
        }
    }
}
