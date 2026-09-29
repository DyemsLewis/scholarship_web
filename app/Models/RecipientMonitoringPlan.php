<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringPlan extends Model
{
    public const FREQUENCIES = [
        'monthly',
        'quarterly',
        'semester',
        'annual',
        'custom',
    ];

    public const STATUSES = [
        'draft',
        'active',
    ];

    protected $fillable = [
        'scholarship_id',
        'created_by',
        'updated_by',
        'frequency',
        'starts_on',
        'ends_on',
        'grace_period_days',
        'allow_exception_requests',
        'instructions',
        'status',
        'version',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'grace_period_days' => 'integer',
            'allow_exception_requests' => 'boolean',
            'version' => 'integer',
            'activated_at' => 'datetime',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(RecipientMonitoringRequirement::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(RecipientMonitoringCycle::class)
            ->orderByDesc('due_at')
            ->orderByDesc('id');
    }
}
