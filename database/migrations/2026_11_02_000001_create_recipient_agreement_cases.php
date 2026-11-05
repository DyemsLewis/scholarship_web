<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_agreement_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('case_type', 32);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('subject', 160);
            $table->text('details');
            $table->json('agreement_snapshot');
            $table->string('agreement_version');
            $table->timestamp('agreement_accepted_at');
            $table->string('term_key', 160)->nullable();
            $table->text('term_text')->nullable();
            $table->foreignId('benefit_entitlement_id')->nullable()
                ->constrained('recipient_benefit_entitlements')->nullOnDelete();
            $table->foreignId('benefit_release_record_id')->nullable()
                ->constrained('recipient_benefit_release_records')->nullOnDelete();
            $table->json('benefit_snapshot')->nullable();
            // A deadline begins only when a notice is actually issued, not on draft creation.
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('response_received_at')->nullable();
            $table->string('finding', 32)->default('not_assessed');
            $table->string('outcome', 48)->nullable();
            $table->foreignId('support_decision_id')->nullable()
                ->constrained('recipient_support_decisions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('disclosure_status', 32)->default('not_shared');
            $table->timestamp('disclosure_review_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['scholarship_application_id', 'opened_by', 'request_key'], 'recipient_agreement_case_request');
            $table->index(['scholarship_application_id', 'id'], 'recipient_agreement_case_history');
            $table->index(['case_type', 'status', 'response_due_at'], 'recipient_agreement_case_queue');
        });

        Schema::create('recipient_agreement_case_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')
                ->constrained('recipient_agreement_cases', indexName: 'agreement_response_case_fk')->cascadeOnDelete();
            $table->unsignedInteger('case_revision');
            $table->unsignedInteger('notice_revision');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('author_role', 32);
            $table->text('body');
            $table->string('requested_resolution', 160)->nullable();
            $table->timestamp('created_at');
            $table->unique(['recipient_agreement_case_id', 'case_revision'], 'agreement_response_revision');
        });

        Schema::create('recipient_agreement_case_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')
                ->constrained('recipient_agreement_cases', indexName: 'agreement_evidence_case_fk')->cascadeOnDelete();
            $table->foreignId('response_id')->nullable()
                ->constrained('recipient_agreement_case_responses')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamp('created_at');
        });

        Schema::create('recipient_agreement_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')
                ->constrained('recipient_agreement_cases', indexName: 'agreement_event_case_fk')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 32);
            $table->text('note');
            $table->json('payload')->nullable();
            $table->timestamp('created_at');
            $table->unique(['recipient_agreement_case_id', 'revision'], 'agreement_event_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_agreement_case_events');
        Schema::dropIfExists('recipient_agreement_case_evidence');
        Schema::dropIfExists('recipient_agreement_case_responses');
        Schema::dropIfExists('recipient_agreement_cases');
    }
};
