<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_case_privacy_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')->constrained(indexName: 'case_privacy_case_fk')->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorder_name');
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->unsignedInteger('source_revision');
            $table->unsignedInteger('case_revision');
            $table->string('assessment', 32);
            $table->string('audience', 32);
            $table->text('purpose');
            $table->string('basis_reference', 500)->nullable();
            $table->text('rationale');
            $table->json('blockers');
            $table->boolean('audit_retention_hold');
            $table->date('recheck_on');
            $table->timestamp('created_at');
            $table->unique(['recipient_agreement_case_id', 'request_key'], 'case_privacy_request');
        });
        Schema::create('recipient_case_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')->nullable()->constrained(indexName: 'case_access_case_fk')->cascadeOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('recipient_agreement_case_evidence', indexName: 'case_access_evidence_fk')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32);
            $table->string('resource', 16);
            $table->string('route_name', 120);
            $table->string('outcome', 16);
            $table->string('dedupe_key', 64)->unique('case_access_dedupe');
            $table->timestamp('created_at')->index('case_access_retention');
            $table->index(['recipient_agreement_case_id', 'id'], 'case_access_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_case_access_logs');
        Schema::dropIfExists('recipient_case_privacy_reviews');
    }
};
