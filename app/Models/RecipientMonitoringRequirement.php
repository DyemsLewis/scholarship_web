<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringRequirement extends Model
{
    protected $fillable = [
        'recipient_monitoring_plan_id',
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
        'active',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'requires_file' => 'boolean',
            'requires_original_verification' => 'boolean',
            'minimum_grade' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringPlan::class, 'recipient_monitoring_plan_id');
    }
}
