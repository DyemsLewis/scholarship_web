<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('eligibility_review_rules')->nullable();
        });
        Schema::create('eligibility_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->char('context_hash', 64);
            $table->json('criteria_snapshot');
            $table->text('explanation');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('evidence_mime', 80)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('scholarship_application_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['scholarship_id', 'applicant_id', 'context_hash'], 'eligibility_review_context_unique');
            $table->index(['scholarship_id', 'status', 'created_at'], 'eligibility_review_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_review_requests');
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn('eligibility_review_rules'));
    }
};
