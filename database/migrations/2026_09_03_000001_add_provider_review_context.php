<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('citizenship_status', 40)->nullable()->after('account_managed_by');
            $table->string('academic_year', 20)->nullable()->after('enrollment_status');
            $table->string('academic_term', 60)->nullable()->after('academic_year');
            $table->string('current_scholarship_status', 40)->nullable()->after('support_needs');
            $table->text('current_scholarship_details')->nullable()->after('current_scholarship_status');
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('application_questions')->nullable()->after('review_rubric');
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->json('application_answers')->nullable()->after('review_rubric_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn('application_answers');
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('application_questions');
        });

        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'citizenship_status',
                'academic_year',
                'academic_term',
                'current_scholarship_status',
                'current_scholarship_details',
            ]);
        });
    }
};
