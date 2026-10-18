<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Admin\PortalManagerWorkspaceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PortalManagerController extends Controller
{
    public function __construct(private readonly PortalManagerWorkspaceQuery $workspace) {}

    public function index(): View
    {
        return view('admin-portal-manager-workspace');
    }

    public function data(): JsonResponse
    {
        return response()->json($this->workspace->overview());
    }

    public function accounts(): View
    {
        return $this->area('accounts', 'Account oversight');
    }

    public function accountData(): JsonResponse
    {
        return response()->json($this->workspace->accounts());
    }

    public function reviews(): View
    {
        return $this->area('reviews', 'Review oversight');
    }

    public function reviewData(): JsonResponse
    {
        return response()->json($this->workspace->reviews());
    }

    public function providerReview(User $provider): RedirectResponse
    {
        abort_unless($provider->isProvider() && ! $provider->isManagedAccount(), 404);

        return redirect()->route('admin.workspaces.portal.reviews', ['focus' => 'providers']);
    }

    public function programReview(Scholarship $scholarship): RedirectResponse
    {
        return redirect()->route('admin.workspaces.portal.reviews', ['focus' => 'programs']);
    }

    public function applicantReview(User $applicant): RedirectResponse
    {
        abort_unless($applicant->isApplicant(), 404);

        return redirect()->route('admin.workspaces.portal.reviews', ['focus' => 'applicants']);
    }

    public function monitoringReview(ScholarshipApplication $application): RedirectResponse
    {
        return redirect()->route('admin.workspaces.portal.reviews', ['focus' => 'monitoring']);
    }

    public function support(): View
    {
        return $this->area('support', 'Support oversight');
    }

    public function supportData(): JsonResponse
    {
        return response()->json($this->workspace->support());
    }

    public function billing(): View
    {
        return $this->area('services', 'Service delivery oversight');
    }

    public function billingData(): JsonResponse
    {
        return response()->json($this->workspace->services());
    }

    public function finance(): View
    {
        return $this->area('finance', 'Finance oversight');
    }

    public function financeData(): JsonResponse
    {
        return response()->json($this->workspace->finance());
    }

    public function records(): View
    {
        return $this->area('records', 'Records oversight');
    }

    public function recordsData(): JsonResponse
    {
        return response()->json($this->workspace->records());
    }

    private function area(string $area, string $title): View
    {
        return view('admin-portal-manager-area', compact('area', 'title'));
    }
}
