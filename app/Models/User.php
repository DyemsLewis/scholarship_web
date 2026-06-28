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

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function providerVerificationDocuments(): HasMany
    {
        return $this->hasMany(ProviderVerificationDocument::class, 'provider_id');
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
            'account_managed_by' => $this->studentProfile?->account_managed_by,
            'display_name' => $this->adminProfile?->display_name,
            'provider_name' => $this->provider_name,
            'provider_type' => $this->provider_type,
            'provider_website' => $this->provider_website,
            'provider_address' => $this->provider_address,
            'provider_description' => $this->provider_description,
            'verification_status' => $this->providerProfile?->verification_status,
            'verification_notes' => $this->providerProfile?->verification_notes,
            'can_post_scholarships' => $this->isProvider() && $this->providerProfile?->isVerified(),
            'education_level' => $this->studentProfile?->education_level,
            'school' => $this->studentProfile?->school,
            'school_type' => $this->studentProfile?->school_type,
            'learner_reference_number' => $this->studentProfile?->learner_reference_number,
            'course_or_strand' => $this->studentProfile?->course_or_strand,
            'year_level' => $this->studentProfile?->year_level,
            'enrollment_status' => $this->studentProfile?->enrollment_status,
            'gwa' => $this->studentProfile?->gwa,
            'grading_scale' => $this->studentProfile?->grading_scale,
            'income_bracket' => $this->studentProfile?->income_bracket,
            'household_size' => $this->studentProfile?->household_size,
            'preferred_categories' => $this->studentProfile?->preferred_categories,
            'preferred_locations' => $this->studentProfile?->preferred_locations,
            'willing_to_relocate' => $this->studentProfile?->willing_to_relocate,
            'support_needs' => $this->studentProfile?->support_needs,
            'scholarship_goal' => $this->studentProfile?->scholarship_goal,
            'address' => $this->studentProfile?->address,
            'barangay' => $this->studentProfile?->barangay,
            'city' => $this->studentProfile?->city,
            'province' => $this->studentProfile?->province,
            'region' => $this->studentProfile?->region,
            'latitude' => $this->studentProfile?->latitude,
            'longitude' => $this->studentProfile?->longitude,
            'birthdate' => $this->studentProfile?->birthdate?->format('Y-m-d'),
            'guardian_name' => $this->studentProfile?->guardian_name,
            'guardian_relationship' => $this->studentProfile?->guardian_relationship,
            'guardian_contact' => $this->studentProfile?->guardian_contact,
            'guardian_email' => $this->studentProfile?->guardian_email,
            'guardian_is_account_owner' => (bool) $this->studentProfile?->guardian_is_account_owner,
            'role' => $this->role,
            'is_admin' => $this->is_admin,
        ];
    }

    public static function applicantProfileRequiredFields(?array $payload = null): array
    {
        $payload ??= [];
        $educationLevel = $payload['education_level'] ?? null;
        $accountManagedBy = $payload['account_managed_by'] ?? null;
        $requiresCoursePath = in_array($educationLevel, ['senior_high_school', 'college', 'tvet'], true);
        $requiresGrades = ! in_array($educationLevel, ['preschool'], true);
        $requiresGuardian = in_array($educationLevel, ['preschool', 'elementary', 'junior_high_school', 'senior_high_school'], true)
            || in_array($accountManagedBy, ['parent_guardian', 'relative', 'school_representative', 'other'], true);

        $fields = [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'middle_initial' => 'Middle initial',
            'contact_number' => 'Contact number',
            'account_managed_by' => 'Account managed by',
            'birthdate' => 'Birthdate',
        ];

        $fields += [
            'education_level' => 'Education level',
            'school' => 'School / learning institution',
        ];

        if ($requiresCoursePath) {
            $fields['course_or_strand'] = 'Track / strand / course / program';
        }

        $fields += [
            'year_level' => 'Grade / year level',
            'enrollment_status' => 'Enrollment status',
        ];

        if ($requiresGrades) {
            $fields += [
                'gwa' => 'GWA / general average',
                'grading_scale' => 'Grading scale',
            ];
        }

        $fields += [
            'income_bracket' => 'Household income bracket',
            'address' => 'Address',
            'barangay' => 'Barangay',
            'city' => 'City / municipality',
            'province' => 'Province',
            'region' => 'Region',
        ];

        if ($requiresGuardian) {
            $fields += [
                'guardian_name' => 'Guardian name',
                'guardian_relationship' => 'Relationship to learner',
                'guardian_contact' => 'Guardian contact',
            ];
        }

        return $fields;
    }

    public function applicantProfileReadiness(): array
    {
        $this->loadMissing('studentProfile');

        $payload = $this->publicPayload();
        $fields = self::applicantProfileRequiredFields($payload);
        $missing = collect($fields)
            ->reject(fn (string $label, string $key) => filled($payload[$key] ?? null))
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
        $total = count($fields);
        $completed = $total - count($missing);

        return [
            'complete' => $missing === [],
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 100 : (int) round(($completed / $total) * 100),
            'missing' => $missing,
        ];
    }

    public function hasCompleteApplicantProfile(): bool
    {
        return $this->applicantProfileReadiness()['complete'];
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
