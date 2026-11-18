<?php

namespace App\Support;

use App\Models\Scholarship;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecipientOnboardingPolicy
{
    public static function normalize(mixed $input): array
    {
        if ($input === null) {
            return ['version' => 1, 'final_agreement_required' => false, 'instructions' => null];
        }

        if (is_string($input)) {
            $input = strlen($input) <= 12000 ? json_decode($input, true) : null;
        }

        if (is_array($input) && is_string($input['instructions'] ?? null)) {
            $input['instructions'] = trim($input['instructions']);
        }

        $data = Validator::make(['onboarding_policy' => $input], [
            'onboarding_policy' => ['required', 'array:version,final_agreement_required,instructions'],
            'onboarding_policy.version' => ['sometimes', 'integer', Rule::in([1])],
            'onboarding_policy.final_agreement_required' => ['required', 'boolean'],
            'onboarding_policy.instructions' => [
                Rule::requiredIf(in_array(data_get($input, 'final_agreement_required'), [true, 1, '1'], true)),
                'nullable', 'string', 'max:3000',
            ],
        ])->validate()['onboarding_policy'];

        $required = (bool) $data['final_agreement_required'];

        return [
            'version' => 1,
            'final_agreement_required' => $required,
            'instructions' => $required ? $data['instructions'] : null,
        ];
    }

    public static function version(array $policy): string
    {
        return 'recipient-onboarding-v1-'.substr(hash('sha256', json_encode(self::normalize($policy), JSON_THROW_ON_ERROR)), 0, 24);
    }

    public static function assertMutable(Scholarship $program): void
    {
        if (! $program->exists || ! $program->isDirty('onboarding_policy')) {
            return;
        }

        if (self::normalize($program->getRawOriginal('onboarding_policy')) !== self::normalize($program->onboarding_policy)
            && $program->applications()->exists()) {
            throw ValidationException::withMessages([
                'onboarding_policy' => 'The final-agreement requirement and its disclosed instructions are fixed after the first application. Use a new intake to change them.',
            ]);
        }
    }
}
