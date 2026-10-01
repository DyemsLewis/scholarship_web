<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PortalManagerController extends Controller
{
    public function index(): View
    {
        return view('admin-portal-manager-workspace');
    }

    public function data(): JsonResponse
    {
        $today = now();
        $accountAttention = User::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('email_verified_at')
                    ->orWhere('must_reset_password', true)
                    ->orWhere('account_status', 'suspended');
            })
            ->count();
        $providerReviews = User::query()
            ->where('role', 'provider')
            ->whereNull('parent_account_id')
            ->whereHas('providerProfile', fn ($query) => $query->where('verification_status', 'pending'))
            ->count();
        $programReviews = Scholarship::query()->where('status', 'pending_review')->count();
        $paid = ProviderServicePurchase::query()->where('status', 'paid')->whereNotNull('paid_at');

        return response()->json([
            'summary' => [
                'account_attention' => $accountAttention,
                'pending_reviews' => $providerReviews + $programReviews,
                'open_reports' => SupportReport::query()->where('admin_status', 'open')->count(),
                'active_services' => (clone $paid)->where('fulfillment_status', '!=', 'completed')->count(),
                'month_revenue' => (int) (clone $paid)->whereBetween('paid_at', [
                    $today->copy()->startOfMonth(),
                    $today->copy()->endOfMonth(),
                ])->sum('amount'),
                'activity_today' => ActivityLog::query()->whereBetween('created_at', [
                    $today->copy()->startOfDay(),
                    $today->copy()->endOfDay(),
                ])->count(),
            ],
        ]);
    }

    public function accounts(): View
    {
        return view('admin-account-manager-workspace');
    }

    public function reviews(): View
    {
        return view('admin-review-officer-workspace');
    }

    public function providerReview(User $provider): View
    {
        abort_unless($provider->isProvider() && ! $provider->isManagedAccount(), 404);

        return view('admin-provider-review', ['provider' => $provider]);
    }

    public function programReview(Scholarship $scholarship): View
    {
        return view('admin-program-review', ['scholarship' => $scholarship]);
    }

    public function applicantReview(User $applicant): View
    {
        abort_unless($applicant->isApplicant(), 404);

        return view('admin-applicant-review', ['applicant' => $applicant]);
    }

    public function monitoringReview(ScholarshipApplication $application): View
    {
        return view('admin-monitoring-review', ['application' => $application]);
    }

    public function support(): View
    {
        return view('admin-support-officer-workspace');
    }

    public function billing(): View
    {
        return view('admin-billing-officer-workspace');
    }

    public function billingRequest(ProviderServicePurchase $purchase): View
    {
        return view('admin-service-workspace', ['purchase' => $purchase]);
    }

    public function finance(): View
    {
        return view('admin-finance-officer-workspace');
    }

    public function records(): View
    {
        return view('admin-records-officer-workspace');
    }
}
