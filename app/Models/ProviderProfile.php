<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderProfile extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'middle_initial',
        'contact_number',
        'provider_name',
        'provider_type',
        'provider_website',
        'provider_address',
        'provider_description',
        'verification_status',
        'verification_notes',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'approved';
    }
}
