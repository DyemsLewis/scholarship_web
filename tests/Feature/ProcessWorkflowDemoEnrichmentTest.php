<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientBenefitFollowUpService;
use App\Services\RecipientSupportSettlementService;
use App\Services\SelectionComparisonService;
use App\Services\SelectionEvaluationService;
use Database\Seeders\FreshWorkflowDemoSeeder;
use Database\Seeders\ProcessWorkflowDemoEnrichmentSeeder as Enrichment;
use Database\Seeders\Support\WorkflowDemoTimelineAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessWorkflowDemoEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private array $originalIds = [];

    public function test_additive_examples_have_actionable_dss_support_and_closeout_without_changing_existing_records(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $before = $this->existingRows();
        $this->seed(Enrichment::class);
        $this->assertSame($before, $this->existingRows());
        $this->assertSame(16, Scholarship::count());
        $this->assertSame(12, User::where('username', 'like', Enrichment::PREFIX.'%')->count());
        $this->assertSame([], WorkflowDemoTimelineAudit::inspect()['violations']);
        foreach (array_keys(Enrichment::SCENARIOS) as $key) {
            $application = $this->application($key);
            $this->assertTrue($application->applicant->applicantProfileReadiness()['verified'], $key);
            $this->assertTrue(Hash::check('password123', $application->applicant->password));
            Storage::disk('local')->assertExists($application->applicant->studentProfile->profile_photo_path);
            $this->assertTrue($application->submitted_at->lt($application->scholarship->deadline));
            foreach ($application->stageProgresses->whereNotNull('completed_at') as $stage) {
                $this->assertTrue($stage->completed_at->gt($application->scholarship->deadline), $key.': '.$stage->stage_key);
            }
        }
        $first = $this->application('dss.first');
        $comparison = app(SelectionComparisonService::class)->forProgram($first->scholarship);
        foreach (['dss.first' => [1, 'selected'], 'dss.second' => [2, 'selected'], 'dss.third' => [3, 'waitlisted']] as $key => [$rank, $suggestion]) {
            $application = $this->application($key);
            $this->assertTrue(app(SelectionEvaluationService::class)->summary($application)['ready_for_comparison']);
            $this->assertSame($rank, $comparison['rows'][$application->id]['rank']);
            $this->assertSame($suggestion, $comparison['rows'][$application->id]['recommended_outcome']);
            $this->assertNull($application->final_outcome);
        }
        $missing = app(SelectionEvaluationService::class)->summary($this->application('dss.score'));
        $this->assertFalse($missing['ready_for_comparison']);
        $this->assertNull($missing['score']);
        $this->assertSame(0, $this->application('dss.score')->stageEvaluations()->where('stage_key', 'interview')->count());
        foreach (['support.appointment' => 'awaiting_submission', 'support.review' => 'awaiting_submission',
            'support.close' => 'ready_to_close', 'support.decision' => 'closed', 'support.history' => 'closed'] as $key => $status) {
            $this->assertSame($status, $this->application($key)->monitoringCases()->sole()->status, $key);
        }
        foreach (['support.decision', 'support.history', 'award.complete'] as $key) {
            $settlement = app(RecipientSupportSettlementService::class)->summary($this->application($key));
            $this->assertTrue($settlement['can_complete'], $key.': '.json_encode($settlement['blockers']));
            $this->assertSame($key === 'award.complete' ? 1 : 2, $settlement['counts']['received']);
        }
        $this->assertFalse($this->application('support.decision')->supportDecisions()->exists());
        $this->assertSame('renewed', $this->application('support.history')->supportDecisions()->sole()->decision);
        $this->assertNull($this->application('award.receipt')->benefitReleaseRecords()->sole()->receiptResponse);
        $followup = $this->application('award.followup')->benefitReleaseRecords()->sole();
        $payload = app(RecipientBenefitFollowUpService::class)->payload($followup, $followup->applicant);
        $this->assertTrue($payload['can_reply']);
        $this->assertSame('awaiting_response', $payload['status']);
        $this->assertFalse($this->application('award.complete')->scholarship->monitoringCycles()->exists());
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 5);
        $this->assertDatabaseCount('recipient_support_decisions', 1);
        $this->assertDatabaseCount('recipient_benefit_follow_ups', 1);
        $counts = [User::count(), ScholarshipApplication::count(), ActivityLog::count()];
        $this->seed(Enrichment::class);
        $this->assertSame($counts, [User::count(), ScholarshipApplication::count(), ActivityLog::count()]);
        $this->assertSame($before, $this->existingRows());

        // Exercise the next actions in SQLite only; hosted fixtures remain undecided.
        $officer = User::where('username', 'talunaw.decisions')->sole();
        $reference = app(SelectionComparisonService::class)->forApplication($first->fresh())['reference'];
        $this->actingAs($officer)->patchJson('/provider/applications/'.$first->id.'/final-outcome', [
            'outcome' => 'selected', 'comparison_reference' => $reference, 'expected_outcome' => null,
        ])->assertSuccessful();
        $this->assertSame('selected', $first->fresh()->final_outcome);
        $case = $this->application('support.close')->monitoringCases()->sole();
        $this->actingAs(User::where('username', 'talunaw.monitoring')->sole())->patchJson('/provider/monitoring-cases/'.$case->id.'/close', [
            'outcome' => 'completed',
        ])->assertSuccessful();
        $this->assertSame('closed', $case->fresh()->status);
        foreach (['support.decision', 'award.complete'] as $key) {
            $application = $this->application($key);
            $this->actingAs(User::where('username', 'talunaw.recipients')->sole())->postJson('/provider/applications/'.$application->id.'/support-decision', [
                'decision' => 'completed', 'reason_category' => 'program_completed', 'effective_on' => now()->toDateString(),
                'reason' => 'All agreed support and required reviews are complete.', 'confirmed' => true,
            ])->assertSuccessful();
            $this->assertSame('completed', $application->supportDecisions()->sole()->decision);
        }
    }

    private function application(string $key): ScholarshipApplication
    {
        return ScholarshipApplication::whereHas('applicant', fn ($q) => $q->where('username', Enrichment::PREFIX.$key))->sole();
    }

    private function existingRows(): array
    {
        return collect(['scholarships', 'scholarship_applications', 'student_profiles', 'application_stage_progresses',
            'recipient_monitoring_cases', 'recipient_benefit_release_records'])->mapWithKeys(function ($table) {
                $this->originalIds[$table] ??= DB::table($table)->pluck('id')->all();
                return [$table => DB::table($table)->whereIn('id', $this->originalIds[$table])->orderBy('id')->get()
                    ->mapWithKeys(fn ($row) => [$row->id => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))])->all()];
            })->all();
    }
}
