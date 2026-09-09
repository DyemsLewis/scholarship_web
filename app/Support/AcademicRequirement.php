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

    /**
     * Broad reference ranges compiled from commonly used Philippine grading tables.
     * Ranges intentionally overlap because institutions do not use one official conversion.
     */
    private const GRADE_POINT_REFERENCE_RANGES = [
        ['grade_point' => 1.00, 'minimum_percentage' => 96, 'maximum_percentage' => 100],
        ['grade_point' => 1.25, 'minimum_percentage' => 93, 'maximum_percentage' => 98],
        ['grade_point' => 1.50, 'minimum_percentage' => 90, 'maximum_percentage' => 95],
        ['grade_point' => 1.75, 'minimum_percentage' => 87, 'maximum_percentage' => 92],
        ['grade_point' => 2.00, 'minimum_percentage' => 84, 'maximum_percentage' => 89],
        ['grade_point' => 2.25, 'minimum_percentage' => 81, 'maximum_percentage' => 86],
        ['grade_point' => 2.50, 'minimum_percentage' => 78, 'maximum_percentage' => 83],
        ['grade_point' => 2.75, 'minimum_percentage' => 75, 'maximum_percentage' => 80],
        ['grade_point' => 3.00, 'minimum_percentage' => 75, 'maximum_percentage' => 77],
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
        $studentScale = self::normalizeScale($studentScale, $studentValue);
        $withComparison = fn (array $result, string $mode, bool $isComparable = false): array => [
            ...$result,
            'student_scale' => $studentScale,
            'requirement_scale' => $requiredScale,
            'comparison_mode' => $mode,
            'is_comparable' => $isComparable,
        ];

        if ($requiredScale === self::SCALE_PASS_FAIL) {
            return $withComparison([
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'This program uses pass/fail or competency evidence, so documents should be reviewed instead of numeric grades.',
                'counts' => false,
            ], 'manual_review');
        }

        if ($requiredScale === self::SCALE_OTHER) {
            return $withComparison([
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'This program uses another grading scale. A reviewer should confirm the academic requirement manually.',
                'counts' => false,
            ], 'manual_review');
        }

        if (! self::hasValue($minimum)) {
            return $withComparison([
                'label' => 'Academic requirement',
                'status' => 'info',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => null,
                'note' => 'No numeric academic minimum is listed.',
                'counts' => false,
            ], 'not_required');
        }

        if (! self::hasValue($studentValue)) {
            return $withComparison([
                'label' => 'Academic requirement',
                'status' => 'missing',
                'student_value' => null,
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'Add your academic record and grading scale in your profile to improve matching.',
                'counts' => true,
            ], 'missing_value');
        }

        if ($studentScale !== null && $requiredScale !== null && $studentScale !== $requiredScale) {
            return $withComparison([
                'label' => 'Academic requirement',
                'status' => 'missing',
                'student_value' => self::studentLabel($studentValue, $studentScale),
                'requirement' => self::requirementLabel($minimum, $requiredScale),
                'note' => 'The student and scholarship use different grading scales. Use the reference equivalents below, then confirm the school record before deciding.',
                'counts' => true,
                'equivalence' => self::referenceEquivalence(
                    (float) $studentValue,
                    $studentScale,
                    (float) $minimum,
                    $requiredScale,
                ),
            ], 'scale_mismatch');
        }

        $studentNumber = (float) $studentValue;
        $minimumNumber = (float) $minimum;
        $isPassing = $requiredScale === self::SCALE_GRADE_POINT
            ? $studentNumber <= $minimumNumber
            : $studentNumber >= $minimumNumber;

        return $withComparison([
            'label' => 'Academic requirement',
            'status' => $isPassing ? 'pass' : 'fail',
            'student_value' => self::studentLabel($studentValue, $studentScale),
            'requirement' => self::requirementLabel($minimum, $requiredScale),
            'note' => $isPassing
                ? 'Academic record meets the listed requirement.'
                : 'Academic record may need provider review.',
            'counts' => true,
        ], 'same_scale_numeric', true);
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

    public static function withReferenceEquivalence(?array $breakdown): ?array
    {
        if ($breakdown === null || ! is_array($breakdown['criteria'] ?? null)) {
            return $breakdown;
        }

        $breakdown['criteria'] = array_map(function (array $criterion): array {
            if (($criterion['comparison_mode'] ?? null) !== 'scale_mismatch'
                || ! empty($criterion['equivalence'])) {
                return $criterion;
            }

            $studentValue = self::firstNumericValue($criterion['student_value'] ?? null);
            $minimum = self::firstNumericValue($criterion['requirement'] ?? null);
            $studentScale = $criterion['student_scale'] ?? null;
            $requiredScale = $criterion['requirement_scale'] ?? null;

            if ($studentValue === null || $minimum === null || ! is_string($studentScale) || ! is_string($requiredScale)) {
                return $criterion;
            }

            $criterion['equivalence'] = self::referenceEquivalence(
                $studentValue,
                $studentScale,
                $minimum,
                $requiredScale,
            );

            return $criterion;
        }, $breakdown['criteria']);

        return $breakdown;
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    public static function referenceEquivalence(
        float $studentValue,
        string $studentScale,
        float $minimum,
        string $requiredScale,
    ): ?array {
        $applicantEquivalent = self::equivalentLabel($studentValue, $studentScale, $requiredScale);
        $requirementEquivalent = self::equivalentLabel($minimum, $requiredScale, $studentScale);

        if ($applicantEquivalent === null && $requirementEquivalent === null) {
            return null;
        }

        return [
            'applicant' => $applicantEquivalent,
            'requirement' => $requirementEquivalent,
            'notice' => 'Reference estimate only. Grading conversions vary by school; confirm the uploaded academic record before making a decision.',
        ];
    }

    private static function firstNumericValue(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value) || preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    private static function equivalentLabel(float $value, string $fromScale, string $toScale): ?string
    {
        if ($fromScale === self::SCALE_PERCENTAGE && $toScale === self::SCALE_GRADE_POINT) {
            $matches = array_values(array_filter(
                self::GRADE_POINT_REFERENCE_RANGES,
                fn (array $range): bool => $value >= $range['minimum_percentage']
                    && $value <= $range['maximum_percentage'],
            ));

            if ($matches === []) {
                return null;
            }

            $gradePoints = array_column($matches, 'grade_point');

            return self::numberRangeLabel(min($gradePoints), max($gradePoints), ' GWA/GPA');
        }

        if ($fromScale === self::SCALE_GRADE_POINT && $toScale === self::SCALE_PERCENTAGE) {
            $closest = collect(self::GRADE_POINT_REFERENCE_RANGES)
                ->sortBy(fn (array $range): float => abs($range['grade_point'] - $value))
                ->first();

            if ($closest === null || abs($closest['grade_point'] - $value) > 0.13) {
                return null;
            }

            return self::numberRangeLabel(
                $closest['minimum_percentage'],
                $closest['maximum_percentage'],
                '%',
                0,
            );
        }

        return null;
    }

    private static function numberRangeLabel(
        float $minimum,
        float $maximum,
        string $suffix,
        int $decimals = 2,
    ): string {
        $minimumLabel = number_format($minimum, $decimals);
        $maximumLabel = number_format($maximum, $decimals);

        if ($minimum === $maximum) {
            return "Approx. {$minimumLabel}{$suffix}";
        }

        return "Approx. {$minimumLabel}-{$maximumLabel}{$suffix}";
    }
}
