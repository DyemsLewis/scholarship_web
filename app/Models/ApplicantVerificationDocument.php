<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantVerificationDocument extends Model
{
    public const PROFILE_EVIDENCE_TYPES = [
        'academic_record',
        'school_record',
        'achievement_evidence',
    ];

    protected $fillable = [
        'applicant_id',
        'uploaded_by',
        'document_type',
        'original_name',
        'path',
        'mime_type',
        'size',
        'status',
        'review_notes',
        'uploaded_at',
        'terms_accepted_at',
        'terms_version',
        'ocr_status',
        'ocr_provider',
        'ocr_grade',
        'ocr_grading_scale',
        'ocr_label',
        'ocr_message',
        'ocr_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'ocr_grade' => 'decimal:2',
            'ocr_processed_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
