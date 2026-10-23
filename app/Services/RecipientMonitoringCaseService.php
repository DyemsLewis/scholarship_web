<?php

namespace App\Services;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringIntervention;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipientMonitoringCaseService
{
    /**
     * @param  iterable<int, ScholarshipApplication>  $applications
     * @return Collection<int, RecipientMonitoringCase>
     */
    public function seedForCycle(RecipientMonitoringCycle $cycle, iterable $applications): Collection
    {
        return collect($applications)
            ->map(function (ScholarshipApplication $application) use ($cycle): RecipientMonitoringCase {
                return RecipientMonitoringCase::query()->firstOrCreate(
                    [
                        'recipient_monitoring_cycle_id' => $cycle->id,
                        'scholarship_application_id' => $application->id,
                    ],
                    [
                        'applicant_id' => $application->applicant_id,
                        'status' => $this->initialStatus($cycle, $application),
                        'opened_at' => $cycle->published_at ?? now(),
                        'closed_at' => $cycle->status === 'closed' ? now() : null,
                    ],
                );
            })
            ->values();
    }

    /** @return Collection<int, RecipientMonitoringCase> */
    public function activateForAcceptedApplication(ScholarshipApplication $application): Collection
    {
        if ($application->student_response_status !== 'accepted') {
            return collect();
        }

        $cycles = RecipientMonitoringCycle::query()
            ->where('scholarship_id', $application->scholarship_id)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->get();

        return $cycles->map(function (RecipientMonitoringCycle $cycle) use ($application): RecipientMonitoringCase {
            $case = RecipientMonitoringCase::query()->firstOrCreate(
                [
                    'recipient_monitoring_cycle_id' => $cycle->id,
                    'scholarship_application_id' => $application->id,
                ],
                [
                    'applicant_id' => $application->applicant_id,
                    'status' => RecipientSupportCatalog::CASE_AWAITING_SUBMISSION,
                    'opened_at' => $cycle->published_at ?? now(),
                ],
            );

            if ($case->status === RecipientSupportCatalog::CASE_AWAITING_AGREEMENT) {
                $this->transition($case, RecipientSupportCatalog::CASE_AWAITING_SUBMISSION);
            }

            return $case->fresh();
        })->values();
    }

    public function transition(
        RecipientMonitoringCase $case,
        string $status,
        ?User $actor = null,
        ?string $outcome = null,
        ?string $reason = null,
    ): RecipientMonitoringCase {
        if (! in_array($status, RecipientSupportCatalog::CASE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'The monitoring case status is invalid.']);
        }

        if ($case->status === $status) {
            return $case;
        }

        if (! RecipientSupportCatalog::canTransitionCase($case->status, $status)) {
            throw ValidationException::withMessages([
                'status' => 'The monitoring case cannot move from '.Str::headline($case->status).' to '.Str::headline($status).'.',
            ]);
        }

