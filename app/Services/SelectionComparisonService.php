<?php

namespace App\Services;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Support\SelectionScoringPolicy;

final class SelectionComparisonService
{
    private const SLOT_STATUSES = ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'];

    public function __construct(private readonly SelectionEvaluationService $evaluations) {}

    public function forProgram(Scholarship $program): array
    {
        $active = $program->applications()->with(['scholarship', 'stageProgresses', 'stageEvaluations.recorder.providerProfile'])
            ->whereNull('final_outcome')
            ->where(fn ($query) => $query->whereNull('application_state')->orWhereNotIn('application_state', ['closed', 'withdrawn']))
            ->whereNotIn('status', [...self::SLOT_STATUSES, 'waitlisted', 'rejected', 'withdrawn', 'exam_failed', 'interview_failed', 'not_awarded', 'benefits_terminated'])
            ->orderBy('id')->get();
        $waitlist = $program->applications()->where(fn ($query) => $query->where('final_outcome', 'waitlisted')->orWhere('status', 'waitlisted'))
            ->orderBy('waitlist_position')->orderBy('id')->get(['id', 'waitlist_position']);
        $occupied = $program->applications()->whereIn('status', self::SLOT_STATUSES)->count();
        $remaining = $program->slots_available === null ? null : max(0, $program->slots_available - $occupied);
        $summaries = $active->mapWithKeys(fn (ScholarshipApplication $application): array => [$application->id => $this->evaluations->summary($application)]);
        $fingerprints = $active->map(fn (ScholarshipApplication $application) => $application->selection_scoring_snapshot
            ? SelectionScoringPolicy::fingerprint($application->selection_scoring_snapshot) : null)->filter()->unique();
        $policy = $program->selection_scoring_policy;
        $scoringEnabled = ($policy['weights'] ?? []) !== [] || $active->contains(fn (ScholarshipApplication $application): bool => ($application->selection_scoring_snapshot['weights'] ?? []) !== []);
        $policyId = $policy ? SelectionScoringPolicy::fingerprint($policy) : $fingerprints->first();
        $compatible = $fingerprints->count() <= 1 && ($fingerprints->isEmpty() || $fingerprints->first() === $policyId);
        $pending = $active->where('workflow_stage', 'decision');
        $ready = $pending->filter(fn (ScholarshipApplication $application): bool => $compatible
            && $summaries[$application->id]['ready_for_comparison']);
        $ready = $ready->sort(function (ScholarshipApplication $left, ScholarshipApplication $right) use ($summaries): int {
            // IDs stabilize display only; equal scores always retain the same rank.
            return ($summaries[$right->id]['score'] <=> $summaries[$left->id]['score']) ?: ($left->id <=> $right->id);
        })->values();
        $intakeClosed = $program->status === 'closed' || ($program->deadline && $program->deadline->copy()->endOfDay()->isPast());
        $reasons = [];
        if (! $intakeClosed) {
            $reasons[] = 'Application intake is not confirmed closed.';
        }
        if (! in_array($program->status, ['published', 'closed'], true)) {
            $reasons[] = 'Program publication needs review before selection suggestions are used.';
        }
        if ($active->count() > $pending->count()) {
            $reasons[] = ($active->count() - $pending->count()).' applicants are still completing selection.';
        }
        if ($pending->count() > $ready->count()) {
            $reasons[] = ($pending->count() - $ready->count()).' pending records need evaluation or policy review.';
        }
        if (! $compatible) {
            $reasons[] = 'Different scoring plans cannot be ranked together.';
        }
        if ($waitlist->isNotEmpty()) {
            $reasons[] = 'An official waitlist already exists; review it before offering remaining slots.';
        }
        if (! $scoringEnabled) {
            $reasons = ['This intake uses manual qualifying checks rather than numeric ranking.'];
        }
        $canSuggest = $reasons === [] && $ready->isNotEmpty();
        $reference = hash('sha256', json_encode([
            'version' => 1, 'program' => $program->id, 'policy' => $policyId, 'program_status' => $program->status,
            'deadline' => $program->deadline?->toDateString(), 'intake_closed' => (bool) $intakeClosed,
            'slots' => [$program->slots_available, $occupied], 'waitlist' => $waitlist->toArray(),
            'active' => $active->map(fn (ScholarshipApplication $application): array => [
                'id' => $application->id, 'stage' => $application->workflow_stage, 'status' => $application->status,
                'correction' => $application->correction_status,
                'policy' => $application->selection_scoring_snapshot ? SelectionScoringPolicy::fingerprint($application->selection_scoring_snapshot) : null,
                'score' => $summaries[$application->id]['score'], 'state' => $summaries[$application->id]['state'],
                'stages' => collect($summaries[$application->id]['stages'])->map(fn (array $row): array => [$row['key'], $row['revision'], $row['stage_revision'], $row['state'], $row['score']])->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR));
        $rows = [];
        foreach ($pending as $application) {
            $evaluation = $summaries[$application->id];
            $rows[$application->id] = [
                'score' => $evaluation['score'], 'rank' => null, 'tied' => false, 'tie_at_cutoff' => false,
                'recommended_outcome' => null, 'label' => $compatible ? $evaluation['label'] : 'Scoring plan review', 'state' => $compatible ? $evaluation['state'] : 'policy_review',
                'evaluation' => $evaluation, 'ranked_count' => $ready->count(), 'reference' => $reference,
            ];
        }
        $groups = $ready->groupBy(fn (ScholarshipApplication $application): string => number_format($summaries[$application->id]['score'], 2, '.', ''));
        $ahead = 0;
        foreach ($groups as $group) {
            $cutoffTie = $remaining !== null && $group->count() > 1 && $ahead < $remaining && $ahead + $group->count() > $remaining;
            foreach ($group as $application) {
                $suggestion = $canSuggest && ! $cutoffTie
                    ? ($remaining === null || $ahead + $group->count() <= $remaining ? 'selected' : 'waitlisted') : null;
                $rows[$application->id] = [
                    ...$rows[$application->id], 'rank' => $ahead + 1, 'tied' => $group->count() > 1,
                    'tie_at_cutoff' => $cutoffTie, 'recommended_outcome' => $suggestion,
                    'state' => $cutoffTie ? 'tie_review' : ($suggestion ?? 'provisional'),
                    'label' => $cutoffTie ? 'Resolve cutoff tie' : match ($suggestion) {
                        'selected' => 'Consider selecting', 'waitlisted' => 'Consider waitlisting', default => 'Provisional order',
                    },
                ];
            }
            $ahead += $group->count();
        }
        $unranked = $pending->whereNotIn('id', $ready->pluck('id')->all())->sortBy([['submitted_at', 'asc'], ['id', 'asc']]);
        $ordered = $ready->pluck('id')->merge($unranked->pluck('id'))->values()->all();

        return [
            'program_id' => $program->id, 'reference' => $reference, 'rows' => $rows, 'ordered_ids' => $ordered,
            'scoring_enabled' => $scoringEnabled,
            'capacity' => ['total' => $program->slots_available, 'occupied' => $occupied, 'remaining' => $remaining],
            'ranked' => $ready->count(), 'pending' => $pending->count(), 'awaiting_selection' => $active->count() - $pending->count(),
            'waitlist_count' => $waitlist->count(), 'first_waitlist_id' => $waitlist->first()?->id,
            'provisional' => ! $canSuggest, 'reasons' => $reasons,
            'label' => ! $scoringEnabled ? 'Manual decision review' : ($canSuggest ? 'Ready to compare' : 'Provisional comparison'),
            'notice' => 'Equal scores share a rank. Suggested review order is not an official waitlist position. The officer confirms every outcome.',
        ];
    }

    public function forApplication(ScholarshipApplication $application, ?array $context = null): array
    {
        $application->loadMissing('scholarship');
        if (in_array($application->final_outcome, ['selected', 'not_selected'], true)) {
            return [...($application->selection_decision_snapshot['comparison'] ?? []), 'historical' => true, 'reference' => null];
        }
        $context ??= $this->forProgram($application->scholarship);
        $row = $context['rows'][$application->id] ?? [
            'rank' => null, 'ranked_count' => $context['ranked'], 'tied' => false, 'tie_at_cutoff' => false,
            'recommended_outcome' => null, 'state' => 'manual', 'label' => 'Manual review',
        ];
        if ($application->final_outcome === 'waitlisted') {
            $row['state'] = 'official_waitlist';
            $row['label'] = 'Official waitlist #'.($application->waitlist_position ?: '-');
        }
        unset($row['evaluation']);

        return [...$row,
            'reference' => hash('sha256', $context['reference'].':'.$application->id.':'.$application->final_outcome.':'.$application->waitlist_position),
            'capacity' => $context['capacity'], 'provisional' => $context['provisional'], 'reasons' => $context['reasons'],
            'waitlist_priority' => $context['first_waitlist_id'] !== null && $context['first_waitlist_id'] !== $application->id,
            'scoring_enabled' => ($application->selection_scoring_snapshot['weights'] ?? []) !== [],
            'historical' => false,
            'comparison_url' => route('provider.workspaces.decisions.pending', ['program_id' => $application->scholarship_id], false),
        ];
    }

    public function requiresRationale(array $comparison, string $outcome): bool
    {
        return ($comparison['scoring_enabled'] ?? false) && (
            ($comparison['tie_at_cutoff'] ?? false)
            || (isset($comparison['recommended_outcome']) && $comparison['recommended_outcome'] !== $outcome)
            || ($outcome === 'selected' && ($comparison['waitlist_priority'] ?? false))
        );
    }

    public function publicContext(array $context): array
    {
        return array_diff_key($context, array_flip(['rows', 'ordered_ids', 'first_waitlist_id', 'reference']));
    }
}
