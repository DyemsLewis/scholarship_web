<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicantEvidenceReview;
use App\Models\ApplicantVerificationDocument;
use App\Models\User;
use App\Services\ApplicantEvidenceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicantEvidenceReviewController extends Controller
{
    public function update(Request $request, ApplicantVerificationDocument $document, ApplicantEvidenceReviewService $reviews): JsonResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('manage_reviews'), 403);
        $request->merge(['review_notes' => is_string($request->input('review_notes')) ? trim($request->input('review_notes')) : $request->input('review_notes')]);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'review_notes' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:1500'],
            'academic_grading_scale' => ['sometimes', Rule::prohibitedIf($document->document_type !== 'academic_record' || $request->input('status') !== 'approved')],
            'academic_result' => ['sometimes', Rule::prohibitedIf($document->document_type !== 'academic_record' || $request->input('status') !== 'approved')],
        ]);
        $document = $reviews->review($request->user(), $document, $data);
        $applicant = $document->applicant->fresh();

        return response()->json([
            'message' => $document->status === 'approved' ? 'Supporting document accepted.' : 'Replacement requested for this document.',
            'document' => ['id' => $document->id, 'document_type' => $document->document_type,
                'status' => $document->status, 'review_notes' => $document->review_notes, ...$document->reviewPayload()],
            'evidence_review' => $reviews->summary($applicant),
            'user' => $applicant->publicPayload(),
        ]);
    }

    public function history(Request $request, User $applicant): JsonResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('manage_reviews'), 403);
        abort_unless($applicant->isApplicant(), 404);

        return response()->json(['history' => ApplicantEvidenceReview::where('applicant_id', $applicant->id)
            ->orderByDesc('id')->paginate(25, ['id', 'document_id', 'document_type', 'original_name', 'revision', 'action', 'from_status', 'to_status', 'actor_name', 'actor_role', 'note', 'changed_fields', 'created_at'])]);
    }
}
