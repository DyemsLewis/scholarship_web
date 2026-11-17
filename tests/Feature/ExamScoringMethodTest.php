<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Support\SelectionScoringPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExamScoringMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_total_exam_option_preserves_stage_weights_and_interview_criteria(): void
    {
        $criteria = SelectionScoringPolicy::preset(['exam', 'interview'], []);
        $total = SelectionScoringPolicy::preset(['exam', 'interview'], [], 'total');
        $this->assertSame($criteria['weights'], $total['weights']);
        $this->assertArrayNotHasKey('exam', $total['stage_criteria']);
        $this->assertSame($criteria['stage_criteria']['interview'], $total['stage_criteria']['interview']);
        $this->assertSame('total', SelectionScoringPolicy::examMethod($total));
    }

    public function test_create_and_edit_draft_save_the_scoring_choice_without_a_schema_change(): void
    {
        [$provider, $program] = $this->draft('total');
        $this->assertSame('total', $program->selection_scoring_policy['exam_scoring_method']);
        $this->assertArrayNotHasKey('frozen_at', $program->selection_scoring_policy);
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft', 'exam_scoring_method' => 'criteria',
        ])->assertOk()->assertJsonPath('scholarship.exam_scoring_method', 'criteria');
        $this->assertCount(3, $program->fresh()->selection_scoring_policy['stage_criteria']['exam']);
    }

    public function test_an_omitted_choice_preserves_the_configured_total_method(): void
    {
        [$provider, $program] = $this->draft('total');
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", ['title' => 'Renamed draft', 'status' => 'draft'])
            ->assertOk()->assertJsonPath('scholarship.exam_scoring_method', 'total');
        $this->assertSame('total', SelectionScoringPolicy::examMethod($program->fresh()->selection_scoring_policy));
    }

    public function test_first_submission_freezes_the_selected_total_method_for_all_applicants(): void
    {
        [, $program] = $this->draft('total');
        $first = $this->application($program);
        $second = $this->application($program->fresh());
        $this->assertNotEmpty($first->selection_scoring_snapshot['frozen_at']);
        $this->assertSame($first->selection_scoring_snapshot, $second->selection_scoring_snapshot);
        $this->assertSame('total', $first->selection_scoring_snapshot['exam_scoring_method']);
    }

    public function test_exam_total_is_normalized_without_requiring_exam_criteria_or_changing_interview(): void
    {
        [$provider, $program] = $this->draft('total');
        $application = $this->application($program);
        $workflow = app(ApplicationWorkflowService::class);
        foreach (['screening', 'formal_application'] as $stage) $application = $workflow->recordStageResult($application, $stage, 'passed', $provider);
        ApplicationSchedule::create(['scholarship_application_id' => $application->id, 'type' => 'exam', 'title' => 'Completed exam',
            'scheduled_at' => now()->subHour(), 'mode' => 'onsite', 'status' => 'completed', 'completed_at' => now(), 'created_by' => $provider->id]);
        $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
            'result' => 'passed', 'evaluation' => ['score' => 43, 'maximum_score' => 50, 'expected_stage_revision' => 0, 'expected_revision' => 0],
        ])->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.stages.2.score', 86)
            ->assertJsonPath('application.selection_evaluation.stages.2.criteria', [])
            ->assertJsonPath('application.selection_evaluation.stages.3.criteria.0.key', 'motivation');
        $this->assertNull($application->fresh()->final_outcome);
    }

    public function test_method_cannot_change_after_an_application_and_snapshot_is_untouched(): void
    {
        [$provider, $program] = $this->draft('total');
        $application = $this->application($program);
        $before = $application->selection_scoring_snapshot;
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft', 'exam_scoring_method' => 'criteria',
        ])->assertUnprocessable()->assertJsonValidationErrors('exam_scoring_method');
        $this->assertSame($before, $application->fresh()->selection_scoring_snapshot);
        $this->assertSame($before, $program->fresh()->selection_scoring_policy);
    }

    public function test_invalid_choices_are_rejected_and_existing_total_intakes_are_recognized(): void
    {
        [$provider, $program] = $this->draft('total');
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'draft', 'exam_scoring_method' => 'automatic',
        ])->assertUnprocessable()->assertJsonValidationErrors('exam_scoring_method');
        $this->assertSame('total', SelectionScoringPolicy::examMethod(['version' => '1.0', 'weights' => ['exam' => 100]]));
        $this->assertSame('criteria', SelectionScoringPolicy::examMethod(SelectionScoringPolicy::preset(['exam'], [])));
    }

    public function test_changing_a_published_program_method_requires_a_new_publication_review(): void
    {
        [$provider, $program] = $this->draft('total');
        $program->update(['status' => 'published']);
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => $program->title, 'status' => 'published', 'exam_scoring_method' => 'criteria',
            'description' => 'Published program with an updated exam scoring method.', 'terms_accepted' => true,
        ])->assertOk()->assertJsonPath('scholarship.status', 'pending_review');
        $this->assertSame('criteria', $program->fresh()->selection_scoring_policy['exam_scoring_method']);
    }

    public function test_saving_the_same_frozen_method_does_not_rewrite_the_policy_or_evaluations(): void
    {
        [$provider, $program] = $this->draft('total');
        $application = $this->application($program);
        $policy = $program->fresh()->selection_scoring_policy;
        $this->actingAs($provider)->putJson("/provider/scholarships/{$program->id}", [
            'title' => 'Updated draft title', 'status' => 'draft', 'exam_scoring_method' => 'total',
        ])->assertOk()->assertJsonPath('scholarship.exam_scoring_method', 'total');
        $this->assertSame($policy, $program->fresh()->selection_scoring_policy);
        $this->assertSame($policy, $application->fresh()->selection_scoring_snapshot);
        $this->assertDatabaseCount('application_stage_evaluations', 0);
    }

    private function draft(string $method): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Exam method draft', 'status' => 'draft', 'exam_scoring_method' => $method,
            'selection_stages' => json_encode(['screening', 'formal_application', 'exam', 'interview', 'decision']),
        ])->assertCreated()->assertJsonPath('scholarship.exam_scoring_method', $method);

        return [$provider, Scholarship::findOrFail($response->json('scholarship.id'))];
    }

    private function application(Scholarship $program): ScholarshipApplication
    {
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(), 'rubric_scores' => ['eligibility_fit' => 100, 'academic_merit' => 90, 'financial_need' => 90, 'document_quality' => 100],
            'rubric_scored_by' => $program->provider_id, 'rubric_scored_at' => now()]);

        return app(ApplicationWorkflowService::class)->start($application);
    }
}
