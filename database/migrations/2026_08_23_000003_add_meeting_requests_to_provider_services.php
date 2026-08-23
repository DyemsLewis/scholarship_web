<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->timestamp('meeting_scheduled_for')->nullable()->after('milestones');
            $table->string('meeting_mode', 20)->nullable()->after('meeting_scheduled_for');
            $table->text('meeting_purpose')->nullable()->after('meeting_mode');
            $table->string('meeting_status', 20)->nullable()->after('meeting_purpose');
            $table->text('meeting_admin_note')->nullable()->after('meeting_status');
            $table->timestamp('meeting_decided_at')->nullable()->after('meeting_admin_note');
            $table->foreignId('meeting_decided_by')->nullable()->after('meeting_decided_at')->constrained('users')->nullOnDelete();

            $table->index(['meeting_status', 'meeting_scheduled_for'], 'provider_services_meeting_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropForeign(['meeting_decided_by']);
            $table->dropIndex('provider_services_meeting_status_date_index');
            $table->dropColumn([
                'meeting_scheduled_for',
                'meeting_mode',
                'meeting_purpose',
                'meeting_status',
                'meeting_admin_note',
                'meeting_decided_at',
                'meeting_decided_by',
            ]);
        });
    }
};
