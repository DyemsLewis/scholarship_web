<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantVerificationDocument extends Model
{
    public const PROFILE_UPLOAD_TYPES = [
        'academic_record',
        'recent_school_id',
        'school_record',
        'identity_record',
        'income_record',
        'residency_record',
        'guardian_record',
        'achievement_evidence',
    ];

    // Retired evidence remains accessible in existing review records.
    public const PROFILE_EVIDENCE_TYPES = [
        ...self::PROFILE_UPLOAD_TYPES,
        'no_current_scholarship_proof',
    ];

    public const EVIDENCE_DETAILS = [
        'identity_record' => ['label' => 'Identity and birth record', 'supports' => 'Official name and date of birth', 'examples' => 'Birth certificate or valid government-issued ID showing your name and date of birth.', 'icon' => 'fa-solid fa-address-card'],
        'recent_school_id' => ['label' => 'Recent school ID', 'supports' => 'Student identity and school', 'examples' => 'Clear front of your current or most recent school ID.', 'icon' => 'fa-solid fa-id-card'],
        'academic_record' => ['label' => 'Academic record', 'supports' => 'Education level, course or strand, year level, and academic result', 'examples' => 'Latest report card, certified grades, transcript, or assessment record.', 'icon' => 'fa-solid fa-file-lines'],
        'school_record' => ['label' => 'School enrollment proof', 'supports' => 'Current school, enrollment status, and academic year', 'examples' => 'Current certificate of enrollment or school registration form.', 'icon' => 'fa-solid fa-school'],
        'income_record' => ['label' => 'Household income proof', 'supports' => 'Declared household income and financial circumstances', 'examples' => 'Income tax return, payslip, certificate of income, or certificate of indigency. Use a parent or guardian record when they support your household.', 'icon' => 'fa-solid fa-file-invoice'],
        'residency_record' => ['label' => 'Residence proof', 'supports' => 'Barangay, city or municipality, province, and region', 'examples' => 'Barangay residency certificate or a recent utility bill matching your residence. A parent or guardian record is accepted when you live with them.', 'icon' => 'fa-solid fa-location-dot'],
        'guardian_record' => ['label' => 'Parent or guardian ID', 'supports' => 'Named parent, guardian, or person managing the account', 'examples' => 'Valid ID of the parent or guardian named in your profile.', 'icon' => 'fa-solid fa-user-shield'],
    ];

    public static function requirementsFor(User $user): array
    {
        $profile = $user->studentProfile;
        $requiresGuardian = User::applicantRequiresGuardian([
            'birthdate' => $profile?->birthdate,
            'education_level' => $profile?->education_level,
            'account_managed_by' => $profile?->account_managed_by,
        ]);

        return collect(self::EVIDENCE_DETAILS)
            ->map(fn (array $details, string $type): array => [
                'value' => $type,
                ...$details,
                'required' => $type !== 'guardian_record' || $requiresGuardian,
            ])
            ->values()
            ->all();
    }

    public static function requiredTypesFor(User $user): array
    {
        return collect(self::requirementsFor($user))->where('required', true)->pluck('value')->all();
    }

    public static function missingRequiredTypesFor(User $user): array
    {
        $uploadedTypes = $user->applicantVerificationDocuments()->pluck('document_type')->all();

        return array_values(array_diff(self::requiredTypesFor($user), $uploadedTypes));
    }

    protected $fillable = [
        'applicant_id',
        'uploaded_by',
        'document_type',
        'original_name',
        'path',
        'mime_type',
        'size',
        'status',
        'review_notes',
        'uploaded_at',
        'terms_accepted_at',
        'terms_version',
        'ocr_status',
        'ocr_provider',
        'ocr_grade',
        'ocr_grading_scale',
        'ocr_label',
        'ocr_message',
        'ocr_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'ocr_grade' => 'decimal:2',
            'ocr_processed_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
