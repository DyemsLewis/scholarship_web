<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProgramSelectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_passing_prescreening_advances_to_the_configured_stage_and_syncs_its_schedule(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);
        ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'exam',
            'title' => 'General qualifying exam',
            'scheduled_at' => now()->addDays(3),
            'mode' => 'onsite',
            'venue' => 'Community Learning Center',
            'instructions' => 'Bring a school ID and arrive 15 minutes early.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Eligibility and required files were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'exam')
            ->assertJsonPath('application.status', 'exam_qualified')
            ->assertJsonPath('application.schedules.0.type', 'exam');

        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
            'attendance_status' => 'not_required',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
    }

    public function test_provider_can_configure_exam_and_formal_application_in_either_order(): void
    {
        $workflow = app(ApplicationWorkflowService::class);
        [$provider, , , $examFirst] = $this->applicationWithPlan([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);
        $examFirst = $workflow->start($examFirst);
        $examFirst = $workflow->recordStageResult($examFirst, 'screening', 'passed', $provider);
        $this->assertSame('exam', $examFirst->workflow_stage);

        [$secondProvider, , , $formalFirst] = $this->applicationWithPlan([
            'screening',
            'formal_application',
            'exam',
            'decision',
        ]);
        $formalFirst = $workflow->start($formalFirst);
        $formalFirst = $workflow->recordStageResult($formalFirst, 'screening', 'passed', $secondProvider);
        $this->assertSame('formal_application', $formalFirst->workflow_stage);
    }

    public function test_final_outcome_is_recorded_only_after_configured_stages_are_complete(): void
    {
        [$provider, $applicant, , $application] = $this->applicationWithPlan([
            'screening',
            'formal_application',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/final-outcome", [
                'outcome' => 'selected',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/final-outcome", [
                'outcome' => 'selected',
                'notes' => 'Selected after the provider completed its formal review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.final_outcome', 'selected')
            ->assertJsonPath('application.workflow.is_closed', true);

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_outcome',
        ]);
    }

    public function test_generic_status_endpoint_cannot_skip_the_current_stage(): void
    {
        [$provider, , , $application] = $this->applicationWithPlan([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'exam_qualified',
                'decision_reason' => 'for_exam',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_program_form_stores_the_provider_stage_order_with_required_boundaries(): void
    {
        $provider = $this->provider();

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Configurable Selection Scholarship',
                'description' => 'A program with an interview before its formal application step.',
                'selection_stages' => json_encode(['interview', 'formal_application']),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.selection_stages', [
                'screening',
                'interview',
                'formal_application',
                'decision',
            ]);
    }

    public function test_program_stage_order_cannot_change_after_an_application_is_submitted(): void
    {
        [$provider, , $scholarship] = $this->applicationWithPlan([
            'screening',
            'formal_application',
            'decision',
        ]);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['exam', 'formal_application']),
                'exam_duration_minutes' => 60,
                'exam_passing_score' => 75,
                'status' => $scholarship->status,
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selection_stages');
    }

    public function test_applicant_progress_contains_only_the_submitted_program_stages(): void
    {
        [, $applicant, , $application] = $this->applicationWithPlan([
            'screening',
            'interview',
            'formal_application',
            'decision',
        ]);
        app(ApplicationWorkflowService::class)->start($application);

        $payload = $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->json('application.workflow.steps');

        $this->assertSame(
            ['screening', 'interview', 'formal_application', 'decision'],
            array_column($payload, 'key'),
        );
    }

    public function test_screening_decision_and_distribution_are_not_schedule_types(): void
    {
        [$provider, , $scholarship] = $this->applicationWithPlan([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);

        foreach (['screening', 'decision', 'distribution'] as $type) {
            $this->actingAs($provider)
                ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                    'type' => $type,
                    'title' => 'Invalid workflow schedule',
                    'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                    'mode' => 'provider_managed',
                    'instructions' => 'This should not be stored as a schedule.',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('type');
        }

        $this->assertSame(0, ScholarshipEvent::query()->count());
    }

    private function applicationWithPlan(array $stages): array
    {
        $provider = $this->provider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Used to verify configurable provider selection stages.',
            'selection_stages' => $stages,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        return [$provider, $applicant, $scholarship, $application];
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
}
