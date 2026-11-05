<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            // Null on resolution allows history, but never two pending requests for an award.
            $table->foreignId('pending_application_id')->nullable()->unique()->constrained('scholarship_applications')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->text('reason');
            $table->json('agreement_snapshot')->nullable();
            $table->string('agreement_version')->nullable();
            $table->timestamp('agreement_accepted_at')->nullable();
            $table->timestamp('requested_at');
            $table->text('review_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('support_decision_id')->nullable()->constrained('recipient_support_decisions')->nullOnDelete();
            $table->timestamps();
            $table->index(['scholarship_application_id', 'id'], 'recipient_withdrawal_application_history');
            $table->index(['status', 'requested_at'], 'recipient_withdrawal_review_queue');
        });

        Schema::create('recipient_withdrawal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_withdrawal_request_id');
            $table->foreign('recipient_withdrawal_request_id', 'recipient_withdrawal_event_request_fk')
                ->references('id')->on('recipient_withdrawal_requests')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role');
            $table->text('note');
            $table->timestamp('created_at');
            $table->unique(['recipient_withdrawal_request_id', 'revision'], 'recipient_withdrawal_event_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_withdrawal_events');
        Schema::dropIfExists('recipient_withdrawal_requests');
    }
};
