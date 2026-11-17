<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\ApplicationStageEvaluation;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Support\SelectionScoringPolicy;
use App\Support\StageEvaluationRubric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StageCriterionLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_create_and_edit_persist_labels_without_changing_weights_keys_or_scales(): void
    {
        [$provider, $program] = $this->draft();
        $policy = $program->selection_scoring_policy;
        $expected = SelectionScoringPolicy::preset($program->selection_stages, $program->review_rubric ?? []);
        $this->assertSame('Mathematics', $policy['stage_criteria']['exam'][0]['label']);
        $this->assertSame('Study motivation', $policy['stage_criteria']['interview'][0]['label']);
        foreach (['exam', 'interview'] as $stage) {
            foreach (['key', 'weight', 'maximum_score', 'description'] as $field) {
                $this->assertSame(array_column($expected['stage_criteria'][$stage], $field), array_column($policy['stage_criteria'][$stage], $field));
            }
        }
        $this->assertSame($expected['weights'], $policy['weights']);
        $this->assertArrayNotHasKey('frozen_at', $policy);
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, [
            'stage_criterion_labels' => ['exam' => ['knowledge' => '  Quantitative skills  ']],
        ]))->assertOk()->assertJsonPath('scholarship.stage_criterion_labels.exam.knowledge', 'Quantitative skills')
            ->assertJsonPath('scholarship.stage_criterion_labels.interview.motivation', 'Study motivation');
    }

    public function test_unrelated_saves_and_total_exam_mode_preserve_custom_labels(): void
    {
        [$provider, $program] = $this->draft();
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, ['exam_scoring_method' => 'total']))
            ->assertOk()->assertJsonPath('scholarship.stage_criterion_labels.exam.knowledge', 'Mathematics');
        $this->assertArrayNotHasKey('exam', $program->fresh()->selection_scoring_policy['stage_criteria']);
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, ['title' => 'Updated program']))->assertOk();
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, ['exam_scoring_method' => 'criteria']))
            ->assertOk()->assertJsonPath('scholarship.selection_scoring_policy.stage_criteria.exam.0.label', 'Mathematics')
            ->assertJsonPath('scholarship.selection_scoring_policy.stage_criteria.interview.0.label', 'Study motivation');
    }

    public function test_first_application_freezes_custom_labels_for_all_applicants(): void
    {
        [, $program] = $this->draft();
        $first = $this->application($program);
        $second = $this->application($program->fresh());
        $this->assertNotEmpty($first->selection_scoring_snapshot['frozen_at']);
        $this->assertSame($first->selection_scoring_snapshot, $second->selection_scoring_snapshot);
        $this->assertSame('Mathematics', $first->selection_scoring_snapshot['stage_criteria']['exam'][0]['label']);
    }

    public function test_changed_labels_are_rejected_after_application_without_rewriting_any_records(): void
    {
        [$provider, $program] = $this->draft();
        $application = $this->application($program);
        $before = $program->fresh()->selection_scoring_policy;
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, [
            'title' => 'Must not persist', 'stage_criterion_labels' => ['interview' => ['motivation' => 'Different basis']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('stage_criterion_labels');
        $this->assertSame($before, $program->fresh()->selection_scoring_policy);
        $this->assertSame($before, $application->fresh()->selection_scoring_snapshot);
        $this->assertSame('Criterion label draft', $program->title);
        $this->assertDatabaseCount('application_stage_evaluations', 0);
    }

    public function test_unchanged_labels_can_be_saved_after_freeze_without_rewriting_history(): void
    {
        [$provider, $program] = $this->draft();
        $application = $this->application($program);
        $before = $program->fresh()->selection_scoring_policy;
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, [
            'stage_criterion_labels' => StageEvaluationRubric::labels($before),
        ]))->assertOk();
        $this->assertSame($before, $program->fresh()->selection_scoring_policy);
        $this->assertSame($before, $application->fresh()->selection_scoring_snapshot);
    }

    public function test_existing_intakes_without_label_configuration_keep_their_original_policy(): void
    {
        [$provider, $program] = $this->draft();
        $policy = SelectionScoringPolicy::preset($program->selection_stages, $program->review_rubric ?? []);
        unset($policy['stage_criterion_labels']);
        $policy['version'] = '2.1';
        $program->forceFill(['selection_scoring_policy' => $policy])->save();
        $application = $this->application($program);
        $before = $program->fresh()->selection_scoring_policy;
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, [
            'stage_criterion_labels' => StageEvaluationRubric::labels($before),
        ]))->assertOk();
        $this->assertSame($before, $program->fresh()->selection_scoring_policy);
        $this->assertSame($before, $application->fresh()->selection_scoring_snapshot);
    }

    public function test_invalid_duplicate_unknown_and_overlong_labels_are_rejected(): void
    {
        [$provider, $program] = $this->draft();
        $before = $program->selection_scoring_policy;
        foreach ([
            ['exam' => ['knowledge' => '']],
            ['exam' => ['knowledge' => '   ']],
            ['exam' => ['knowledge' => str_repeat('A', 81)]],
            ['exam' => ['knowledge' => ['weight' => 100]]],
            ['exam' => ['knowledge' => 'reasoning and analysis']],
            ['exam' => ['knowledge' => "Hidden\u{200B}label"]],
            ['exam' => ['injected' => 'Other criterion']],
            ['screening' => ['academic_merit' => 'Other criterion']],
        ] as $labels) {
            $response = $this->actingAs($provider)->putJson($this->url($program), $this->update($program, ['stage_criterion_labels' => $labels]));
            $response->assertUnprocessable();
            $this->assertStringContainsString('stage_criterion_labels', json_encode($response->json('errors')));
            $this->assertSame($before, $program->fresh()->selection_scoring_policy);
        }
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, ['stage_criterion_labels' => '{broken']))
            ->assertUnprocessable()->assertJsonValidationErrors('stage_criterion_labels');
    }

    public function test_label_changes_to_published_programs_require_publication_review(): void
    {
        [$provider, $program] = $this->draft();
        $program->update(['status' => 'published']);
        $this->actingAs($provider)->putJson($this->url($program), $this->update($program, [
            'status' => 'published', 'description' => 'Published assessment with new criterion names.', 'terms_accepted' => true,
            'stage_criterion_labels' => ['exam' => ['knowledge' => 'Quantitative skills']],
        ]))->assertOk()->assertJsonPath('scholarship.status', 'pending_review');
    }

    public function test_other_providers_cannot_change_the_program_criterion_labels(): void
    {
        [, $program] = $this->draft();
        $other = User::factory()->create(['role' => 'provider']);
        $before = $program->selection_scoring_policy;
        $this->actingAs($other)->putJson($this->url($program), $this->update($program, [
            'stage_criterion_labels' => ['exam' => ['knowledge' => 'Unauthorized label']],
        ]))->assertForbidden();
        $this->assertSame($before, $program->fresh()->selection_scoring_policy);
    }

    public function test_custom_labels_flow_into_evaluation_fields_and_saved_breakdowns_with_the_same_score(): void
    {
        [$provider, $program] = $this->draft();
        $application = $this->application($program);
        $workflow = app(ApplicationWorkflowService::class);
        foreach (['screening', 'formal_application'] as $stage) {
            $application = $workflow->recordStageResult($application, $stage, 'passed', $provider);
        }
        ApplicationSchedule::create(['scholarship_application_id' => $application->id, 'type' => 'exam', 'title' => 'Completed exam',
            'scheduled_at' => now()->subHour(), 'mode' => 'onsite', 'status' => 'completed', 'completed_at' => now(), 'created_by' => $provider->id]);
        $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
            'result' => 'passed', 'evaluation' => ['criteria_scores' => [
                'knowledge' => ['score' => 36, 'maximum_score' => 40],
                'reasoning' => ['score' => 32, 'maximum_score' => 40],
                'application' => ['score' => 18, 'maximum_score' => 20],
            ], 'expected_stage_revision' => 0, 'expected_revision' => 0],
        ])->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.stages.2.score', 86)
            ->assertJsonPath('application.selection_evaluation.stages.2.criteria.0.label', 'Mathematics')
            ->assertJsonPath('application.selection_evaluation.stages.3.criteria.0.label', 'Study motivation');
        $evaluation = ApplicationStageEvaluation::where('scholarship_application_id', $application->id)->where('stage_key', 'exam')->firstOrFail();
        $this->assertSame('Mathematics', $evaluation->breakdown[0]['label']);
        $this->assertSame('40', (string) $evaluation->breakdown[0]['weight']);
        $this->assertNull($application->fresh()->final_outcome);
    }

    private function draft(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Criterion label draft', 'status' => 'draft', 'exam_scoring_method' => 'criteria',
            'selection_stages' => json_encode(['screening', 'formal_application', 'exam', 'interview', 'decision']),
            'stage_criterion_labels' => json_encode(['exam' => ['knowledge' => 'Mathematics'], 'interview' => ['motivation' => 'Study motivation']]),
        ])->assertCreated()->assertJsonPath('scholarship.stage_criterion_labels.exam.knowledge', 'Mathematics');

        return [$provider, Scholarship::findOrFail($response->json('scholarship.id'))];
    }

    private function application(Scholarship $program): ScholarshipApplication
    {
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(), 'rubric_scores' => ['eligibility_fit' => 100, 'academic_merit' => 90, 'financial_need' => 90, 'document_quality' => 100],
            'rubric_scored_by' => $program->provider_id, 'rubric_scored_at' => now()]);

        return app(ApplicationWorkflowService::class)->start($application);
    }

    private function url(Scholarship $program): string
    {
        return "/provider/scholarships/{$program->id}";
    }

    private function update(Scholarship $program, array $overrides = []): array
    {
        return [...['title' => $program->title, 'status' => 'draft'], ...$overrides];
    }
}
