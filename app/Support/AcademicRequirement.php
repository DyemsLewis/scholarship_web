<?php

namespace App\Support;

class AcademicRequirement
{
    public const SCALE_PERCENTAGE = 'percentage';
    public const SCALE_GRADE_POINT = 'grade_point';
    public const SCALE_PASS_FAIL = 'pass_fail';
    public const SCALE_OTHER = 'other';

    public const SCALES = [
        self::SCALE_PERCENTAGE,
        self::SCALE_GRADE_POINT,
        self::SCALE_PASS_FAIL,
        self::SCALE_OTHER,
    ];

    public static function normalizeScale(?string $scale, mixed $minimum = null): ?string
    {
        $scale = trim((string) $scale);

        if (in_array($scale, self::SCALES, true)) {
            return $scale;
        }

        if (self::hasValue($minimum)) {
            return (float) $minimum <= 5
                ? self::SCALE_GRADE_POINT
                : self::SCALE_PERCENTAGE;
        }

        return null;
    }

    public static function requiresNumeric(?string $scale): bool
    {
        return in_array($scale, [self::SCALE_PERCENTAGE, self::SCALE_GRADE_POINT], true);
    }

    public static function scaleLabel(?string $scale): string
    {
        return match ($scale) {
            self::SCALE_PERCENTAGE => 'General average / percentage',
            self::SCALE_GRADE_POINT => 'GWA / GPA grade point',
            self::SCALE_PASS_FAIL => 'Pass/fail or competency based',
            self::SCALE_OTHER => 'Other grading scale',
            default => 'No academic minimum',
        };
    }

    public static function requirementLabel(mixed $minimum, ?string $scale): string
    {
        $scale = self::normalizeScale($scale, $minimum);

        if ($scale === self::SCALE_PASS_FAIL) {
            return 'Pass/fail or competency based';
        }

        if ($scale === self::SCALE_OTHER) {
            return 'Manual academic review';
        }

        if (! self::hasValue($minimum)) {
            return 'No academic minimum';
        }

        $value = number_format((float) $minimum, 2);

        return $scale === self::SCALE_GRADE_POINT
            ? "Maximum GWA/GPA {$value}"
            : "Minimum average {$value}%";
    }

    public static function match(mixed $studentValue, ?string $studentScale, mixed $minimum, ?string $requiredScale): array
    {
        $requiredScale = self::normalizeScale($requiredScale, $minimum);

        if ($requiredScale === self::SCALE_PASS_FAIL) {
            return [
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'This program uses pass/fail or competency evidence, so documents should be reviewed instead of numeric grades.',
                'counts' => false,
            ];
        }

        if ($requiredScale === self::SCALE_OTHER) {
            return [
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'This program uses another grading scale. A reviewer should confirm the academic requirement manually.',
                'counts' => false,
            ];
        }

        if (! self::hasValue($minimum)) {
            return [
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => null,
                'note' => 'No numeric academic minimum is listed.',
                'counts' => false,
            ];
        }

        if (! self::hasValue($studentValue)) {
            return [
                'label' => 'Academic requirement',
                'status' => 'missing',
                'student_value' => null,
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'Add your academic record and grading scale in your profile to improve matching.',
                'counts' => true,
            ];
        }

        $studentScale = self::normalizeScale($studentScale, $studentValue);

        if ($studentScale !== null && $requiredScale !== null && $studentScale !== $requiredScale) {
            return [
                'label' => 'Academic requirement',
                'status' => 'missing',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'The student and scholarship use different grading scales, so this needs provider review.',
                'counts' => true,
            ];
        }

        $studentNumber = (float) $studentValue;
        $minimumNumber = (float) $minimum;
        $isPassing = $requiredScale === self::SCALE_GRADE_POINT
            ? $studentNumber <= $minimumNumber
            : $studentNumber >= $minimumNumber;

        return [
            'label' => 'Academic requirement',
            'status' => $isPassing ? 'pass' : 'fail',
            'student_value' => self::studentLabel($studentValue, $studentScale),
            'requirement' => self::requirementLabel($minimum, $requiredScale),
            'note' => $isPassing
                ? 'Academic record meets the listed requirement.'
                : 'Academic record may need provider review.',
            'counts' => true,
        ];
    }

    public static function studentLabel(mixed $studentValue, ?string $studentScale): ?string
    {
        if (! self::hasValue($studentValue)) {
            return null;
        }

        $studentScale = self::normalizeScale($studentScale, $studentValue);
        $value = number_format((float) $studentValue, 2);

        return $studentScale === self::SCALE_GRADE_POINT
            ? "{$value} GWA/GPA"
            : "{$value}%";
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
