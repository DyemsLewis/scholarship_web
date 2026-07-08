<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('terms_version', 30)->nullable();
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->timestamp('provider_terms_accepted_at')->nullable();
            $table->string('provider_terms_version', 30)->nullable();
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 30)->nullable();
        });

        Schema::table('student_documents', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 30)->nullable();
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 30)->nullable();
        });

        Schema::table('provider_verification_documents', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_verification_documents', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });

        Schema::table('student_documents', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn(['provider_terms_accepted_at', 'provider_terms_version']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'privacy_accepted_at', 'terms_version']);
        });
    }
};
