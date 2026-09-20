<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->string('review_status')->default('pending')->index();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('recipient_monitoring_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision')->index();
            $table->text('notes')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_reviews');

        Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['review_status']);
            $table->dropColumn([
                'review_status',
                'review_notes',
                'reviewed_by',
                'reviewed_at',
            ]);
        });
    }
};
