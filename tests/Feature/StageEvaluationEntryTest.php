<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\SelectionEvaluationService;
use App\Support\ReviewRubric;
use App\Support\SelectionScoringPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StageEvaluationEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_passing_an_exam_saves_its_evaluation_and_advances_in_one_request(): void
    {
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->passed())
            ->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.completed', 2)
            ->assertJsonPath('application.selection_evaluation.stages.2.raw_score', 43)
            ->assertJsonPath('application.selection_evaluation.stages.2.maximum_score', 50)
            ->assertJsonPath('application.selection_evaluation.stages.2.score', 86);
        $this->assertDatabaseHas('application_stage_evaluations', ['stage_key' => 'exam', 'revision' => 1, 'stage_revision' => 1, 'recorded_by' => $provider->id]);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $application->applicant_id, 'title' => 'Exam passed']);
        $this->assertNull($application->fresh()->final_outcome);
        $this->assertNull($application->fresh()->waitlist_position);
    }

    public function test_interview_total_is_normalized_and_completes_the_selection_evaluation(): void
    {
        [$provider, $application] = $this->application('interview');
        $payload = $this->passed();
        $payload['evaluation']['score'] = 18;
        $payload['evaluation']['maximum_score'] = 20;
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'interview'), $payload)
            ->assertOk()->assertJsonPath('application.workflow_stage', 'decision')
            ->assertJsonPath('application.selection_evaluation.completed', 3)
            ->assertJsonPath('application.selection_evaluation.score', 88.2);
        $this->assertNull($application->fresh()->final_outcome);
    }

    public function test_missing_score_cannot_pass_a_scoring_enabled_stage(): void
    {
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), ['result' => 'passed'])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        $this->assertUnchangedExam($application);
    }

    public function test_score_above_the_maximum_rolls_back_the_stage_result_and_history(): void
    {
        [$provider, $application] = $this->application();
        $historyCount = $application->statusHistories()->count();
        $payload = $this->passed();
        $payload['evaluation']['score'] = 51;
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->assertUnchangedExam($application);
        $this->assertSame($historyCount, $application->statusHistories()->count());
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_stale_stage_or_evaluation_references_cannot_advance_an_applicant(): void
    {
        [$provider, $application] = $this->application();
        foreach (['expected_stage_revision', 'expected_revision'] as $reference) {
            $payload = $this->passed();
            $payload['evaluation'][$reference] = 9;
            $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
            $this->assertUnchangedExam($application);
        }
    }

    public function test_score_entry_does_not_bypass_the_activity_completion_gate(): void
    {
        [$provider, $application] = $this->application();
        $application->schedules()->where('type', 'exam')->update(['status' => 'scheduled', 'completed_at' => null]);
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->passed())
            ->assertUnprocessable()->assertJsonValidationErrors('result');
        $this->assertUnchangedExam($application);
    }

    public function test_not_passed_keeps_the_existing_reason_workflow_without_creating_a_score(): void
    {
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), [
            'result' => 'not_passed', 'decision_reason' => 'failed_exam', 'notes' => 'Recorded result did not meet the exam standard.',
        ])->assertOk()->assertJsonPath('application.status', 'exam_failed');
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_a_legacy_intake_keeps_its_previous_outcome_workflow_without_silent_reranking(): void
    {
        [$provider, $application] = $this->application();
        $application->forceFill(['selection_scoring_snapshot' => null])->save();
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), ['result' => 'passed'])
            ->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.state', 'legacy')
            ->assertJsonPath('application.selection_evaluation.score', null);
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_formal_verification_stays_a_qualifying_check_and_does_not_accept_ranking_points(): void
    {
        [$provider, $application] = $this->application('formal_application');
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'formal_application'), $this->passed())
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'formal_application'), ['result' => 'passed'])
            ->assertOk()->assertJsonPath('application.workflow_stage', 'exam');
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_correction_requires_a_reason_preserves_the_original_and_does_not_notify_or_advance(): void
    {
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->passed())->assertOk();
        $before = $application->fresh()->only(['workflow_stage', 'status', 'final_outcome', 'waitlist_position']);
        $payload = ['score' => 44, 'maximum_score' => 50, 'expected_stage_revision' => 1, 'expected_revision' => 1];
        $this->postJson($this->evaluationUrl($application), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->evaluationUrl($application), [...$payload, 'note' => 'Corrected a transcription error against the score sheet.'])
            ->assertOk()->assertJsonPath('application.selection_evaluation.stages.2.score', 88)
            ->assertJsonPath('application.selection_evaluation.stages.2.history.0.revision', 2)
            ->assertJsonPath('application.selection_evaluation.stages.2.history.1.score', 43);
        $this->assertDatabaseHas('application_stage_evaluations', ['stage_key' => 'exam', 'revision' => 1, 'score' => 43]);
        $this->assertSame($before, $application->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('portal_notifications', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'application_stage_evaluation_recorded']);
        $this->postJson($this->evaluationUrl($application), [...$payload, 'note' => 'An obsolete score form must not overwrite this.'])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
    }

    public function test_a_missing_earlier_score_can_be_added_without_reopening_the_passed_stage(): void
    {
        [$provider, $application] = $this->application();
        $application = app(ApplicationWorkflowService::class)->recordStageResult($application, 'exam', 'passed', $provider);
        $this->actingAs($provider)->postJson($this->evaluationUrl($application), [
            'score' => 43, 'maximum_score' => 50, 'expected_stage_revision' => 1, 'expected_revision' => 0,
        ])->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.stages.2.score', 86);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_scoring_access_is_limited_to_approved_assigned_selection_staff_and_managers(): void
    {
        [$provider, $application] = $this->application();
        $application = app(ApplicationWorkflowService::class)->recordStageResult($application, 'exam', 'passed', $provider);
        $payload = ['score' => 43, 'maximum_score' => 50, 'expected_stage_revision' => 1, 'expected_revision' => 0];
        foreach (['verify_applications', 'record_final_decisions'] as $permission) {
            $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
                'permissions' => [$permission], 'assigned_program_ids' => [$application->scholarship_id]]);
            $this->actingAs($staff)->postJson($this->evaluationUrl($application), $payload)->assertForbidden();
        }
        $unassigned = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_selection_activities'], 'assigned_program_ids' => []]);
        $this->actingAs($unassigned)->postJson($this->evaluationUrl($application), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'provider']))->postJson($this->evaluationUrl($application), $payload)->assertForbidden();
        $unassigned->update(['assigned_program_ids' => [$application->scholarship_id]]);
        $this->actingAs($unassigned->fresh())->postJson($this->evaluationUrl($application), $payload)->assertOk();
    }

    public function test_finalized_or_correction_pending_applications_cannot_have_their_score_changed(): void
    {
        [$provider, $application] = $this->application();
        $application = app(ApplicationWorkflowService::class)->recordStageResult($application, 'exam', 'passed', $provider);
        $payload = ['score' => 43, 'maximum_score' => 50, 'expected_stage_revision' => 1, 'expected_revision' => 0];
        foreach ([['final_outcome' => 'selected'], ['final_outcome' => null, 'correction_status' => 'requested']] as $change) {
            $application->update($change);
            $this->actingAs($provider)->postJson($this->evaluationUrl($application), $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        }
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_bulk_actions_cannot_skip_individual_evaluations(): void
    {
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson("/provider/scholarships/{$application->scholarship_id}/applications/bulk-advance", [
            'application_ids' => [$application->id], 'target_stage' => 'pass_stage',
        ])->assertUnprocessable()->assertJsonValidationErrors('application_ids');
        $event = ScholarshipEvent::create([
            'scholarship_id' => $application->scholarship_id, 'type' => 'exam', 'title' => 'Completed exam',
            'scheduled_at' => now()->subHour(), 'mode' => 'onsite', 'status' => 'completed', 'created_by' => $provider->id,
        ]);
        $this->patchJson("/provider/scholarships/{$application->scholarship_id}/events/{$event->id}/attendance", [
            'application_ids' => [$application->id], 'attendance_status' => 'passed',
        ])->assertUnprocessable()->assertJsonValidationErrors('application_ids');
        $this->assertUnchangedExam($application);
    }

    public function test_bulk_screening_cannot_skip_the_published_scoring_rubric(): void
    {
        [$provider, $existing] = $this->application();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $existing->scholarship_id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $application = app(ApplicationWorkflowService::class)->start($application);
        $this->actingAs($provider)->patchJson("/provider/scholarships/{$application->scholarship_id}/applications/bulk-advance", [
            'application_ids' => [$application->id], 'target_stage' => 'pass_prescreening',
        ])->assertUnprocessable()->assertJsonValidationErrors('application_ids');
        $this->assertSame('screening', $application->fresh()->workflow_stage);
        $this->assertSame(0, $application->stageEvaluations()->count());
    }

    private function assertUnchangedExam(ScholarshipApplication $application): void
    {
        $this->assertSame('exam', $application->fresh()->workflow_stage);
        $this->assertDatabaseHas('application_stage_progresses', ['scholarship_application_id' => $application->id, 'stage_key' => 'exam', 'status' => 'current', 'evaluation_revision' => 0]);
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_exam_criteria_are_calculated_on_the_server_and_saved_with_the_outcome(): void
    {
        [$provider, $application] = $this->application('exam', true);
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->criteriaOutcome())
            ->assertOk()->assertJsonPath('application.workflow_stage', 'interview')
            ->assertJsonPath('application.selection_evaluation.stages.2.score', 86)
            ->assertJsonPath('application.selection_evaluation.stages.2.breakdown.1.score', 16)
            ->assertJsonPath('application.selection_evaluation.stages.2.breakdown.1.maximum_score', 20)
            ->assertJsonPath('application.selection_evaluation.stages.2.breakdown.1.contribution', 32);
        $this->assertDatabaseHas('application_stage_evaluations', ['stage_key' => 'exam', 'source' => 'stage_criteria', 'score' => 86, 'maximum_score' => 100]);
        $this->assertNull($application->fresh()->final_outcome);
    }

    public function test_interview_criteria_use_fixed_ratings_and_flow_into_the_final_dss(): void
    {
        [$provider, $application] = $this->application('interview', true);
        $payload = ['result' => 'passed', 'evaluation' => ['criteria_scores' => [
            'motivation' => ['score' => 4.5, 'maximum_score' => 5],
            'communication' => ['score' => 4, 'maximum_score' => 5],
            'readiness' => ['score' => 5, 'maximum_score' => 5],
        ], 'expected_stage_revision' => 0, 'expected_revision' => 0]];
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'interview'), $payload)
            ->assertOk()->assertJsonPath('application.workflow_stage', 'decision')
            ->assertJsonPath('application.selection_evaluation.stages.3.score', 90)
            ->assertJsonPath('application.selection_evaluation.score', 88.2);
        $this->assertNull($application->fresh()->final_outcome);
        $this->assertNull($application->fresh()->waitlist_position);
    }

    public function test_criteria_cannot_be_bypassed_by_a_total_or_partial_or_extra_scores(): void
    {
        [$provider, $application] = $this->application('exam', true);
        $full = $this->criteriaOutcome();
        $partial = $full;
        unset($partial['evaluation']['criteria_scores']['reasoning']);
        $extra = $full;
        $extra['evaluation']['criteria_scores']['appearance'] = ['score' => 100, 'maximum_score' => 100];
        $tampered = $full;
        $tampered['evaluation']['score'] = 100;
        $tampered['evaluation']['maximum_score'] = 100;
        foreach ([$this->passed(), $partial, $extra, $tampered] as $payload) {
            $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $payload)->assertUnprocessable();
            $this->assertUnchangedExam($application);
        }
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_criterion_limits_decimal_precision_and_injected_weights_are_rejected(): void
    {
        [$provider, $application] = $this->application('exam', true);
        foreach ([['score' => 41, 'maximum_score' => 40], ['score' => -1, 'maximum_score' => 40],
            ['score' => '', 'maximum_score' => 40], ['score' => 0, 'maximum_score' => 0],
            ['score' => 1.00001, 'maximum_score' => 40], ['score' => 30, 'maximum_score' => 40, 'weight' => 100]] as $row) {
            $payload = $this->criteriaOutcome();
            $payload['evaluation']['criteria_scores']['knowledge'] = $row;
            $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $payload)->assertUnprocessable();
            $this->assertUnchangedExam($application);
        }
    }

    public function test_all_zero_criteria_are_valid_but_missing_is_not_a_zero_score(): void
    {
        [$provider, $application] = $this->application('exam', true);
        $payload = $this->criteriaOutcome();
        foreach ($payload['evaluation']['criteria_scores'] as &$row) $row['score'] = 0;
        unset($row);
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $payload)
            ->assertOk()->assertJsonPath('application.selection_evaluation.stages.2.score', 0);
    }

    public function test_interview_maxima_cannot_be_changed_to_inflate_a_rating(): void
    {
        [$provider, $application] = $this->application('interview', true);
        $scores = \Database\Seeders\Support\DemoStageScores::atPercentage($application->selection_scoring_snapshot, 'interview', 90);
        $scores['motivation']['maximum_score'] = 4.5;
        $scores['motivation']['score'] = 4;
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'interview'), ['result' => 'passed', 'evaluation' => [
            'criteria_scores' => $scores, 'expected_stage_revision' => 0, 'expected_revision' => 0,
        ]])->assertUnprocessable()->assertJsonValidationErrors('criteria_scores.motivation.maximum_score');
        $this->assertSame('interview', $application->fresh()->workflow_stage);
    }

    public function test_criteria_corrections_keep_the_original_breakdown_and_require_a_reason(): void
    {
        [$provider, $application] = $this->application('exam', true);
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->criteriaOutcome())->assertOk();
        $payload = $this->criteriaOutcome()['evaluation'];
        $payload['expected_stage_revision'] = 1;
        $payload['expected_revision'] = 1;
        $payload['criteria_scores']['knowledge']['score'] = 38;
        $this->postJson($this->evaluationUrl($application), $payload)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($this->evaluationUrl($application), [...$payload, 'note' => 'Corrected the knowledge section against the answer sheet.'])
            ->assertOk()->assertJsonPath('application.selection_evaluation.stages.2.score', 88)
            ->assertJsonPath('application.selection_evaluation.stages.2.history.1.breakdown.0.score', 36);
        $this->assertSame('interview', $application->fresh()->workflow_stage);
        $this->assertDatabaseCount('portal_notifications', 1);
        $this->postJson($this->evaluationUrl($application), [...$payload, 'note' => 'A stale score form must not overwrite the new revision.'])->assertUnprocessable();
    }

    public function test_criteria_still_require_a_completed_activity_and_assigned_selection_staff(): void
    {
        [$provider, $application] = $this->application('exam', true);
        $application->schedules()->where('type', 'exam')->update(['status' => 'scheduled', 'completed_at' => null]);
        $this->actingAs($provider)->patchJson($this->resultUrl($application, 'exam'), $this->criteriaOutcome())->assertUnprocessable();
        $other = User::factory()->create(['role' => 'provider']);
        $this->actingAs($other)->patchJson($this->resultUrl($application, 'exam'), $this->criteriaOutcome())->assertForbidden();
        $this->assertUnchangedExam($application);
    }

    private function criteriaOutcome(): array
    {
        return ['result' => 'passed', 'evaluation' => ['criteria_scores' => [
            'knowledge' => ['score' => 36, 'maximum_score' => 40],
            'reasoning' => ['score' => 16, 'maximum_score' => 20],
            'application' => ['score' => 9, 'maximum_score' => 10],
        ], 'expected_stage_revision' => 0, 'expected_revision' => 0]];
    }

    private function passed(): array
    {
        return ['result' => 'passed', 'evaluation' => ['score' => 43, 'maximum_score' => 50, 'expected_stage_revision' => 0, 'expected_revision' => 0]];
    }

    private function resultUrl(ScholarshipApplication $application, string $stage): string
    {
        return "/provider/applications/{$application->id}/stages/{$stage}/result";
    }

    private function evaluationUrl(ScholarshipApplication $application): string
    {
        return "/provider/applications/{$application->id}/stages/exam/evaluation";
    }

    private function application(string $stage = 'exam', bool $criteria = false): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Stage evaluation form test', 'description' => 'Isolated stage evaluation test.',
            'status' => 'published', 'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'],
            'review_rubric' => ReviewRubric::DEFAULT,
        ]);
        if (! $criteria) {
            $legacy = SelectionScoringPolicy::preset($program->selection_stages, $program->review_rubric);
            unset($legacy['stage_criteria']);
            $program->forceFill(['selection_scoring_policy' => [...$legacy, 'version' => '1.0']])->save();
        }
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(),
            'rubric_scores' => array_fill_keys(array_column(ReviewRubric::DEFAULT, 'key'), 92),
            'rubric_scored_by' => $provider->id, 'rubric_scored_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        if ($stage === 'formal_application') {
            return [$provider, $application];
        }
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        foreach (['exam', 'interview'] as $activity) {
            ApplicationSchedule::create([
                'scholarship_application_id' => $application->id, 'type' => $activity,
                'title' => ucfirst($activity).' activity', 'scheduled_at' => now()->subHour(), 'mode' => 'onsite',
                'status' => 'completed', 'completed_at' => now(), 'created_by' => $provider->id, 'updated_by' => $provider->id,
            ]);
        }
        if ($stage === 'interview') {
            $application = $workflow->recordStageResult($application, 'exam', 'passed', $provider);
            $scores = $criteria ? \Database\Seeders\Support\DemoStageScores::atPercentage($application->selection_scoring_snapshot, 'exam', 84) : null;
            app(SelectionEvaluationService::class)->record($application, $provider, 'exam', $criteria ? null : 42, $criteria ? null : 50, 1, criteriaScores: $scores);
        }

        return [$provider, $application];
    }
}
