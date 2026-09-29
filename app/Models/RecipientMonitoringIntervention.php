<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringIntervention extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'recipient_monitoring_cycle_requirement_id',
        'scholarship_application_id',
        'applicant_id',
        'created_by',
        'type',
        'summary',
        'action_required',
        'follow_up_on',
        'status',
        'completion_notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_on' => 'date',
            'completed_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
