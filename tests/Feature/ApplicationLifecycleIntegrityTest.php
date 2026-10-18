<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\RecipientSupportDecision;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationLifecycleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_withdrawal_clears_waitlist_and_open_correction_state(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = $this->applicationAtDecision($this->program($provider), $applicant, $provider);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordFinalOutcome($application, 'waitlisted', $provider);
        $workflow->requestCorrection($application, $provider, 'Confirm your latest school record.');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/withdraw", [
                'reason' => 'I no longer want to continue this application.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'withdrawn')
            ->assertJsonPath('application.final_outcome', null)
            ->assertJsonPath('application.correction_status', null)
            ->assertJsonPath('application.waitlist_position', null)
            ->assertJsonPath('application.workflow.next_action.key', 'withdrawn');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'withdrawn',
            'application_state' => 'withdrawn',
            'workflow_stage' => 'complete',
            'final_outcome' => null,
            'correction_status' => null,
            'waitlist_position' => null,
            'decision_reason' => 'applicant_withdrawal',
        ]);
        $this->assertDatabaseMissing('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'status' => 'current',
        ]);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/withdraw", [
                'reason' => 'Trying to withdraw the same application again.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_an_open_correction_cannot_be_overwritten_or_resolved_before_response(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = app(ApplicationWorkflowService::class)->start(
            $this->application($this->program($provider), $applicant),
        );

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'request',
                'message' => 'Upload a clearer copy of the school record.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'request',
                'message' => 'This request must not replace the first request.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'resolve',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'correction_status' => 'requested',
            'correction_message' => 'Upload a clearer copy of the school record.',
        ]);
    }

    public function test_correction_returns_to_the_same_stage_and_notifies_the_responsible_reviewer(): void
    {
        $provider = $this->provider();
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['review_applications'],
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($this->application($this->program($provider), $applicant));
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application->update(['assigned_reviewer_id' => $reviewer->id]);

        $this->actingAs($reviewer)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'request',
                'targets' => ['application_files'],
                'message' => 'Replace the unreadable school document.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'formal_application');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/formal_application/result", [
                'result' => 'passed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/correction-response", [
                'response' => 'I uploaded a readable replacement.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'application_correction',
            'title' => 'Applicant submitted a correction',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $reviewer->id,
            'type' => 'application_correction',
            'title' => 'Applicant submitted a correction',
        ]);

        $this->actingAs($reviewer)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'resolve',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'formal_application')
            ->assertJsonPath('application.workflow.application_state', 'in_provider_process');
    }

    public function test_duplicate_waitlist_decision_is_rejected_and_restore_is_audited(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = $this->applicationAtDecision($this->program($provider), $applicant, $provider);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/waitlist", ['action' => 'waitlist'])
            ->assertOk();

        $historyCount = ApplicationStatusHistory::query()
            ->where('scholarship_application_id', $application->id)
            ->count();
        $notificationCount = PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'application_outcome')
            ->count();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/waitlist", ['action' => 'waitlist'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        $this->assertSame($historyCount, ApplicationStatusHistory::query()
            ->where('scholarship_application_id', $application->id)
            ->count());
        $this->assertSame($notificationCount, PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'application_outcome')
            ->count());

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/waitlist", [
                'action' => 'restore',
                'note' => 'Return this applicant to the final review list.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.final_outcome', null)
            ->assertJsonPath('application.workflow.current_stage', 'decision');

        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'from_status' => 'waitlisted',
            'to_status' => 'approved',
            'decision_reason' => 'waitlist_restored',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $provider->id,
            'action' => 'application_waitlist_updated',
        ]);
    }

    public function test_canonical_final_decision_enforces_program_capacity(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider, ['slots_available' => 1]);
        $workflow = app(ApplicationWorkflowService::class);
        $first = $this->applicationAtDecision(
            $program,
            User::factory()->create(['role' => 'applicant']),
            $provider,
        );
        $second = $this->applicationAtDecision(
            $program,
            User::factory()->create(['role' => 'applicant']),
            $provider,
        );

        $workflow->recordFinalOutcome($first, 'selected', $provider);

        try {
            $workflow->recordFinalOutcome($second, 'selected', $provider);
            $this->fail('A second applicant was selected after all award slots were filled.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('outcome', $error->errors());
        }

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $second->id,
            'status' => 'approved',
            'application_state' => 'awaiting_decision',
            'workflow_stage' => 'decision',
            'final_outcome' => null,
        ]);
    }

    public function test_provider_can_reopen_the_latest_stage_result_with_an_audit_reason(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($this->application($this->program($provider), $applicant));
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $schedule = ApplicationSchedule::create([
            'scholarship_application_id' => $application->id,
            'type' => 'formal_application',
            'title' => 'Formal application handoff',
            'scheduled_at' => now()->addDay(),
            'mode' => 'provider_managed',
            'instructions' => 'Follow the provider instructions.',
            'status' => 'scheduled',
            'attendance_status' => 'not_required',
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data?workspace=review")
            ->assertOk()
            ->assertJsonPath('application.decision_reopen.can_reopen', true)
            ->assertJsonPath('application.decision_reopen.stage', 'screening');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision/reopen", [
                'reason' => 'The reviewer selected the wrong screening result.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'under_review')
            ->assertJsonPath('application.workflow.current_stage', 'screening')
            ->assertJsonPath('application.decision_reopen.can_reopen', false);

        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'screening',
            'status' => 'current',
            'result' => null,
        ]);
        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'formal_application',
            'status' => 'pending',
        ]);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'decision_reason' => 'decision_reopened',
            'review_notes' => 'Pre-screening review reopened: The reviewer selected the wrong screening result.',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
            'title' => 'Application review reopened',
        ]);
    }

    public function test_provider_can_reopen_a_failed_selection_stage(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($this->application($this->program($provider), $applicant));
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult(
            $application,
            'formal_application',
            'not_passed',
            $provider,
            'The original completion record was incorrect.',
            'formal_application_not_completed',
        );

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision/reopen", [
                'reason' => 'New provider records show the handoff was completed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.application_state', 'in_provider_process')
            ->assertJsonPath('application.workflow.current_stage', 'formal_application')
            ->assertJsonPath('application.final_outcome', null);

        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'formal_application',
            'status' => 'current',
            'result' => null,
        ]);
    }

    public function test_provider_can_reopen_a_final_outcome_before_recipient_onboarding(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $this->applicationAtDecision($this->program($provider), $applicant, $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision/reopen", [
                'reason' => 'The final selection was recorded for the wrong applicant.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.application_state', 'awaiting_decision')
            ->assertJsonPath('application.workflow.current_stage', 'decision')
            ->assertJsonPath('application.final_outcome', null)
            ->assertJsonPath('redirect_url', "/provider/applications/{$application->id}?workspace=decision");

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'provider_contract_terms_snapshot' => null,
            'awarded_amount' => null,
        ]);
    }

    public function test_selected_outcome_cannot_be_reopened_after_recipient_activity_begins(): void
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $this->applicationAtDecision($this->program($provider), $applicant, $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        RecipientSupportDecision::create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'decision' => 'renewed',
            'effective_on' => now()->toDateString(),
            'next_period_terms' => 'Continue support under the existing recipient agreement.',
            'decided_by' => $provider->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision/reopen", [
                'reason' => 'Attempt to reopen after recipient support started.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
        ]);
        $this->assertDatabaseMissing('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'decision_reason' => 'decision_reopened',
        ]);
    }

    public function test_application_files_are_immutable_after_the_application_closes(): void
    {
        Storage::fake('local');
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = app(ApplicationWorkflowService::class)->start(
            $this->application($this->program($provider), $applicant),
        );
        $path = "application-documents/{$application->id}/school-record.pdf";
        Storage::disk('local')->put($path, 'school record');
        $document = ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Latest report card or grades',
            'original_name' => 'school-record.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 13,
            'status' => 'rejected',
            'uploaded_at' => now(),
        ]);
        app(ApplicationWorkflowService::class)->recordStageResult(
            $application,
            'screening',
            'not_passed',
            $provider,
        );

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", [
                'status' => 'accepted',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/documents", [
                'document_name' => 'Latest report card or grades',
                'document_file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        $this->assertDatabaseHas('application_documents', [
            'id' => $document->id,
            'path' => $path,
            'status' => 'rejected',
        ]);
    }

    private function provider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        return $provider;
    }

    private function program(User $provider, array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to verify application lifecycle safeguards.',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'slots_available' => 5,
            'status' => 'published',
            ...$attributes,
        ]);
    }

    private function application(Scholarship $program, User $applicant): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
    }

    private function applicationAtDecision(
        Scholarship $program,
        User $applicant,
        User $provider,
    ): ScholarshipApplication {
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($this->application($program, $applicant));
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);

        return $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
    }
}
