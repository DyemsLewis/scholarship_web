<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    protected $fillable = [
        'user_id',
        'document_name',
        'original_name',
        'path',
        'mime_type',
        'size',
        'uploaded_at',
        'terms_accepted_at',
        'terms_version',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
