<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DssCalculationSnapshot extends Model
{
    protected $fillable = [
        'scholarship_application_id',
        'applicant_id',
        'scholarship_id',
        'methodology_version',
        'input_hash',
        'source',
        'eligibility_score',
        'suitability_score',
        'recommendation',
        'eligibility_breakdown',
        'dss_breakdown',
        'applicant_inputs',
        'scholarship_inputs',
        'academic_evaluation',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_score' => 'decimal:2',
            'suitability_score' => 'decimal:2',
            'eligibility_breakdown' => 'array',
            'dss_breakdown' => 'array',
            'applicant_inputs' => 'array',
            'scholarship_inputs' => 'array',
            'academic_evaluation' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }
}
