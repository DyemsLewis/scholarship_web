<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->json('provider_objectives')->nullable()->after('description');
            $table->text('provider_objective_notes')->nullable()->after('provider_objectives');
        });

        DB::table('scholarships')
            ->whereIn('title', [
                'Tulay Aral Senior High Support Grant',
                'Tulay Aral College Starter Grant',
            ])
            ->update([
                'provider_objectives' => json_encode(['education_access', 'community_development', 'equity_inclusion']),
                'provider_objective_notes' => 'The program helps qualified learners continue their education while strengthening educational participation in the communities served by the foundation.',
            ]);

        DB::table('scholarships')
            ->where('title', 'Bukas Kinabukasan School Essentials Grant')
            ->update([
                'provider_objectives' => json_encode(['education_access', 'community_development', 'equity_inclusion']),
                'provider_objective_notes' => 'The program reduces practical learning barriers by providing essential school materials and shared learning resources to younger learners.',
            ]);

        DB::table('scholarships')
            ->where('title', 'Bukas Kinabukasan STEM Pathways Grant')
            ->update([
                'provider_objectives' => json_encode(['education_access', 'priority_skills', 'future_talent', 'academic_excellence']),
                'provider_objective_notes' => 'The program supports STEM learners and helps build a future pool of students prepared for science, engineering, computing, and technology pathways.',
            ]);
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn(['provider_objectives', 'provider_objective_notes']);
        });
    }
};
