<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApplicantEvidenceReview;
use App\Models\ApplicantVerificationDocument;
use App\Models\PortalNotification;
use App\Models\ScholarshipApplication;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\AdminWorkspace;
use App\Support\AcademicRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicantEvidenceReviewService
{
    public const CLAIMS = [
        'identity_record' => ['first_name', 'last_name', 'middle_initial', 'suffix', 'birthdate', 'citizenship_status'],
        'academic_record' => ['first_name', 'last_name', 'middle_initial', 'suffix', 'school', 'education_level', 'school_type', 'learner_reference_number', 'course_or_strand', 'year_level', 'academic_year', 'academic_term', 'gwa', 'grading_scale'],
        'recent_school_id' => ['first_name', 'last_name', 'middle_initial', 'suffix', 'school', 'education_level'],
        'school_record' => ['first_name', 'last_name', 'middle_initial', 'suffix', 'school', 'enrollment_status', 'academic_year', 'academic_term', 'education_level'],
        'income_record' => ['income_bracket', 'household_size'],
        'residency_record' => ['address', 'barangay', 'city', 'province', 'region'],
        'guardian_record' => ['guardian_name', 'guardian_relationship', 'account_managed_by', 'birthdate', 'education_level'],
        'achievement_evidence' => ['achievements'],
        'qualification_record' => ['first_name', 'last_name', 'middle_initial', 'suffix', 'completed_qualification', 'completed_qualification_school', 'completed_qualification_year', 'completed_qualification_details'],
    ];

    public function review(User $actor, ApplicantVerificationDocument $document, array $data): ApplicantVerificationDocument
    {
        abort_unless($actor->isAdmin() && $actor->hasPortalPermission('manage_reviews')
            && ! AdminWorkspace::usesPortalManagerWorkspace($actor), 403);

        return DB::transaction(function () use ($actor, $document, $data): ApplicantVerificationDocument {
            $applicant = User::query()->lockForUpdate()->findOrFail($document->applicant_id);
            abort_unless($applicant->isApplicant(), 404);
            $document = $applicant->applicantVerificationDocuments()->lockForUpdate()->findOrFail($document->id);
            abort_unless(in_array($document->document_type, ApplicantVerificationDocument::PROFILE_UPLOAD_TYPES, true), 422);
            abort_if($document->revision !== (int) $data['expected_revision'], 409, 'This record changed. Reload it before recording a decision.');
            if ($data['status'] === 'approved' && ! Storage::disk('local')->exists($document->path)) {
                throw ValidationException::withMessages(['document' => 'The stored document is missing. Ask the applicant to upload a replacement.']);
            }
            $previous = $document->status;
            $academicResult = null;
            $previousAcademicResult = null;
            $academicChanged = false;
            if ($document->document_type === 'academic_record' && $data['status'] === 'approved') {
                $scale = is_string($data['academic_grading_scale'] ?? null) ? $data['academic_grading_scale'] : null;
                $numeric = AcademicRequirement::requiresNumeric($scale);
                $result = Validator::make($data, [
                    'academic_grading_scale' => ['required', 'string', Rule::in(AcademicRequirement::SCALES)],
                    'academic_result' => $numeric
                        ? ['required', 'numeric', 'gt:0', $scale === 'grade_point' ? 'max:5' : 'max:100']
                        : ['nullable', 'prohibited'],
                ])->validate();
                $academicResult = ['grading_scale' => $result['academic_grading_scale'], 'gwa' => $numeric ? (float) $result['academic_result'] : null];
                $profile = $applicant->studentProfile;
                $previousAcademicResult = ['grading_scale' => $profile?->grading_scale, 'gwa' => $profile?->gwa !== null ? (float) $profile->gwa : null];
                $academicChanged = $academicResult !== $previousAcademicResult;
                // These claims are being checked against this locked document revision.
                // Avoid invalidating the very acceptance recorded in this transaction.
                StudentProfile::withoutEvents(fn () => $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
                    ...$academicResult, 'academic_result_source' => 'admin_review', 'academic_result_extracted_at' => now(),
                ]));
                $applicant->unsetRelation('studentProfile');
            }
            $document->fill([
                'status' => $data['status'], 'review_notes' => $data['review_notes'] ?? null,
                'revision' => $document->revision + 1, 'reviewed_revision' => $document->revision + 1,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ])->save();
            $this->record($document, $data['status'] === 'approved' ? 'accepted' : 'replacement_requested', $previous, $actor, $document->review_notes,
                $academicChanged ? ['grading_scale', 'gwa'] : []);
            if (in_array($document->document_type, ApplicantVerificationDocument::requiredTypesFor($applicant), true)
                || ($document->document_type === 'achievement_evidence' && filled($applicant->studentProfile?->achievements))) {
                $this->reopenProfile($applicant);
            }
            ActivityLog::record($actor, 'applicant_evidence_reviewed', 'Recorded an individual profile evidence decision.', null, [
                'applicant_id' => $applicant->id, 'document_id' => $document->id,
                'document_type' => $document->document_type, 'revision' => $document->revision, 'status' => $document->status,
                'previous_academic_result' => $previousAcademicResult, 'verified_academic_result' => $academicResult,
            ]);
            if ($academicChanged) {
                ScholarshipApplication::query()->where('applicant_id', $applicant->id)
                    ->where(fn ($query) => $query->whereNull('application_state')->orWhereNotIn('application_state', ['closed', 'withdrawn']))
                    ->get()->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)
                        ->syncApplication($application, 'admin_academic_result_corrected'));
            }
            PortalNotification::create([
                'user_id' => $applicant->id, 'type' => 'applicant_profile_verification',
                'title' => $document->status === 'approved' ? 'Supporting document accepted' : 'Replace a supporting document',
                'message' => (ApplicantVerificationDocument::EVIDENCE_DETAILS[$document->document_type]['label'] ?? 'Supporting document')
                    .($document->status === 'approved' ? ' was accepted for profile review. Overall profile verification is a separate decision.' : ': '.$document->review_notes),
                'action_url' => '/dashboard/profile?section=verification&evidence='.$document->document_type,
            ]);

            return $document->fresh('reviewer.adminProfile');
        });
    }

    public function blockers(User $applicant): array
    {
        $applicant->unsetRelation('studentProfile');
        $documents = $applicant->applicantVerificationDocuments()->get()->keyBy('document_type');

        return collect(ApplicantVerificationDocument::requiredTypesFor($applicant))
            ->filter(fn (string $type): bool => ! $documents->get($type)?->hasCurrentAcceptance())
            ->values()->all();
    }

    public function assertReady(User $applicant): void
    {
        $blockers = $this->blockers($applicant);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['evidence_reviews' => 'Accept each required supporting document before verifying the profile: '.collect($blockers)
                ->map(fn (string $type): string => ApplicantVerificationDocument::EVIDENCE_DETAILS[$type]['label'])->implode(', ').'.']);
        }
        foreach ($applicant->applicantVerificationDocuments()->whereIn('document_type', ApplicantVerificationDocument::requiredTypesFor($applicant))->get() as $document) {
            if (! Storage::disk('local')->exists($document->path)) {
                throw ValidationException::withMessages(['evidence_reviews' => 'A required supporting document is no longer available. Request a replacement before approval.']);
            }
        }
    }

    public function summary(User $applicant): array
    {
        $required = ApplicantVerificationDocument::requiredTypesFor($applicant);
        $documents = $applicant->applicantVerificationDocuments()->get()->keyBy('document_type');
        $accepted = collect($required)->filter(fn (string $type): bool => $documents->get($type)?->hasCurrentAcceptance()
            && Storage::disk('local')->exists($documents[$type]->path))->values()->all();
        $missing = array_values(array_diff($required, $documents->keys()->all()));
        $rejected = collect($required)->filter(fn (string $type): bool => $documents->get($type)?->status === 'rejected')->values()->all();

        return [
            'required_count' => count($required), 'accepted_count' => count($accepted),
            'missing_types' => $missing, 'replacement_types' => $rejected,
            'blocking_types' => array_values(array_diff($required, $accepted)),
            'required_evidence_accepted' => count($required) === count($accepted),
            'legacy_profile_approval' => $applicant->studentProfile?->verification_status === 'approved' && count($accepted) < count($required),
            'assurance_notice' => 'Acceptance records a reviewer decision about submitted evidence, not authentication by the issuing authority.',
        ];
    }

    public function invalidateClaims(StudentProfile $profile): void
    {
        $changed = array_values(array_filter(array_keys($profile->getChanges()), fn (string $field): bool => ! in_array($field, ['first_name', 'last_name', 'middle_initial', 'suffix'], true)
            || strcasecmp(trim((string) $profile->getOriginal($field)), trim((string) $profile->getAttribute($field))) !== 0));
        $types = collect(self::CLAIMS)->filter(fn (array $fields): bool => array_intersect($changed, $fields) !== [])->keys()->all();
        if ($types === []) {
            return;
        }
        DB::transaction(function () use ($profile, $changed, $types): void {
            $applicant = User::query()->lockForUpdate()->findOrFail($profile->user_id);
            foreach ($applicant->applicantVerificationDocuments()->whereIn('document_type', $types)->lockForUpdate()->get() as $document) {
                $previous = $document->status;
                $document->fill(['revision' => $document->revision + 1, 'status' => 'submitted', 'review_notes' => null,
                    'reviewed_by' => null, 'reviewed_at' => null, 'reviewed_revision' => null])->save();
                $this->record($document, 'claims_changed', $previous, auth()->user(), null, array_values(array_intersect($changed, self::CLAIMS[$document->document_type])));
            }
            $this->reopenProfile($applicant);
        });
    }

    public function uploaded(ApplicantVerificationDocument $document, bool $replacement = false): void
    {
        $this->record($document, $replacement ? 'file_replaced' : 'uploaded', $replacement ? $document->getOriginal('status') : null, auth()->user());
        if ($document->status === 'submitted' && (in_array($document->document_type, ApplicantVerificationDocument::requiredTypesFor($document->applicant), true)
            || ($document->document_type === 'achievement_evidence' && filled($document->applicant->studentProfile?->achievements)))) {
            $this->reopenProfile($document->applicant);
        }
    }

    public function deleting(ApplicantVerificationDocument $document): void
    {
        $this->record($document, 'removed', $document->status, auth()->user());
    }

    public function resetReviews(User $applicant, string $note): void
    {
        foreach ($applicant->applicantVerificationDocuments()->whereIn('document_type', ApplicantVerificationDocument::PROFILE_UPLOAD_TYPES)->lockForUpdate()->get() as $document) {
            $previous = $document->status;
            $document->fill(['revision' => $document->revision + 1, 'status' => 'submitted', 'review_notes' => null,
                'reviewed_revision' => null, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            $this->record($document, 'review_reopened', $previous, auth()->user(), $note);
        }
    }

    public function reopenProfile(User $applicant): void
    {
        $documents = $applicant->applicantVerificationDocuments()->get();
        $required = ApplicantVerificationDocument::requiredTypesFor($applicant->fresh('studentProfile'));
        $rejected = $documents->whereIn('document_type', $required)->firstWhere('status', 'rejected');
        $applicant->studentProfile()->update([
            'verification_status' => ! $documents->contains('document_type', 'academic_record') ? 'unsubmitted' : ($rejected ? 'rejected' : 'pending'),
            'verification_notes' => $rejected?->review_notes, 'verified_by' => null, 'verified_at' => null,
        ]);
        $applicant->unsetRelation('studentProfile');
    }

    private function record(ApplicantVerificationDocument $document, string $action, ?string $previous, ?User $actor, ?string $note = null, array $changedFields = []): void
    {
        ApplicantEvidenceReview::create([
            'applicant_id' => $document->applicant_id, 'document_id' => $document->id,
            'document_type' => $document->document_type, 'original_name' => $document->original_name,
            'revision' => $document->revision, 'action' => $action, 'from_status' => $previous,
            'to_status' => $action === 'removed' ? null : $document->status,
            'actor_id' => $actor?->id, 'actor_name' => $actor?->name, 'actor_role' => $actor?->role,
            'note' => $note, 'changed_fields' => $changedFields ?: null,
        ]);
    }
}
