<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_benefit_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_record_id')->unique()->constrained('recipient_benefit_release_records')->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->text('instructions');
            $table->timestamp('notice_sent_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('respond_by');
            $table->string('recipient_response', 32)->nullable();
            $table->text('recipient_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('replacement_record_id')->nullable()->unique()->constrained('recipient_benefit_release_records')->nullOnDelete();
            $table->json('events');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_benefit_follow_ups');
    }
};
