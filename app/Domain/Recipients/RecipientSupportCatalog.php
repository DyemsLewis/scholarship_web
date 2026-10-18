<?php

namespace App\Domain\Recipients;

use InvalidArgumentException;

final class RecipientSupportCatalog
{
    public const SUPPORT_ONE_TIME = 'one_time';

    public const SUPPORT_RECURRING = 'recurring';

    public const SUPPORT_MIXED = 'mixed';

    public const SUPPORT_MODES = [
        self::SUPPORT_ONE_TIME,
        self::SUPPORT_RECURRING,
        self::SUPPORT_MIXED,
    ];

    public const MONITORING_NONE = 'none';

    public const MONITORING_ONE_TIME_FOLLOW_UP = 'one_time_follow_up';

    public const MONITORING_RECURRING = 'recurring';

    public const MONITORING_POLICIES = [
        self::MONITORING_NONE,
        self::MONITORING_ONE_TIME_FOLLOW_UP,
        self::MONITORING_RECURRING,
    ];

    public const CASE_AWAITING_AGREEMENT = 'awaiting_agreement';

    public const CASE_AWAITING_SUBMISSION = 'awaiting_submission';

    public const CASE_SUBMITTED = 'submitted';

    public const CASE_UNDER_REVIEW = 'under_review';

    public const CASE_CORRECTION_REQUESTED = 'correction_requested';

    public const CASE_EXTENSION_PENDING = 'extension_pending';

    public const CASE_FOLLOW_UP = 'follow_up';

    public const CASE_READY_TO_CLOSE = 'ready_to_close';

    public const CASE_CLOSED = 'closed';

    public const CASE_WAIVED = 'waived';

    public const CASE_CANCELLED = 'cancelled';

    public const CASE_STATUSES = [
        self::CASE_AWAITING_AGREEMENT,
        self::CASE_AWAITING_SUBMISSION,
        self::CASE_SUBMITTED,
        self::CASE_UNDER_REVIEW,
        self::CASE_CORRECTION_REQUESTED,
        self::CASE_EXTENSION_PENDING,
        self::CASE_FOLLOW_UP,
        self::CASE_READY_TO_CLOSE,
        self::CASE_CLOSED,
        self::CASE_WAIVED,
        self::CASE_CANCELLED,
    ];

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_COMPLETED_WITH_SUPPORT = 'completed_with_support';

    public const OUTCOME_INCOMPLETE = 'incomplete';

    public const OUTCOME_EXCUSED = 'excused';

    public const OUTCOME_WAIVED = 'waived';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const CASE_OUTCOMES = [
        self::OUTCOME_COMPLETED,
        self::OUTCOME_COMPLETED_WITH_SUPPORT,
        self::OUTCOME_INCOMPLETE,
        self::OUTCOME_EXCUSED,
        self::OUTCOME_WAIVED,
        self::OUTCOME_CANCELLED,
    ];

    public const ENTITLEMENT_PENDING_AGREEMENT = 'pending_agreement';

    public const ENTITLEMENT_READY = 'ready';

    public const ENTITLEMENT_ACTIVE = 'active';

    public const ENTITLEMENT_SCHEDULED = 'scheduled';

    public const ENTITLEMENT_RELEASED = 'released';

    public const ENTITLEMENT_ISSUE_OPEN = 'issue_open';

    public const ENTITLEMENT_FULFILLED = 'fulfilled';

    public const ENTITLEMENT_WITHHELD = 'withheld';

    public const ENTITLEMENT_CANCELLED = 'cancelled';

    public const ENTITLEMENT_STATUSES = [
        self::ENTITLEMENT_PENDING_AGREEMENT,
        self::ENTITLEMENT_READY,
        self::ENTITLEMENT_ACTIVE,
        self::ENTITLEMENT_SCHEDULED,
        self::ENTITLEMENT_RELEASED,
        self::ENTITLEMENT_ISSUE_OPEN,
        self::ENTITLEMENT_FULFILLED,
        self::ENTITLEMENT_WITHHELD,
        self::ENTITLEMENT_CANCELLED,
    ];

