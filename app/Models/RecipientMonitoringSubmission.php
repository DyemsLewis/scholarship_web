<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringSubmission extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'recipient_monitoring_cycle_requirement_id',
        'scholarship_application_id',
        'applicant_id',
        'applicant_note',
        'submission_source',
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

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringCycleRequirement::class, 'recipient_monitoring_cycle_requirement_id');
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

    public function evidenceSnapshot(): ?array
    {
        if (blank($this->path)) {
            return null;
        }

        return [
            'path' => $this->path,
            'original_name' => $this->original_name,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reported_grade' => $this->reported_grade ?? $this->ocr_grade,
            'grading_scale' => $this->reported_grading_scale ?? $this->ocr_grading_scale,
        ];
    }

    public function allowsApplicantEdit(bool $replacingFile = true): bool
    {
        return in_array($this->review_status, [null, 'pending'], true)
            || ($replacingFile && $this->review_status === 'needs_correction');
    }

    public function preserveEvidenceBeforeCorrection(): bool
    {
        $snapshot = $this->evidenceSnapshot();
        if ($this->review_status !== 'needs_correction' || $snapshot === null) {
            return false;
        }

        $review = $this->reviews()->first();
        if ($review?->decision === 'needs_correction' && $review->evidence_snapshot === null) {
            $review->update(['evidence_snapshot' => $snapshot]);
        } elseif (data_get($review?->evidence_snapshot, 'path') !== $snapshot['path']) {
            $this->reviews()->create([
                'reviewed_by' => $this->reviewed_by,
                'decision' => 'needs_correction',
                'notes' => $this->review_notes,
                'decided_at' => $this->reviewed_at ?? now(),
                'evidence_snapshot' => $snapshot,
            ]);
        }

        return true;
    }
}
