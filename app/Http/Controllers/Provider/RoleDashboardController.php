<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Queries\Provider\Dashboards\ApplicationReviewerDashboardQuery;
use App\Queries\Provider\Dashboards\BenefitReleaseOfficerDashboardQuery;
use App\Queries\Provider\Dashboards\BillingStaffDashboardQuery;
use App\Queries\Provider\Dashboards\DecisionOfficerDashboardQuery;
use App\Queries\Provider\Dashboards\MonitoringOfficerDashboardQuery;
use App\Queries\Provider\Dashboards\OrganizationProfileManagerDashboardQuery;
use App\Queries\Provider\Dashboards\RecipientOfficerDashboardQuery;
use App\Queries\Provider\Dashboards\SelectionOfficerDashboardQuery;
use App\Queries\Provider\Dashboards\SupportStaffDashboardQuery;
use App\Queries\Provider\Dashboards\TeamAdministratorDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleDashboardController extends Controller
{
    public function reviewer(Request $request, ApplicationReviewerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function selection(Request $request, SelectionOfficerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function decisions(Request $request, DecisionOfficerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function recipients(Request $request, RecipientOfficerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function monitoring(Request $request, MonitoringOfficerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function releases(Request $request, BenefitReleaseOfficerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function organizationProfile(Request $request, OrganizationProfileManagerDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function team(Request $request, TeamAdministratorDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function support(Request $request, SupportStaffDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }

    public function billing(Request $request, BillingStaffDashboardQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user()));
    }
}
