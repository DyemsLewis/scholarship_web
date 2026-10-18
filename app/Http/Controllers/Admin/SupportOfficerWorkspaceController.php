<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportCaseQueueRequest;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Admin\Dashboards\SupportOfficerDashboardQuery;
use App\Queries\Admin\SupportCaseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SupportOfficerWorkspaceController extends Controller
{
    public function __construct(
        private readonly SupportCaseQuery $cases,
        private readonly SupportOfficerDashboardQuery $dashboard,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-support-overview');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json(['summary' => $this->cases->summary()]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json($this->dashboard->get());
    }

    public function queue(Request $request, string $queue): View
    {
        $this->authorizeWorkspace($request);
        abort_unless(in_array($queue, ['needs-response', 'waiting', 'resolved'], true), 404);

        return view('admin-support-case-directory');
    }

    public function data(SupportCaseQueueRequest $request): JsonResponse
    {
        return response()->json($this->cases->directory($request->validated()));
    }

    public function show(Request $request, SupportReport $report): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-support-case-record', ['report' => $report]);
    }

    public function showData(Request $request, SupportReport $report): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json(['report' => $this->cases->record($report)]);
    }

    public function reporterPhoto(Request $request, User $reporter)
    {
        $this->authorizeWorkspace($request);
        abort_unless($reporter->isApplicant(), 404);

        $profile = $reporter->studentProfile;
        abort_unless($profile?->profile_photo_path, 404);
        abort_unless(Storage::disk('local')->exists($profile->profile_photo_path), 404);

        return Storage::disk('local')->response(
            $profile->profile_photo_path,
            $profile->profile_photo_original_name ?: 'applicant-photo',
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin()
            && $request->user()->hasPortalPermission('manage_reports'),
            403,
        );
    }
}
