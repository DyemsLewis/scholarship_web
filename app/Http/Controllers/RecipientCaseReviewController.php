<?php

namespace App\Http\Controllers;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\ScholarshipApplication;
use App\Services\RecipientAgreementCaseAccess;
use App\Services\RecipientAgreementCaseService;
use App\Services\RecipientCaseReviewPresenter;
use App\Services\RecipientCaseReviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipientCaseReviewController extends Controller
{
    public function __construct(private readonly RecipientCaseReviewService $service, private readonly RecipientCaseReviewPresenter $presenter, private readonly RecipientAgreementCaseAccess $access) {}

    public function page(Request $request)
    {
        $this->service->authorizeAdmin($request->user());

        return view('admin-recipient-cases');
    }

    public function queue(Request $request)
    {
        $this->service->authorizeAdmin($request->user());
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'all', 'requested', 'awaiting_information', 'appeal_pending', 'reviewed'])], 'type' => ['nullable', Rule::in(['agreement_concern', 'benefit_delivery'])], 'search' => ['nullable', 'string', 'max:160'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = $this->access->visibleTo($request->user())->whereNotNull('escalated_at');
        if (($data['status'] ?? 'open') === 'open') {
            $query->whereIn('review_state', ['requested', 'awaiting_information', 'appeal_pending']);
        } elseif ($data['status'] !== 'all') {
            $query->where('review_state', $data['status']);
        }
        if (filled($data['type'] ?? null)) {
            $query->where('case_type', $data['type']);
        }
        if (filled($data['search'] ?? null)) {
            $query->where('subject', 'like', '%'.trim($data['search']).'%');
        }
        $rows = $query->with(['application.applicant.studentProfile', 'application.scholarship.provider.providerProfile', 'latestReview'])->orderByRaw("CASE WHEN review_state = 'appeal_pending' THEN 0 ELSE 1 END")->orderBy('escalated_at')->paginate(20);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));

        return response()->json(['cases' => $rows]);
    }

    public function history(Request $request, ScholarshipApplication $application)
    {
        $this->role($request);
        if ($request->user()->isApplicant()) {
            abort_unless($application->applicant_id === $request->user()->id, 403);
        } else {
            abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        }
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $rows = $this->access->visibleTo($request->user())->where('scholarship_application_id', $application->id)->whereNotNull('notice_sent_at')->whereNotNull('review_state')->whereNotIn('status', ['draft', 'cancelled'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(10);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));

        return response()->json(['cases' => $rows]);
    }

    public function show(Request $request, RecipientAgreementCase $case)
    {
        $this->role($request);

        return $this->result($request, $case);
    }

    public function mutate(Request $request, RecipientAgreementCase $case, string $action)
    {
        $this->role($request);
        $admin = $request->user()->isAdmin();
        abort_unless(in_array($action, $admin ? ['information', 'outcome'] : ['request', 'statement', 'appeal'], true), 403);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'evidence' => ['sometimes', 'array', 'max:3'], 'evidence.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $result = $this->service->{$action}($request->user(), $case, (int) $data['expected_revision'], $request->all(), $request->file('evidence', []));

        return $this->result($request, $result, 'Case review updated. No automatic support or receipt change was made.');
    }

    public function evidence(Request $request, RecipientAgreementCaseEvidence $evidence)
    {
        $this->role($request);
        $this->service->authorize($request->user(), $evidence->agreementCase);

        return app(RecipientAgreementCaseService::class)->evidenceResponse($request->user(), $evidence);
    }

    private function role(Request $request): void
    {
        abort_unless($request->routeIs('admin.*') ? $request->user()->isAdmin() : ($request->routeIs('provider.*') ? $request->user()->isProvider() : $request->user()->isApplicant()), 403);
        if ($request->user()->isAdmin()) {
            $this->service->authorizeAdmin($request->user());
        }
    }

    private function result(Request $request, RecipientAgreementCase $case, ?string $message = null)
    {
        return response()->json(['case' => $this->presenter->payload($case, $request->user()), 'message' => $message]);
    }
}
