<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scholarship extends Model
{
    protected $fillable = [
        'provider_id',
        'image_path',
        'title',
        'category',
        'description',
        'eligibility',
        'eligible_courses',
        'eligible_year_levels',
        'eligible_locations',
        'income_requirement',
        'location_name',
        'location_address',
        'latitude',
        'longitude',
        'requirements',
        'award_amount',
        'minimum_gwa',
        'deadline',
        'status',
        'views_count',
    ];

    protected function casts(): array
    {
        return [
            'award_amount' => 'decimal:2',
            'minimum_gwa' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'deadline' => 'date',
            'views_count' => 'integer',
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

    public function bookmarks(): HasMany
    {
        return $this->hasMany(ScholarshipBookmark::class);
    }
}
