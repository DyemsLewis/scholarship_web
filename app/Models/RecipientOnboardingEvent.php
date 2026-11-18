<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientOnboardingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'details' => 'array', 'recorded_at' => 'datetime'];
    }

    public function onboardingRecord(): BelongsTo
    {
        return $this->belongsTo(RecipientOnboardingRecord::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
