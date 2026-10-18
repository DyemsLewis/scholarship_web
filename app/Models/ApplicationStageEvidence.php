<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationStageEvidence extends Model
{
    protected $table = 'application_stage_evidence';

    protected $fillable = [
        'scholarship_id',
        'scholarship_application_id',
        'application_stage_session_id',
        'application_stage_assignment_id',
        'supersedes_evidence_id',
        'stage_key',
        'scope',
        'category',
        'original_name',
        'path',
        'disk',
        'mime_type',
        'size',
        'event_date',
        'note',
        'visibility',
        'is_current',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'event_date' => 'date',
            'is_current' => 'boolean',
            'uploaded_at' => 'datetime',
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

    public function supersededEvidence(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_evidence_id');
    }

    public function replacementEvidence(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_evidence_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
