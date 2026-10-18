<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientMonitoringCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'recipient_monitoring_case_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'outcome',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function monitoringCase(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringCase::class, 'recipient_monitoring_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
