<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientBenefitEntitlement extends Model
{
    protected $fillable = [
        'scholarship_id',
        'scholarship_application_id',
        'applicant_id',
        'scholarship_benefit_id',
        'benefit_type',
        'title',
        'amount',
        'coverage',
        'frequency',
        'duration',
        'description',
        'status',
        'assigned_at',
        'ready_at',
        'scheduled_at',
        'fulfilled_at',
        'withheld_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'assigned_at' => 'datetime',
            'ready_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'withheld_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(ScholarshipBenefit::class, 'scholarship_benefit_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RecipientBenefitReleaseAllocation::class);
    }

    public function isOneTime(): bool
    {
        return $this->frequency === 'one_time';
    }
}
