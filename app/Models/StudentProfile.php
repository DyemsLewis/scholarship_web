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
        'education_level',
        'school',
        'school_type',
        'learner_reference_number',
        'course_or_strand',
        'year_level',
        'enrollment_status',
        'gwa',
        'grading_scale',
        'income_bracket',
        'household_size',
        'preferred_categories',
        'preferred_locations',
        'willing_to_relocate',
        'support_needs',
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
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'gwa' => 'decimal:2',
            'household_size' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'guardian_is_account_owner' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
