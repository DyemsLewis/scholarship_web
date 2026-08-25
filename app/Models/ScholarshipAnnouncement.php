<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipAnnouncement extends Model
{
    protected $fillable = [
        'scholarship_id',
        'audience',
        'title',
        'message',
        'recipient_count',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
