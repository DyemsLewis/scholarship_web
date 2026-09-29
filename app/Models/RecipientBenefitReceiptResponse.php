<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientBenefitReceiptResponse extends Model
{
    protected $fillable = [
        'recipient_benefit_release_record_id',
        'scholarship_application_id',
        'applicant_id',
        'response_type',
        'received_on',
        'recipient_note',
        'issue_type',
        'issue_details',
        'evidence_original_name',
        'evidence_path',
        'evidence_mime_type',
        'evidence_size',
        'status',
        'responded_at',
        'resolution_outcome',
        'resolution_notes',
        'resolution_proof_original_name',
        'resolution_proof_path',
        'resolution_proof_mime_type',
        'resolution_proof_size',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitReleaseRecord::class, 'recipient_benefit_release_record_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
