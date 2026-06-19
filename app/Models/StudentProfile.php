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
        'middle_initial',
        'contact_number',
        'school',
        'course_or_strand',
        'year_level',
        'enrollment_status',
        'gwa',
        'grading_scale',
        'income_bracket',
        'address',
        'barangay',
        'city',
        'province',
        'region',
        'birthdate',
        'guardian_name',
        'guardian_contact',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'gwa' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
