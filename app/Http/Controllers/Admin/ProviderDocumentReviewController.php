<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderVerificationDocument;
use App\Services\ProviderVerificationReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderDocumentReviewController extends Controller
{
    public function update(Request $request, ProviderVerificationDocument $document, ProviderVerificationReviewService $reviews): JsonResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('manage_reviews'), 403);
        if (is_string($request->input('review_notes'))) {
            $request->merge(['review_notes' => trim($request->input('review_notes'))]);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'review_notes' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:1500'],
            'compared' => ['exclude_unless:status,approved', 'required', 'accepted'],
        ]);
        $document = $reviews->reviewDocument($request, $document, $data);

        return response()->json([
            'message' => $document->status === 'approved' ? 'Document accepted. Final account verification is still separate.' : 'Replacement requested for this document.',
            'document_id' => $document->id,
        ]);
    }
}
