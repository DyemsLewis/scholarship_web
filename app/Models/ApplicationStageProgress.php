<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStageProgress extends Model
{
    protected $table = 'application_stage_progresses';

    protected $fillable = [
        'scholarship_application_id',
        'stage_key',
        'position',
        'status',
        'operational_status',
        'result',
        'outcome_key',
        'notes',
        'proof_required',
        'started_at',
        'completed_at',
        'decided_by',
        'decided_at',
        'proof_verified_by',
        'proof_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'proof_required' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'decided_at' => 'datetime',
            'proof_verified_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function proofVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proof_verified_by');
    }
}
