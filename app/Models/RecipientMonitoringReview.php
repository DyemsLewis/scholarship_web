<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringReview extends Model
{
    protected $fillable = [
        'recipient_monitoring_submission_id',
        'reviewed_by',
        'decision',
        'notes',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringSubmission::class, 'recipient_monitoring_submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
