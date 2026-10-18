<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ApplicationStageSession extends Model
{
    protected $fillable = [
        'scholarship_id',
        'stage_key',
        'title',
        'starts_at',
        'ends_at',
        'timezone',
        'mode',
        'venue',
        'location_address',
        'latitude',
        'longitude',
        'meeting_url',
        'capacity',
        'instructions',
        'status',
        'proof_required',
        'published_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'meeting_url' => 'encrypted',
            'capacity' => 'integer',
            'proof_required' => 'boolean',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function assignments(): HasMany
    {
        return $this->hasMany(ApplicationStageAssignment::class);
    }

    public function attendances(): HasManyThrough
    {
        return $this->hasManyThrough(
            ApplicationStageAttendance::class,
            ApplicationStageAssignment::class,
        );
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ApplicationStageEvidence::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ApplicationStageAuditEvent::class);
    }
}
