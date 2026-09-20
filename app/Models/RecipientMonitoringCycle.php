<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientMonitoringCycle extends Model
{
    protected $fillable = [
        'scholarship_id',
        'created_by',
        'title',
        'period_type',
        'academic_period',
        'school_year',
        'opens_at',
        'due_at',
        'minimum_grade',
        'grading_scale',
        'instructions',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'date',
            'due_at' => 'date',
            'minimum_grade' => 'decimal:2',
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

    public function submissions(): HasMany
    {
        return $this->hasMany(RecipientMonitoringSubmission::class);
    }
}
