<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('income_bracket')->nullable()->after('gwa');
            $table->string('barangay')->nullable()->after('address');
            $table->string('city')->nullable()->after('barangay');
            $table->string('province')->nullable()->after('city');
            $table->string('enrollment_status')->nullable()->after('year_level');
        });

        Schema::table('scholarships', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
            $table->text('eligible_courses')->nullable()->after('eligibility');
            $table->text('eligible_year_levels')->nullable()->after('eligible_courses');
            $table->text('eligible_locations')->nullable()->after('eligible_year_levels');
            $table->string('income_requirement')->nullable()->after('eligible_locations');
            $table->unsignedInteger('views_count')->default(0)->after('status');
        });

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->decimal('eligibility_score', 5, 2)->nullable()->after('document_checklist');
            $table->json('eligibility_breakdown')->nullable()->after('eligibility_score');
            $table->string('decision_reason')->nullable()->after('review_notes');
        });

        Schema::table('application_documents', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('size');
            $table->text('review_notes')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('decision_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['scholarship_application_id', 'changed_at'], 'application_status_history_lookup');
        });

        Schema::create('scholarship_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scholarship_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scholarship_bookmarks');
        Schema::dropIfExists('application_status_histories');

        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'review_notes', 'reviewed_at']);
        });

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn(['eligibility_score', 'eligibility_breakdown', 'decision_reason']);
        });

        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'eligible_courses',
                'eligible_year_levels',
                'eligible_locations',
                'income_requirement',
                'views_count',
            ]);
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'income_bracket',
                'barangay',
                'city',
                'province',
                'enrollment_status',
            ]);
        });
    }
};
