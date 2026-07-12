<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAssessment extends Model
{
    protected $fillable = [
        'provider_id',
        'title',
        'assessment_type',
        'image_path',
        'description',
        'duration_minutes',
        'passing_score',
        'delivery_mode',
        'venue',
        'instructions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'passing_score' => 'decimal:2',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
