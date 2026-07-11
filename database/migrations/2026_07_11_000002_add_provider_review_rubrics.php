<?php

use App\Support\ReviewRubric;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('review_rubric')->nullable()->after('requirements');
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->json('review_rubric_snapshot')->nullable()->after('eligibility_breakdown');
            $table->json('rubric_scores')->nullable()->after('review_rubric_snapshot');
            $table->decimal('rubric_total_score', 5, 2)->nullable()->after('rubric_scores');
            $table->foreignId('rubric_scored_by')->nullable()->after('rubric_total_score')->constrained('users')->nullOnDelete();
            $table->timestamp('rubric_scored_at')->nullable()->after('rubric_scored_by');
        });

        $defaultRubric = json_encode(ReviewRubric::DEFAULT, JSON_THROW_ON_ERROR);
        DB::table('scholarships')->whereNull('review_rubric')->update(['review_rubric' => $defaultRubric]);

        DB::table('scholarship_applications')
            ->select(['id', 'scholarship_id'])
            ->whereNull('review_rubric_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    $rubric = DB::table('scholarships')
                        ->where('id', $application->scholarship_id)
                        ->value('review_rubric');

                    DB::table('scholarship_applications')
                        ->where('id', $application->id)
                        ->update(['review_rubric_snapshot' => $rubric]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rubric_scored_by');
            $table->dropColumn([
                'review_rubric_snapshot',
                'rubric_scores',
                'rubric_total_score',
                'rubric_scored_at',
            ]);
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('review_rubric');
        });
    }
};
