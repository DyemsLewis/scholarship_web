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
        'result',
        'notes',
        'started_at',
        'completed_at',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'decided_at' => 'datetime',
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
}
