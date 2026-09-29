<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_support_decisions', function (Blueprint $table): void {
            $table->string('reason_category')->nullable()->after('decision');
            $table->date('notice_given_on')->nullable()->after('next_review_on');
            $table->string('decision_document_original_name')->nullable()->after('next_period_terms');
            $table->string('decision_document_path')->nullable()->after('decision_document_original_name');
            $table->string('decision_document_mime_type')->nullable()->after('decision_document_path');
            $table->unsignedBigInteger('decision_document_size')->default(0)->after('decision_document_mime_type');
            $table->string('applicant_response_type')->nullable()->after('decided_at');
            $table->text('applicant_response_message')->nullable()->after('applicant_response_type');
            $table->string('applicant_response_original_name')->nullable()->after('applicant_response_message');
            $table->string('applicant_response_path')->nullable()->after('applicant_response_original_name');
            $table->string('applicant_response_mime_type')->nullable()->after('applicant_response_path');
            $table->unsignedBigInteger('applicant_response_size')->default(0)->after('applicant_response_mime_type');
            $table->timestamp('applicant_responded_at')->nullable()->after('applicant_response_size');
            $table->string('response_status')->nullable()->index()->after('applicant_responded_at');
            $table->string('resolution_outcome')->nullable()->after('response_status');
            $table->text('resolution_notes')->nullable()->after('resolution_outcome');
            $table->string('resolution_proof_original_name')->nullable()->after('resolution_notes');
            $table->string('resolution_proof_path')->nullable()->after('resolution_proof_original_name');
            $table->string('resolution_proof_mime_type')->nullable()->after('resolution_proof_path');
            $table->unsignedBigInteger('resolution_proof_size')->default(0)->after('resolution_proof_mime_type');
            $table->foreignId('resolved_by')->nullable()->after('resolution_proof_size')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('recipient_support_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'reason_category',
                'notice_given_on',
                'decision_document_original_name',
                'decision_document_path',
                'decision_document_mime_type',
                'decision_document_size',
                'applicant_response_type',
                'applicant_response_message',
                'applicant_response_original_name',
                'applicant_response_path',
                'applicant_response_mime_type',
                'applicant_response_size',
                'applicant_responded_at',
                'response_status',
                'resolution_outcome',
                'resolution_notes',
                'resolution_proof_original_name',
                'resolution_proof_path',
                'resolution_proof_mime_type',
                'resolution_proof_size',
                'resolved_at',
            ]);
        });
    }
};
