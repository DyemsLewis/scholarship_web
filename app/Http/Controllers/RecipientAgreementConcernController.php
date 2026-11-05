<?php

namespace App\Http\Controllers;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\ScholarshipApplication;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Services\RecipientAgreementCaseAccess;
use App\Services\RecipientAgreementCaseService;
use App\Services\RecipientAgreementConcernPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipientAgreementConcernController extends Controller
{
    public function __construct(
        private readonly RecipientAgreementCaseService $cases,
        private readonly RecipientAgreementCaseAccess $access,
        private readonly RecipientAgreementConcernPresenter $presenter,
    ) {}

    public function history(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->authorizeApplication($request, $application);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $query = $this->access->visibleTo($request->user())->where('case_type', Catalog::AGREEMENT_CONCERN)
            ->where('scholarship_application_id', $application->id);
        $rows = $query->with(['application.scholarship', 'application.applicant.studentProfile'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(10);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));
        $options = $request->user()->isProvider() ? $this->cases->concernOptions($application) : [];

        return response()->json([
            'cases' => $rows, 'options' => $options,
            'create_url' => $options ? route('provider.agreement-concerns.store', $application, false) : null,
        ]);
    }

    public function queue(Request $request, ProviderWorkspaceScope $scope): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'all', ...Catalog::STATUSES])],
            'page' => ['sometimes', 'integer', 'min:1'], 'program_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:160'],
        ]);
        $query = $this->access->visibleTo($request->user())->where('case_type', Catalog::AGREEMENT_CONCERN);
        if (filled($data['program_id'] ?? null)) {
            abort_unless($scope->programs($request->user())->whereKey($data['program_id'])->exists(), 403);
            $query->whereHas('application', fn ($q) => $q->where('scholarship_id', $data['program_id']));
        }
        $status = $data['status'] ?? 'open';
        if ($status === 'open') {
            $query->whereIn('status', ['draft', 'awaiting_response', 'under_review', 'appeal_pending']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (filled($data['search'] ?? null)) {
            $words = preg_split('/\s+/u', trim($data['search']), -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function (Builder $q) use ($words, $data): void {
                $q->where('subject', 'like', '%'.trim($data['search']).'%')
                    ->orWhereHas('application.applicant.studentProfile', function (Builder $profiles) use ($words): void {
                        foreach ($words as $word) {
                            $profiles->where(fn ($names) => $names->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%'));
                        }
                    });
            });
        }
        $rows = $query->with(['application.scholarship', 'application.applicant.studentProfile'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));

        return response()->json(['cases' => $rows, 'programs' => $scope->programs($request->user())->orderBy('title')->get(['id', 'title'])]);
    }

    public function show(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);

        return $this->result($request, $concern);
    }

    public function store(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $case = $this->cases->createDraft($request->user(), $application, Catalog::AGREEMENT_CONCERN, $request->all());

        return $this->result($request, $case, 'Private draft saved. No notice has been sent.', 201);
    }

    public function update(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $case = $this->cases->reviseDraft($request->user(), $concern, $this->revision($request), $request->all());

        return $this->result($request, $case, 'Private draft updated.');
    }

    public function cancel(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $request->validate(['confirmed' => ['accepted']]);
        $case = $this->cases->cancelDraft($request->user(), $concern, $this->revision($request));

        return $this->result($request, $case, 'Unsent draft cancelled. Scholarship support is unchanged.');
    }

    public function upload(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $request->validate(['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $this->cases->attachDraftEvidence($request->user(), $concern, $this->revision($request), $request->file('evidence'));

        return $this->result($request, $concern->fresh(), 'Private evidence added.');
    }

    public function exclude(Request $request, RecipientAgreementCase $concern, RecipientAgreementCaseEvidence $evidence): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $case = $this->cases->excludeDraftEvidence($request->user(), $concern, $this->revision($request), $evidence);

        return $this->result($request, $case, 'Attachment removed from this unsent draft.');
    }

    public function issue(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $case = $this->cases->issueConcern($request->user(), $concern, $this->revision($request), $request->all());

        return $this->result($request, $case, 'Notice sent. No finding or support change was made.');
    }

    public function respond(Request $request, RecipientAgreementCase $concern): JsonResponse
    {
        $this->authorizeConcern($request, $concern);
        $request->validate(['evidence' => ['sometimes', 'array', 'max:3'], 'evidence.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $case = $this->cases->respondToConcern($request->user(), $concern, $this->revision($request), $request->all(), $request->file('evidence', []));

        return $this->result($request, $case, 'Your response was sent for review. Scholarship support is unchanged.');
    }

    public function evidence(Request $request, RecipientAgreementCaseEvidence $evidence)
    {
        $this->authorizeConcern($request, $evidence->agreementCase);

        return $this->cases->evidenceResponse($request->user(), $evidence);
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['expected_revision' => ['required', 'integer', 'min:1']])['expected_revision'];
    }

    private function authorizeApplication(Request $request, ScholarshipApplication $application): void
    {
        $actor = $request->user();
        $this->authorizeEndpointRole($request);
        if ($actor->isApplicant()) {
            abort_unless($actor->isActive() && $application->applicant_id === $actor->id, 403);
        } else {
            $this->access->authorizeCreate($actor, $application, Catalog::AGREEMENT_CONCERN);
        }
    }

    private function authorizeConcern(Request $request, RecipientAgreementCase $case): void
    {
        $this->authorizeEndpointRole($request);
        $actor = $request->user();
        abort_unless($case->case_type === Catalog::AGREEMENT_CONCERN && ($actor->isProvider() || $actor->isApplicant()), 403);
        $this->access->authorizeView($actor, $case);
    }

    private function authorizeEndpointRole(Request $request): void
    {
        abort_unless($request->routeIs('dashboard.*') ? $request->user()->isApplicant() : $request->user()->isProvider(), 403);
    }

    private function result(Request $request, RecipientAgreementCase $case, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(['case' => $this->presenter->payload($case, $request->user()), 'message' => $message], $status);
    }
}
