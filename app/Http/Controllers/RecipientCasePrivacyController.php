<?php

namespace App\Http\Controllers;

use App\Models\RecipientAgreementCase;
use App\Services\RecipientCaseAccessAudit;
use App\Services\RecipientCasePrivacyService;
use App\Services\RecipientCaseReviewService;
use Illuminate\Http\Request;

class RecipientCasePrivacyController extends Controller
{
    public function __construct(private readonly RecipientCasePrivacyService $privacy, private readonly RecipientCaseAccessAudit $audit) {}

    public function show(Request $request, RecipientAgreementCase $case)
    {
        $this->role($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'access_page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(['privacy' => $this->privacy->payload($request->user(), $case, $data['page'] ?? 1),
            'access' => $this->audit->history($request->user(), $case, $data['access_page'] ?? 1)], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, RecipientAgreementCase $case)
    {
        $this->role($request);
        app(RecipientCaseReviewService::class)->authorizeAdmin($request->user());
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $this->privacy->record($request->user(), $case, $data['expected_revision'], $request->all());

        return response()->json(['privacy' => $this->privacy->payload($request->user(), $case->fresh()), 'message' => 'Internal privacy assessment saved. Nothing was shared with another provider.'], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function role(Request $request): void
    {
        abort_unless($request->routeIs('admin.*') ? $request->user()->isAdmin() : ($request->routeIs('provider.*') ? $request->user()->isProvider() : $request->user()->isApplicant()), 403);
        if ($request->user()->isAdmin()) {
            app(RecipientCaseReviewService::class)->authorizeAdmin($request->user());
        }
    }
}
