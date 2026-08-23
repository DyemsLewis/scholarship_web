<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $methods = [
            'Tulay Aral Senior High Support Grant' => 'online',
            'Tulay Aral College Starter Grant' => 'onsite',
            'Bukas Kinabukasan School Essentials Grant' => 'provider_review',
            'Bukas Kinabukasan STEM Pathways Grant' => 'onsite',
        ];

        foreach ($methods as $title => $method) {
            DB::table('scholarships')
                ->where('title', $title)
                ->update(['application_mode' => $method]);
        }
    }

    public function down(): void
    {
        DB::table('scholarships')
            ->whereIn('title', [
                'Tulay Aral Senior High Support Grant',
                'Tulay Aral College Starter Grant',
                'Bukas Kinabukasan School Essentials Grant',
                'Bukas Kinabukasan STEM Pathways Grant',
            ])
            ->update(['application_mode' => 'online']);
    }
};
