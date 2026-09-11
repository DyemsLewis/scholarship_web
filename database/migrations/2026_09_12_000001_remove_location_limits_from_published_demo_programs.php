<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $eligibilityByTitle = [
            'Tulay Aral Senior High Support Grant' => 'Currently enrolled senior high school learner with a general average of at least 80% and a household income within the listed bracket.',
            'Tulay Aral College Starter Grant' => 'Incoming first-year college learner with proof of admission, a general average of at least 85%, and availability for a short finalist interview.',
            'Bukas Kinabukasan School Essentials Grant' => 'Currently enrolled elementary or junior high school learner whose parent or guardian can complete the application.',
            'Bukas Kinabukasan STEM Pathways Grant' => 'Grade 11 or Grade 12 STEM learner with at least an 85% general average and availability for a qualifying exam and finalist interview.',
        ];

        foreach ($eligibilityByTitle as $title => $eligibility) {
            DB::table('scholarships')
                ->where('title', $title)
                ->where('status', 'published')
                ->update([
                    'eligible_locations' => null,
                    'eligibility' => $eligibility,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Location limits are intentionally not restored because they may exclude applicants.
    }
};
