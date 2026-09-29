<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringCycleRequirement extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'source_requirement_id',
        'type',
        'title',
        'description',
        'evidence_description',
        'required',
        'requires_file',
        'requires_original_verification',
        'minimum_grade',
        'grading_scale',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'requires_file' => 'boolean',
            'requires_original_verification' => 'boolean',
            'minimum_grade' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringCycle::class, 'recipient_monitoring_cycle_id');
    }

    public function sourceRequirement(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringRequirement::class, 'source_requirement_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(RecipientMonitoringSubmission::class);
    }

    public function adjustmentRequests(): HasMany
    {
        return $this->hasMany(RecipientMonitoringAdjustmentRequest::class);
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(RecipientMonitoringIntervention::class);
    }
}
