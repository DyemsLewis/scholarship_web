<?php

namespace App\Models;

use App\Domain\Recipients\RecipientSupportCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringCase extends Model
{
    protected $fillable = [
        'recipient_monitoring_cycle_id',
        'scholarship_application_id',
        'applicant_id',
        'status',
        'due_status',
        'effective_due_at',
        'outcome',
        'status_reason',
        'next_action_role',
        'next_action',
        'opened_at',
        'submitted_at',
        'review_started_at',
        'ready_to_close_at',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'effective_due_at' => 'date',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'ready_to_close_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RecipientMonitoringCycle::class, 'recipient_monitoring_cycle_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecipientMonitoringCaseEvent::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [
            RecipientSupportCatalog::CASE_CLOSED,
            RecipientSupportCatalog::CASE_WAIVED,
            RecipientSupportCatalog::CASE_CANCELLED,
        ], true);
    }
}