        if ($outcome !== null && ! in_array($outcome, RecipientSupportCatalog::CASE_OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => 'The monitoring case outcome is invalid.']);
        }

        if ($status === RecipientSupportCatalog::CASE_CLOSED && $outcome === null) {
            throw ValidationException::withMessages(['outcome' => 'Choose an outcome before closing the monitoring case.']);
        }

        $now = now();
        $attributes = [
            'status' => $status,
            'status_reason' => filled($reason) ? trim((string) $reason) : null,
        ];

        if ($status === RecipientSupportCatalog::CASE_SUBMITTED) {
            $attributes['submitted_at'] = $case->submitted_at ?? $now;
        }
        if ($status === RecipientSupportCatalog::CASE_UNDER_REVIEW) {
            $attributes['review_started_at'] = $case->review_started_at ?? $now;
        }
        if ($status === RecipientSupportCatalog::CASE_READY_TO_CLOSE) {
            $attributes['ready_to_close_at'] = $case->ready_to_close_at ?? $now;
        }
        if ($status === RecipientSupportCatalog::CASE_CLOSED) {
            $attributes['outcome'] = $outcome;
            $attributes['closed_by'] = $actor?->id;
            $attributes['closed_at'] = $now;
        }
        if ($case->isClosed() && ! in_array($status, [
            RecipientSupportCatalog::CASE_CLOSED,
            RecipientSupportCatalog::CASE_WAIVED,
            RecipientSupportCatalog::CASE_CANCELLED,
        ], true)) {
            $attributes['outcome'] = null;
            $attributes['closed_by'] = null;
            $attributes['closed_at'] = null;
            $attributes['reopened_by'] = $actor?->id;
            $attributes['reopened_at'] = $now;
        }

        $case->update($attributes);

        if ($case->isClosed()) {
            app(RecipientMonitoringReminderService::class)->markCaseResolved($case);
        }

        return $case->fresh();
    }

    public function close(
        RecipientMonitoringCase $case,
        User $actor,
        string $outcome,
        ?string $reason = null,
    ): RecipientMonitoringCase {
        if ($case->isClosed()) {
            throw ValidationException::withMessages(['case' => 'This check-in is already closed.']);
        }
        if (! in_array($outcome, [
            RecipientSupportCatalog::OUTCOME_COMPLETED,
            RecipientSupportCatalog::OUTCOME_COMPLETED_WITH_SUPPORT,
            RecipientSupportCatalog::OUTCOME_INCOMPLETE,
        ], true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose a valid check-in outcome.']);
        }

        if (in_array($outcome, [
            RecipientSupportCatalog::OUTCOME_COMPLETED,
            RecipientSupportCatalog::OUTCOME_COMPLETED_WITH_SUPPORT,
        ], true) && $case->status !== RecipientSupportCatalog::CASE_READY_TO_CLOSE) {
            throw ValidationException::withMessages([
                'outcome' => 'Complete every required item before closing this check-in as completed.',
            ]);
        }
        if ($outcome === RecipientSupportCatalog::OUTCOME_COMPLETED_WITH_SUPPORT
            && ! $case->application->monitoringInterventions()
                ->where('recipient_monitoring_cycle_id', $case->recipient_monitoring_cycle_id)
                ->where('status', 'completed')
                ->exists()) {
            throw ValidationException::withMessages([
                'outcome' => 'A completed follow-up is required for the completed-with-support outcome.',
            ]);
        }
        if ($outcome === RecipientSupportCatalog::OUTCOME_INCOMPLETE) {
            if ($case->due_status !== 'overdue') {
                throw ValidationException::withMessages([
                    'outcome' => 'An incomplete check-in can only be closed after its request window ends.',
                ]);
            }
            if (! in_array($case->status, [
                RecipientSupportCatalog::CASE_AWAITING_SUBMISSION,
                RecipientSupportCatalog::CASE_CORRECTION_REQUESTED,
                RecipientSupportCatalog::CASE_FOLLOW_UP,
            ], true)) {
                throw ValidationException::withMessages([
                    'outcome' => 'Review submitted records and pending requests before closing this check-in.',
                ]);
            }
        }
        $this->ensureNoOpenCaseWork($case);
        if ($outcome !== RecipientSupportCatalog::OUTCOME_COMPLETED && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Add a reason for this check-in outcome.']);
        }

        return DB::transaction(function () use ($case, $actor, $outcome, $reason): RecipientMonitoringCase {
            $fromStatus = $case->status;
            $now = now();
            $case->forceFill([
                'status' => RecipientSupportCatalog::CASE_CLOSED,
                'due_status' => 'closed',
                'outcome' => $outcome,
                'status_reason' => filled($reason) ? trim((string) $reason) : 'All required items were confirmed.',
                'next_action_role' => null,
                'next_action' => null,
                'closed_by' => $actor->id,
                'closed_at' => $now,
            ])->save();
            app(RecipientMonitoringReminderService::class)->markCaseResolved($case);
            $this->recordEvent($case, $actor, 'closed', $fromStatus, RecipientSupportCatalog::CASE_CLOSED, $outcome, $reason);
            $this->closeCycleWhenComplete($case, $actor);

            return $case->fresh();
        });
    }

    public function waive(
        RecipientMonitoringCase $case,
        User $actor,
        string $outcome,
        string $reason,
    ): RecipientMonitoringCase {
        if ($case->isClosed()) {
            throw ValidationException::withMessages(['case' => 'This check-in is already closed.']);
        }
        if (! in_array($outcome, [
            RecipientSupportCatalog::OUTCOME_WAIVED,
            RecipientSupportCatalog::OUTCOME_EXCUSED,
        ], true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose waived or excused for this action.']);
        }
        $this->ensureNoOpenCaseWork($case);

        return DB::transaction(function () use ($case, $actor, $outcome, $reason): RecipientMonitoringCase {
            $fromStatus = $case->status;
            $case->forceFill([
                'status' => RecipientSupportCatalog::CASE_WAIVED,
                'due_status' => 'closed',
                'outcome' => $outcome,
                'status_reason' => trim($reason),
                'next_action_role' => null,
                'next_action' => null,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();
            app(RecipientMonitoringReminderService::class)->markCaseResolved($case);
            $this->recordEvent($case, $actor, 'waived', $fromStatus, RecipientSupportCatalog::CASE_WAIVED, $outcome, $reason);
            $this->closeCycleWhenComplete($case, $actor);

            return $case->fresh();
        });
    }

    public function reopen(
        RecipientMonitoringCase $case,
        User $actor,
        string $reason,
    ): RecipientMonitoringCase {
        if (! in_array($case->status, [
            RecipientSupportCatalog::CASE_CLOSED,
            RecipientSupportCatalog::CASE_WAIVED,
        ], true)) {
            throw ValidationException::withMessages(['case' => 'Only a closed or waived check-in can be reopened.']);
        }
        if ($case->cycle->status === 'cancelled') {
            throw ValidationException::withMessages(['case' => 'A cancelled monitoring period cannot be reopened.']);
        }

        return DB::transaction(function () use ($case, $actor, $reason): RecipientMonitoringCase {
            $fromStatus = $case->status;
            $previousOutcome = $case->outcome;
            if ($case->cycle->status === 'closed') {
                $case->cycle->forceFill([
                    'status' => 'open',
                    'closed_by' => null,
                    'closed_at' => null,
                    'reopened_by' => $actor->id,
                    'reopened_at' => now(),
                ])->save();
            }
            $case->forceFill([
                'status' => RecipientSupportCatalog::CASE_UNDER_REVIEW,
                'due_status' => 'open',
                'outcome' => null,
                'status_reason' => trim($reason),
                'next_action_role' => 'provider',
                'next_action' => 'review_requirements',
                'closed_by' => null,
                'closed_at' => null,
                'reopened_by' => $actor->id,
                'reopened_at' => now(),
            ])->save();
            $reopenedCase = $this->sync($case->cycle->fresh(), $case->application);
            $this->recordEvent(
                $reopenedCase,
                $actor,
                'reopened',
                $fromStatus,
                $reopenedCase->status,
                null,
                $reason,
                ['previous_outcome' => $previousOutcome],
            );

            return $reopenedCase;
        });
    }

    public function sync(
        RecipientMonitoringCycle $cycle,
        ScholarshipApplication $application,
    ): RecipientMonitoringCase {
        $case = RecipientMonitoringCase::query()->firstOrCreate(
            [
                'recipient_monitoring_cycle_id' => $cycle->id,
                'scholarship_application_id' => $application->id,
            ],
            [
                'applicant_id' => $application->applicant_id,
                'status' => $this->initialStatus($cycle, $application),
                'opened_at' => $cycle->published_at ?? now(),
            ],
        );
        $cycle->loadMissing('requirements');
        $today = CarbonImmutable::today();
        $submissions = $application->monitoringSubmissions()
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->get();
        $adjustments = $application->monitoringAdjustmentRequests()
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->get();
        $openInterventions = $application->monitoringInterventions()
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->where('status', 'open')
            ->get();
        $approvedDueAt = $adjustments
            ->where('request_type', 'extension')
            ->where('status', 'approved')
            ->whereNotNull('approved_due_at')
            ->max('approved_due_at');
        $effectiveDueAt = $approvedDueAt
            ? CarbonImmutable::parse($approvedDueAt)
            : CarbonImmutable::parse($cycle->due_at);
        $dueStatus = $this->dueStatus($case, $cycle, $effectiveDueAt, $today);
        $required = $cycle->requirements->where('required', true)->values();
        $requiredSubmissions = $required->isEmpty()
            ? $submissions->whereNull('recipient_monitoring_cycle_requirement_id')
            : $submissions->whereIn('recipient_monitoring_cycle_requirement_id', $required->modelKeys());
        $submittedRequirementIds = $requiredSubmissions
            ->pluck('recipient_monitoring_cycle_requirement_id')
            ->filter()
            ->unique();
        $missing = $required->isEmpty()
            ? ($requiredSubmissions->isEmpty() ? collect(['legacy']) : collect())
            : $required->reject(fn ($requirement): bool => $submittedRequirementIds->contains($requirement->id));
        $missingApplicantCount = $required->isEmpty()
            ? $missing->count()
            : $missing->where('requires_file', true)->count();
        $missingProviderCount = $required->isEmpty()
            ? 0
            : $missing->where('requires_file', false)->count();
        $pendingReviewCount = $requiredSubmissions
            ->filter(fn ($submission): bool => blank($submission->review_status)
                || $submission->review_status === 'pending')
            ->count();
        $correctionCount = $requiredSubmissions->where('review_status', 'needs_correction')->count();
        $notMetCount = $requiredSubmissions->where('review_status', 'not_met')->count();
        $completedCount = $requiredSubmissions
            ->whereIn('review_status', ['met', 'excused'])
            ->count();
        $requiredCount = $required->isEmpty() ? 1 : $required->count();
        $allComplete = $completedCount >= $requiredCount;
        $pendingAdjustment = $adjustments->firstWhere('status', 'pending');
        $anyReviewed = $requiredSubmissions->contains(
            fn ($submission): bool => filled($submission->reviewed_at),
        );

        $status = match (true) {
            $case->status === RecipientSupportCatalog::CASE_CLOSED => RecipientSupportCatalog::CASE_CLOSED,
            $case->status === RecipientSupportCatalog::CASE_CANCELLED => RecipientSupportCatalog::CASE_CANCELLED,
            $case->status === RecipientSupportCatalog::CASE_WAIVED => RecipientSupportCatalog::CASE_WAIVED,
            $cycle->status === 'cancelled' => RecipientSupportCatalog::CASE_CANCELLED,
            $cycle->status === 'closed' => RecipientSupportCatalog::CASE_CLOSED,
            $application->student_response_status !== 'accepted' => RecipientSupportCatalog::CASE_AWAITING_AGREEMENT,
            $pendingAdjustment !== null => RecipientSupportCatalog::CASE_EXTENSION_PENDING,
            $correctionCount > 0 => RecipientSupportCatalog::CASE_CORRECTION_REQUESTED,
            $openInterventions->isNotEmpty() || $notMetCount > 0 => RecipientSupportCatalog::CASE_FOLLOW_UP,
            $allComplete => RecipientSupportCatalog::CASE_READY_TO_CLOSE,
            $missing->isNotEmpty() => RecipientSupportCatalog::CASE_AWAITING_SUBMISSION,
            $pendingReviewCount > 0 && $anyReviewed => RecipientSupportCatalog::CASE_UNDER_REVIEW,
            $pendingReviewCount > 0 => RecipientSupportCatalog::CASE_SUBMITTED,
            default => RecipientSupportCatalog::CASE_AWAITING_SUBMISSION,
        };
        [$nextActionRole, $nextAction] = $this->nextAction(
            $status,
            $dueStatus,
            $cycle,
            $missingApplicantCount,
            $missingProviderCount,
            $openInterventions,
        );
        $now = now();
        $attributes = [
            'status' => $status,
            'due_status' => $dueStatus,
            'effective_due_at' => $effectiveDueAt,
            'status_reason' => $case->isClosed()
                ? $case->status_reason
                : $this->statusReason(
                    $status,
                    $missingApplicantCount,
                    $missingProviderCount,
                    $pendingReviewCount,
                ),
            'next_action_role' => $nextActionRole,
            'next_action' => $nextAction,
        ];

        if (in_array($status, [
            RecipientSupportCatalog::CASE_SUBMITTED,
            RecipientSupportCatalog::CASE_UNDER_REVIEW,
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED,
            RecipientSupportCatalog::CASE_FOLLOW_UP,
            RecipientSupportCatalog::CASE_READY_TO_CLOSE,
        ], true) && $requiredSubmissions->isNotEmpty()) {
            $attributes['submitted_at'] = $case->submitted_at ?? $requiredSubmissions->max('submitted_at') ?? $now;
        }
        if (in_array($status, [
            RecipientSupportCatalog::CASE_UNDER_REVIEW,
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED,
            RecipientSupportCatalog::CASE_FOLLOW_UP,
            RecipientSupportCatalog::CASE_READY_TO_CLOSE,
        ], true) && $anyReviewed) {
            $attributes['review_started_at'] = $case->review_started_at ?? $now;
        }
        if ($status === RecipientSupportCatalog::CASE_READY_TO_CLOSE) {
            $attributes['ready_to_close_at'] = $case->ready_to_close_at ?? $now;
        }
        if (in_array($status, [RecipientSupportCatalog::CASE_CLOSED, RecipientSupportCatalog::CASE_CANCELLED], true)) {
            $attributes['closed_at'] = $case->closed_at ?? $now;
        }

        $case->forceFill($attributes);
        if ($case->isDirty()) {
            $case->save();
        }

        return $case->fresh();
    }

    public function payload(
        RecipientMonitoringCase $case,
        ?Collection $adjustments = null,
        ?Collection $interventions = null,
        bool $includeContext = false,
        string $audience = 'provider',
    ): array {
        $adjustments ??= collect();
        $interventions ??= collect();

        return [
            'id' => $case->id,
            'status' => $case->status,
            'status_label' => match ($case->status) {
                RecipientSupportCatalog::CASE_AWAITING_AGREEMENT => 'Agreement needed',
                RecipientSupportCatalog::CASE_AWAITING_SUBMISSION => 'Waiting for records',
                RecipientSupportCatalog::CASE_SUBMITTED => 'Ready for review',
                RecipientSupportCatalog::CASE_UNDER_REVIEW => 'Review in progress',
                RecipientSupportCatalog::CASE_CORRECTION_REQUESTED => 'Replacement requested',
                RecipientSupportCatalog::CASE_EXTENSION_PENDING => 'Request pending',
                RecipientSupportCatalog::CASE_FOLLOW_UP => 'Follow-up needed',
                RecipientSupportCatalog::CASE_READY_TO_CLOSE => 'Requirements complete',
                RecipientSupportCatalog::CASE_CLOSED => 'Check-in closed',
                RecipientSupportCatalog::CASE_WAIVED => 'Check-in waived',
                default => 'Check-in cancelled',
            },
            'due_status' => $case->due_status,
            'due_status_label' => match ($case->due_status) {
                'upcoming' => 'Opens later',
                'extended' => 'Extension active',
                'grace_period' => 'Request window open',
                'overdue' => 'Past due',
                'closed' => 'Closed',
                default => 'Open',
            },
            'effective_due_at' => $case->effective_due_at?->format('Y-m-d'),
            'effective_due_label' => $case->effective_due_at?->format('M d, Y'),
            'status_reason' => $case->status_reason,
            'next_action' => $case->next_action ? [
                'key' => $case->next_action,
                'role' => $case->next_action_role,
                'role_label' => match ($case->next_action_role) {
                    'applicant' => 'Recipient',
                    'provider' => 'Provider',
                    default => null,
                },
                'label' => $this->nextActionLabel($case->next_action),
            ] : null,
            'outcome' => $case->outcome,
            'outcome_label' => RecipientSupportCatalog::outcomeLabel($case->outcome),
            'decision_reason' => $case->isClosed() ? $case->status_reason : null,
            'closed_at' => $case->closed_at?->format('M d, Y h:i A'),
            'reopened_at' => $case->reopened_at?->format('M d, Y h:i A'),
            'exception_context' => $includeContext
                ? $this->exceptionContextPayload($adjustments)
                : null,
            'history' => $includeContext
                ? $this->historyPayload($case, $adjustments, $interventions, $audience)
                : [],
        ];
    }

    private function exceptionContextPayload(Collection $adjustments): ?array
    {
        if ($adjustments->isEmpty()) {
            return null;
        }

        $latest = $adjustments->sortByDesc('id')->first();
        $activeExtension = $adjustments
            ->where('request_type', 'extension')
            ->where('status', 'approved')
            ->whereNotNull('approved_due_at')
            ->sortByDesc('approved_due_at')
            ->first();
        $approvedExceptionCount = $adjustments
            ->where('request_type', 'exception')
            ->where('status', 'approved')
            ->count();
        $pendingCount = $adjustments->where('status', 'pending')->count();

        $summary = match (true) {
            $pendingCount > 0 => $pendingCount.' request'.($pendingCount === 1 ? '' : 's').' awaiting a decision',
            $activeExtension !== null => 'Extension approved through '.$activeExtension->approved_due_at->format('M d, Y'),
            $approvedExceptionCount > 0 => $approvedExceptionCount.' approved exception'.($approvedExceptionCount === 1 ? '' : 's'),
            $latest?->status === 'declined' => 'Latest adjustment request declined',
            default => 'Adjustment history recorded',
        };

        return [
            'request_count' => $adjustments->count(),
            'pending_count' => $pendingCount,
            'approved_count' => $adjustments->where('status', 'approved')->count(),
            'summary' => $summary,
            'latest_type' => $latest?->request_type,
            'latest_type_label' => $latest?->request_type === 'extension'
                ? 'Extension request'
                : 'Exception request',
            'latest_status' => $latest?->status,
            'latest_status_label' => match ($latest?->status) {
                'approved' => 'Approved',
                'declined' => 'Declined',
                default => 'Pending review',
            },
            'reason_label' => filled($latest?->reason_category)
                ? Str::headline($latest->reason_category)
                : null,
            'explanation' => $latest?->explanation,
            'decision_notes' => $latest?->decision_notes,
            'approved_due_label' => $activeExtension?->approved_due_at?->format('M d, Y'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function historyPayload(
        RecipientMonitoringCase $case,
        Collection $adjustments,
        Collection $interventions,
        string $audience,
    ): array {
        $providerAudience = $audience === 'provider';
        $history = $case->events()
            ->with('actor')
            ->get()
            ->map(function ($event) use ($providerAudience): array {
                $title = match ($event->action) {
                    'closed' => 'Check-in closed',
                    'waived' => 'Check-in waived',
                    'reopened' => 'Check-in reopened',
                    default => Str::headline($event->action),
                };

                return [
                    'id' => 'case-'.$event->id,
                    'kind' => 'case_decision',
                    'title' => $title,
                    'detail' => RecipientSupportCatalog::outcomeLabel($event->outcome),
                    'reason' => $event->reason,
                    'tone' => $event->action === 'reopened'
                        ? 'amber'
                        : ($event->outcome === RecipientSupportCatalog::OUTCOME_INCOMPLETE ? 'rose' : 'emerald'),
                    'actor' => $providerAudience ? $event->actor?->name : 'Provider team',
                    'occurred_at' => $event->created_at?->format('M d, Y h:i A'),
                    'sort_at' => (($event->created_at?->getTimestamp() ?? 0) * 10) + 2,
                ];
            });

        foreach ($adjustments as $adjustment) {
            $typeLabel = $adjustment->request_type === 'extension' ? 'Extension' : 'Exception';
            $history->push([
                'id' => 'adjustment-requested-'.$adjustment->id,
                'kind' => 'adjustment_requested',
                'title' => $typeLabel.' requested',
                'detail' => filled($adjustment->reason_category) ? Str::headline($adjustment->reason_category) : null,
                'reason' => $adjustment->explanation,
                'tone' => 'amber',
                'actor' => $providerAudience ? 'Recipient' : 'You',
                'occurred_at' => $adjustment->created_at?->format('M d, Y h:i A'),
                'sort_at' => ($adjustment->created_at?->getTimestamp() ?? 0) * 10,
            ]);
            if ($adjustment->decided_at) {
                $history->push([
                    'id' => 'adjustment-decided-'.$adjustment->id,
                    'kind' => 'adjustment_decided',
                    'title' => $typeLabel.' '.Str::headline($adjustment->status),
                    'detail' => $adjustment->approved_due_at
                        ? 'New deadline: '.$adjustment->approved_due_at->format('M d, Y')
                        : null,
                    'reason' => $adjustment->decision_notes,
                    'tone' => $adjustment->status === 'approved' ? 'emerald' : 'rose',
                    'actor' => $providerAudience ? $adjustment->decider?->name : 'Provider team',
                    'occurred_at' => $adjustment->decided_at->format('M d, Y h:i A'),
                    'sort_at' => ($adjustment->decided_at->getTimestamp() * 10) + 1,
                ]);
            }
        }

        foreach ($interventions as $intervention) {
            $history->push([
                'id' => 'follow-up-started-'.$intervention->id,
                'kind' => 'follow_up',
                'title' => Str::headline($intervention->type).' added',
                'detail' => $intervention->summary,
                'reason' => $intervention->action_required,
                'tone' => 'sky',
                'actor' => $providerAudience ? $intervention->creator?->name : 'Provider team',
                'occurred_at' => $intervention->created_at?->format('M d, Y h:i A'),
                'sort_at' => ($intervention->created_at?->getTimestamp() ?? 0) * 10,
            ]);
            if ($intervention->completed_at) {
                $history->push([
                    'id' => 'follow-up-completed-'.$intervention->id,
                    'kind' => 'follow_up_completed',
                    'title' => 'Follow-up completed',
                    'detail' => $intervention->summary,
                    'reason' => $intervention->completion_notes,
                    'tone' => 'emerald',
                    'actor' => $providerAudience ? $intervention->creator?->name : 'Provider team',
                    'occurred_at' => $intervention->completed_at->format('M d, Y h:i A'),
                    'sort_at' => ($intervention->completed_at->getTimestamp() * 10) + 1,
                ]);
            }
        }

        return $history
            ->sortByDesc('sort_at')
            ->take(12)
            ->map(fn (array $item): array => collect($item)->except('sort_at')->all())
            ->values()
            ->all();
    }

    private function dueStatus(
        RecipientMonitoringCase $case,
        RecipientMonitoringCycle $cycle,
        CarbonImmutable $effectiveDueAt,
        CarbonImmutable $today,
    ): string {
        if ($case->isClosed()
            || $case->status === RecipientSupportCatalog::CASE_WAIVED
            || in_array($cycle->status, ['closed', 'cancelled'], true)) {
            return 'closed';
        }
        if ($cycle->opens_at?->isAfter($today)) {
            return 'upcoming';
        }
        if ($today->lessThanOrEqualTo($effectiveDueAt)) {
            return $cycle->due_at && $effectiveDueAt->isAfter($cycle->due_at)
                ? 'extended'
                : 'open';
        }

        return $today->lessThanOrEqualTo($effectiveDueAt->addDays($cycle->grace_period_days ?? 0))
            ? 'grace_period'
            : 'overdue';
    }

    /** @return array{string|null, string|null} */
    private function nextAction(
        string $status,
        string $dueStatus,
        RecipientMonitoringCycle $cycle,
        int $missingApplicantCount,
        int $missingProviderCount,
        Collection $openInterventions,
    ): array {
        if (in_array($status, [RecipientSupportCatalog::CASE_CLOSED, RecipientSupportCatalog::CASE_CANCELLED], true)) {
            return [null, null];
        }

        return match ($status) {
            RecipientSupportCatalog::CASE_AWAITING_AGREEMENT => ['applicant', 'accept_agreement'],
            RecipientSupportCatalog::CASE_EXTENSION_PENDING => ['provider', 'decide_adjustment'],
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED => ['applicant', 'replace_record'],
            RecipientSupportCatalog::CASE_SUBMITTED,
            RecipientSupportCatalog::CASE_UNDER_REVIEW => ['provider', 'review_requirements'],
            RecipientSupportCatalog::CASE_FOLLOW_UP => $openInterventions->contains(
                fn (RecipientMonitoringIntervention $intervention): bool => filled($intervention->action_required),
            )
                ? ['applicant', 'complete_follow_up']
                : ['provider', 'manage_follow_up'],
            RecipientSupportCatalog::CASE_READY_TO_CLOSE => ['provider', 'close_check_in'],
            RecipientSupportCatalog::CASE_WAIVED => [null, null],
            default => match (true) {
                $dueStatus === 'upcoming' => [null, 'wait_for_opening'],
                $dueStatus === 'overdue' => ['provider', 'resolve_overdue'],
                $dueStatus === 'grace_period' && $cycle->allow_exception_requests => ['applicant', 'request_adjustment'],
                $missingApplicantCount > 0 => ['applicant', 'submit_requirements'],
                $missingProviderCount > 0 => ['provider', 'record_requirement'],
                default => ['provider', 'review_requirements'],
            },
        };
    }

    private function statusReason(
        string $status,
        int $missingApplicantCount,
        int $missingProviderCount,
        int $pendingReviewCount,
    ): ?string {
        return match ($status) {
            RecipientSupportCatalog::CASE_AWAITING_SUBMISSION => match (true) {
                $missingApplicantCount > 0 => $missingApplicantCount.' recipient item'.($missingApplicantCount === 1 ? '' : 's').' still needed.',
                $missingProviderCount > 0 => $missingProviderCount.' provider item'.($missingProviderCount === 1 ? '' : 's').' still needed.',
                default => 'Required records are still incomplete.',
            },
            RecipientSupportCatalog::CASE_SUBMITTED,
            RecipientSupportCatalog::CASE_UNDER_REVIEW => $pendingReviewCount.' item'.($pendingReviewCount === 1 ? '' : 's').' waiting for review.',
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED => 'A replacement record is needed.',
            RecipientSupportCatalog::CASE_EXTENSION_PENDING => 'A recipient request needs a decision.',
            RecipientSupportCatalog::CASE_FOLLOW_UP => 'A requirement needs documented follow-up.',
            RecipientSupportCatalog::CASE_READY_TO_CLOSE => 'All required items are confirmed.',
            default => null,
        };
    }

    private function nextActionLabel(string $action): string
    {
        return match ($action) {
            'accept_agreement' => 'Accept the recipient agreement',
            'submit_requirements' => 'Submit the remaining records',
            'record_requirement' => 'Record the provider-confirmed item',
            'decide_adjustment' => 'Review the recipient request',
            'review_requirements' => 'Review submitted records',
            'replace_record' => 'Upload the requested replacement',
            'complete_follow_up' => 'Complete the provider follow-up',
            'manage_follow_up' => 'Record the follow-up outcome',
            'request_adjustment' => 'Request an extension or exception',
            'resolve_overdue' => 'Review the overdue check-in',
            'close_check_in' => 'Close the completed check-in',
            'wait_for_opening' => 'Wait for the check-in to open',
            default => 'Review the check-in',
        };
    }

    private function ensureNoOpenCaseWork(RecipientMonitoringCase $case): void
    {
        if ($case->application->monitoringAdjustmentRequests()
            ->where('recipient_monitoring_cycle_id', $case->recipient_monitoring_cycle_id)
            ->where('status', 'pending')
            ->exists()) {
            throw ValidationException::withMessages([
                'case' => 'Decide the recipient extension or exception request before closing this check-in.',
            ]);
        }
        if ($case->application->monitoringInterventions()
            ->where('recipient_monitoring_cycle_id', $case->recipient_monitoring_cycle_id)
            ->where('status', 'open')
            ->exists()) {
            throw ValidationException::withMessages([
                'case' => 'Complete the open follow-up before closing this check-in.',
            ]);
        }
    }

    private function recordEvent(
        RecipientMonitoringCase $case,
        User $actor,
        string $action,
        string $fromStatus,
        string $toStatus,
        ?string $outcome,
        ?string $reason,
        array $metadata = [],
    ): void {
        $case->events()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'outcome' => $outcome,
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function closeCycleWhenComplete(RecipientMonitoringCase $case, User $actor): void
    {
        $cycle = $case->cycle;
        if ($cycle->status === 'cancelled' || ! $cycle->cases()->exists()) {
            return;
        }
        $hasOpenCases = $cycle->cases()
            ->whereNotIn('status', [
                RecipientSupportCatalog::CASE_CLOSED,
                RecipientSupportCatalog::CASE_WAIVED,
                RecipientSupportCatalog::CASE_CANCELLED,
            ])
            ->exists();
        if (! $hasOpenCases) {
            $cycle->forceFill([
                'status' => 'closed',
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();
        }
    }

    private function initialStatus(
        RecipientMonitoringCycle $cycle,
        ScholarshipApplication $application,
    ): string {
        if ($cycle->status === 'closed') {
            return RecipientSupportCatalog::CASE_CLOSED;
        }

        return $application->student_response_status === 'accepted'
            ? RecipientSupportCatalog::CASE_AWAITING_SUBMISSION
            : RecipientSupportCatalog::CASE_AWAITING_AGREEMENT;
    }
}
