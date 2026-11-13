<?php

namespace App\Services;

use App\Models\ApplicationStageEvaluation;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ScholarshipSelectionPlan;
use App\Support\SelectionScoringPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SelectionEvaluationService
{
    public function freezeForSubmission(ScholarshipApplication $application): ScholarshipApplication
    {
        return DB::transaction(function () use ($application): ScholarshipApplication {
            $program = Scholarship::query()->lockForUpdate()->findOrFail($application->scholarship_id);
            $locked = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->selection_scoring_snapshot !== null) {
                return $locked;
            }

            $policy = $program->selection_scoring_policy;
            if ($policy === null) {
                $policy = SelectionScoringPolicy::preset($program->selection_stages, $program->review_rubric ?? []);
                $policy['frozen_at'] = now()->toISOString();
                $program->forceFill(['selection_scoring_policy' => $policy])->save();
            }

            if ($policy['stages'] !== ScholarshipSelectionPlan::normalize($program->selection_stages)) {
                throw ValidationException::withMessages([
                    'selection_stages' => 'This intake already has a fixed scoring plan. Use a new program cycle for a different selection path.',
                ]);
            }

            $locked->forceFill([
                'selection_scoring_snapshot' => $policy,
                'review_rubric_snapshot' => $policy['review_rubric'],
            ])->save();

            return $locked;
        });
    }

    public function captureScreening(ScholarshipApplication $application, User $actor): void
    {
        $policy = $application->selection_scoring_snapshot;
        if (! $policy || ! isset($policy['weights']['screening'])) {
            return;
        }

        $criteria = $policy['screening_criteria'];
        $scores = $application->rubric_scores ?? [];
        $snapshot = collect($application->review_rubric_snapshot ?? [])->keyBy('key');
        $weighted = 0.0;
        $totalWeight = 0;
        $breakdown = [];
        foreach ($criteria as $criterion) {
            $score = $scores[$criterion['key']] ?? null;
            if (! is_numeric($score) || ! is_finite((float) $score) || $score < 0 || $score > 100
                || $snapshot->get($criterion['key']) !== $criterion) {
                return;
            }
            $weighted += (float) $score * $criterion['weight'];
            $totalWeight += $criterion['weight'];
            $breakdown[] = [...$criterion, 'score' => (float) $score];
        }
        if ($totalWeight === 0 || ! $application->rubric_scored_by || ! $application->rubric_scored_at) {
            return;
        }

        $this->append($application, $actor, 'screening', round($weighted / $totalWeight, 4), 100,
            (int) $application->stageEvaluations()->where('stage_key', 'screening')->max('revision'),
            (int) $application->stageProgresses()->where('stage_key', 'screening')->value('evaluation_revision'),
            null, $breakdown);
    }

    public function record(
        ScholarshipApplication $application,
        User $actor,
        string $stage,
        mixed $score,
        mixed $maximumScore,
        int $expectedStageRevision,
        int $expectedRevision = 0,
        ?string $note = null,
    ): ApplicationStageEvaluation {
        if (! in_array($stage, ['exam', 'interview'], true)) {
            throw ValidationException::withMessages(['stage' => 'Record an exam or interview evaluation. Screening reuses the reviewed rubric.']);
        }

        return $this->append($application, $actor, $stage, $score, $maximumScore, $expectedRevision, $expectedStageRevision, $note);
    }

    private function append(
        ScholarshipApplication $application,
        User $actor,
        string $stage,
        mixed $score,
        mixed $maximumScore,
        int $expectedRevision,
        int $expectedStageRevision,
        ?string $note,
        array $breakdown = [],
    ): ApplicationStageEvaluation {
        return DB::transaction(function () use ($application, $actor, $stage, $score, $maximumScore, $expectedRevision, $expectedStageRevision, $note, $breakdown): ApplicationStageEvaluation {
            Scholarship::query()->whereKey($application->scholarship_id)->lockForUpdate()->firstOrFail();
            $locked = ScholarshipApplication::query()->with(['scholarship', 'stageProgresses'])->lockForUpdate()->findOrFail($application->id);
            $owner = $actor->providerOrganizationOwner();
            abort_unless($actor->isProvider() && $actor->isActive() && $owner->isActive()
                && $owner->providerProfile?->verification_status === 'approved'
                && $actor->canAccessProviderProgram($locked->scholarship)
                && $actor->hasPortalPermission($stage === 'screening' ? 'verify_applications' : 'manage_selection_activities'), 403);

            if (! is_numeric($score) || ! is_numeric($maximumScore)
                || ! is_finite((float) $score) || ! is_finite((float) $maximumScore)) {
                throw ValidationException::withMessages(['score' => 'Enter a valid score and maximum score.']);
            }
            $values = Validator::make(['score' => $score, 'maximum_score' => $maximumScore, 'note' => $note], [
                'score' => ['bail', 'required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
                'maximum_score' => ['bail', 'required', 'numeric', 'min:0.0001', 'max:1000000', 'decimal:0,4'],
                'note' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            if ((float) $score > (float) $maximumScore) {
                throw ValidationException::withMessages(['score' => 'The score cannot be higher than the maximum score.']);
            }

            $policy = $locked->selection_scoring_snapshot;
            $progress = $locked->stageProgresses->firstWhere('stage_key', $stage);
            if (! isset($policy['weights'][$stage]) || $progress?->status !== 'passed' || $progress?->result !== 'passed'
                || ! $progress->completed_at || filled($locked->final_outcome)
                || in_array($locked->application_state, ['closed', 'withdrawn'], true)
                || in_array($locked->correction_status, ['requested', 'submitted'], true)) {
                throw ValidationException::withMessages(['evaluation' => 'A fixed scoring plan and a completed, passed stage are required before recording this evaluation.']);
            }
            $previous = $locked->stageEvaluations()->where('stage_key', $stage)->orderByDesc('revision')->first();
            $latest = $previous?->revision ?? 0;
            if ($expectedRevision !== $latest || $expectedStageRevision !== $progress->evaluation_revision) {
                throw ValidationException::withMessages(['evaluation' => 'The evaluation changed. Reload the record before saving.']);
            }
            if ($stage !== 'screening' && $previous?->stage_revision === $progress->evaluation_revision
                && mb_strlen(trim($note ?? '')) < 10) {
                throw ValidationException::withMessages(['note' => 'Explain the score correction in at least 10 characters. The original evaluation stays in history.']);
            }

            // Append revisions instead of overwriting the evidence used for an earlier score.
            return $locked->stageEvaluations()->create([
                'stage_key' => $stage,
                'revision' => $latest + 1,
                'stage_revision' => $progress->evaluation_revision,
                'policy_fingerprint' => SelectionScoringPolicy::fingerprint($policy),
                'score' => $values['score'],
                'maximum_score' => $values['maximum_score'],
                'percentage' => round(((float) $score / (float) $maximumScore) * 100, 4),
                'source' => $stage === 'screening' ? 'reviewed_screening_rubric' : 'stage_evaluation',
                'breakdown' => $breakdown,
                'note' => filled($note) ? trim($note) : null,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        });
    }

    public function summary(ScholarshipApplication $application): array
    {
        $recorded = $application->selection_decision_snapshot['evaluation'] ?? null;
        if ($application->final_outcome && is_array($recorded)) {
            $recorded['historical'] = true;
            $recorded['ready_for_comparison'] = false;
            $recorded['description'] = 'Evaluation saved with this decision. Later changes do not rewrite the recorded basis.';
            $recorded['stages'] = array_map(fn (array $stage): array => [...$stage, 'can_record' => false], $recorded['stages']);

            return $recorded;
        }
        $application->loadMissing(['scholarship', 'stageProgresses', 'stageEvaluations.recorder']);
        $frozen = $application->selection_scoring_snapshot !== null;
        $policy = $application->selection_scoring_snapshot ?? SelectionScoringPolicy::preset(
            data_get($application->submission_snapshot, 'current.program.selection_stages') ?? $application->scholarship->selection_stages,
            $application->review_rubric_snapshot ?? [],
        );
        $evaluations = $application->stageEvaluations->sortByDesc('revision')->unique('stage_key')->keyBy('stage_key');
        $progresses = $application->stageProgresses->keyBy('stage_key');
        $rows = [];
        $complete = 0;
        $allPassed = true;
        $total = 0.0;
        foreach (array_diff($policy['stages'], ['decision']) as $stage) {
            $progress = $progresses->get($stage);
            $evaluation = $evaluations->get($stage);
            $passed = $progress?->status === 'passed' && $progress?->result === 'passed' && $progress?->completed_at;
            $allPassed = $allPassed && $passed;
            $weight = $policy['weights'][$stage] ?? null;
            $current = $frozen && $passed && $evaluation
                && $evaluation->stage_revision === $progress->evaluation_revision
                && hash_equals(SelectionScoringPolicy::fingerprint($policy), $evaluation->policy_fingerprint);
            if ($current && $stage === 'screening') {
                $current = collect($evaluation->breakdown)->every(function (array $criterion) use ($application): bool {
                    $score = $application->rubric_scores[$criterion['key']] ?? null;

                    return is_numeric($score) && (float) $score === (float) $criterion['score'];
                });
            }
            $percentage = $weight !== null && $current ? (float) $evaluation->percentage : null;
            if ($percentage !== null) {
                $complete++;
                $total += $percentage * $weight / 100;
            }
            $state = $weight === null ? ($passed ? 'passed' : 'pending')
                : ($current ? 'evaluated' : ($evaluation ? 'needs_review' : 'not_evaluated'));
            $rows[] = [
                'key' => $stage,
                'label' => match ($stage) {
                    'screening' => 'Pre-screening review',
                    'formal_application' => 'Formal application',
                    'exam' => 'Examination',
                    'interview' => 'Interview',
                },
                'weight' => $weight,
                'state' => $state,
                'status_label' => match ($state) {
                    'evaluated' => 'Evaluated', 'passed' => 'Passed', 'pending' => 'Pending check',
                    'needs_review' => 'Re-evaluation needed', default => 'Not evaluated',
                },
                'score' => $percentage === null ? null : round($percentage, 2),
                'contribution' => $percentage === null ? null : round($percentage * $weight / 100, 2),
                'recorded_by' => $current ? ($evaluation->recorder?->name ?? 'Former team member') : null,
                'recorded_at' => $current ? $evaluation->recorded_at->format('M d, Y h:i A') : null,
                'revision' => $evaluation?->revision ?? 0,
                'stage_revision' => $progress?->evaluation_revision ?? 0,
                'raw_score' => $current ? (float) $evaluation->score : null,
                'maximum_score' => $current ? (float) $evaluation->maximum_score : null,
                'note' => $current ? $evaluation->note : null,
                'history' => $application->stageEvaluations->where('stage_key', $stage)->sortByDesc('revision')->take(10)
                    ->map(fn (ApplicationStageEvaluation $entry): array => [
                        'revision' => $entry->revision,
                        'score' => (float) $entry->score,
                        'maximum_score' => (float) $entry->maximum_score,
                        'percentage' => round((float) $entry->percentage, 2),
                        'note' => $entry->note,
                        'recorded_by' => $entry->recorder?->name ?? 'Former team member',
                        'recorded_at' => $entry->recorded_at->format('M d, Y h:i A'),
                    ])->values()->all(),
                'can_record' => $frozen && $weight !== null && $passed && in_array($stage, ['exam', 'interview'], true)
                    && ! filled($application->final_outcome)
                    && ! in_array($application->application_state, ['closed', 'withdrawn'], true)
                    && ! in_array($application->correction_status, ['requested', 'submitted'], true),
                'criteria' => $weight !== null && $stage === 'screening' ? $policy['screening_criteria'] : [],
            ];
        }
        $count = count($policy['weights']);
        $ready = $frozen && $count > 0 && $complete === $count && $allPassed
            && ! in_array($application->application_state, ['closed', 'withdrawn'], true)
            && ! in_array($application->correction_status, ['requested', 'submitted'], true);
        $state = ! $frozen ? 'legacy' : ($count === 0 ? 'qualifying_only' : ($ready ? 'ready' : 'incomplete'));

        return [
            'version' => $policy['version'],
            'policy_frozen' => $frozen,
            'state' => $state,
            'label' => match ($state) {
                'legacy' => 'Existing intake', 'qualifying_only' => 'Qualifying checks only',
                'ready' => 'Evaluations complete', default => 'Evaluation incomplete',
            },
            'score' => $ready ? round($total, 2) : null,
            'completed' => $complete,
            'required' => $count,
            'ready_for_comparison' => $ready,
            'stages' => $rows,
            'description' => match ($state) {
                'legacy' => 'This application predates the fixed scoring plan. No score or waitlist order has been changed.',
                'qualifying_only' => 'This selection path uses qualifying checks. There is no numeric score to rank.',
                'ready' => 'Based on the completed stage evaluations. The final outcome is still your decision.',
                default => 'A final score appears only after every scored stage and qualifying check is complete.',
            },
            'notice' => $policy['notice'],
        ];
    }
}
