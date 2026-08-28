<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderExamReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_records_one_exam_result_and_advances_to_the_configured_next_stage(): void
    {
        [$provider, $applicant, $application] = $this->applicationAtStage('exam');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
                'result' => 'passed',
                'notes' => 'The provider confirmed the applicant passed the external exam.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('application.workflow.current_stage', 'formal_application')
            ->assertJsonPath('application.workflow.next_action.label', 'Follow the provider application instructions');

        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'exam',
            'status' => 'passed',
            'result' => 'passed',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
            'title' => 'Exam passed',
        ]);
    }

    public function test_failed_exam_requires_a_decision_reason(): void
    {
        [$provider, $_applicant, $application] = $this->applicationAtStage('exam');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
                'result' => 'not_passed',
                'notes' => 'Applicant did not pass the scholarship exam.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_reason');

        $this->assertSame('exam', $application->fresh()->workflow_stage);
        $this->assertSame(0, PortalNotification::query()->count());
    }

    public function test_failed_interview_closes_the_application_and_notifies_the_applicant(): void
    {
        [$provider, $applicant, $application] = $this->applicationAtStage('interview');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/interview/result", [
                'result' => 'not_passed',
                'decision_reason' => 'failed_interview',
                'notes' => 'Applicant did not pass the scholarship interview.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'interview_failed')
            ->assertJsonPath('application.workflow.current_stage', 'complete')
            ->assertJsonPath('application.workflow.application_state', 'closed');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'interview_failed',
            'decision_reason' => 'failed_interview',
            'final_outcome' => 'not_selected',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
            'title' => 'Interview not passed',
        ]);
    }

    private function applicationAtStage(string $stage): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $middleStages = $stage === 'exam'
            ? ['exam', 'formal_application']
            : ['interview', 'formal_application'];
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => ucfirst($stage).' Workflow Scholarship',
            'description' => 'Used to verify a provider-managed external stage result.',
            'selection_stages' => ['screening', ...$middleStages, 'decision'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);

        return [$provider, $applicant, $application];
    }
}
