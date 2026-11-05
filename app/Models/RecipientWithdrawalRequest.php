<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientWithdrawalRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'declined', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'agreement_snapshot' => 'array',
            'agreement_accepted_at' => 'datetime',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecipientWithdrawalEvent::class)->orderByDesc('revision');
    }

    public function supportDecision(): BelongsTo
    {
        return $this->belongsTo(RecipientSupportDecision::class, 'support_decision_id');
    }
}
