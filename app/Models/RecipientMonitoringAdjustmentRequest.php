<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringAdjustmentRequest extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'recipient_monitoring_cycle_requirement_id',
        'scholarship_application_id',
        'applicant_id',
        'request_type',
        'reason_category',
        'explanation',
        'requested_due_at',
        'attachment_original_name',
        'attachment_path',
        'attachment_mime_type',
        'attachment_size',
        'status',
        'decision_notes',
        'approved_due_at',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_due_at' => 'date',
            'approved_due_at' => 'date',
            'decided_at' => 'datetime',
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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
