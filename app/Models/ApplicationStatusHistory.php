<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusHistory extends Model
{
    protected $hidden = ['selection_evaluation_snapshot'];

    protected $fillable = [
        'scholarship_application_id',
        'changed_by',
        'from_status',
        'to_status',
        'decision_reason',
        'review_notes',
        'changed_at',
        'selection_evaluation_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'selection_evaluation_snapshot' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
