<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApplicationStageTransitionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_configured_path_keeps_one_current_stage_and_audits_every_decision(): void
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

        $this->assertCurrentStage($application, 'screening');

        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $this->assertSame('approved', $application->status);
        $this->assertSame('in_provider_process', $application->application_state);
        $this->assertCurrentStage($application, 'formal_application');

        $this->completedSchedule($application, $provider, 'formal_application');
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $this->assertSame('exam_qualified', $application->status);
        $this->assertCurrentStage($application, 'exam');

        $this->completedSchedule($application, $provider, 'exam');
        $application = $workflow->recordStageResult($application, 'exam', 'passed', $provider);
        $this->assertSame('interview', $application->status);
        $this->assertCurrentStage($application, 'interview');

        $this->completedSchedule($application, $provider, 'interview');
        $application = $workflow->recordStageResult($application, 'interview', 'passed', $provider);
        $this->assertSame('approved', $application->status);
        $this->assertSame('awaiting_decision', $application->application_state);
        $this->assertCurrentStage($application, 'decision');

        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);

        $this->assertSame('awarded', $application->status);
        $this->assertSame('closed', $application->application_state);
        $this->assertSame('complete', $application->workflow_stage);
        $this->assertSame('selected', $application->final_outcome);
        $this->assertSame(0, $application->stageProgresses()->where('status', 'current')->count());
        $this->assertSame(5, $application->stageProgresses()->where('status', 'passed')->count());
        $this->assertSame('selected', $application->stageProgresses()
            ->where('stage_key', 'decision')
            ->value('result'));
        $this->assertSame(
            ['approved', 'exam_qualified', 'interview', 'approved', 'awarded'],
            $application->statusHistories()->orderBy('id')->pluck('to_status')->all(),
        );
    }

    public function test_each_negative_stage_result_closes_that_stage_and_skips_every_later_stage(): void
    {
        $stages = ['screening', 'formal_application', 'exam', 'interview', 'decision'];
        $cases = [
            'screening' => ['status' => 'rejected', 'outcome' => null, 'reason' => 'outside_eligibility'],
            'formal_application' => ['status' => 'not_awarded', 'outcome' => 'not_selected', 'reason' => 'formal_application_not_completed'],
            'exam' => ['status' => 'exam_failed', 'outcome' => 'not_selected', 'reason' => 'failed_exam'],
            'interview' => ['status' => 'interview_failed', 'outcome' => 'not_selected', 'reason' => 'failed_interview'],
        ];
        $workflow = app(ApplicationWorkflowService::class);

        foreach ($cases as $targetStage => $expected) {
            [$provider, $application] = $this->application($stages);
            $application = $workflow->start($application);
            $targetPosition = array_search($targetStage, $stages, true);

            foreach (array_slice($stages, 0, $targetPosition) as $passedStage) {
                if ($passedStage !== 'screening') {
                    $this->completedSchedule($application, $provider, $passedStage);
                }

                $application = $workflow->recordStageResult(
                    $application,
                    $passedStage,
                    'passed',
                    $provider,
                );
            }

            if ($targetStage !== 'screening') {
                $this->completedSchedule($application, $provider, $targetStage);
            }

            $application = $workflow->recordStageResult(
                $application,
                $targetStage,
                'not_passed',
                $provider,
                "The {$targetStage} result was not passed.",
                $expected['reason'],
            );
            $progress = $application->stageProgresses()->get()->keyBy('stage_key');

            $this->assertSame($expected['status'], $application->status, $targetStage);
            $this->assertSame($expected['outcome'], $application->final_outcome, $targetStage);
            $this->assertSame('closed', $application->application_state, $targetStage);
            $this->assertSame('complete', $application->workflow_stage, $targetStage);
            $this->assertSame('not_passed', $progress[$targetStage]->status, $targetStage);
            $this->assertSame('not_passed', $progress[$targetStage]->result, $targetStage);
            $this->assertSame(0, $progress->where('status', 'current')->count(), $targetStage);

            foreach (array_slice($stages, $targetPosition + 1) as $laterStage) {
                $this->assertSame('skipped', $progress[$laterStage]->status, "{$targetStage} -> {$laterStage}");
            }
        }
    }

    public function test_out_of_order_and_premature_decisions_do_not_partially_mutate_the_application(): void
    {
        [$provider, $application] = $this->application([
            'screening',
            'exam',
            'formal_application',
            'decision',
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $originalProgress = $application->stageProgresses()
            ->orderBy('position')
            ->pluck('status', 'stage_key')
            ->all();

        try {
            $workflow->recordStageResult($application, 'exam', 'passed', $provider);
            $this->fail('An out-of-order stage result was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('result', $error->errors());
        }

        try {
            $workflow->recordFinalOutcome($application, 'selected', $provider);
            $this->fail('A final outcome was accepted before the decision stage.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('outcome', $error->errors());
        }

        $application->refresh();

        $this->assertSame('submitted', $application->status);
        $this->assertSame('submitted', $application->application_state);
        $this->assertSame('screening', $application->workflow_stage);
        $this->assertNull($application->final_outcome);
        $this->assertSame($originalProgress, $application->stageProgresses()
            ->orderBy('position')
            ->pluck('status', 'stage_key')
            ->all());
        $this->assertSame(0, $application->statusHistories()->count());
    }

    public function test_completing_a_schedule_does_not_advance_the_application_without_a_result(): void
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
        $schedule = ApplicationSchedule::create([
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
            'title' => 'Qualifying examination',
            'scheduled_at' => now()->subHour(),
            'mode' => 'onsite',
            'venue' => 'Provider testing room',
            'instructions' => 'Bring a school ID.',
            'status' => 'scheduled',
            'attendance_status' => 'not_required',
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('schedule.status', 'completed')
            ->assertJsonPath('application.workflow.current_stage', 'exam');

        $application->refresh();

        $this->assertSame('exam_qualified', $application->status);
        $this->assertSame('in_provider_process', $application->application_state);
        $this->assertSame('exam', $application->workflow_stage);
        $this->assertSame('current', $application->stageProgresses()
            ->where('stage_key', 'exam')
            ->value('status'));
        $this->assertSame(1, $application->statusHistories()->count());
    }

    private function assertCurrentStage(ScholarshipApplication $application, string $stage): void
    {
        $application->refresh();

        $this->assertSame($stage, $application->workflow_stage);
        $this->assertSame(1, $application->stageProgresses()->where('status', 'current')->count());
        $this->assertSame('current', $application->stageProgresses()
            ->where('stage_key', $stage)
            ->value('status'));
    }

    private function completedSchedule(
        ScholarshipApplication $application,
        User $provider,
        string $stage,
    ): ApplicationSchedule {
        return ApplicationSchedule::create([
            'scholarship_application_id' => $application->id,
            'type' => $stage,
            'title' => ucfirst(str_replace('_', ' ', $stage)),
            'scheduled_at' => now()->subHour(),
            'mode' => 'onsite',
            'venue' => 'Provider office',
            'instructions' => 'Attend the provider-managed activity.',
            'status' => 'completed',
            'attendance_status' => 'not_required',
            'completed_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);
    }

    /** @return array{User, ScholarshipApplication} */
    private function application(array $stages): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to protect the application-stage transition contract.',
            'selection_stages' => $stages,
            'slots_available' => 10,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        return [$provider, $application];
    }
}
