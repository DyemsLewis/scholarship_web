<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_notifications', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'read_at', 'created_at'],
                'portal_notifications_user_unread_index',
            );
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'application_documents_review_queue_index');
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->index(
                ['scholarship_id', 'application_state', 'workflow_stage'],
                'scholarship_applications_program_workflow_index',
            );
        });

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->index(
                ['verification_status', 'updated_at'],
                'provider_profiles_verification_queue_index',
            );
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $table->index(['actor_role', 'created_at'], 'activity_logs_role_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('portal_notifications', function (Blueprint $table): void {
            $table->dropIndex('portal_notifications_user_unread_index');
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropIndex('application_documents_review_queue_index');
        });

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropIndex('scholarship_applications_program_workflow_index');
        });

        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropIndex('provider_profiles_verification_queue_index');
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_user_created_index');
            $table->dropIndex('activity_logs_role_created_index');
        });
    }
};
