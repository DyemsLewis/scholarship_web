<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->unsignedSmallInteger('exam_duration_minutes')->nullable();
            $table->decimal('exam_passing_score', 5, 2)->nullable();
        });

        if (! Schema::hasTable('provider_assessments')) {
            return;
        }

        $assessments = DB::table('provider_assessments')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get()
            ->unique('provider_id')
            ->keyBy('provider_id');

        DB::table('scholarships')
            ->select(['id', 'provider_id', 'selection_stages'])
            ->orderBy('id')
            ->chunkById(100, function ($scholarships) use ($assessments): void {
                foreach ($scholarships as $scholarship) {
                    $selectionStages = json_decode((string) $scholarship->selection_stages, true);
                    $assessment = $assessments->get($scholarship->provider_id);

                    if (! is_array($selectionStages) || ! in_array('exam', $selectionStages, true) || ! $assessment) {
                        continue;
                    }

                    DB::table('scholarships')
                        ->where('id', $scholarship->id)
                        ->update([
                            'exam_duration_minutes' => $assessment->duration_minutes,
                            'exam_passing_score' => $assessment->passing_score,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn(['exam_duration_minutes', 'exam_passing_score']);
        });
    }
};
