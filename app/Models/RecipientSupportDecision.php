<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecipientSupportDecision extends Model
{
    protected $fillable = [
        'scholarship_application_id',
        'applicant_id',
        'decision',
        'reason_category',
        'effective_on',
        'support_ends_on',
        'next_review_on',
        'notice_given_on',
        'reason',
        'next_period_terms',
        'decision_document_original_name',
        'decision_document_path',
        'decision_document_mime_type',
        'decision_document_size',
        'decided_by',
        'decided_at',
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
        'resolved_by',
        'resolved_at',
        'agreement_case_review_id',
        'closeout_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'support_ends_on' => 'date',
            'next_review_on' => 'date',
            'notice_given_on' => 'date',
            'decided_at' => 'datetime',
            'applicant_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closeout_snapshot' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function agreementReview(): BelongsTo
    {
        return $this->belongsTo(RecipientCaseReview::class, 'agreement_case_review_id');
    }

    public function approvedWithdrawal(): HasOne
    {
        return $this->hasOne(RecipientWithdrawalRequest::class, 'support_decision_id')->where('status', 'approved');
    }

    public function isApprovedWithdrawal(): bool
    {
        if ($this->decision !== 'terminated' || $this->reason_category !== 'recipient_withdrew') {
            return false;
        }

        $withdrawal = $this->approvedWithdrawal;

        return $withdrawal && $withdrawal->scholarship_application_id === $this->scholarship_application_id
            && $withdrawal->applicant_id === $this->applicant_id;
    }
}
