<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApplicationStageAssignment extends Model
{
    protected $fillable = [
        'application_stage_session_id',
        'scholarship_application_id',
        'previous_assignment_id',
        'status',
        'notes',
        'assigned_by',
        'assigned_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ApplicationStageSession::class, 'application_stage_session_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    public function replacementAssignments(): HasMany
    {
        return $this->hasMany(self::class, 'previous_assignment_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(ApplicationStageAttendance::class);
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
