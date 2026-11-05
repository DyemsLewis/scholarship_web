<?php

namespace App\Http\Controllers;

use App\Models\RecipientWithdrawalRequest;
use App\Models\ScholarshipApplication;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Services\RecipientWithdrawalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipientWithdrawalController extends Controller
{
    public function __construct(private readonly RecipientWithdrawalService $withdrawals) {}

    public function applicantHistory(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->withdrawals->authorizeApplicant($request->user(), $application);

        return $this->history($request, $application);
    }

    public function providerHistory(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->withdrawals->authorizeProvider($request->user(), $application);

        return $this->history($request, $application);
    }

    public function showApplicant(Request $request, RecipientWithdrawalRequest $withdrawal): JsonResponse
    {
        $this->withdrawals->authorizeApplicant($request->user(), $withdrawal->application);

        return response()->json(['request' => $this->withdrawals->payload($withdrawal, $request->user())]);
    }

    public function showProvider(Request $request, RecipientWithdrawalRequest $withdrawal): JsonResponse
    {
        $this->withdrawals->authorizeProvider($request->user(), $withdrawal->application);

        return response()->json(['request' => $this->withdrawals->payload($withdrawal, $request->user())]);
    }

    public function store(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->withdrawals->authorizeApplicant($request->user(), $application);
        $this->trim($request, 'reason');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000'], 'confirmed' => ['accepted']]);
        $withdrawal = $this->withdrawals->submit($request->user(), $application, $data['reason']);

        return response()->json([
            'message' => 'Withdrawal request sent. Your support has not been changed.',
            'request' => $this->withdrawals->payload($withdrawal, $request->user()),
        ], 201);
    }

    public function cancel(Request $request, RecipientWithdrawalRequest $withdrawal): JsonResponse
    {
        $this->withdrawals->authorizeApplicant($request->user(), $withdrawal->application);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted']]);
        $withdrawal = $this->withdrawals->cancel($request->user(), $withdrawal, (int) $data['expected_revision']);

        return response()->json([
            'message' => 'The pending request was cancelled. Your support has not been changed.',
            'request' => $this->withdrawals->payload($withdrawal, $request->user()),
        ]);
    }

    public function review(Request $request, RecipientWithdrawalRequest $withdrawal): JsonResponse
    {
        $this->withdrawals->authorizeProvider($request->user(), $withdrawal->application);
        $this->trim($request, 'review_note');
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['approved', 'declined'])],
            'review_note' => ['required', 'string', 'min:10', 'max:2000'],
            'expected_revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'],
        ]);
        $withdrawal = $this->withdrawals->review($request->user(), $withdrawal, $data);

        return response()->json([
            'message' => $withdrawal->status === 'approved' ? 'Withdrawal approved. Future scholarship benefits have ended.' : 'Withdrawal request declined. Support is unchanged.',
            'request' => $this->withdrawals->payload($withdrawal, $request->user()),
        ]);
    }

    public function queue(Request $request, ProviderWorkspaceScope $scope): JsonResponse
    {
        abort_unless($request->user()?->isProvider() && $request->user()->hasPortalPermission('manage_recipients'), 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([...RecipientWithdrawalRequest::STATUSES, 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $query = RecipientWithdrawalRequest::query()->whereIn('scholarship_application_id', $scope->applications($request->user())->select('scholarship_applications.id'));
        if (filled($data['program_id'] ?? null)) {
            abort_unless($scope->programs($request->user())->whereKey($data['program_id'])->exists(), 403);
            $query->whereHas('application', fn (Builder $applications) => $applications->where('scholarship_id', $data['program_id']));
        }
        if (filled($data['search'] ?? null)) {
            $search = '%'.trim($data['search']).'%';
            $words = preg_split('/\s+/u', trim($data['search']), -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function (Builder $requests) use ($search, $words): void {
                $requests->whereHas('application.scholarship', fn (Builder $programs) => $programs->where('title', 'like', $search))
                    ->orWhereHas('application.applicant.studentProfile', function (Builder $profiles) use ($words): void {
                        foreach ($words as $word) {
                            $profiles->where(fn (Builder $names) => $names->where('first_name', 'like', '%'.$word.'%')
                                ->orWhere('last_name', 'like', '%'.$word.'%'));
                        }
                    });
            });
        }
        if (($data['status'] ?? 'pending') !== 'all') {
            $query->where('status', $data['status'] ?? 'pending');
        }
        $requests = $query->with(['events', 'application.scholarship', 'application.applicant.studentProfile'])->orderBy('requested_at')->orderBy('id')->paginate(20);
        $requests->through(fn (RecipientWithdrawalRequest $withdrawal): array => $this->withdrawals->payload($withdrawal, $request->user()));

        return response()->json([
            'requests' => $requests,
            'programs' => $scope->programs($request->user())->orderBy('title')->get(['id', 'title']),
        ]);
    }

    private function history(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $requests = $application->recipientWithdrawalRequests()->with(['events', 'application.scholarship', 'application.applicant'])->paginate(20);
        $requests->through(fn (RecipientWithdrawalRequest $withdrawal): array => $this->withdrawals->payload($withdrawal, $request->user()));

        return response()->json([
            'can_request' => $request->user()->isApplicant() && $this->withdrawals->available($application),
            'requests' => $requests,
        ]);
    }

    private function trim(Request $request, string $field): void
    {
        if (is_string($request->input($field))) {
            $request->merge([$field => trim($request->input($field))]);
        }
    }
}
