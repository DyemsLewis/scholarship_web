<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStageAuditEvent extends Model
{
    protected $fillable = [
        'scholarship_id',
        'scholarship_application_id',
        'application_stage_session_id',
        'application_stage_assignment_id',
        'stage_key',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
        'actor_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(ApplicationStageSession::class, 'application_stage_session_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ApplicationStageAssignment::class, 'application_stage_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
