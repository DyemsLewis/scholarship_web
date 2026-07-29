<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->string('provider_status', 20)->default('not_required')->after('status');
            $table->foreignId('provider_resolved_by')->nullable()->after('provider_status')->constrained('users')->nullOnDelete();
            $table->timestamp('provider_resolved_at')->nullable()->after('provider_resolved_by');
            $table->string('admin_status', 20)->default('open')->after('provider_resolved_at');
            $table->foreignId('admin_resolved_by')->nullable()->after('admin_status')->constrained('users')->nullOnDelete();
            $table->timestamp('admin_resolved_at')->nullable()->after('admin_resolved_by');

            $table->index(['provider_id', 'provider_status', 'created_at'], 'support_reports_provider_role_queue_index');
            $table->index(['admin_status', 'created_at'], 'support_reports_admin_role_queue_index');
        });

        DB::table('support_reports')
            ->orderBy('id')
            ->chunkById(100, function ($reports): void {
                foreach ($reports as $report) {
                    $resolved = $report->status === 'resolved';
                    $isProgramReport = $report->assigned_role === 'provider';

                    DB::table('support_reports')
                        ->where('id', $report->id)
                        ->update([
                            'provider_status' => $isProgramReport
                                ? ($resolved ? 'resolved' : 'open')
                                : 'not_required',
                            'provider_resolved_by' => $isProgramReport && $resolved ? $report->resolved_by : null,
                            'provider_resolved_at' => $isProgramReport && $resolved ? $report->resolved_at : null,
                            'admin_status' => $resolved ? 'resolved' : 'open',
                            'admin_resolved_by' => $resolved ? $report->resolved_by : null,
                            'admin_resolved_at' => $resolved ? $report->resolved_at : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->dropIndex('support_reports_provider_role_queue_index');
            $table->dropIndex('support_reports_admin_role_queue_index');
            $table->dropForeign(['provider_resolved_by']);
            $table->dropForeign(['admin_resolved_by']);
            $table->dropColumn([
                'provider_status',
                'provider_resolved_by',
                'provider_resolved_at',
                'admin_status',
                'admin_resolved_by',
                'admin_resolved_at',
            ]);
        });
    }
};
