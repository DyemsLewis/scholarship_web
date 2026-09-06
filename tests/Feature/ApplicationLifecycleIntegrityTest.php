<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
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
