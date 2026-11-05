<?php

namespace App\Support;

use App\Models\StudentProfile;
use Illuminate\Validation\Rule;

class CompletedQualification
{
    public const OPTIONS = [
        'none' => 'No completed qualification yet',
        'elementary' => 'Elementary completion',
        'junior_high_school' => 'Junior High School completion',
        'senior_high_school' => 'Senior High School completion',
        'pre_k12_high_school' => 'High school diploma (old curriculum / before K-12)',
        'als_secondary' => 'ALS secondary completion / equivalency',
        'tvet' => 'TVET / vocational qualification',
        'college' => 'College degree',
        'postgraduate' => 'Postgraduate qualification',
        'other' => 'Other completed qualification',
    ];

    public const FIELDS = [
        'completed_qualification', 'completed_qualification_school',
        'completed_qualification_year', 'completed_qualification_details',
    ];

    public static function requiresEvidence(?StudentProfile $profile): bool
    {
        return isset(self::OPTIONS[$profile?->completed_qualification ?? ''])
            && $profile->completed_qualification !== 'none';
    }

    public static function input(array $input, ?StudentProfile $profile): array
    {
        if (array_intersect(array_keys($input), self::FIELDS) === []) {
            return [];
        }

        $values = collect(self::FIELDS)->mapWithKeys(function (string $field) use ($input, $profile): array {
            $value = array_key_exists($field, $input) ? $input[$field] : $profile?->getAttribute($field);

            return [$field => is_string($value) ? (trim($value) !== '' ? trim($value) : null) : $value];
        })->all();

        if (in_array($values['completed_qualification'], [null, 'none'], true)) {
            foreach (array_slice(self::FIELDS, 1) as $field) {
                $values[$field] = null;
            }
        } elseif ($values['completed_qualification'] !== 'other') {
            $values['completed_qualification_details'] = null;
        }

        return $values;
    }

    public static function rules(array $input): array
    {
        $declared = filled($input['completed_qualification'] ?? null)
            && $input['completed_qualification'] !== 'none';

        return [
            'completed_qualification' => ['nullable', 'string', Rule::in(array_keys(self::OPTIONS))],
            'completed_qualification_school' => [Rule::requiredIf($declared), 'nullable', 'string', 'max:255'],
            'completed_qualification_year' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'completed_qualification_details' => [Rule::requiredIf(($input['completed_qualification'] ?? null) === 'other'), 'nullable', 'string', 'max:255'],
        ];
    }
}
