<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringSubmission extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'scholarship_application_id',
        'applicant_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'ocr_status',
        'ocr_provider',
        'ocr_grade',
        'ocr_grading_scale',
        'ocr_label',
        'ocr_message',
        'ocr_processed_at',
        'reported_grade',
        'reported_grading_scale',
        'grade_source',
        'submitted_at',
        'review_status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'ocr_grade' => 'decimal:2',
            'ocr_processed_at' => 'datetime',
            'reported_grade' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringCycle::class, 'recipient_monitoring_cycle_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RecipientMonitoringReview::class)
            ->orderByDesc('decided_at')
            ->orderByDesc('id');
    }
}
