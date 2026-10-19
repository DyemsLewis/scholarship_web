<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->string('cancellation_status', 30)->nullable()->after('revision_count');
            $table->text('cancellation_reason')->nullable()->after('cancellation_status');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_reason');
            $table->foreignId('cancellation_requested_by')->nullable()->after('cancellation_requested_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_decision_note')->nullable()->after('cancellation_requested_by');
            $table->timestamp('cancellation_decided_at')->nullable()->after('cancellation_decision_note');
            $table->foreignId('cancellation_decided_by')->nullable()->after('cancellation_decided_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_decided_by');
            $table->string('refund_status', 30)->nullable()->after('cancelled_at');
            $table->string('refund_reference', 120)->nullable()->after('refund_status');

            $table->index(['cancellation_status', 'cancellation_requested_at'], 'provider_services_cancellation_status_index');
            $table->index(['refund_status', 'cancelled_at'], 'provider_services_refund_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropForeign(['cancellation_requested_by']);
            $table->dropForeign(['cancellation_decided_by']);
            $table->dropIndex('provider_services_cancellation_status_index');
            $table->dropIndex('provider_services_refund_status_index');
            $table->dropColumn([
                'cancellation_status',
                'cancellation_reason',
                'cancellation_requested_at',
                'cancellation_requested_by',
                'cancellation_decision_note',
                'cancellation_decided_at',
                'cancellation_decided_by',
                'cancelled_at',
                'refund_status',
                'refund_reference',
            ]);
        });
    }
};
