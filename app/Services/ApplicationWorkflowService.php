<?php

namespace App\Services;

use App\Models\ApplicationStageProgress;
use App\Models\ApplicationStatusHistory;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationWorkflowService
{
    public const RESULTS = ['passed', 'not_passed'];

    public const FINAL_OUTCOMES = ['selected', 'waitlisted', 'not_selected'];

    public function initialize(ScholarshipApplication $application): ScholarshipApplication
    {
        // Reload progress every time because a payload may initialize the relation earlier in the same request.
        $application->load(['scholarship', 'stageProgresses']);
        $stages = $this->stagesForApplication($application);
        $isUninitialized = blank($application->workflow_stage);
        $currentStage = $application->workflow_stage ?: $this->inferStage($application->status, $stages);

        if ($currentStage !== 'complete' && ! in_array($currentStage, $stages, true)) {
            $currentStage = $this->inferStage($application->status, $stages);
        }

        $applicationState = $isUninitialized
            ? $this->inferState($application->status)
            : ($application->application_state ?: $this->inferState($application->status));
        $finalOutcome = $isUninitialized
            ? $this->inferFinalOutcome($application->status)
            : ($application->final_outcome ?: $this->inferFinalOutcome($application->status));

        if ($application->stageProgresses->isEmpty()) {
            $this->createInitialProgress($application, $stages, $currentStage, $finalOutcome);
        } else {
            foreach ($stages as $position => $stage) {
                $progress = $application->stageProgresses()->firstOrCreate([
                    'stage_key' => $stage,
                ], [
                    'position' => $position,
                    'status' => $stage === $currentStage ? 'current' : 'pending',
                    'started_at' => $stage === $currentStage ? now() : null,
                ]);

                if ((int) $progress->position !== $position) {
                    $progress->update(['position' => $position]);
                }
            }
        }

        if ($application->workflow_stage !== $currentStage
            || $application->application_state !== $applicationState
            || $application->final_outcome !== $finalOutcome
            || (int) $application->workflow_version !== 2) {
            $application->update([
                'workflow_version' => 2,
                'application_state' => $applicationState,
                'workflow_stage' => $currentStage,
                'final_outcome' => $finalOutcome,
            ]);
        }

        return $application->fresh()->load(['scholarship', 'stageProgresses']);
    }

    public function start(ScholarshipApplication $application): ScholarshipApplication
    {
        $application->update([
            'workflow_version' => 2,
            'application_state' => 'submitted',
            'workflow_stage' => 'screening',
            'final_outcome' => null,
        ]);
        $application = $this->initialize($application);

        return $this->captureSubmissionSnapshot($application, 'initial_submission');
    }

    public function captureSubmissionSnapshot(
        ScholarshipApplication $application,
        string $source = 'correction_resubmitted',
    ): ScholarshipApplication {
        $application->loadMissing([
            'applicant.studentProfile',
            'documents',
            'scholarship',
        ]);
        $profile = $application->applicant?->studentProfile;
        $existing = is_array($application->submission_snapshot)
            ? $application->submission_snapshot
            : [];
        $selectionStages = $this->stagesForApplication($application);
        $history = collect($existing['history'] ?? []);

        if (isset($existing['current']) && is_array($existing['current'])) {
            $history->push($existing['current']);
        }

        $version = max(1, (int) ($existing['version'] ?? 0) + 1);
        $current = [
            'version' => $version,
            'source' => $source,
            'captured_at' => now()->toISOString(),
            'applicant' => [
                'name' => $application->applicant?->name,
                'email' => $application->applicant?->email,
                'contact_number' => $application->applicant?->contact_number,
                'education_level' => $profile?->education_level,
                'school' => $profile?->school,
                'school_type' => $profile?->school_type,
                'course_or_strand' => $profile?->course_or_strand,
                'year_level' => $profile?->year_level,
                'gwa' => $profile?->gwa,
                'grading_scale' => $profile?->grading_scale,
                'income_bracket' => $profile?->income_bracket,
                'location' => collect([
                    $profile?->barangay,
                    $profile?->city,
                    $profile?->province,
                    $profile?->region,
                ])->filter()->implode(', '),
            ],
            'program' => [
                'title' => $application->scholarship?->title,
                'deadline' => $application->scholarship?->deadline?->toDateString(),
                'requirements' => $application->document_checklist ?? [],
                'optional_requirements' => $application->optional_document_checklist ?? [],
                // Keep the submitted process stable when a provider edits the program later.
                'selection_stages' => $selectionStages,
            ],
            'documents' => $application->documents
                ->sortBy('id')
                ->map(fn ($document): array => [
                    'name' => $document->document_name,
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'uploaded_at' => $document->uploaded_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ];

        $application->update([
            'submission_snapshot' => [
                'version' => $version,
                'current' => $current,
                'history' => $history->take(-5)->values()->all(),
            ],
        ]);

        return $application->fresh();
    }

    public function recordStageResult(
        ScholarshipApplication $application,
        string $stage,
        string $result,
        User $actor,
        ?string $notes = null,
        ?string $decisionReason = null,
    ): ScholarshipApplication {
        if (! in_array($result, self::RESULTS, true)) {
            throw ValidationException::withMessages([
                'result' => 'Choose Passed or Not passed.',
            ]);
        }

        $application = $this->initialize($application);

        return DB::transaction(function () use ($application, $stage, $result, $actor, $notes, $decisionReason): ScholarshipApplication {
            $locked = ScholarshipApplication::query()
                ->with(['scholarship', 'stageProgresses'])
                ->lockForUpdate()
                ->findOrFail($application->id);

            if (in_array($locked->application_state, ['closed', 'withdrawn'], true)) {
                throw ValidationException::withMessages([
                    'result' => 'This application is already closed.',
                ]);
            }

            if (in_array($locked->correction_status, ['requested', 'submitted'], true)) {
                throw ValidationException::withMessages([
                    'result' => 'Resolve the correction request before recording a stage result.',
                ]);
            }

            if ($locked->workflow_stage !== $stage) {
                throw ValidationException::withMessages([
                    'result' => 'This applicant is no longer at that stage.',
                ]);
            }

            $progress = $locked->stageProgresses->firstWhere('stage_key', $stage);

            if (! $progress || $progress->status !== 'current') {
                throw ValidationException::withMessages([
                    'result' => 'This stage is not ready for a result.',
                ]);
            }

            $previousStatus = $locked->status;
            $reason = $decisionReason ?: $this->defaultDecisionReason($stage, $result);
            $now = now();
            $progress->update([
                'status' => $result === 'passed' ? 'passed' : 'not_passed',
                'result' => $result,
                'notes' => $notes,
                'completed_at' => $now,
                'decided_by' => $actor->id,
                'decided_at' => $now,
            ]);

            if ($result === 'passed') {
                $nextStage = ScholarshipSelectionPlan::nextStage(
                    $stage,
                    $this->stagesForApplication($locked),
                );

                if ($nextStage === null) {
                    $nextStage = 'decision';
                }

                $nextProgress = $locked->stageProgresses->firstWhere('stage_key', $nextStage);
                $nextProgress?->update([
                    'status' => 'current',
                    'result' => null,
                    'started_at' => $nextProgress->started_at ?: $now,
                ]);
                $nextStatus = $this->legacyStatusForStage($nextStage);
                $locked->update([
                    'status' => $nextStatus,
                    'application_state' => $nextStage === 'decision'
                        ? 'awaiting_decision'
                        : 'in_provider_process',
                    'workflow_stage' => $nextStage,
                    'final_outcome' => null,
                    'decision_reason' => $reason,
                    'review_notes' => $notes ?: $locked->review_notes,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ]);
            } else {
                $locked->stageProgresses()
                    ->where('position', '>', $progress->position)
                    ->whereIn('status', ['pending', 'current'])
                    ->update(['status' => 'skipped', 'updated_at' => $now]);
                $nextStatus = $this->failureStatusForStage($stage);
                $locked->update([
                    'status' => $nextStatus,
                    'application_state' => 'closed',
                    'workflow_stage' => 'complete',
                    'final_outcome' => $stage === 'screening' ? null : 'not_selected',
                    'decision_reason' => $reason,
                    'review_notes' => $notes ?: $locked->review_notes,
                    'outcome_at' => $stage === 'screening' ? $locked->outcome_at : $now,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $now,
                ]);
            }

            ApplicationStatusHistory::create([
                'scholarship_application_id' => $locked->id,
                'changed_by' => $actor->id,
                'from_status' => $previousStatus,
                'to_status' => $locked->status,
                'decision_reason' => $reason,
                'review_notes' => $notes ?: ucfirst(str_replace('_', ' ', $stage))." {$result}.",
                'changed_at' => $now,
            ]);

            return $locked->fresh()->load(['scholarship', 'stageProgresses']);
        });
    }

    public function recordFinalOutcome(
        ScholarshipApplication $application,
        string $outcome,
        User $actor,
        ?string $notes = null,
        ?string $decisionReason = null,
    ): ScholarshipApplication {
        if (! in_array($outcome, self::FINAL_OUTCOMES, true)) {
            throw ValidationException::withMessages([
                'outcome' => 'Choose Selected, Waitlisted, or Not selected.',
            ]);
        }

        $application = $this->initialize($application);

        return DB::transaction(function () use ($application, $outcome, $actor, $notes, $decisionReason): ScholarshipApplication {
            $locked = ScholarshipApplication::query()
                ->with(['scholarship', 'stageProgresses'])
                ->lockForUpdate()
                ->findOrFail($application->id);

            if (in_array($locked->correction_status, ['requested', 'submitted'], true)) {
                throw ValidationException::withMessages([
                    'outcome' => 'Resolve the correction request before recording the final outcome.',
                ]);
            }

            if ($locked->workflow_stage !== 'decision' && $locked->final_outcome !== 'waitlisted') {
                throw ValidationException::withMessages([
                    'outcome' => 'Complete the configured provider stages before recording the final outcome.',
                ]);
            }

            $previousStatus = $locked->status;
            $status = match ($outcome) {
                'selected' => 'awarded',
                'waitlisted' => 'waitlisted',
                'not_selected' => 'not_awarded',
            };
            $reason = $decisionReason ?: match ($outcome) {
                'selected' => 'approved_for_award',
                'waitlisted' => 'funds_limited',
                'not_selected' => 'not_selected',
            };
            $now = now();
            $waitlistPosition = $outcome === 'waitlisted'
                ? ($locked->waitlist_position ?: ((int) ScholarshipApplication::query()
                    ->where('scholarship_id', $locked->scholarship_id)
                    ->where('status', 'waitlisted')
                    ->max('waitlist_position') + 1))
                : null;
            $decision = $locked->stageProgresses->firstWhere('stage_key', 'decision');
            $decision?->update([
                'status' => $outcome === 'waitlisted'
                    ? 'current'
                    : ($outcome === 'selected' ? 'passed' : 'not_passed'),
                'result' => $outcome,
                'notes' => $notes,
                'completed_at' => $outcome === 'waitlisted' ? null : $now,
                'decided_by' => $actor->id,
                'decided_at' => $now,
            ]);
            $locked->update([
                'status' => $status,
                'application_state' => $outcome === 'waitlisted' ? 'awaiting_decision' : 'closed',
                'workflow_stage' => $outcome === 'waitlisted' ? 'decision' : 'complete',
                'final_outcome' => $outcome,
                'decision_reason' => $reason,
                'outcome_notes' => $notes ?: $locked->outcome_notes,
                'outcome_at' => $outcome === 'waitlisted' ? null : $now,
                'waitlisted_at' => $outcome === 'waitlisted' ? $now : null,
                'waitlist_position' => $waitlistPosition,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $now,
            ]);

            ApplicationStatusHistory::create([
                'scholarship_application_id' => $locked->id,
                'changed_by' => $actor->id,
                'from_status' => $previousStatus,
                'to_status' => $status,
                'decision_reason' => $reason,
                'review_notes' => $notes ?: 'Final provider outcome recorded.',
                'changed_at' => $now,
            ]);

            return $locked->fresh()->load(['scholarship', 'stageProgresses']);
        });
    }

    public function withdraw(ScholarshipApplication $application, User $actor, ?string $reason): ScholarshipApplication
    {
        $application = $this->initialize($application);

        return DB::transaction(function () use ($application, $actor, $reason): ScholarshipApplication {
            $locked = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            $previousStatus = $locked->status;
            $now = now();
            $locked->stageProgresses()
                ->whereIn('status', ['pending', 'current'])
                ->update(['status' => 'skipped', 'updated_at' => $now]);
            $locked->update([
                'status' => 'withdrawn',
                'application_state' => 'withdrawn',
                'workflow_stage' => 'complete',
                'withdrawal_reason' => $reason,
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => $now,
            ]);
            ApplicationStatusHistory::create([
                'scholarship_application_id' => $locked->id,
                'changed_by' => $actor->id,
                'from_status' => $previousStatus,
                'to_status' => 'withdrawn',
                'decision_reason' => 'applicant_withdrawal',
                'review_notes' => $reason ?: 'Application withdrawn by applicant.',
                'changed_at' => $now,
            ]);

            return $locked->fresh()->load(['scholarship', 'stageProgresses']);
        });
    }

    public function payload(ScholarshipApplication $application): array
    {
        $application->loadMissing(['scholarship', 'stageProgresses']);
        $stages = $this->stagesForApplication($application);

        if (! $this->hasInitializedWorkflow($application, $stages)) {
            $application = $this->initialize($application);
            $stages = $this->stagesForApplication($application);
        }

        $progress = $application->stageProgresses->keyBy('stage_key');
        $steps = collect($stages)->map(function (string $stage, int $position) use ($progress): array {
            $record = $progress->get($stage);

            return [
                'key' => $stage,
                'position' => $position,
                'label' => $this->stageLabel($stage),
                'description' => $this->stageDescription($stage),
                'status' => $record?->status ?? 'pending',
                'result' => $record?->result,
                'notes' => $record?->notes,
                'started_at' => $record?->started_at?->format('M d, Y h:i A'),
                'completed_at' => $record?->completed_at?->format('M d, Y h:i A'),
            ];
        })->values();
        $completed = $steps->whereIn('status', ['passed', 'not_passed'])->count();

        return [
            'version' => 2,
            'application_state' => $application->application_state,
            'application_state_label' => $this->stateLabel($application->application_state),
            'current_stage' => $application->workflow_stage,
            'current_stage_label' => $this->stageLabel($application->workflow_stage),
            'final_outcome' => $application->final_outcome,
            'final_outcome_label' => $this->outcomeLabel($application->final_outcome),
            'is_closed' => in_array($application->application_state, ['closed', 'withdrawn'], true),
            'next_action' => $this->nextAction($application),
            'provider_action' => $this->providerAction($application),
            'completed_steps' => $completed,
            'total_steps' => $steps->count(),
            'percent' => (int) round(($completed / max($steps->count(), 1)) * 100),
            'steps' => $steps->all(),
        ];
    }

    private function hasInitializedWorkflow(ScholarshipApplication $application, array $stages): bool
    {
        if ((int) $application->workflow_version !== 2
            || blank($application->workflow_stage)
            || blank($application->application_state)) {
            return false;
        }

        $progressStages = $application->stageProgresses
            ->pluck('stage_key')
            ->all();

        return collect($stages)->every(
            fn (string $stage): bool => in_array($stage, $progressStages, true),
        );
    }

    private function createInitialProgress(
        ScholarshipApplication $application,
        array $stages,
        string $currentStage,
        ?string $finalOutcome,
    ): void {
        $currentIndex = array_search($currentStage, $stages, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $failedStage = match ($application->status) {
            'rejected' => 'screening',
            'exam_failed' => 'exam',
            'interview_failed' => 'interview',
            'not_awarded' => 'decision',
            default => null,
        };

        foreach ($stages as $position => $stage) {
            $status = 'pending';
            $result = null;

            if ($application->status === 'withdrawn') {
                $status = 'skipped';
            } elseif ($failedStage !== null) {
                $failedIndex = array_search($failedStage, $stages, true);
                $failedIndex = $failedIndex === false ? $currentIndex : $failedIndex;
                $status = $position < $failedIndex
                    ? 'passed'
                    : ($position === $failedIndex ? 'not_passed' : 'skipped');
                $result = $position < $failedIndex ? 'passed' : ($position === $failedIndex ? 'not_passed' : null);
            } elseif ($finalOutcome === 'selected') {
                $status = 'passed';
                $result = $stage === 'decision' ? 'selected' : 'passed';
            } elseif ($finalOutcome === 'waitlisted') {
                $status = $stage === 'decision' ? 'current' : 'passed';
                $result = $stage === 'decision' ? 'waitlisted' : 'passed';
            } elseif ($position < $currentIndex) {
                $status = 'passed';
                $result = 'passed';
            } elseif ($position === $currentIndex) {
                $status = 'current';
            }

            ApplicationStageProgress::query()->firstOrCreate([
                'scholarship_application_id' => $application->id,
                'stage_key' => $stage,
            ], [
                'position' => $position,
                'status' => $status,
                'result' => $result,
                'started_at' => in_array($status, ['current', 'passed', 'not_passed'], true)
                    ? ($application->submitted_at ?: now())
                    : null,
                'completed_at' => in_array($status, ['passed', 'not_passed'], true)
                    ? ($application->updated_at ?: now())
                    : null,
            ]);
        }
    }

    private function stagesForApplication(ScholarshipApplication $application): array
    {
        $snapshotStages = data_get($application->submission_snapshot, 'current.program.selection_stages');

        if (is_array($snapshotStages) && $snapshotStages !== []) {
            return ScholarshipSelectionPlan::normalize($snapshotStages);
        }

        return ScholarshipSelectionPlan::normalize($application->scholarship?->selection_stages);
    }

    private function inferStage(?string $status, array $stages): string
    {
        $stage = match (true) {
            in_array($status, ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed'], true) => 'exam',
            $status === 'interview' => 'interview',
            $status === 'approved' => 'formal_application',
            in_array($status, ['waitlisted'], true) => 'decision',
            in_array($status, ['rejected', 'exam_failed', 'interview_failed', 'not_awarded', 'awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'withdrawn'], true) => 'complete',
            default => 'screening',
        };

        return $stage === 'complete' || in_array($stage, $stages, true)
            ? $stage
            : 'screening';
    }

    private function inferState(?string $status): string
    {
        return match (true) {
            $status === 'withdrawn' => 'withdrawn',
            in_array($status, ['rejected', 'exam_failed', 'interview_failed', 'not_awarded', 'awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true) => 'closed',
            $status === 'waitlisted' => 'awaiting_decision',
            $status === 'submitted' => 'submitted',
            in_array($status, ['under_review', 'qualified', 'shortlisted'], true) => 'under_review',
            default => 'in_provider_process',
        };
    }

    private function inferFinalOutcome(?string $status): ?string
    {
        return match (true) {
            in_array($status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true) => 'selected',
            $status === 'waitlisted' => 'waitlisted',
            in_array($status, ['not_awarded', 'exam_failed', 'interview_failed'], true) => 'not_selected',
            default => null,
        };
    }

    private function legacyStatusForStage(string $stage): string
    {
        return match ($stage) {
            'screening' => 'under_review',
            'exam' => 'exam_qualified',
            'interview' => 'interview',
            'formal_application', 'decision' => 'approved',
            default => 'approved',
        };
    }

    private function failureStatusForStage(string $stage): string
    {
        return match ($stage) {
            'screening' => 'rejected',
            'exam' => 'exam_failed',
            'interview' => 'interview_failed',
            default => 'not_awarded',
        };
    }

    private function defaultDecisionReason(string $stage, string $result): string
    {
        return match ([$stage, $result]) {
            ['screening', 'passed'] => 'passed_prescreening',
            ['screening', 'not_passed'] => 'outside_eligibility',
            ['formal_application', 'passed'] => 'formal_application_completed',
            ['formal_application', 'not_passed'] => 'formal_application_not_completed',
            ['exam', 'passed'] => 'passed_exam',
            ['exam', 'not_passed'] => 'failed_exam',
            ['interview', 'passed'] => 'passed_interview',
            ['interview', 'not_passed'] => 'failed_interview',
            default => 'other',
        };
    }

    private function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'screening' => 'Pre-screening review',
            'formal_application' => 'Formal application',
            'exam' => 'Exam',
            'interview' => 'Interview',
            'decision' => 'Final decision',
            'complete' => 'Completed',
            default => 'Application received',
        };
    }

    private function stageDescription(string $stage): string
    {
        return match ($stage) {
            'screening' => 'The provider checks eligibility, profile details, and submitted files.',
            'formal_application' => 'Continue with the provider using its official instructions and original documents.',
            'exam' => 'Complete the provider-managed exam outside the portal.',
            'interview' => 'Attend the provider-managed interview outside the portal.',
            'decision' => 'The provider records whether the applicant is selected, waitlisted, or not selected.',
            default => 'Provider-managed application stage.',
        };
    }

    private function stateLabel(?string $state): string
    {
        return match ($state) {
            'submitted' => 'Submitted',
            'under_review' => 'Under review',
            'needs_correction' => 'Correction needed',
            'in_provider_process' => 'Continuing with provider',
            'awaiting_decision' => 'Awaiting final decision',
            'closed' => 'Completed',
            'withdrawn' => 'Withdrawn',
            default => 'Submitted',
        };
    }

    private function outcomeLabel(?string $outcome): ?string
    {
        return match ($outcome) {
            'selected' => 'Selected',
            'waitlisted' => 'Waitlisted',
            'not_selected' => 'Not selected',
            default => null,
        };
    }

    private function nextAction(ScholarshipApplication $application): array
    {
        if ($application->correction_status === 'requested') {
            return [
                'key' => 'correction',
                'label' => 'Update the requested information',
                'actor' => 'applicant',
                'actor_label' => 'Applicant',
                'description' => 'The provider requested a correction. Open the application, review the note, and submit the updated information.',
                'url' => route('dashboard.applications.show', $application, false),
            ];
        }

        if ($application->correction_status === 'submitted') {
            return [
                'key' => 'correction_review',
                'label' => 'Wait for the provider to review your correction',
                'actor' => 'provider',
                'actor_label' => 'Scholarship provider',
                'description' => 'Your correction was submitted successfully. No further applicant action is needed unless the provider sends another request.',
                'url' => route('dashboard.applications.show', $application, false),
            ];
        }

        return match ($application->application_state) {
            'submitted', 'under_review' => [
                'key' => 'wait',
                'label' => 'Wait for the provider review',
                'actor' => 'provider',
                'actor_label' => 'Scholarship provider',
                'description' => 'The provider is checking eligibility, profile details, and submitted files. Keep your contact details current.',
                'url' => route('dashboard.applications.show', $application, false),
            ],
            'awaiting_decision' => [
                'key' => 'decision',
                'label' => 'Wait for the final provider decision',
                'actor' => 'provider',
                'actor_label' => 'Scholarship provider',
                'description' => 'All configured stages are complete. The provider must now record the final selection outcome.',
                'url' => route('dashboard.applications.show', $application, false),
            ],
            'withdrawn' => [
                'key' => 'withdrawn',
                'label' => 'Application withdrawn',
                'actor' => 'none',
                'actor_label' => 'No action required',
                'description' => 'This application is closed because it was withdrawn.',
                'url' => route('dashboard.applications.show', $application, false),
            ],
            'closed' => [
                'key' => 'result',
                'label' => 'Review the application result',
                'actor' => 'applicant',
                'actor_label' => 'Applicant',
                'description' => 'The provider process is complete. Open the application to review the recorded result and any final instructions.',
                'url' => route('dashboard.applications.show', $application, false),
            ],
            default => match ($application->workflow_stage) {
                'formal_application' => [
                    'key' => 'formal_application',
                    'label' => 'Follow the provider application instructions',
                    'actor' => 'applicant',
                    'actor_label' => 'Applicant',
                    'description' => 'Continue through the provider\'s formal process and keep original documents ready when requested.',
                    'url' => route('dashboard.applications.show', $application, false),
                ],
                'exam' => [
                    'key' => 'exam',
                    'label' => 'Review the exam details',
                    'actor' => 'applicant',
                    'actor_label' => 'Applicant',
                    'description' => 'Check the provider-managed exam schedule and follow the listed instructions.',
                    'url' => route('dashboard.applications.show', $application, false),
                ],
                'interview' => [
                    'key' => 'interview',
                    'label' => 'Review the interview details',
                    'actor' => 'applicant',
                    'actor_label' => 'Applicant',
                    'description' => 'Check the provider-managed interview schedule and follow the listed instructions.',
                    'url' => route('dashboard.applications.show', $application, false),
                ],
                default => [
                    'key' => 'application',
                    'label' => 'Review application',
                    'actor' => 'applicant',
                    'actor_label' => 'Applicant',
                    'description' => 'Open the application to review its latest status and instructions.',
                    'url' => route('dashboard.applications.show', $application, false),
                ],
            },
        };
    }

    private function providerAction(ScholarshipApplication $application): array
    {
        if ($application->correction_status === 'requested') {
            return [
                'key' => 'await_correction',
                'label' => 'Wait for the applicant correction',
                'actor' => 'applicant',
                'actor_label' => 'Applicant',
                'description' => 'The applicant must respond before another provider decision can be recorded.',
            ];
        }

        if ($application->correction_status === 'submitted') {
            return [
                'key' => 'review_correction',
                'label' => 'Review the applicant correction',
                'actor' => 'provider',
                'actor_label' => 'Provider reviewer',
                'description' => 'The applicant submitted the requested correction and it is ready for review.',
            ];
        }

        return match ($application->workflow_stage) {
            'screening' => ['key' => 'screening', 'label' => 'Review eligibility and required files', 'actor' => 'provider', 'actor_label' => 'Provider reviewer', 'description' => 'Check the applicant profile, DSS guidance, and supporting files before recording the pre-screening result.'],
            'formal_application' => ['key' => 'formal_application', 'label' => 'Record the formal application result', 'actor' => 'provider', 'actor_label' => 'Provider reviewer', 'description' => 'Confirm whether the applicant completed the provider-managed formal application requirements.'],
            'exam' => ['key' => 'exam', 'label' => 'Record the exam result', 'actor' => 'provider', 'actor_label' => 'Provider reviewer', 'description' => 'After the shared exam activity is complete, record whether this applicant passed.'],
            'interview' => ['key' => 'interview', 'label' => 'Record the interview result', 'actor' => 'provider', 'actor_label' => 'Provider reviewer', 'description' => 'After the shared interview activity is complete, record whether this applicant passed.'],
            'decision' => ['key' => 'decision', 'label' => 'Record the final outcome', 'actor' => 'provider', 'actor_label' => 'Provider reviewer', 'description' => 'Choose selected, waitlisted, or not selected after all configured stages are complete.'],
            default => ['key' => 'complete', 'label' => 'No action required', 'actor' => 'none', 'actor_label' => 'No action required', 'description' => 'This application workflow is complete.'],
        };
    }
}
