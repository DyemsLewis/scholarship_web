<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'program_cycle',
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
        'optional_requirements',
        'post_qualification_requirements',
        'handoff_mode',
        'handoff_instructions',
        'handoff_deadline',
        'handoff_location_name',
        'handoff_location_address',
        'handoff_url',
        'review_rubric',
        'award_amount',
        'minimum_gwa',
        'minimum_grade_scale',
        'slots_available',
        'application_mode',
        'selection_stages',
        'exam_duration_minutes',
        'exam_passing_score',
        'renewal_policy',
        'return_service_contract',
        'other_contract_terms',
        'contact_email',
        'contact_number',
        'application_opens_at',
        'expected_results_at',
        'official_program_url',
        'contact_person',
        'contact_department',
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
            'application_opens_at' => 'date',
            'expected_results_at' => 'date',
            'handoff_deadline' => 'date',
            'deadline' => 'date',
            'review_rubric' => 'array',
            'selection_stages' => 'array',
            'exam_duration_minutes' => 'integer',
            'exam_passing_score' => 'decimal:2',
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

    public function events(): HasMany
    {
        return $this->hasMany(ScholarshipEvent::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(ScholarshipAnnouncement::class)
            ->latest('published_at')
            ->latest('id');
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(ScholarshipBenefit::class)->orderBy('sort_order')->orderBy('id');
    }

    public function benefitPayload(): array
    {
        $this->loadMissing('benefits');
        $benefits = $this->benefits
            ->map(fn (ScholarshipBenefit $benefit): array => $benefit->programPayload())
            ->values()
            ->all();

        if ($benefits !== [] || $this->award_amount === null) {
            return $benefits;
        }

        return [ScholarshipBenefit::payloadFromValues(
            'cash_grant',
            'Cash grant',
            $this->award_amount,
            null,
            'one_time',
            null,
            null,
        )];
    }

    public function benefitSummary(): string
    {
        $benefits = collect($this->benefitPayload());

        if ($benefits->isEmpty()) {
            return 'Benefits not specified';
        }

        $summary = $benefits
            ->take(2)
            ->pluck('display_summary')
            ->implode(' + ');
        $remaining = $benefits->count() - 2;

        return $remaining > 0 ? $summary.' + '.$remaining.' more' : $summary;
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(ScholarshipBookmark::class);
    }

    public function funnelEvents(): HasMany
    {
        return $this->hasMany(ScholarshipFunnelEvent::class);
    }

    public function dssSnapshots(): HasMany
    {
        return $this->hasMany(DssCalculationSnapshot::class);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereHas('provider', function (Builder $providerQuery): void {
                $providerQuery
                    ->where('role', 'provider')
                    ->where('account_status', 'active')
                    ->whereNotNull('email_verified_at')
                    ->whereHas('providerProfile', fn (Builder $profileQuery) => $profileQuery
                        ->where('verification_status', 'approved'));
            });
    }

    public function scopeAcceptingApplications(Builder $query): Builder
    {
        return $query
            ->discoverable()
            ->where(function (Builder $openingQuery): void {
                $openingQuery
                    ->whereNull('application_opens_at')
                    ->orWhereDate('application_opens_at', '<=', now()->toDateString());
            });
    }

    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query
            ->publiclyVisible()
            ->where(function (Builder $deadlineQuery): void {
                $deadlineQuery
                    ->whereNull('deadline')
                    ->orWhereDate('deadline', '>=', now()->toDateString());
            });
    }

    public function isAcceptingApplications(): bool
    {
        return $this->isDiscoverable()
            && ($this->application_opens_at === null || ! $this->application_opens_at->isAfter(now()->startOfDay()));
    }

    public function isDiscoverable(): bool
    {
        return $this->isPubliclyVisible()
            && ($this->deadline === null || ! $this->deadline->isBefore(now()->startOfDay()));
    }

    public function isPubliclyVisible(): bool
    {
        $this->loadMissing('provider.providerProfile');
        $provider = $this->provider;

        return $this->status === 'published'
            && $provider?->isProvider()
            && $provider->isActive()
            && $provider->hasVerifiedEmail()
            && $provider->providerProfile?->isVerified();
    }
}
