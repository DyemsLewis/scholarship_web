<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->string('privacy_request_type', 40)->nullable()->after('category');
            $table->index(
                ['category', 'privacy_request_type', 'admin_status'],
                'support_reports_privacy_queue_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->dropIndex('support_reports_privacy_queue_index');
            $table->dropColumn('privacy_request_type');
        });
    }
};
