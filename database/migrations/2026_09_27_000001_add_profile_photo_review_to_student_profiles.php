<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('profile_photo_review_status', 32)->nullable()->after('profile_photo_updated_at');
            $table->text('profile_photo_review_note')->nullable()->after('profile_photo_review_status');
            $table->foreignId('profile_photo_reviewed_by')->nullable()->after('profile_photo_review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('profile_photo_reviewed_at')->nullable()->after('profile_photo_reviewed_by');
            $table->foreignId('profile_photo_review_application_id')->nullable()->after('profile_photo_reviewed_at')->constrained('scholarship_applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_photo_review_application_id');
            $table->dropConstrainedForeignId('profile_photo_reviewed_by');
            $table->dropColumn([
                'profile_photo_review_status',
                'profile_photo_review_note',
                'profile_photo_reviewed_at',
            ]);
        });
    }
};
