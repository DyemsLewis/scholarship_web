<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientBenefitRelease extends Model
{
    protected $fillable = [
        'scholarship_id',
        'created_by',
        'title',
        'release_at',
        'benefit_description',
        'amount',
        'release_method',
        'location',
        'instructions',
        'requires_original_verification',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'release_at' => 'datetime',
            'amount' => 'decimal:2',
            'requires_original_verification' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(RecipientBenefitReleaseRecord::class);
    }
}
