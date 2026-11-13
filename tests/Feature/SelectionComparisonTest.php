<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\SelectionComparisonService;
use App\Services\SelectionEvaluationService;
use App\Support\ReviewRubric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectionComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_stage_scores_drive_select_and_waitlist_suggestions_without_recording_outcomes(): void
    {
        [$provider, $program] = $this->program(2);
        $first = $this->candidate($provider, $program, 95, 'Highest');
        $second = $this->candidate($provider, $program, 90, 'Second');
        $third = $this->candidate($provider, $program, 75, 'Third');
        $context = app(SelectionComparisonService::class)->forProgram($program->fresh());
        $this->assertFalse($context['provisional']);
        $this->assertSame('selected', $context['rows'][$first->id]['recommended_outcome']);
        $this->assertSame('selected', $context['rows'][$second->id]['recommended_outcome']);
        $this->assertSame('waitlisted', $context['rows'][$third->id]['recommended_outcome']);
        $this->assertSame([1, 2, 3], array_column($context['rows'], 'rank'));
        $this->assertSame(0, $program->applications()->whereNotNull('final_outcome')->count());
    }

    public function test_a_cutoff_tie_has_a_shared_rank_and_no_arbitrary_winner(): void
    {
        [$provider, $program] = $this->program(2);
        $this->candidate($provider, $program, 95, 'First');
        $left = $this->candidate($provider, $program, 90.001, 'TiedOne');
        $right = $this->candidate($provider, $program, 90.004, 'TiedTwo');
        $service = app(SelectionComparisonService::class);
        $context = $service->forProgram($program->fresh());
        foreach ([$left, $right] as $candidate) {
            $row = $context['rows'][$candidate->id];
            $this->assertSame(2, $row['rank']);
            $this->assertTrue($row['tie_at_cutoff']);
            $this->assertNull($row['recommended_outcome']);
            $this->assertTrue($service->requiresRationale($service->forApplication($candidate, $context), 'selected'));
        }
    }

    public function test_missing_evaluations_never_become_zero_or_a_selection_recommendation(): void
    {
        [$provider, $program] = $this->program(2);
        $complete = $this->candidate($provider, $program, 0, 'RealZero');
        $missing = $this->candidate($provider, $program, 90, 'Missing', false);
        $context = app(SelectionComparisonService::class)->forProgram($program->fresh());
        $this->assertSame(0.0, $context['rows'][$complete->id]['score']);
        $this->assertSame(1, $context['rows'][$complete->id]['rank']);
        $this->assertNull($context['rows'][$complete->id]['recommended_outcome']);
        $this->assertNull($context['rows'][$missing->id]['score']);
        $this->assertNull($context['rows'][$missing->id]['rank']);
        $this->assertTrue($context['provisional']);
    }

    public function test_open_intake_and_applicants_still_in_selection_keep_the_order_provisional(): void
    {
        [$provider, $program] = $this->program(2);
        $candidate = $this->candidate($provider, $program, 95, 'Complete');
        $program->update(['deadline' => now()->addDay()->toDateString()]);
        $service = app(SelectionComparisonService::class);
        $this->assertNull($service->forProgram($program->fresh())['rows'][$candidate->id]['recommended_outcome']);
        $program->update(['status' => 'closed']);
        $this->assertSame('selected', $service->forProgram($program->fresh())['rows'][$candidate->id]['recommended_outcome']);
        $program->update(['status' => 'published', 'deadline' => now()->subDay()->toDateString()]);
        $pending = $this->candidate($provider, $program, 90, 'Earlier');
        $pending->update(['workflow_stage' => 'interview', 'application_state' => 'in_provider_process']);
        $context = $service->forProgram($program->fresh());
        $this->assertSame(1, $context['awaiting_selection']);
        $this->assertNull($context['rows'][$candidate->id]['recommended_outcome']);
    }

    public function test_different_policies_and_legacy_records_are_not_silently_ranked_together(): void
    {
        [$provider, $program] = $this->program(2);
        $first = $this->candidate($provider, $program, 95, 'First');
        $other = $this->candidate($provider, $program, 90, 'Other');
        $other->forceFill(['selection_scoring_snapshot' => [...$other->selection_scoring_snapshot, 'version' => 'different']])->save();
        $service = app(SelectionComparisonService::class);
        $context = $service->forProgram($program->fresh());
        $this->assertNull($context['rows'][$first->id]['rank']);
        $this->assertTrue($context['provisional']);
        $other->forceFill(['selection_scoring_snapshot' => null])->save();
        $context = $service->forProgram($program->fresh());
        $this->assertNull($context['rows'][$other->id]['rank']);
        $this->assertNull($context['rows'][$first->id]['recommended_outcome']);
    }

    public function test_rank_is_program_wide_even_when_search_or_pagination_hides_other_candidates(): void
    {
        [$provider, $program] = $this->program(3);
        $last = null;
        foreach ([99, 98, 97, 96, 95, 94, 93] as $index => $score) {
            $last = $this->candidate($provider, $program, $score, 'Candidate'.($index + 1));
        }
        $url = "/provider/workspaces/decisions/data?queue=pending&program_id={$program->id}";
        $this->actingAs($provider)->getJson($url.'&page=2')->assertOk()
            ->assertJsonPath('applications.0.selection_comparison.rank', 6)
            ->assertJsonPath('applications.1.selection_comparison.rank', 7);
        $this->getJson($url.'&search=Candidate7')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('applications.0.id', $last->id)->assertJsonPath('applications.0.selection_comparison.rank', 7);
    }

    public function test_a_stale_comparison_cannot_record_an_outcome(): void
    {
        [$provider, $program] = $this->program(1);
        $first = $this->candidate($provider, $program, 95, 'First');
        $other = $this->candidate($provider, $program, 90, 'Other');
        $reference = app(SelectionComparisonService::class)->forApplication($first)['reference'];
        app(SelectionEvaluationService::class)->record($other, $provider, 'exam', 100, 100, 1, 1, 'Corrected the score after checking the signed sheet.');
        $this->actingAs($provider)->patchJson("/provider/applications/{$first->id}/final-outcome", [
            'outcome' => 'selected', 'comparison_reference' => $reference,
        ])->assertUnprocessable()->assertJsonValidationErrors('comparison_reference');
        $this->assertNull($first->fresh()->final_outcome);
        $this->assertNull($first->fresh()->selection_decision_snapshot);
    }

    public function test_a_different_outcome_requires_an_internal_reason_and_preserves_the_recorded_evaluation(): void
    {
        [$provider, $program] = $this->program(1);
        $first = $this->candidate($provider, $program, 95, 'First');
        $lower = $this->candidate($provider, $program, 75, 'Lower');
        $reference = app(SelectionComparisonService::class)->forApplication($lower)['reference'];
        $payload = ['outcome' => 'selected', 'comparison_reference' => $reference, 'notes' => 'Your application was selected.'];
        $this->actingAs($provider)->patchJson("/provider/applications/{$lower->id}/final-outcome", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('selection_rationale');
        $this->patchJson("/provider/applications/{$lower->id}/final-outcome", [...$payload, 'selection_rationale' => 'Private rationale: the panel accepted additional reviewed evidence.'])
            ->assertOk()->assertJsonPath('application.selection_evaluation.score', 75)
            ->assertJsonPath('application.selection_evaluation.historical', true);
        $snapshot = $lower->fresh()->selection_decision_snapshot;
        $this->assertSame(2, $snapshot['comparison']['rank']);
        $this->assertSame('waitlisted', $snapshot['comparison']['recommended_outcome']);
        $history = $lower->statusHistories()->whereNotNull('selection_evaluation_snapshot')->sole();
        $this->assertSame($snapshot, $history->selection_evaluation_snapshot);
        $this->assertArrayNotHasKey('selection_decision_snapshot', $lower->fresh()->toArray());
        $this->assertArrayNotHasKey('selection_evaluation_snapshot', $history->toArray());
        $this->actingAs($lower->applicant)->getJson("/dashboard/applications/{$lower->id}/data")
            ->assertOk()->assertDontSee('Private rationale');
        $this->assertNull($first->fresh()->final_outcome);
    }

    public function test_reopening_keeps_the_old_decision_basis_in_history(): void
    {
        [$provider, $program] = $this->program(1);
        $candidate = $this->candidate($provider, $program, 95, 'First');
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordFinalOutcome($candidate, 'selected', $provider);
        $before = $application->selection_decision_snapshot;
        $application = $workflow->reopenLatestDecision($application, $provider, 'Reopen this outcome for the panel to reconsider.');
        $this->assertNull($application->selection_decision_snapshot);
        $this->assertSame($before, $application->statusHistories()->whereNotNull('selection_evaluation_snapshot')->sole()->selection_evaluation_snapshot);
        $this->assertTrue(app(SelectionEvaluationService::class)->summary($application)['ready_for_comparison']);
    }

    public function test_an_official_waitlist_keeps_its_positions_instead_of_being_sorted_by_live_scores(): void
    {
        [$provider, $program] = $this->program(0);
        $lower = $this->candidate($provider, $program, 75, 'Earlier');
        $higher = $this->candidate($provider, $program, 95, 'Later');
        $workflow = app(ApplicationWorkflowService::class);
        $workflow->recordFinalOutcome($lower, 'waitlisted', $provider);
        $workflow->recordFinalOutcome($higher, 'waitlisted', $provider);
        $this->actingAs($provider)->getJson("/provider/workspaces/decisions/data?queue=waitlist&program_id={$program->id}")
            ->assertOk()->assertJsonPath('applications.0.id', $lower->id)
            ->assertJsonPath('applications.0.decision.label', 'Waitlist #1')
            ->assertJsonPath('applications.1.id', $higher->id)->assertJsonPath('applications.1.decision.label', 'Waitlist #2');
    }

    public function test_program_scopes_are_respected_by_the_comparison_api(): void
    {
        [$owner, $program] = $this->program(2);
        $this->candidate($owner, $program, 90, 'Allowed');
        $officer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id,
            'permissions' => ['record_final_decisions'], 'assigned_program_ids' => []]);
        $this->actingAs($officer)->getJson("/provider/workspaces/decisions/data?program_id={$program->id}")->assertForbidden();
    }

    public function test_unlimited_full_and_non_boundary_tied_groups_use_the_actual_capacity(): void
    {
        [$provider, $program] = $this->program(2);
        $left = $this->candidate($provider, $program, 90, 'FirstTie');
        $right = $this->candidate($provider, $program, 90, 'SecondTie');
        $service = app(SelectionComparisonService::class);
        foreach ([2 => 'selected', 0 => 'waitlisted'] as $slots => $suggestion) {
            $program->update(['slots_available' => $slots]);
            $context = $service->forProgram($program->fresh());
            foreach ([$left, $right] as $candidate) {
                $this->assertSame($suggestion, $context['rows'][$candidate->id]['recommended_outcome']);
                $this->assertFalse($context['rows'][$candidate->id]['tie_at_cutoff']);
                $this->assertSame(1, $context['rows'][$candidate->id]['rank']);
            }
        }
        $program->update(['slots_available' => null]);
        $context = $service->forProgram($program->fresh());
        $this->assertSame('selected', $context['rows'][$left->id]['recommended_outcome']);
        $this->assertNull($context['capacity']['remaining']);
    }

    public function test_scored_decisions_require_a_current_reference_and_cannot_be_bulk_selected(): void
    {
        [$provider, $program] = $this->program(1);
        $candidate = $this->candidate($provider, $program, 95, 'First');
        $this->actingAs($provider)->patchJson("/provider/applications/{$candidate->id}/final-outcome", ['outcome' => 'selected'])
            ->assertUnprocessable()->assertJsonValidationErrors('comparison_reference');
        $this->patchJson("/provider/scholarships/{$program->id}/applications/bulk-advance", ['application_ids' => [$candidate->id], 'target_stage' => 'selected'])
            ->assertUnprocessable()->assertJsonValidationErrors('application_ids');
        $this->assertNull($candidate->fresh()->final_outcome);
    }

    public function test_a_recorded_score_and_prior_waitlist_basis_survive_promotion_and_later_profile_changes(): void
    {
        [$provider, $program] = $this->program(0);
        $candidate = $this->candidate($provider, $program, 90, 'First');
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordFinalOutcome($candidate, 'waitlisted', $provider);
        $waitlistBasis = $application->selection_decision_snapshot;
        $program->update(['slots_available' => 1]);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $application->update(['rubric_scores' => ['academic_merit' => 20, 'financial_need' => 20]]);
        $this->assertEquals(90, app(SelectionEvaluationService::class)->summary($application->fresh())['score']);
        $this->assertSame(2, $application->statusHistories()->whereNotNull('selection_evaluation_snapshot')->count());
        $this->assertSame($waitlistBasis, $application->statusHistories()->where('to_status', 'waitlisted')->sole()->selection_evaluation_snapshot);
    }

    public function test_legacy_awards_and_waitlists_are_not_miscounted_as_unfinished_selection(): void
    {
        [$provider, $program] = $this->program(2);
        $candidate = $this->candidate($provider, $program, 95, 'Pending');
        ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id, 'status' => 'awarded']);
        $service = app(SelectionComparisonService::class);
        $context = $service->forProgram($program->fresh());
        $this->assertSame(1, $context['capacity']['occupied']);
        $this->assertSame(0, $context['awaiting_selection']);
        $this->assertSame('selected', $context['rows'][$candidate->id]['recommended_outcome']);
        ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id, 'status' => 'waitlisted', 'waitlist_position' => 1]);
        $context = $service->forProgram($program->fresh());
        $this->assertSame(1, $context['waitlist_count']);
        $this->assertSame(0, $context['awaiting_selection']);
        $this->assertNull($context['rows'][$candidate->id]['recommended_outcome']);
    }

    private function program(?int $slots): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Final selection comparison', 'description' => 'Isolated selection tests.',
            'status' => 'published', 'slots_available' => $slots, 'deadline' => now()->subDay()->toDateString(),
            'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'], 'review_rubric' => ReviewRubric::DEFAULT,
        ]);

        return [$provider, $program];
    }

    private function candidate(User $provider, Scholarship $program, float $score, string $name, bool $complete = true): ScholarshipApplication
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update(['first_name' => $name, 'last_name' => 'Learner', 'middle_initial' => null]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now()->subDays(2),
            'rubric_scores' => ['eligibility_fit' => 100, 'academic_merit' => $score, 'financial_need' => $score, 'document_quality' => 100],
            'rubric_scored_by' => $provider->id, 'rubric_scored_at' => now()->subDay(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        foreach (['screening', 'formal_application', 'exam', 'interview'] as $stage) {
            $application = $workflow->recordStageResult($application, $stage, 'passed', $provider);
            if ($complete && in_array($stage, ['exam', 'interview'], true)) {
                app(SelectionEvaluationService::class)->record($application, $provider, $stage, $score, 100, 1);
            }
        }

        return $application;
    }
}
