<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'suffix',
        'gender',
        'middle_initial',
        'contact_number',
        'account_managed_by',
        'citizenship_status',
        'education_level',
        'school',
        'school_type',
        'learner_reference_number',
        'course_or_strand',
        'year_level',
        'enrollment_status',
        'academic_year',
        'academic_term',
        'gwa',
        'grading_scale',
        'academic_result_source',
        'academic_result_extracted_at',
        'income_bracket',
        'household_size',
        'preferred_categories',
        'preferred_locations',
        'willing_to_relocate',
        'support_needs',
        'current_scholarship_status',
        'current_scholarship_details',
        'scholarship_goal',
        'address',
        'barangay',
        'city',
        'province',
        'region',
        'latitude',
        'longitude',
        'birthdate',
        'guardian_name',
        'guardian_relationship',
        'guardian_contact',
        'guardian_email',
        'guardian_is_account_owner',
        'profile_photo_path',
        'profile_photo_original_name',
        'profile_photo_mime_type',
        'profile_photo_size',
        'profile_photo_updated_at',
        'verification_status',
        'verification_notes',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'gwa' => 'decimal:2',
            'academic_result_extracted_at' => 'datetime',
            'household_size' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'guardian_is_account_owner' => 'boolean',
            'profile_photo_size' => 'integer',
            'profile_photo_updated_at' => 'datetime',
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
}
