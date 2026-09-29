<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringOversightReview extends Model
{
    protected $fillable = [
        'scholarship_application_id',
        'reviewed_by',
        'outcome',
        'notes',
        'flags_snapshot',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'flags_snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
