<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ProviderVerificationDocument extends Model
{
    protected $fillable = [
        'provider_id',
        'uploaded_by',
        'document_type',
        'original_name',
        'path',
        'mime_type',
        'size',
        'status',
        'review_notes',
        'revision',
        'reviewed_by',
        'reviewed_at',
        'uploaded_at',
        'terms_accepted_at',
        'terms_version',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'reviewed_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewStatus(): string
    {
        // Old account-level approvals are not individual document reviews.
        return $this->reviewed_by && $this->reviewed_at ? $this->status : 'submitted';
    }

    public static function invalidateFor(User $provider): void
    {
        $provider->providerVerificationDocuments()->update([
            'status' => 'submitted', 'review_notes' => null,
            'reviewed_by' => null, 'reviewed_at' => null,
            'revision' => DB::raw('revision + 1'),
        ]);
    }
}
