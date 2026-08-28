<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\ScholarshipEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApplicationWorkflowReworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_configured_stage_order_advances_without_attendance_or_schedule_completion(): void
    {
        [$provider, $application] = $this->application([
            'screening',
            'formal_application',
            'exam',
            'interview',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);

        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $this->assertSame('formal_application', $application->workflow_stage);

        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $this->assertSame('exam', $application->workflow_stage);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
                'result' => 'passed',
                'notes' => 'Provider confirmed the exam result outside the portal.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'interview');

        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'exam',
            'status' => 'passed',
        ]);
    }

    public function test_publishing_an_old_stage_schedule_does_not_regress_an_applicant(): void
    {
        [$provider, $application] = $this->application([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'exam', 'passed', $provider);
        $this->assertSame('formal_application', $application->workflow_stage);

        $event = ScholarshipEvent::create([
            'scholarship_id' => $application->scholarship_id,
            'type' => 'exam',
            'title' => 'Additional exam schedule',
            'scheduled_at' => now()->addDay(),
            'mode' => 'onsite',
            'venue' => 'Provider office',
            'instructions' => 'Only current exam-stage applicants should receive this.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        $this->assertSame(0, app(ScholarshipEventService::class)->syncEligibleApplications($event));
        $this->assertSame('formal_application', $application->fresh()->workflow_stage);
        $this->assertDatabaseMissing('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
        ]);
    }

    public function test_submitted_stage_order_stays_stable_and_handoff_appears_only_when_reached(): void
    {
        [$provider, $application] = $this->application([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);

        $application->scholarship->update([
            'selection_stages' => ['screening', 'interview', 'formal_application', 'decision'],
        ]);

        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $this->assertSame('exam', $application->workflow_stage);
        $this->assertSame(
            ['screening', 'exam', 'formal_application', 'decision'],
            collect($workflow->payload($application)['steps'])->pluck('key')->all(),
        );

        $this->actingAs($application->applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.formal_application_handoff', null);

        $application = $workflow->recordStageResult($application, 'exam', 'passed', $provider);

        $this->actingAs($application->applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'formal_application')
            ->assertJsonStructure(['application' => ['formal_application_handoff']]);
    }

    public function test_final_outcomes_snapshots_and_withdrawal_use_the_canonical_state(): void
    {
        [$provider, $application] = $this->application([
            'screening',
            'formal_application',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $this->assertSame(1, data_get($application->submission_snapshot, 'version'));

        $application = $workflow->captureSubmissionSnapshot($application, 'correction_resubmitted');
        $this->assertSame(2, data_get($application->submission_snapshot, 'version'));
        $this->assertCount(1, data_get($application->submission_snapshot, 'history', []));

        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'waitlisted', $provider);
        $this->assertSame('decision', $application->workflow_stage);
        $this->assertSame('awaiting_decision', $application->application_state);

        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $this->assertSame('selected', $application->final_outcome);
        $this->assertSame('closed', $application->application_state);
        $this->assertSame('complete', $application->workflow_stage);

        [, $withdrawnApplication] = $this->application();
        $withdrawnApplication = $workflow->start($withdrawnApplication);
        $withdrawnApplication = $workflow->withdraw(
            $withdrawnApplication,
            $withdrawnApplication->applicant,
            'Applying to a different program.',
        );
        $this->assertSame('withdrawn', $withdrawnApplication->application_state);
        $this->assertSame('complete', $withdrawnApplication->workflow_stage);
    }

    private function application(array $stages = ['screening', 'formal_application', 'decision']): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to verify the canonical application workflow.',
            'selection_stages' => $stages,
            'slots_available' => 10,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        return [$provider, $application];
    }
}
