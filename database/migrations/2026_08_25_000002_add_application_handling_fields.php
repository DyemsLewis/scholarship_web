<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->string('correction_status')->nullable()->after('review_notes')->index();
            $table->text('correction_message')->nullable()->after('correction_status');
            $table->text('correction_response')->nullable()->after('correction_message');
            $table->foreignId('correction_requested_by')->nullable()->after('correction_response')->constrained('users')->nullOnDelete();
            $table->timestamp('correction_requested_at')->nullable()->after('correction_requested_by');
            $table->timestamp('correction_responded_at')->nullable()->after('correction_requested_at');
            $table->timestamp('correction_resolved_at')->nullable()->after('correction_responded_at');
            $table->text('withdrawal_reason')->nullable()->after('correction_resolved_at');
            $table->foreignId('withdrawn_by')->nullable()->after('withdrawal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
            $table->unsignedInteger('waitlist_position')->nullable()->after('withdrawn_at');
            $table->timestamp('waitlisted_at')->nullable()->after('waitlist_position');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_requested_by');
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn([
                'correction_status',
                'correction_message',
                'correction_response',
                'correction_requested_at',
                'correction_responded_at',
                'correction_resolved_at',
                'withdrawal_reason',
                'withdrawn_at',
                'waitlist_position',
                'waitlisted_at',
            ]);
        });
    }
};
