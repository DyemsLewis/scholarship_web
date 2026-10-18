<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordsActivityRequest;
use App\Models\ActivityLog;
use App\Queries\Admin\Dashboards\RecordsOfficerDashboardQuery;
use App\Queries\Admin\RecordsWorkspaceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecordsOfficerWorkspaceController extends Controller
{
    public function __construct(
        private readonly RecordsWorkspaceQuery $records,
        private readonly RecordsOfficerDashboardQuery $dashboard,
    ) {}

    public function dashboard(Request $request): View
    {
        $this->authorizePermission($request, 'view_logs');

        return view('admin-records-officer-dashboard');
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'view_logs');

        return response()->json($this->dashboard->get($request->user()));
    }

    public function activity(Request $request): View
    {
        $this->authorizePermission($request, 'view_logs');

        return view('admin-records-activity');
    }

    public function activityData(RecordsActivityRequest $request): JsonResponse
    {
        return response()->json($this->records->activity($request->validated()));
    }

    public function show(Request $request, ActivityLog $activityLog): View
    {
        $this->authorizePermission($request, 'view_logs');

        return view('admin-records-activity-record', ['entry' => $activityLog]);
    }

    public function showData(Request $request, ActivityLog $activityLog): JsonResponse
    {
        $this->authorizePermission($request, 'view_logs');

        return response()->json(['entry' => $this->records->record($activityLog)]);
    }

    public function exports(Request $request): View
    {
        $this->authorizePermission($request, 'export_data');

        return view('admin-records-exports');
    }

    public function exportData(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'export_data');

        return response()->json($this->records->exportDesk());
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->isAdmin()
            && $request->user()->hasPortalPermission($permission),
            403,
        );
    }
}
