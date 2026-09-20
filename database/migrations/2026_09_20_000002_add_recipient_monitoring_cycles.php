<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('period_type')->default('semester');
            $table->string('academic_period')->nullable();
            $table->string('school_year')->nullable();
            $table->date('opens_at')->nullable();
            $table->date('due_at');
            $table->decimal('minimum_grade', 5, 2);
            $table->string('grading_scale')->default('percentage');
            $table->text('instructions')->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['scholarship_id', 'due_at']);
        });

        Schema::create('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('ocr_status')->default('not_requested')->index();
            $table->string('ocr_provider')->nullable();
            $table->decimal('ocr_grade', 5, 2)->nullable();
            $table->string('ocr_grading_scale')->nullable();
            $table->string('ocr_label')->nullable();
            $table->text('ocr_message')->nullable();
            $table->timestamp('ocr_processed_at')->nullable();
            $table->decimal('reported_grade', 5, 2)->nullable();
            $table->string('reported_grading_scale')->nullable();
            $table->string('grade_source')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recipient_monitoring_cycle_id', 'scholarship_application_id'],
                'recipient_monitoring_submission_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_submissions');
        Schema::dropIfExists('recipient_monitoring_cycles');
    }
};
