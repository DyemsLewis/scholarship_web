<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\RecipientBenefitPayoutDetails;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Services\RecipientBenefitPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BenefitPayoutReviewController extends Controller
{
    public function __construct(private readonly RecipientBenefitPayoutService $payouts) {}

    public function show(Request $request, RecipientBenefitReleaseRecord $record): JsonResponse
    {
        $this->authorizeProgram($request, $record);

        return DB::transaction(function () use ($request, $record): JsonResponse {
            [$record, $details] = $this->lockedReview($request, $record);
            $this->audit($request, $record, $details->revision, 'viewed');
            $destination = $details->destination;

            return response()->json([
                'recipient_name' => $record->applicant?->name,
                'release_title' => $record->release->title,
                'method' => $details->method,
                'status' => $details->review_status,
                'revision' => $details->revision,
                'submitted_label' => $details->submitted_at->format('M d, Y h:i A'),
                'destination' => [
                    'institution' => $destination['institution'],
                    'account_holder' => $destination['account_holder'],
                    'account_number' => $destination['account_number'],
                ],
                'correction_reasons' => RecipientBenefitPayoutService::CORRECTION_REASONS,
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    public function review(Request $request, RecipientBenefitReleaseRecord $record): JsonResponse
    {
        $this->authorizeProgram($request, $record);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_correction'])],
            'correction_reason' => ['required_if:decision,request_correction', 'prohibited_unless:decision,request_correction', Rule::in(array_keys(RecipientBenefitPayoutService::CORRECTION_REASONS))],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'confirmed' => ['accepted'],
        ]);

        return DB::transaction(function () use ($request, $record, $input): JsonResponse {
            [$record, $details] = $this->lockedReview($request, $record);
            abort_unless($details->revision === (int) $input['expected_revision'], 409,
                'These payout details changed. Reopen the review to check the current submission.');

            $approved = $input['decision'] === 'approve';
            abort_if($approved && $details->review_status !== 'submitted', 409, 'These details have already been approved.');
            $reviewedRevision = $details->revision;
            $details->update([
                'review_status' => $approved ? 'approved' : 'correction_requested',
                'revision' => $reviewedRevision + 1,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'correction_reason' => $approved ? null : $input['correction_reason'],
            ]);
            $action = $approved ? 'approved' : 'correction_requested';
            $this->audit($request, $record, $reviewedRevision, $action);
            $title = $approved ? 'Payout details approved' : 'Correct your payout details';
            PortalNotification::create([
                'user_id' => $record->applicant_id,
                'type' => 'benefit_payout_'.$action,
                'title' => $title,
                'message' => $approved
                    ? 'The provider approved your payout details. The benefit has not been marked sent.'
                    : 'The provider requested a correction. Open your benefit release to review the reason and submit new details.',
                'action_url' => route('dashboard.monitoring.benefits.show', [
                    'application' => $record->scholarship_application_id,
                    'record' => 'release-'.$record->id,
                ], false),
            ]);

            return response()->json(['message' => $approved ? 'Payout details approved.' : 'Correction requested.']);
        });
    }

    private function authorizeProgram(Request $request, RecipientBenefitReleaseRecord $record): void
    {
        $actor = $request->user();
        abort_unless($actor?->isProvider() && $actor->hasPortalPermission('manage_benefit_releases')
            && $actor->canAccessProviderProgram($record->release?->scholarship), 403);
    }

    /** @return array{RecipientBenefitReleaseRecord, RecipientBenefitPayoutDetails} */
    private function lockedReview(Request $request, RecipientBenefitReleaseRecord $record): array
    {
        $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($record->scholarship_application_id);
        $record = RecipientBenefitReleaseRecord::query()->lockForUpdate()->findOrFail($record->id);
        $this->authorizeProgram($request, $record);
        abort_unless($this->payouts->isActive($record, $application), 409, 'This release is not accepting payout reviews.');
        $details = $this->payouts->currentDetails($record);
        abort_unless($details && in_array($details->review_status, ['submitted', 'approved'], true), 409,
            'There are no payout details available for review.');

        return [$record, $details];
    }

    private function audit(Request $request, RecipientBenefitReleaseRecord $record, int $revision, string $action): void
    {
        ActivityLog::record(
            $request->user(),
            'benefit_payout_'.$action,
            'A provider staff member '.$action.' payout details.',
            $request,
            ['benefit_release_record_id' => $record->id, 'payout_revision' => $revision],
        );
    }
}
