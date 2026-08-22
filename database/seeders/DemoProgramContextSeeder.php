<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use Illuminate\Database\Seeder;

class DemoProgramContextSeeder extends Seeder
{
    /**
     * Add applicant-facing context to existing demo programs without resetting workflow data.
     */
    public function run(): void
    {
        $programs = [
            'Tulay Aral Senior High Support Grant' => [
                'provider_email' => 'tulayaral@scholarship.test',
                'results_offset_days' => 12,
                'contact_person' => 'Mara L. Reyes',
                'contact_department' => 'Community Scholarship Desk',
                'benefit_durations' => [
                    'School support grant' => 'Current program cycle',
                    'School supplies support' => 'Current program cycle',
                ],
            ],
            'Tulay Aral College Starter Grant' => [
                'provider_email' => 'tulayaral@scholarship.test',
                'results_offset_days' => 16,
                'contact_person' => 'Mara L. Reyes',
                'contact_department' => 'Community Scholarship Desk',
                'benefit_durations' => [
                    'College starter grant' => 'College entry period',
                    'College transition mentoring' => 'College entry period',
                ],
            ],
            'Bukas Kinabukasan School Essentials Grant' => [
                'provider_email' => 'bukasfoundation@scholarship.test',
                'results_offset_days' => 10,
                'contact_person' => 'Paolo C. Mendoza',
                'contact_department' => 'Learner Support Office',
                'benefit_durations' => [
                    'School essentials package' => 'Current school year',
                    'Shared learning-device access' => 'Current school year',
                ],
            ],
            'Bukas Kinabukasan STEM Pathways Grant' => [
                'provider_email' => 'bukasfoundation@scholarship.test',
                'results_offset_days' => 21,
                'contact_person' => 'Paolo C. Mendoza',
                'contact_department' => 'STEM Programs Office',
                'benefit_durations' => [
                    'STEM learning grant' => 'Current program cycle',
                    'STEM enrichment session' => 'Current program cycle',
                    'STEM pathway mentoring' => 'Current school year',
                ],
            ],
        ];

        foreach ($programs as $title => $context) {
            $scholarship = Scholarship::query()
                ->where('title', $title)
                ->whereHas('provider', fn ($query) => $query->where('email', $context['provider_email']))
                ->first();

            if (! $scholarship) {
                continue;
            }

            $deadline = $scholarship->deadline?->copy() ?? now()->addDays(60)->startOfDay();
            $contextValues = [
                'program_cycle' => 'School Year '.$deadline->year.'-'.($deadline->year + 1),
                'application_opens_at' => $deadline->copy()->subDays(60)->toDateString(),
                'expected_results_at' => $deadline->copy()->addDays($context['results_offset_days'])->toDateString(),
                'contact_person' => $context['contact_person'],
                'contact_department' => $context['contact_department'],
            ];

            foreach ($contextValues as $field => $value) {
                if (blank($scholarship->{$field})) {
                    $scholarship->{$field} = $value;
                }
            }

            if ($scholarship->isDirty()) {
                $scholarship->save();
            }

            foreach ($context['benefit_durations'] as $benefitTitle => $duration) {
                $benefit = $scholarship->benefits()->where('title', $benefitTitle)->first();

                if ($benefit && blank($benefit->duration)) {
                    $benefit->update(['duration' => $duration]);
                }
            }
        }
    }
}
