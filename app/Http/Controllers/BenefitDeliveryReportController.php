<?php

namespace App\Http\Controllers;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\ScholarshipApplication;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Services\BenefitDeliveryReportPresenter;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientAgreementCaseAccess;
use App\Services\RecipientAgreementCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BenefitDeliveryReportController extends Controller
{
    public function __construct(private readonly BenefitDeliveryReportService $reports, private readonly BenefitDeliveryReportPresenter $presenter, private readonly RecipientAgreementCaseAccess $access) {}

    public function history(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->role($request);
        $this->reports->authorizeApplication($request->user(), $application);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'legacy_page' => ['sometimes', 'integer', 'min:1']]);
        $query = $this->access->visibleTo($request->user())->where('case_type', Catalog::BENEFIT_DELIVERY)
            ->where('scholarship_application_id', $application->id)->where('status', '!=', 'draft');
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(10);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));
        $legacy = $application->benefitReceiptResponses()->where('response_type', 'issue')->whereNull('recipient_resolution_confirmed_at')->whereDoesntHave('deliveryReport');

        return response()->json([
            'reports' => $rows, 'options' => $request->user()->isApplicant() ? $this->reports->options($application) : [],
            'create_url' => $request->user()->isApplicant() ? route('dashboard.delivery-reports.store', $application, false) : null,
            'legacy_reports' => $this->legacy($request, $legacy),
        ]);
    }

    public function queue(Request $request, ProviderWorkspaceScope $scope): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'all', 'awaiting_provider', 'awaiting_recipient', 'closed'])],
            'search' => ['nullable', 'string', 'max:160'], 'program_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'], 'legacy_page' => ['sometimes', 'integer', 'min:1']]);
        $query = $this->access->visibleTo($request->user())->where('case_type', Catalog::BENEFIT_DELIVERY)->where('status', '!=', 'draft');
        $legacy = RecipientBenefitReceiptResponse::query()->whereIn('scholarship_application_id', $scope->applications($request->user())->select('scholarship_applications.id'))
            ->where('response_type', 'issue')->whereNull('recipient_resolution_confirmed_at')->whereDoesntHave('deliveryReport');
        if (filled($data['program_id'] ?? null)) {
            abort_unless($scope->programs($request->user())->whereKey($data['program_id'])->exists(), 403);
            $query->whereHas('application', fn ($q) => $q->where('scholarship_id', $data['program_id']));
            $legacy->whereHas('application', fn ($q) => $q->where('scholarship_id', $data['program_id']));
        }
        if (($data['status'] ?? 'open') === 'open') {
            $query->whereIn('status', BenefitDeliveryReportService::OPEN);
        } elseif ($data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        if (filled($data['search'] ?? null)) {
            $words = preg_split('/\s+/u', trim($data['search']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ([$query, $legacy] as $builder) {
                $builder->whereHas('application.applicant.studentProfile', function ($q) use ($words): void {
                    foreach ($words as $word) {
                        $q->where(fn ($n) => $n->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%'));
                    }
                });
            }
        }
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);
        $rows->through(fn ($case) => $this->presenter->payload($case, $request->user(), false));

        return response()->json(['reports' => $rows, 'legacy_reports' => $this->legacy($request, $legacy),
            'programs' => $scope->programs($request->user())->orderBy('title')->get(['id', 'title'])]);
    }

    public function show(Request $request, RecipientAgreementCase $report): JsonResponse
    {
        $this->authorize($request, $report);

        return $this->result($request, $report);
    }

    public function store(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->role($request);
        $this->validateFiles($request);
        $case = $this->reports->submit($request->user(), $application, $request->all(), $request->file('evidence', []));

        return $this->result($request, $case, 'Delivery report sent. The application decision is unchanged.', 201);
    }

    public function import(Request $request, RecipientBenefitReceiptResponse $receipt): JsonResponse
    {
        $this->role($request);
        $case = $this->reports->importReceipt($request->user(), $receipt);

        return $this->result($request, $case, 'Existing receipt issue opened in the delivery-report process.');
    }

    public function propose(Request $request, RecipientAgreementCase $report): JsonResponse
    {
        $this->authorize($request, $report);
        $this->validateFiles($request);

        return $this->result($request, $this->reports->propose($request->user(), $report, $this->revision($request), $request->all(), $request->file('evidence', [])),
            'Response sent. Receipt has not been confirmed by the applicant.');
    }

    public function respond(Request $request, RecipientAgreementCase $report): JsonResponse
    {
        $this->authorize($request, $report);
        $this->validateFiles($request);

        return $this->result($request, $this->reports->respond($request->user(), $report, $this->revision($request), $request->all(), $request->file('evidence', [])), 'Your response was recorded.');
    }

    public function evidence(Request $request, RecipientAgreementCaseEvidence $evidence)
    {
        $this->authorize($request, $evidence->agreementCase);

        return app(RecipientAgreementCaseService::class)->evidenceResponse($request->user(), $evidence);
    }

    private function role(Request $request): void
    {
        abort_unless($request->routeIs('dashboard.*') ? $request->user()->isApplicant() : $request->user()->isProvider(), 403);
    }

    private function authorize(Request $request, RecipientAgreementCase $case): void
    {
        $this->role($request);
        abort_unless($case->case_type === Catalog::BENEFIT_DELIVERY && $case->status !== 'draft', 403);
        $this->reports->authorizeApplication($request->user(), $case->application);
        $this->access->authorizeView($request->user(), $case);
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['expected_revision' => ['required', 'integer', 'min:1']])['expected_revision'];
    }

    private function validateFiles(Request $request): void
    {
        $request->validate(['evidence' => ['sometimes', 'array', 'max:3'], 'evidence.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
    }

    private function result(Request $request, RecipientAgreementCase $case, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(['report' => $this->presenter->payload($case, $request->user()), 'message' => $message], $status);
    }

    private function legacy(Request $request, $query): array
    {
        $prefix = $request->user()->isProvider() ? 'provider' : 'dashboard';
        $rows = $query->with(['releaseRecord.release', 'application.applicant.studentProfile'])->orderByDesc('id')->paginate(5, ['*'], 'legacy_page');
        $rows->through(fn ($receipt) => ['id' => $receipt->id, 'title' => $receipt->releaseRecord?->release?->title,
            'applicant_name' => $receipt->application->applicant->name, 'details' => $receipt->issue_details,
            'import_url' => route($prefix.'.delivery-reports.import', $receipt, false)]);

        return $rows->toArray();
    }
}
