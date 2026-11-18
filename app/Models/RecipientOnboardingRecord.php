<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientOnboardingRecord extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'policy_snapshot' => 'array',
            'revision' => 'integer',
            'instructions_published_at' => 'datetime',
            'completed_on' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function instructionPublisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructions_published_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecipientOnboardingEvent::class, 'onboarding_record_id')->orderBy('revision');
    }
}
