<?php

namespace App\Services;

use App\Models\Scholarship;
use App\Models\ScholarshipBenefit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScholarshipBenefitService
{
    public function normalize(array $validated, Request $request): array
    {
        if (! $request->has('benefits')) {
            return [$validated, $this->legacyBenefits($validated['award_amount'] ?? null)];
        }

        $decoded = json_decode((string) $request->input('benefits'), true);
        $validator = validator(['benefits' => $decoded], [
            'benefits' => ['array', 'max:10'],
            'benefits.*.type' => ['required', Rule::in(array_keys(ScholarshipBenefit::TYPE_LABELS))],
            'benefits.*.title' => ['nullable', 'string', 'max:150'],
            'benefits.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'benefits.*.coverage' => ['nullable', Rule::in(array_keys(ScholarshipBenefit::COVERAGE_LABELS))],
            'benefits.*.frequency' => ['nullable', Rule::in(array_keys(ScholarshipBenefit::FREQUENCY_LABELS))],
            'benefits.*.duration' => ['nullable', 'string', 'max:100'],
            'benefits.*.description' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $amountTypes = ['cash_grant', 'tuition_coverage', 'allowance', 'transportation', 'accommodation', 'other'];
        $coverageTypes = ['tuition_coverage', 'fee_waiver'];
        $benefits = collect($validator->validated()['benefits'])
            ->values()
            ->map(function (array $benefit, int $index) use ($amountTypes, $coverageTypes): array {
                $type = $benefit['type'];

                return [
                    'type' => $type,
                    'title' => filled($benefit['title'] ?? null)
                        ? trim($benefit['title'])
                        : ScholarshipBenefit::TYPE_LABELS[$type],
                    'amount' => in_array($type, $amountTypes, true) && filled($benefit['amount'] ?? null)
                        ? (float) $benefit['amount']
                        : null,
                    'coverage' => in_array($type, $coverageTypes, true)
                        ? ($benefit['coverage'] ?? null)
                        : null,
                    'frequency' => $benefit['frequency'] ?? null,
                    'duration' => filled($benefit['duration'] ?? null)
                        ? trim($benefit['duration'])
                        : null,
                    'description' => filled($benefit['description'] ?? null)
                        ? trim($benefit['description'])
                        : null,
                    'sort_order' => $index,
                ];
            })
            ->all();

        $cashGrant = collect($benefits)->first(
            fn (array $benefit): bool => $benefit['type'] === 'cash_grant' && $benefit['amount'] !== null
        );
        $validated['award_amount'] = $cashGrant['amount'] ?? null;
        unset($validated['benefits']);

        return [$validated, $benefits];
    }

    public function sync(Scholarship $scholarship, ?array $benefits): void
    {
        if ($benefits === null) {
            if (! $scholarship->benefits()->exists() && $scholarship->award_amount !== null) {
                $scholarship->benefits()->create($this->legacyBenefits($scholarship->award_amount)[0]);
            }

            return;
        }

        $scholarship->benefits()->delete();

        if ($benefits !== []) {
            $scholarship->benefits()->createMany($benefits);
        }

        $scholarship->unsetRelation('benefits');
    }

    public function copy(Scholarship $source, Scholarship $target): void
    {
        $source->loadMissing('benefits');
        $benefits = $source->benefits->map(
            fn (ScholarshipBenefit $benefit): array => [
                'type' => $benefit->type,
                'title' => $benefit->title,
                'amount' => $benefit->amount,
                'coverage' => $benefit->coverage,
                'frequency' => $benefit->frequency,
                'duration' => $benefit->duration,
                'description' => $benefit->description,
                'sort_order' => $benefit->sort_order,
            ]
        )->all();

        $target->benefits()->createMany(
            $benefits !== [] ? $benefits : ($this->legacyBenefits($source->award_amount) ?? [])
        );
    }

    public function changed(Scholarship $scholarship, array $benefits): bool
    {
        $scholarship->loadMissing('benefits');
        $current = $scholarship->benefits->map(
            fn (ScholarshipBenefit $benefit): array => $this->comparable($benefit->toArray())
        )->values()->all();
        $incoming = collect($benefits)->map(
            fn (array $benefit): array => $this->comparable($benefit)
        )->values()->all();

        return $current !== $incoming;
    }

    private function legacyBenefits(mixed $amount): ?array
    {
        if (! filled($amount)) {
            return null;
        }

        return [[
            'type' => 'cash_grant',
            'title' => 'Cash grant',
            'amount' => (float) $amount,
            'coverage' => null,
            'frequency' => 'one_time',
            'duration' => null,
            'description' => null,
            'sort_order' => 0,
        ]];
    }

    private function comparable(array $benefit): array
    {
        return [
            'type' => $benefit['type'],
            'title' => $benefit['title'] ?? null,
            'amount' => filled($benefit['amount'] ?? null)
                ? number_format((float) $benefit['amount'], 2, '.', '')
                : null,
            'coverage' => $benefit['coverage'] ?? null,
            'frequency' => $benefit['frequency'] ?? null,
            'duration' => $benefit['duration'] ?? null,
            'description' => $benefit['description'] ?? null,
        ];
    }
}
