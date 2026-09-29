<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_benefit_receipt_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_benefit_release_record_id');
            $table->foreign('recipient_benefit_release_record_id', 'rb_response_record_fk')
                ->references('id')
                ->on('recipient_benefit_release_records')
                ->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rb_response_application_fk')
                ->references('id')
                ->on('scholarship_applications')
                ->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->string('response_type');
            $table->date('received_on')->nullable();
            $table->text('recipient_note')->nullable();
            $table->string('issue_type')->nullable();
            $table->text('issue_details')->nullable();
            $table->string('evidence_original_name')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_mime_type')->nullable();
            $table->unsignedBigInteger('evidence_size')->default(0);
            $table->string('status')->index();
            $table->timestamp('responded_at');
            $table->string('resolution_outcome')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('resolution_proof_original_name')->nullable();
            $table->string('resolution_proof_path')->nullable();
            $table->string('resolution_proof_mime_type')->nullable();
            $table->unsignedBigInteger('resolution_proof_size')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('recipient_benefit_release_record_id', 'rb_response_record_unique');
            $table->index(['scholarship_application_id', 'status'], 'rb_response_application_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_benefit_receipt_responses');
    }
};
