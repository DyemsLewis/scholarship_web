<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientBenefitReleaseRecord extends Model
{
    protected $fillable = [
        'recipient_benefit_release_id',
        'scholarship_application_id',
        'applicant_id',
        'status',
        'originals_verified',
        'notes',
        'receipt_original_name',
        'receipt_path',
        'receipt_mime_type',
        'receipt_size',
        'recorded_by',
        'recorded_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'originals_verified' => 'boolean',
            'recorded_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitRelease::class, 'recipient_benefit_release_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
