<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringCycle extends Model
{
    public const STATUSES = [
        'draft',
        'scheduled',
        'open',
        'grace_period',
        'under_review',
        'follow_up',
        'ready_to_close',
        'closed',
        'cancelled',
    ];

    protected $fillable = [
        'scholarship_id',
        'recipient_monitoring_plan_id',
        'monitoring_plan_version',
        'grace_period_days',
        'allow_exception_requests',
        'created_by',
        'title',
        'period_type',
        'academic_period',
        'school_year',
        'opens_at',
        'due_at',
        'minimum_grade',
        'grading_scale',
        'instructions',
        'status',
        'published_at',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'date',
            'due_at' => 'date',
            'minimum_grade' => 'decimal:2',
            'monitoring_plan_version' => 'integer',
            'grace_period_days' => 'integer',
            'allow_exception_requests' => 'boolean',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringPlan::class, 'recipient_monitoring_plan_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(RecipientMonitoringCycleRequirement::class)
            ->orderBy('sort_order')
            ->orderBy('id');
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

    public function cases(): HasMany
    {
        return $this->hasMany(RecipientMonitoringCase::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
