<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_verification_documents', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('reviewed_revision')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('applicant_evidence_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('applicant_verification_documents')->nullOnDelete();
            $table->string('document_type');
            $table->string('original_name');
            $table->unsignedInteger('revision');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('note')->nullable();
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at');
            $table->index(['applicant_id', 'id']);
        });
        // Existing approvals are retained, without inventing individual reviewer decisions.
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_evidence_reviews');
        Schema::table('applicant_verification_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['revision', 'reviewed_revision', 'reviewed_at']);
        });
    }
};
