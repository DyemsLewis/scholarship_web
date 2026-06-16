<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scholarship extends Model
{
    protected $fillable = [
        'provider_id',
        'title',
        'description',
        'eligibility',
        'requirements',
        'award_amount',
        'minimum_gwa',
        'deadline',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'award_amount' => 'decimal:2',
            'minimum_gwa' => 'decimal:2',
            'deadline' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class);
    }
}
