<?php

namespace App\Support;

class RecipientMonitoringRequirementType
{
    public const ACADEMIC_PROGRESS = 'academic_progress';

    public const ENROLLMENT = 'enrollment';

    public const SCHOOL_ID = 'school_id';

    public const ATTENDANCE = 'attendance';

    public const PROGRAM_PARTICIPATION = 'program_participation';

    public const CUSTOM = 'custom';

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::ACADEMIC_PROGRESS => [
                'label' => 'Academic progress',
                'description' => 'Check the recipient\'s grades for the selected school period.',
                'default_title' => 'Academic progress update',
                'default_evidence' => 'Latest report card, grade report, or transcript.',
                'requires_file' => true,
                'icon' => 'fa-solid fa-graduation-cap',
            ],
            self::ENROLLMENT => [
                'label' => 'Enrollment verification',
                'description' => 'Confirm that the recipient remains enrolled.',
                'default_title' => 'Proof of current enrollment',
                'default_evidence' => 'Enrollment certificate, registration form, or equivalent school record.',
                'requires_file' => true,
                'icon' => 'fa-solid fa-school',
            ],
            self::SCHOOL_ID => [
                'label' => 'Recent school ID',
                'description' => 'Request a current school identification record.',
                'default_title' => 'Recent school ID',
                'default_evidence' => 'Clear image or scan of the current school ID.',
                'requires_file' => true,
                'icon' => 'fa-solid fa-id-card',
            ],
            self::ATTENDANCE => [
                'label' => 'Attendance',
                'description' => 'Review attendance only when it is part of the disclosed scholarship terms.',
                'default_title' => 'Attendance update',
                'default_evidence' => 'School-issued attendance summary or certification.',
                'requires_file' => true,
                'icon' => 'fa-solid fa-calendar-check',
            ],
            self::PROGRAM_PARTICIPATION => [
                'label' => 'Program participation',
                'description' => 'Record attendance in required orientations or scholarship activities.',
                'default_title' => 'Required program activity',
                'default_evidence' => 'Provider attendance record; no applicant upload is required by default.',
                'requires_file' => false,
                'icon' => 'fa-solid fa-people-group',
            ],
            self::CUSTOM => [
                'label' => 'Custom requirement',
                'description' => 'Add another requirement that is necessary and disclosed to recipients.',
                'default_title' => 'Additional monitoring requirement',
                'default_evidence' => 'Describe the acceptable record or provider verification.',
                'requires_file' => true,
                'icon' => 'fa-solid fa-list-check',
            ],
        ];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_keys(self::definitions());
    }
}
