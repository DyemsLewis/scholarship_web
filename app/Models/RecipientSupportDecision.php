<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientSupportDecision extends Model
{
    protected $fillable = [
        'scholarship_application_id',
        'applicant_id',
        'decision',
        'effective_on',
        'support_ends_on',
        'next_review_on',
        'reason',
        'next_period_terms',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'support_ends_on' => 'date',
            'next_review_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
