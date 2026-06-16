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
        'notes',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'document_checklist' => 'array',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
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
}
