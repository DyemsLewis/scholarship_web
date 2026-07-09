<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipApplication extends Model
{
    protected $fillable = [
        'scholarship_id',
        'applicant_id',
        'status',
        'document_checklist',
        'eligibility_score',
        'eligibility_breakdown',
        'notes',
        'review_notes',
        'decision_reason',
        'awarded_amount',
        'outcome_notes',
        'outcome_at',
        'dss_score',
        'dss_recommendation',
        'dss_breakdown',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
        'terms_accepted_at',
        'terms_version',
        'provider_contract_terms_snapshot',
        'provider_contract_terms_accepted_at',
        'provider_contract_terms_version',
        'provider_contract_acceptance_ip',
        'provider_contract_acceptance_user_agent',
        'student_response_status',
        'student_responded_at',
        'student_response_terms_accepted_at',
        'student_response_terms_version',
        'student_response_note',
    ];

    protected function casts(): array
    {
        return [
            'document_checklist' => 'array',
            'eligibility_score' => 'decimal:2',
            'eligibility_breakdown' => 'array',
            'awarded_amount' => 'decimal:2',
            'outcome_at' => 'datetime',
            'dss_score' => 'decimal:2',
            'dss_breakdown' => 'array',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'provider_contract_terms_snapshot' => 'array',
            'provider_contract_terms_accepted_at' => 'datetime',
            'student_responded_at' => 'datetime',
            'student_response_terms_accepted_at' => 'datetime',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class);
    }
}
