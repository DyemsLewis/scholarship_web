<?php

namespace App\Domain\Applications;

final class ApplicationStageCatalog
{
    public const SCREENING = 'screening';

    public const FORMAL_APPLICATION = 'formal_application';

    public const EXAM = 'exam';

    public const INTERVIEW = 'interview';

    public const DECISION = 'decision';

    public const STAGES = [
        self::SCREENING,
        self::FORMAL_APPLICATION,
        self::EXAM,
        self::INTERVIEW,
        self::DECISION,
    ];

    public const OPERATIONAL_STATUSES = [
        'not_started',
        'waiting_for_schedule',
        'scheduled',
        'waiting_for_applicant',
        'ready_for_review',
        'in_review',
        'needs_action',
        'resolved',
    ];

    public const SESSION_STAGES = [
        self::FORMAL_APPLICATION,
        self::EXAM,
        self::INTERVIEW,
    ];

    public const SESSION_MODES = [
        'onsite',
        'online',
        'hybrid',
        'provider_managed',
    ];

    public const SESSION_STATUSES = [
        'draft',
        'scheduled',
        'ongoing',
        'completed',
        'cancelled',
    ];

    public const ASSIGNMENT_STATUSES = [
        'assigned',
        'acknowledged',
        'rescheduled',
        'cancelled',
    ];

    public const ATTENDANCE_STATUSES = [
        'pending',
        'present',
        'absent',
        'excused',
        'not_required',
    ];

    public const EVIDENCE_SCOPES = ['session', 'applicant'];

    public const EVIDENCE_VISIBILITIES = ['provider_private', 'applicant_shared'];

    public const EVIDENCE_CATEGORIES = [
        'verification_record',
        'attendance_sheet',
        'score_sheet',
        'evaluation',
        'decision_record',
        'signed_acknowledgment',
        'other',
    ];

    private const DEFINITIONS = [
        self::SCREENING => [
            'label' => 'Pre-screening',
            'outcomes' => ['qualified', 'needs_correction', 'not_qualified'],
            'supports_sessions' => false,
            'proof_required_by_default' => false,
        ],
        self::FORMAL_APPLICATION => [
            'label' => 'Formal application',
            'outcomes' => ['accepted', 'returned_for_correction', 'rejected', 'withdrawn'],
            'supports_sessions' => true,
            'proof_required_by_default' => false,
        ],
        self::EXAM => [
            'label' => 'Examination',
            'outcomes' => ['passed', 'not_passed', 'absent', 'reschedule', 'waived'],
            'supports_sessions' => true,
            'proof_required_by_default' => true,
        ],
        self::INTERVIEW => [
            'label' => 'Interview',
            'outcomes' => ['recommended', 'not_recommended', 'absent', 'reschedule', 'waived'],
            'supports_sessions' => true,
            'proof_required_by_default' => true,
        ],
        self::DECISION => [
            'label' => 'Final decision',
            'outcomes' => ['selected', 'waitlisted', 'not_selected'],
            'supports_sessions' => false,
            'proof_required_by_default' => false,
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<int, string> */
    public static function outcomesFor(string $stage): array
    {
        return self::DEFINITIONS[$stage]['outcomes'] ?? [];
    }

    public static function supportsSessions(string $stage): bool
    {
        return (bool) (self::DEFINITIONS[$stage]['supports_sessions'] ?? false);
    }

    public static function requiresProofByDefault(string $stage): bool
    {
        return (bool) (self::DEFINITIONS[$stage]['proof_required_by_default'] ?? false);
    }

    public static function isValidOutcome(string $stage, string $outcome): bool
    {
        return in_array($outcome, self::outcomesFor($stage), true);
    }
}
