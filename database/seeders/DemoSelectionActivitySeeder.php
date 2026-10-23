<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoSelectionActivitySeeder extends Seeder
{
    private const PROGRAM_TITLE = 'AralBridge Scholars Pathway';

    private const SESSION_TITLES = [
        'Qualifying exam - Morning group',
        'Finalist interview - Online group',
        'Qualifying exam - Completed group',
        'Finalist interview - Completed group',
        'Qualifying exam - Proof follow-up',
    ];

    private const APPLICANTS = [
        'formal_application' => ['Noel Adrian', 'Pangan'],
        'exam_scheduled' => ['Tala Beatriz', 'Soriano'],
        'exam_result' => ['Ivo Miguel', 'Lacuesta'],
        'interview_scheduled' => ['Amihan Claire', 'Robles'],
        'interview_result' => ['Enzo Rafael', 'Marasigan'],
        'exam_failed' => ['Ethan Cole', 'Martinez'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'production'])) {
            throw new RuntimeException('The demo selection activity seeder is restricted to recognized application environments.');
        }

        if (app()->environment('production')
            && getenv('DEMO_SEED_CONFIRM') !== 'BUILD_COMPLETE_DEMONSTRATION') {
            throw new RuntimeException('Production demo selection seeding requires explicit one-time confirmation.');
        }

        $provider = User::query()
            ->where('role', 'provider')
            ->whereNull('parent_account_id')
            ->where('username', 'aralbridge')
            ->first();
        $scholarship = Scholarship::query()
            ->where('title', self::PROGRAM_TITLE)
            ->when($provider, fn ($query) => $query->where('provider_id', $provider->id))
            ->first();

        if (! $provider || ! $scholarship) {
            throw new RuntimeException('Seed the complete AralBridge demo before adding selection activities.');
        }

        $existingTitles = $scholarship->stageSessions()
            ->whereIn('title', self::SESSION_TITLES)
            ->pluck('title');
        if ($existingTitles->count() === count(self::SESSION_TITLES)) {
            $this->command?->info('Demo selection activities already exist.');

            return;
        }
        if ($existingTitles->isNotEmpty()) {
            throw new RuntimeException('The demo selection activity set is incomplete. Reset the local demo before reseeding it.');
        }

        $applications = collect();
        foreach (self::APPLICANTS as $scenario => [$firstName, $lastName]) {
            $application = ScholarshipApplication::query()
                ->where('scholarship_id', $scholarship->id)
                ->whereHas('applicant.studentProfile', fn ($query) => $query
                    ->where('first_name', $firstName)
                    ->where('last_name', $lastName))
                ->first();

            if (! $application) {
                throw new RuntimeException("The {$scenario} demo application could not be found.");
            }

            $applications->put($scenario, $application);
        }

        app(CompleteDemoSeeder::class)->seedSelectionActivities($provider, $scholarship, $applications);
        $this->command?->info('Normalized exam and interview demo activities created.');
    }
}
