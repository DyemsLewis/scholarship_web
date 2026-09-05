<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_verification_documents', function (Blueprint $table): void {
            $table->string('ocr_status')->default('not_requested')->index();
            $table->string('ocr_provider')->nullable();
            $table->decimal('ocr_grade', 5, 2)->nullable();
            $table->string('ocr_grading_scale')->nullable();
            $table->string('ocr_label')->nullable();
            $table->text('ocr_message')->nullable();
            $table->timestamp('ocr_processed_at')->nullable();
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('academic_result_source')->nullable();
            $table->timestamp('academic_result_extracted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'academic_result_source',
                'academic_result_extracted_at',
            ]);
        });

        Schema::table('applicant_verification_documents', function (Blueprint $table): void {
            $table->dropIndex(['ocr_status']);
            $table->dropColumn([
                'ocr_status',
                'ocr_provider',
                'ocr_grade',
                'ocr_grading_scale',
                'ocr_label',
                'ocr_message',
                'ocr_processed_at',
            ]);
        });
    }
};
