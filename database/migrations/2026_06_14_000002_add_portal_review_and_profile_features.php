<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('school')->nullable()->after('contact_number');
            $table->string('course_or_strand')->nullable()->after('school');
            $table->string('year_level')->nullable()->after('course_or_strand');
            $table->decimal('gwa', 5, 2)->nullable()->after('year_level');
            $table->string('address', 500)->nullable()->after('gwa');
            $table->date('birthdate')->nullable()->after('address');
            $table->string('guardian_name')->nullable()->after('birthdate');
            $table->string('guardian_contact', 30)->nullable()->after('guardian_name');
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->string('verification_status')->default('pending')->after('provider_description');
            $table->text('verification_notes')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_notes');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
        });

        DB::table('provider_profiles')->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->text('review_notes')->nullable()->after('notes');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('document_name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['scholarship_application_id', 'document_name'], 'application_documents_name_unique');
        });

        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_notifications');
        Schema::dropIfExists('application_documents');

        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_notes', 'reviewed_at']);
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verification_status', 'verification_notes', 'verified_at']);
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'school',
                'course_or_strand',
                'year_level',
                'gwa',
                'address',
                'birthdate',
                'guardian_name',
                'guardian_contact',
            ]);
        });
    }
};
