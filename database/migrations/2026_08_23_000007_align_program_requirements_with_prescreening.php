<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sensitiveRequirements = [
            'birth certificate',
            'government-issued id',
            'parent or guardian valid id',
            'recent 2x2 id photo',
        ];
        $genericPostQualificationRequirements = [
            'Original copies of submitted school records',
            'Valid school or government ID',
            'Provider-specific documents requested after qualification',
        ];
        $genericInstructions = 'The provider will contact qualified applicants with the formal application schedule and confirm which original documents to bring.';

        DB::table('scholarships')
            ->orderBy('id')
            ->get()
            ->each(function (object $program) use ($sensitiveRequirements, $genericPostQualificationRequirements, $genericInstructions): void {
                $required = $this->lines($program->requirements);
                $optional = $this->lines($program->optional_requirements);
                $moved = collect([...$required, ...$optional])
                    ->filter(fn (string $item): bool => in_array(strtolower($item), $sensitiveRequirements, true))
                    ->values()
                    ->all();
                $required = collect($required)
                    ->reject(fn (string $item): bool => in_array(strtolower($item), $sensitiveRequirements, true))
                    ->values()
                    ->all();
                $optional = collect($optional)
                    ->reject(fn (string $item): bool => in_array(strtolower($item), $sensitiveRequirements, true))
                    ->values()
                    ->all();
                $postQualification = $this->lines($program->post_qualification_requirements);

                if ($postQualification === $genericPostQualificationRequirements) {
                    $postQualification = [
                        'Original copies of portal pre-screening documents',
                        'Provider formal application form or other provider-specific documents',
                    ];
                }

                $postQualification = collect([...$postQualification, ...$moved])
                    ->unique(fn (string $item): string => strtolower($item))
                    ->values()
                    ->all();
                $handoffMode = filled($program->location_address) ? 'onsite' : ($program->handoff_mode ?: 'provider_contact');
                $handoffDeadline = $program->handoff_deadline;

                if (blank($handoffDeadline) && filled($program->deadline)) {
                    $handoffDeadline = Carbon::parse($program->deadline)->addDays(14)->toDateString();
                }

                DB::table('scholarships')->where('id', $program->id)->update([
                    'requirements' => $this->joined($required),
                    'optional_requirements' => $this->joined($optional),
                    'post_qualification_requirements' => $this->joined($postQualification),
                    'handoff_mode' => $handoffMode,
                    'handoff_instructions' => $program->handoff_instructions === $genericInstructions
                        ? 'If you pass portal pre-screening, follow the provider instructions and bring the listed original documents. The provider will verify them and explain its formal application process. Portal qualification is not a final scholarship award.'
                        : $program->handoff_instructions,
                    'handoff_deadline' => $handoffDeadline,
                    'handoff_location_name' => $program->handoff_location_name ?: $program->location_name,
                    'handoff_location_address' => $program->handoff_location_address ?: $program->location_address,
                    'updated_at' => now(),
                ]);
            });

        $this->updateDemoPrograms();
    }

    public function down(): void
    {
        // Do not move identity documents back into portal pre-screening requirements.
    }

    private function updateDemoPrograms(): void
    {
        $programs = [
            'Tulay Aral Senior High Support Grant' => [
                'requirements' => [
                    'Certificate of enrollment',
                    'Latest report card or grades',
                    'School ID',
                    'Proof of income',
                ],
                'post_qualification_requirements' => [
                    'Original certificate of enrollment',
                    'Original latest report card',
                    'Valid school ID',
                    'Provider formal application form',
                ],
            ],
            'Tulay Aral College Starter Grant' => [
                'requirements' => [
                    'Admission or acceptance letter',
                    'Latest report card or grades',
                    'Proof of income',
                    'Recommendation letter',
                ],
                'post_qualification_requirements' => [
                    'Original proof of college admission or enrollment',
                    'Original latest report card',
                    'Valid school or government ID',
                    'Provider formal application form',
                ],
            ],
            'Bukas Kinabukasan School Essentials Grant' => [
                'requirements' => [
                    'Certificate of enrollment',
                    'Latest report card or grades',
                ],
                'post_qualification_requirements' => [
                    'Original learner enrollment record',
                    'Learner school ID when available',
                    'Parent or guardian valid ID',
                    'Provider formal application form signed by the guardian',
                ],
            ],
            'Bukas Kinabukasan STEM Pathways Grant' => [
                'requirements' => [
                    'Certificate of enrollment',
                    'Latest report card or grades',
                    'School ID',
                    'Recommendation letter',
                ],
                'post_qualification_requirements' => [
                    'Original certificate of enrollment',
                    'Original latest report card',
                    'Valid school ID',
                    'Provider formal application form',
                ],
            ],
        ];

        foreach ($programs as $title => $requirements) {
            DB::table('scholarships')->where('title', $title)->update([
                'requirements' => $this->joined($requirements['requirements']),
                'post_qualification_requirements' => $this->joined($requirements['post_qualification_requirements']),
                'handoff_instructions' => 'If you pass portal pre-screening, bring the listed original documents to the provider. The provider will verify them and explain its formal application process. Portal qualification is not a final scholarship award.',
                'updated_at' => now(),
            ]);
        }
    }

    private function lines(?string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function joined(array $items): ?string
    {
        return $items === [] ? null : implode("\n", $items);
    }
};
