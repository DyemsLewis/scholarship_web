<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_benefit_payout_details', function (Blueprint $table): void {
            $table->string('review_status', 24)->default('submitted');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('correction_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_benefit_payout_details', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'revision', 'reviewed_at', 'correction_reason']);
        });
    }
};
