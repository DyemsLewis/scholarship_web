<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_funnel_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('source')->default('web');
            $table->json('metadata')->nullable();
            $table->string('deduplication_key')->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['scholarship_id', 'event_type']);
            $table->index(['scholarship_application_id', 'occurred_at'], 'funnel_application_occurred_index');
            $table->index(['user_id', 'event_type']);
        });

        Schema::create('dss_calculation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->string('methodology_version');
            $table->string('input_hash', 64);
            $table->string('source')->default('system');
            $table->decimal('eligibility_score', 5, 2)->nullable();
            $table->decimal('suitability_score', 5, 2)->nullable();
            $table->string('recommendation')->nullable();
            $table->json('eligibility_breakdown')->nullable();
            $table->json('dss_breakdown')->nullable();
            $table->json('applicant_inputs');
            $table->json('scholarship_inputs');
            $table->json('academic_evaluation')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['scholarship_application_id', 'input_hash'], 'dss_snapshot_application_input_unique');
            $table->index(['scholarship_id', 'calculated_at']);
            $table->index(['applicant_id', 'calculated_at']);
            $table->index(['methodology_version', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dss_calculation_snapshots');
        Schema::dropIfExists('scholarship_funnel_events');
    }
};
