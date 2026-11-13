<?php

namespace App\Http\Controllers;

use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientBenefitPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ApplicantBenefitPayoutController extends Controller
{
    public function store(
        Request $request,
        RecipientBenefitReleaseRecord $record,
        RecipientBenefitPayoutService $payouts,
        BenefitDeliveryReportService $reports,
    ): JsonResponse {
        $applicant = $request->user();
        abort_unless($applicant?->isApplicant() && $applicant->id === $record->applicant_id, 403);

        return DB::transaction(function () use ($request, $record, $applicant, $payouts, $reports): JsonResponse {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($record->scholarship_application_id);
            abort_unless($application->applicant_id === $applicant->id, 403);
            $record = RecipientBenefitReleaseRecord::query()->lockForUpdate()->findOrFail($record->id);

            abort_unless($payouts->isActive($record, $application), 409,
                'This release is not accepting payout details. Refresh the release for the latest status.');
            $details = $payouts->currentDetails($record);
            abort_unless($payouts->acceptsSubmission($details, $reports->openForRecord($record)->exists()),
                409, 'This release is not accepting payout details. Refresh the release for the latest status.');

            $method = $record->release->release_method;
            $validator = Validator::make($request->only('method', 'institution', 'account_holder', 'account_number', 'confirmed', 'expected_revision'), [
                'method' => ['required', Rule::in([$method])],
                'institution' => ['required', 'string', 'max:120'],
                'account_holder' => ['required', 'string', 'max:120'],
                'account_number' => ['required', 'string', $method === RecipientBenefitPayoutService::WALLET
                    ? 'regex:/\A(?:09[0-9]{9}|\+639[0-9]{9})\z/'
                    : 'regex:/\A[0-9]{4,34}\z/'],
                'confirmed' => ['accepted'],
                'expected_revision' => ['nullable', 'integer', 'min:1'],
            ], [
                'account_number.regex' => $method === RecipientBenefitPayoutService::WALLET
                    ? 'Enter the registered mobile number as 09XXXXXXXXX or +639XXXXXXXXX.'
                    : 'Enter the bank account number using 4 to 34 digits, without spaces.',
                'method.in' => 'The release method changed. Refresh the release before trying again.',
            ], [
                'institution' => $method === RecipientBenefitPayoutService::WALLET ? 'wallet provider' : 'bank name',
                'account_number' => $method === RecipientBenefitPayoutService::WALLET ? 'registered mobile number' : 'account number',
            ]);

            // Always return JSON so rejected payment details are never flashed to the session.
            if ($validator->fails()) {
                return response()->json(['message' => 'Check your payout details.', 'errors' => $validator->errors()], 422);
            }

            $data = $validator->validated();
            $expected = isset($data['expected_revision']) ? (int) $data['expected_revision'] : null;
            abort_unless($expected === $details?->revision, 409, 'The payout submission changed. Refresh this release before trying again.');
            $number = $data['account_number'];
            if ($method === RecipientBenefitPayoutService::WALLET && str_starts_with($number, '+63')) {
                $number = '0'.substr($number, 3);
            }

            $record->payoutDetails()->updateOrCreate([], [
                'method' => $method,
                'destination' => [
                    'institution' => $data['institution'],
                    'account_holder' => $data['account_holder'],
                    'account_number' => $number,
                ],
                'submitted_at' => now(),
                'review_status' => 'submitted',
                'revision' => ($record->payoutDetails?->revision ?? 0) + 1,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'correction_reason' => null,
            ]);

            return response()->json(['message' => 'Payout details submitted. No money was sent.']);
        });
    }
}
