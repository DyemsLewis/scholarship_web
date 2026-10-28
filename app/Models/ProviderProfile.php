<?php

namespace App\Models;

use App\Support\CoverImageCatalog;
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
        'representative_position',
        'provider_name',
        'provider_type',
        'provider_website',
        'provider_address',
        'latitude',
        'longitude',
        'provider_description',
        'mission',
        'year_established',
        'service_area',
        'logo_path',
        'cover_image_source',
        'cover_image_key',
        'cover_image_path',
        'provider_contact_email',
        'provider_contact_number',
        'contact_department',
        'office_hours',
        'legal_name',
        'registration_authority',
        'registration_number',
        'registration_date',
        'verification_status',
        'verification_notes',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'year_established' => 'integer',
            'registration_date' => 'date',
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

    public function coverImageUrl(): string
    {
        return CoverImageCatalog::providerUrl($this);
    }
}
