<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_case_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_agreement_case_id')->constrained(indexName: 'case_review_case_fk')->cascadeOnDelete();
            $table->foreignId('response_id')->unique()->constrained('recipient_agreement_case_responses', indexName: 'case_review_statement_fk')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name');
            $table->unsignedInteger('source_revision');
            $table->string('finding', 32);
            $table->string('recommendation', 48);
            $table->text('rationale');
            $table->json('evidence_ids');
            $table->json('statement_ids');
            $table->boolean('is_appeal')->default(false);
            $table->timestamp('appeal_due_at');
            $table->timestamp('created_at');
        });
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            $table->string('review_state', 32)->nullable()->index('case_review_queue');
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('latest_review_id')->nullable()->constrained('recipient_case_reviews', indexName: 'case_latest_review_fk')->nullOnDelete();
        });
        Schema::table('recipient_support_decisions', function (Blueprint $table): void {
            $table->foreignId('agreement_case_review_id')->nullable()->constrained('recipient_case_reviews', indexName: 'support_case_review_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_support_decisions', function (Blueprint $table): void {
            $table->dropForeign('support_case_review_fk');
            $table->dropColumn('agreement_case_review_id');
        });
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            $table->dropForeign('case_latest_review_fk');
            $table->dropIndex('case_review_queue');
            $table->dropColumn(['review_state', 'escalated_at', 'latest_review_id']);
        });
        Schema::dropIfExists('recipient_case_reviews');
    }
};
