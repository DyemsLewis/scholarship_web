<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\ApplicantEvidenceReviewService;
use Illuminate\Support\Facades\Storage;

trait AcceptsProfileEvidence
{
    private function acceptProfileEvidence(User $applicant, ?User $reviewer = null): void
    {
        $reviewer ??= User::factory()->create(['role' => 'admin']);
        foreach ($applicant->applicantVerificationDocuments()->get() as $document) {
            if (! Storage::disk('local')->exists($document->path)) {
                Storage::disk('local')->put($document->path, 'Test supporting record');
            }
            app(ApplicantEvidenceReviewService::class)->review($reviewer, $document, [
                'status' => 'approved', 'expected_revision' => $document->revision,
                ...($document->document_type === 'academic_record' ? [
                    'academic_grading_scale' => $applicant->fresh()->studentProfile?->grading_scale ?: 'percentage',
                    'academic_result' => \App\Support\AcademicRequirement::requiresNumeric($applicant->studentProfile?->grading_scale ?: 'percentage')
                        ? ($applicant->studentProfile?->gwa ?? $document->ocr_grade ?? 90) : null,
                ] : []),
            ]);
        }
    }
}
