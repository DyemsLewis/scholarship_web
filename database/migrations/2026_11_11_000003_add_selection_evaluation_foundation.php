<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('selection_scoring_policy')->nullable();
        });
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->json('selection_scoring_snapshot')->nullable();
        });
        Schema::table('application_stage_progresses', function (Blueprint $table): void {
            $table->unsignedInteger('evaluation_revision')->default(0);
        });
        Schema::create('application_stage_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->string('stage_key', 30);
            $table->unsignedInteger('revision');
            $table->unsignedInteger('stage_revision');
            $table->string('policy_fingerprint', 64);
            $table->decimal('score', 12, 4);
            $table->decimal('maximum_score', 12, 4);
            $table->decimal('percentage', 7, 4);
            $table->string('source', 40);
            $table->json('breakdown')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->unique(['scholarship_application_id', 'stage_key', 'revision'], 'stage_evaluation_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_stage_evaluations');
        Schema::table('application_stage_progresses', fn (Blueprint $table) => $table->dropColumn('evaluation_revision'));
        Schema::table('scholarship_applications', fn (Blueprint $table) => $table->dropColumn('selection_scoring_snapshot'));
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn('selection_scoring_policy'));
    }
};
