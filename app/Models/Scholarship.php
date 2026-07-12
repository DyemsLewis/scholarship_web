<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scholarship extends Model
{
    protected $fillable = [
        'provider_id',
        'image_path',
        'title',
        'category',
        'description',
        'eligibility',
        'eligible_education_levels',
        'eligible_courses',
        'eligible_school_types',
        'eligible_year_levels',
        'eligible_locations',
        'income_requirement',
        'location_name',
        'location_address',
        'latitude',
        'longitude',
        'requirements',
        'review_rubric',
        'award_amount',
        'minimum_gwa',
        'minimum_grade_scale',
        'slots_available',
        'application_mode',
        'renewal_policy',
        'return_service_contract',
        'other_contract_terms',
        'contact_email',
        'contact_number',
        'deadline',
        'status',
        'views_count',
        'provider_terms_accepted_at',
        'provider_terms_version',
    ];

    protected function casts(): array
    {
        return [
            'award_amount' => 'decimal:2',
            'minimum_gwa' => 'decimal:2',
            'slots_available' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'deadline' => 'date',
            'review_rubric' => 'array',
            'views_count' => 'integer',
            'provider_terms_accepted_at' => 'datetime',
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

    public function providerAssessment(): HasOne
    {
        return $this->hasOne(ProviderAssessment::class, 'provider_id', 'provider_id');
    }

    public function scopeAcceptingApplications(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(function (Builder $deadlineQuery): void {
                $deadlineQuery
                    ->whereNull('deadline')
                    ->orWhereDate('deadline', '>=', now()->toDateString());
            });
    }

    public function isAcceptingApplications(): bool
    {
        return $this->status === 'published'
            && ($this->deadline === null || ! $this->deadline->isBefore(now()->startOfDay()));
    }
}
