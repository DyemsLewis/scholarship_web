<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\ScholarshipEligibilityService;
use App\Services\SelectionEvaluationService;
use App\Support\ReviewRubric;
use App\Support\SelectionScoringPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SelectionEvaluationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_presets_only_score_available_evaluations_and_never_formal_verification(): void
    {
        foreach ([
            [['exam', 'interview'], [], ['exam' => 60, 'interview' => 40]],
            [['exam'], [], ['exam' => 100]],
            [['interview'], [], ['interview' => 100]],
            [[], [], []],
            [[], ReviewRubric::DEFAULT, ['screening' => 100]],
            [['exam'], ReviewRubric::DEFAULT, ['screening' => 40, 'exam' => 60]],
            [['interview'], ReviewRubric::DEFAULT, ['screening' => 50, 'interview' => 50]],
            [['exam', 'interview'], ReviewRubric::DEFAULT, ['screening' => 30, 'exam' => 40, 'interview' => 30]],
        ] as [$stages, $rubric, $expected]) {
            $policy = SelectionScoringPolicy::preset($stages, $rubric);
            $this->assertSame($expected, $policy['weights']);
            $this->assertArrayNotHasKey('formal_application', $policy['weights']);
        }
    }

    public function test_new_submissions_freeze_the_same_program_policy_without_rewriting_older_applications(): void
    {
        [$provider, $application] = $this->application();
        $snapshot = $application->selection_scoring_snapshot;
        $application->scholarship->update(['review_rubric' => []]);
        $another = ScholarshipApplication::create([
            'scholarship_id' => $application->scholarship_id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $another = app(ApplicationWorkflowService::class)->start($another);
        $this->assertSame($snapshot, $another->selection_scoring_snapshot);
        $this->assertSame($snapshot, $application->fresh()->selection_scoring_snapshot);
        $this->assertSame(['academic_merit', 'financial_need'], array_column($snapshot['screening_criteria'], 'key'));

        $application->forceFill(['selection_scoring_snapshot' => null])->save();
        $before = $application->getRawOriginal();
        $summary = app(SelectionEvaluationService::class)->summary($application->fresh());
        $this->assertSame('legacy', $summary['state']);
        $this->assertNull($summary['score']);
        $this->assertSame($before, $application->fresh()->getRawOriginal());
    }

    public function test_stage_scores_are_normalized_and_combined_without_file_completeness_points(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $service = app(SelectionEvaluationService::class);
        $service->record($application, $provider, 'exam', 45, 50, 1);
        $service->record($application, $provider, 'interview', 16, 20, 1);
        $summary = $service->summary($application->fresh());
        $this->assertSame('ready', $summary['state']);
        $this->assertEquals(85.67, $summary['score']);
        $this->assertSame(3, $summary['completed']);
        $this->assertTrue($summary['ready_for_comparison']);
        $this->assertDatabaseCount('application_stage_evaluations', 3);
        $this->assertDatabaseHas('application_stage_evaluations', [
            'stage_key' => 'screening', 'recorded_by' => $provider->id, 'source' => 'reviewed_screening_rubric',
        ]);
        $this->assertNull($application->fresh()->final_outcome);
        $this->assertNull($application->fresh()->waitlist_position);
    }

    public function test_a_different_stage_path_cannot_silently_replace_the_frozen_intake_policy(): void
    {
        [, $application] = $this->application();
        $application->scholarship->update(['selection_stages' => ['screening', 'formal_application', 'decision']]);
        $another = ScholarshipApplication::create([
            'scholarship_id' => $application->scholarship_id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $this->expectException(ValidationException::class);
        app(ApplicationWorkflowService::class)->start($another);
    }

    public function test_recorded_final_decisions_cannot_be_changed_indirectly_by_editing_evaluations(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        foreach (['selected', 'waitlisted', 'not_selected'] as $outcome) {
            $application->update(['final_outcome' => $outcome]);
            try {
                app(SelectionEvaluationService::class)->record($application, $provider, 'exam', 90, 100, 1);
                $this->fail('An official final decision must be reopened before changing evaluations.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertSame($outcome, $application->fresh()->final_outcome);
        }
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_missing_scores_and_incomplete_qualifying_checks_cannot_produce_a_rankable_score(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $service = app(SelectionEvaluationService::class);
        $summary = $service->summary($application);
        $this->assertSame(1, $summary['completed']);
        $this->assertNull($summary['score']);
        $this->assertNull(collect($summary['stages'])->firstWhere('key', 'exam')['score']);
        $service->record($application, $provider, 'exam', 0, 50, 1);
        $service->record($application, $provider, 'interview', 0, 20, 1);
        $this->assertEquals(25.67, $service->summary($application->fresh())['score']);

        $application->stageProgresses()->where('stage_key', 'formal_application')->update(['status' => 'current', 'result' => null]);
        $this->assertNull($service->summary($application->fresh())['score']);
        $this->assertFalse($service->summary($application->fresh())['ready_for_comparison']);
    }

    public function test_scores_are_revisioned_and_stale_forms_cannot_overwrite_a_newer_evaluation(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $service = app(SelectionEvaluationService::class);
        $first = $service->record($application, $provider, 'exam', 45, 50, 1);
        $second = $service->record($application, $provider, 'exam', 46, 50, 1, 1, 'Corrected the transcribed score.');
        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);
        $this->assertSame('45.0000', $first->fresh()->score);
        $this->expectException(ValidationException::class);
        $service->record($application, $provider, 'exam', 47, 50, 1, 1);
    }

    public function test_reopening_and_repassing_a_stage_invalidates_the_previous_score_even_in_the_same_second(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $service = app(SelectionEvaluationService::class);
        $service->record($application, $provider, 'exam', 45, 50, 1);
        $service->record($application, $provider, 'interview', 16, 20, 1);
        $this->assertNotNull($service->summary($application->fresh())['score']);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->reopenLatestDecision($application, $provider, 'Rechecking the interview result.');
        $application = $workflow->recordStageResult($application, 'interview', 'passed', $provider);
        $summary = $service->summary($application);
        $this->assertNull($summary['score']);
        $this->assertSame('needs_review', collect($summary['stages'])->firstWhere('key', 'interview')['state']);
        $this->assertDatabaseCount('application_stage_evaluations', 3);
        try {
            $service->record($application, $provider, 'interview', 16, 20, 1, 1);
            $this->fail('A score form from before the stage was reopened must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $service->record($application, $provider, 'interview', 16, 20, 3, 1);
        $this->assertNotNull($service->summary($application->fresh())['score']);
    }

    public function test_score_validation_rejects_invalid_values_before_writing(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        foreach ([[-1, 100], [101, 100], [5, 0], [null, 100], ['not a score', 100], [INF, 100], [10, 1000001], [1.00001, 100]] as [$score, $maximum]) {
            try {
                app(SelectionEvaluationService::class)->record($application, $provider, 'exam', $score, $maximum, 1);
                $this->fail('Invalid score accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('application_stage_evaluations', 1);
    }

    public function test_unpassed_stages_and_non_scored_stages_cannot_be_evaluated(): void
    {
        [$provider, $application] = $this->application();
        foreach (['exam', 'interview', 'formal_application', 'screening'] as $stage) {
            try {
                app(SelectionEvaluationService::class)->record($application, $provider, $stage, 90, 100, 1);
                $this->fail('Unsupported or unpassed stage accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('application_stage_evaluations', 0);
    }

    public function test_only_approved_and_assigned_selection_staff_can_record_evaluations(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $restricted = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['manage_selection_activities'], 'assigned_program_ids' => [],
        ]);
        $decisionOfficer = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $provider->id,
            'permissions' => ['record_final_decisions'], 'assigned_program_ids' => [$application->scholarship_id],
        ]);
        foreach ([User::factory()->create(['role' => 'provider']), $application->applicant, $restricted, $decisionOfficer] as $actor) {
            try {
                app(SelectionEvaluationService::class)->record($application, $actor, 'exam', 90, 100, 1);
                $this->fail('Unauthorized actor accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $restricted->update(['assigned_program_ids' => [$application->scholarship_id]]);
        app(SelectionEvaluationService::class)->record($application, $restricted->fresh(), 'exam', 90, 100, 1);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $this->expectException(HttpException::class);
        app(SelectionEvaluationService::class)->record($application, $restricted->fresh(), 'interview', 90, 100, 1);
    }

    public function test_qualifying_only_workflows_do_not_invent_a_perfect_score(): void
    {
        [$provider, $application] = $this->application([], []);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $summary = app(SelectionEvaluationService::class)->summary($application);
        $this->assertSame('qualifying_only', $summary['state']);
        $this->assertSame(0, $summary['required']);
        $this->assertNull($summary['score']);
        $this->assertFalse($summary['ready_for_comparison']);
    }

    public function test_final_decision_payload_includes_selection_summary_without_leaking_it_to_applicants(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $this->actingAs($provider)->getJson("/provider/applications/{$application->id}/data?workspace=decision")
            ->assertOk()->assertJsonPath('application.selection_evaluation.state', 'incomplete')
            ->assertJsonPath('application.selection_evaluation.completed', 1)
            ->assertJsonPath('application.selection_evaluation.score', null);
        $this->actingAs($application->applicant)->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()->assertJsonMissingPath('application.selection_evaluation');
    }

    public function test_applicant_match_explanation_counts_the_same_rules_as_the_displayed_percentage(): void
    {
        [, $application] = $this->application();
        $program = $application->scholarship;
        $program->update(['eligible_education_levels' => ['senior_high_school'], 'eligible_school_types' => ['public']]);
        $application->applicant->studentProfile()->update(['education_level' => 'senior_high_school', 'school_type' => 'private']);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program->fresh(), $application->applicant->fresh());
        $counted = collect($match['criteria'])->where('counts_toward_match', true);
        $this->assertSame($counted->count(), $match['match_calculation']['compared']);
        $this->assertSame($counted->where('status', 'pass')->count(), $match['match_calculation']['matched']);
        $this->assertSame($match['score'], $match['match_calculation']['percentage']);
        $this->assertTrue($counted->every(fn ($row) => $row['category'] !== 'readiness'));
        $program->update(['requirements' => 'Latest report card or grades']);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program->fresh(), $application->applicant->fresh());
        $this->assertTrue($match['match_calculation']['includes_document_readiness']);
        $this->assertTrue(collect($match['criteria'])->firstWhere('key', 'documents')['counts_toward_match']);
    }

    public function test_changing_the_reviewed_screening_scores_invalidates_the_previous_selection_evaluation(): void
    {
        [$provider, $application] = $this->application();
        $application = $this->passAll($provider, $application);
        $service = app(SelectionEvaluationService::class);
        $service->record($application, $provider, 'exam', 45, 50, 1);
        $service->record($application, $provider, 'interview', 16, 20, 1);
        $application->update(['rubric_scores' => [...$application->rubric_scores, 'academic_merit' => 95]]);
        $summary = $service->summary($application->fresh());
        $this->assertNull($summary['score']);
        $this->assertSame('needs_review', $summary['stages'][0]['state']);
        $this->assertDatabaseCount('application_stage_evaluations', 3);
    }

    private function application(?array $stages = ['exam', 'interview'], ?array $rubric = null): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Selection evaluation test program',
            'description' => 'An isolated scoring test.', 'status' => 'published',
            'review_rubric' => $rubric ?? ReviewRubric::DEFAULT,
            'selection_stages' => ['screening', 'formal_application', ...$stages, 'decision'],
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(), 'review_rubric_snapshot' => $program->review_rubric,
            'rubric_scores' => ['eligibility_fit' => 100, 'academic_merit' => 90, 'financial_need' => 80, 'document_quality' => 100],
            'rubric_scored_by' => $provider->id, 'rubric_scored_at' => now(),
        ]);

        return [$provider, app(ApplicationWorkflowService::class)->start($application)];
    }

    private function passAll(User $provider, ScholarshipApplication $application): ScholarshipApplication
    {
        $workflow = app(ApplicationWorkflowService::class);
        foreach (['screening', 'formal_application', 'exam', 'interview'] as $stage) {
            $application = $workflow->recordStageResult($application, $stage, 'passed', $provider);
        }

        return $application;
    }
}
