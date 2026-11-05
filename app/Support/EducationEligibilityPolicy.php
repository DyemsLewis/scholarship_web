<?php

namespace App\Support;

use App\Models\StudentProfile;

class EducationEligibilityPolicy
{
    public const ACTIVE_ENROLLMENT = ['Enrolled', 'Continuing student', 'Graduating'];

    public const ENROLLMENT_STATUSES = [
        'Enrolled', 'Incoming student', 'Continuing student', 'Graduating',
        'Not currently enrolled', 'Transfer enrollment pending', 'Returning enrollment pending',
    ];

    public const PENDING_STUDIES = ['Incoming student', 'Transfer enrollment pending', 'Returning enrollment pending', 'Not currently enrolled'];

    public static function normalize(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $qualifications = array_values(array_diff(array_keys(CompletedQualification::OPTIONS), ['none']));
        $required = (bool) ($value['requires_completed_qualification'] ?? false);

        return [
            'requires_completed_qualification' => $required,
            'accepted_qualifications' => $required ? array_values(array_intersect($qualifications, (array) ($value['accepted_qualifications'] ?? []))) : [],
            'unlisted_qualification' => $required && ($value['unlisted_qualification'] ?? null) === 'not_accepted' ? 'not_accepted' : 'review',
            'enrollment_statuses' => array_values(array_intersect(self::ENROLLMENT_STATUSES, (array) ($value['enrollment_statuses'] ?? []))),
        ];
    }

    public static function configured(mixed $value): bool
    {
        $policy = self::normalize($value);

        return $policy['requires_completed_qualification'] || $policy['enrollment_statuses'] !== [];
    }

    public static function qualification(array $policy, ?StudentProfile $profile): ?array
    {
        if (! $policy['requires_completed_qualification']) {
            return null;
        }
        $value = $profile?->completed_qualification;
        $known = isset(CompletedQualification::OPTIONS[$value ?? '']);
        $accepted = $known && $value !== 'other' && in_array($value, $policy['accepted_qualifications'], true);
        $review = ! $known || $value === 'other' || ($value !== 'none' && ! $accepted && $policy['unlisted_qualification'] === 'review');

        return [
            'key' => 'completed_qualification', 'label' => 'Completed qualification',
            'status' => $accepted ? 'pass' : ($review ? 'missing' : 'fail'),
            'student_value' => $known ? CompletedQualification::OPTIONS[$value] : ($value ?: null),
            'requirement' => collect($policy['accepted_qualifications'])->map(fn (string $type): string => CompletedQualification::OPTIONS[$type])->implode('; '),
            'note' => $accepted
                ? 'Your declared completed qualification is explicitly listed by the provider. This does not change your current study level or grant a different qualification.'
                : ($review ? 'The completed qualification needs provider review. Do not claim Senior High School, a degree, or an equivalency you have not completed.'
                    : ($value === 'none' ? 'This program requires a completed qualification, but your profile says none has been completed.' : 'The provider accepts only the listed completed qualifications. Your declared qualification is not included.')),
            'comparison_mode' => $review ? 'manual_review' : 'explicit_qualification_list',
            'rule_context' => ['accepted_qualifications' => $policy['accepted_qualifications'], 'unlisted_qualification' => $policy['unlisted_qualification']],
        ];
    }

    public static function enrollment(array $policy, bool $requiresActiveEnrollment, ?StudentProfile $profile): ?array
    {
        $accepted = $policy['enrollment_statuses'] ?: ($requiresActiveEnrollment ? self::ACTIVE_ENROLLMENT : []);
        if ($accepted === []) {
            return null;
        }
        $value = $profile?->enrollment_status;
        $normalized = fn (?string $status): string => strtolower(trim((string) $status));
        $matches = filled($value) && in_array($normalized($value), array_map($normalized, $accepted), true);
        $unknown = filled($value) && ! in_array($normalized($value), array_map($normalized, self::ENROLLMENT_STATUSES), true)
            && ! in_array($normalized($value), ['graduated', 'not enrolled'], true);

        return [
            'key' => 'enrollment_status', 'label' => 'Enrollment status',
            'status' => blank($value) || $unknown ? 'missing' : ($matches ? 'pass' : 'fail'),
            'student_value' => $value, 'requirement' => implode(', ', $accepted),
            'note' => $unknown ? 'The saved enrollment description cannot be checked automatically. The provider must review the enrollment or admission record.'
                : ($matches ? 'Your declared enrollment status is accepted by this program.' : 'This program requires one of the listed enrollment statuses. Pending admission is not treated as active enrollment.'),
            'comparison_mode' => $unknown ? 'manual_review' : null,
            'rule_context' => $policy['enrollment_statuses'] !== [] ? ['accepted_enrollment_statuses' => $accepted] : null,
        ];
    }

    public static function contextualize(array $criterion, ?StudentProfile $profile): array
    {
        if (! in_array($criterion['key'], ['education_level', 'course', 'year_level'], true)
            || ! in_array($criterion['status'], ['missing', 'fail'], true)) {
            return $criterion;
        }
        $level = preg_replace('/\s+/', ' ', str_replace(['_', '/', '-'], ' ', strtolower(trim((string) $profile?->education_level))));
        $knownLevels = ['preschool', 'preschool kindergarten', 'elementary', 'junior high school', 'senior high school',
            'college', 'college university', 'university', 'tvet', 'tvet vocational', 'vocational', 'als', 'als alternative learning'];
        $unfamiliar = $level !== '' && ! in_array($level, $knownLevels, true);
        $transition = in_array($profile?->enrollment_status, self::PENDING_STUDIES, true);
        $needsReview = $criterion['key'] === 'education_level' ? $unfamiliar
            : ($unfamiliar || ($transition && $criterion['status'] === 'missing'));
        if (! $needsReview) {
            return $criterion;
        }

        return [...$criterion, 'status' => 'missing', 'comparison_mode' => 'manual_review',
            'note' => 'Your current or most recent study information needs provider review. Completed qualifications are checked separately; do not invent a strand, grade, course, or enrollment status.'];
    }
}