    public const ALLOCATION_STATUSES = [
        'scheduled',
        'prepared',
        'released',
        'issue_open',
        'fulfilled',
        'replacement_required',
        'missed',
        'withheld',
        'cancelled',
    ];

    private const CASE_TRANSITIONS = [
        self::CASE_AWAITING_AGREEMENT => [self::CASE_AWAITING_SUBMISSION, self::CASE_CANCELLED],
        self::CASE_AWAITING_SUBMISSION => [self::CASE_SUBMITTED, self::CASE_EXTENSION_PENDING, self::CASE_WAIVED, self::CASE_CANCELLED],
        self::CASE_SUBMITTED => [self::CASE_UNDER_REVIEW, self::CASE_CORRECTION_REQUESTED, self::CASE_EXTENSION_PENDING, self::CASE_READY_TO_CLOSE, self::CASE_FOLLOW_UP],
        self::CASE_UNDER_REVIEW => [self::CASE_CORRECTION_REQUESTED, self::CASE_EXTENSION_PENDING, self::CASE_FOLLOW_UP, self::CASE_READY_TO_CLOSE, self::CASE_WAIVED],
        self::CASE_CORRECTION_REQUESTED => [self::CASE_SUBMITTED, self::CASE_EXTENSION_PENDING, self::CASE_FOLLOW_UP, self::CASE_WAIVED],
        self::CASE_EXTENSION_PENDING => [self::CASE_AWAITING_SUBMISSION, self::CASE_SUBMITTED, self::CASE_CORRECTION_REQUESTED, self::CASE_FOLLOW_UP, self::CASE_WAIVED],
        self::CASE_FOLLOW_UP => [self::CASE_AWAITING_SUBMISSION, self::CASE_SUBMITTED, self::CASE_UNDER_REVIEW, self::CASE_READY_TO_CLOSE, self::CASE_WAIVED],
        self::CASE_READY_TO_CLOSE => [self::CASE_CLOSED, self::CASE_FOLLOW_UP, self::CASE_UNDER_REVIEW],
        self::CASE_CLOSED => [self::CASE_UNDER_REVIEW],
        self::CASE_WAIVED => [self::CASE_CLOSED, self::CASE_UNDER_REVIEW],
        self::CASE_CANCELLED => [self::CASE_AWAITING_SUBMISSION],
    ];

    /** @param iterable<int, string|null> $frequencies */
    public static function supportModeFromFrequencies(iterable $frequencies): string
    {
        $values = collect($frequencies)
            ->filter()
            ->map(fn (string $frequency): string => strtolower($frequency))
            ->unique()
            ->values();

        if ($values->isEmpty() || $values->every(fn (string $value): bool => $value === 'one_time')) {
            return self::SUPPORT_ONE_TIME;
        }

        if ($values->contains('one_time')) {
            return self::SUPPORT_MIXED;
        }

        return self::SUPPORT_RECURRING;
    }

    public static function defaultMonitoringPolicy(string $supportMode): string
    {
        if (! in_array($supportMode, self::SUPPORT_MODES, true)) {
            throw new InvalidArgumentException("Unknown support mode [{$supportMode}].");
        }

        return $supportMode === self::SUPPORT_ONE_TIME
            ? self::MONITORING_NONE
            : self::MONITORING_RECURRING;
    }

    public static function canTransitionCase(string $from, string $to): bool
    {
        return in_array($to, self::CASE_TRANSITIONS[$from] ?? [], true);
    }

    public static function outcomeLabel(?string $outcome): ?string
    {
        return match ($outcome) {
            self::OUTCOME_COMPLETED => 'Completed',
            self::OUTCOME_COMPLETED_WITH_SUPPORT => 'Completed with support',
            self::OUTCOME_INCOMPLETE => 'Incomplete',
            self::OUTCOME_EXCUSED => 'Excused',
            self::OUTCOME_WAIVED => 'Waived',
            self::OUTCOME_CANCELLED => 'Cancelled',
            default => null,
        };
    }
}
