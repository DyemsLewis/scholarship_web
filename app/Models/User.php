<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'username',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function adminProfile(): HasOne
    {
        return $this->hasOne(AdminProfile::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(PortalNotification::class);
    }

    public function scholarshipBookmarks(): HasMany
    {
        return $this->hasMany(ScholarshipBookmark::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isProvider(): bool
    {
        return $this->role === 'provider';
    }

    public function isApplicant(): bool
    {
        return $this->role === 'applicant';
    }

    public function getNameAttribute(): string
    {
        if ($this->isProvider() && $this->provider_name) {
            return $this->provider_name;
        }

        if ($this->isAdmin() && $this->adminProfile?->display_name) {
            return $this->adminProfile->display_name;
        }

        return $this->contactName() ?: ($this->username ?: $this->email);
    }

    public function getFirstNameAttribute(): ?string
    {
        return $this->profileRecord()?->first_name;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->profileRecord()?->last_name;
    }

    public function getMiddleInitialAttribute(): ?string
    {
        return $this->profileRecord()?->middle_initial;
    }

    public function getContactNumberAttribute(): ?string
    {
        return $this->profileRecord()?->contact_number;
    }

    public function getProviderNameAttribute(): ?string
    {
        return $this->providerProfile?->provider_name;
    }

    public function getProviderTypeAttribute(): ?string
    {
        return $this->providerProfile?->provider_type;
    }

    public function getProviderWebsiteAttribute(): ?string
    {
        return $this->providerProfile?->provider_website;
    }

    public function getProviderAddressAttribute(): ?string
    {
        return $this->providerProfile?->provider_address;
    }

    public function getProviderDescriptionAttribute(): ?string
    {
        return $this->providerProfile?->provider_description;
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    public function publicPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_initial' => $this->middle_initial,
            'email' => $this->email,
            'username' => $this->username,
            'contact_number' => $this->contact_number,
            'provider_name' => $this->provider_name,
            'provider_type' => $this->provider_type,
            'provider_website' => $this->provider_website,
            'provider_address' => $this->provider_address,
            'provider_description' => $this->provider_description,
            'verification_status' => $this->providerProfile?->verification_status,
            'verification_notes' => $this->providerProfile?->verification_notes,
            'can_post_scholarships' => $this->isProvider() && $this->providerProfile?->isVerified(),
            'school' => $this->studentProfile?->school,
            'course_or_strand' => $this->studentProfile?->course_or_strand,
            'year_level' => $this->studentProfile?->year_level,
            'enrollment_status' => $this->studentProfile?->enrollment_status,
            'gwa' => $this->studentProfile?->gwa,
            'grading_scale' => $this->studentProfile?->grading_scale,
            'income_bracket' => $this->studentProfile?->income_bracket,
            'address' => $this->studentProfile?->address,
            'barangay' => $this->studentProfile?->barangay,
            'city' => $this->studentProfile?->city,
            'province' => $this->studentProfile?->province,
            'region' => $this->studentProfile?->region,
            'latitude' => $this->studentProfile?->latitude,
            'longitude' => $this->studentProfile?->longitude,
            'birthdate' => $this->studentProfile?->birthdate?->format('Y-m-d'),
            'guardian_name' => $this->studentProfile?->guardian_name,
            'guardian_contact' => $this->studentProfile?->guardian_contact,
            'role' => $this->role,
            'is_admin' => $this->is_admin,
        ];
    }

    private function profileRecord()
    {
        return match ($this->role) {
            'admin' => $this->adminProfile,
            'provider' => $this->providerProfile,
            default => $this->studentProfile,
        };
    }

    private function contactName(): ?string
    {
        $parts = collect([
            $this->first_name,
            $this->middle_initial ? "{$this->middle_initial}." : null,
            $this->last_name,
        ])->filter()->values();

        return $parts->isEmpty() ? null : $parts->implode(' ');
    }
}
