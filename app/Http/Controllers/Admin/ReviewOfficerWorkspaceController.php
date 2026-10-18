<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewQueueRequest;
use App\Models\ApplicationStageEvidence;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Admin\Dashboards\ReviewOfficerDashboardQuery;
use App\Queries\Admin\ReviewQueueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReviewOfficerWorkspaceController extends Controller
{
    public function __construct(
        private readonly ReviewQueueQuery $queues,
        private readonly ReviewOfficerDashboardQuery $dashboard,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-overview');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json(['summary' => $this->queues->summary()]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json($this->dashboard->get());
    }

    public function providers(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-provider-queue');
    }

    public function providerData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->providers($request->validated()));
    }

    public function providerReview(Request $request, User $provider): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($provider->isProvider() && ! $provider->isManagedAccount(), 404);

        return view('admin-review-provider-record', ['provider' => $provider]);
    }

    public function programs(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-program-queue');
    }

    public function programData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->programs($request->validated()));
    }

    public function programReview(Request $request, Scholarship $scholarship): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-program-record', ['scholarship' => $scholarship]);
    }

    public function applicants(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-applicant-queue');
    }

    public function applicantData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->applicants($request->validated()));
    }

    public function applicantReview(Request $request, User $applicant): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($applicant->isApplicant(), 404);

        return view('admin-review-applicant-record', ['applicant' => $applicant]);
    }

    public function benefits(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-benefit-queue');
    }

    public function benefitData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->benefits($request->validated()));
    }

    public function monitoring(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-monitoring-queue');
    }

    public function monitoringData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->monitoring($request->validated()));
    }

    public function monitoringReview(Request $request, ScholarshipApplication $application): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($this->isMonitoringRecord($application), 404);

        return view('admin-review-monitoring-record', ['application' => $application]);
    }

    public function selectionActivities(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-selection-activity-queue');
    }

    public function selectionActivityData(ReviewQueueRequest $request): JsonResponse
    {
        return response()->json($this->queues->selectionActivities($request->validated()));
    }

    public function selectionActivity(Request $request, ApplicationStageSession $session): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-review-selection-activity-record', ['session' => $session]);
    }

    public function selectionActivityRecordData(
        Request $request,
        ApplicationStageSession $session,
    ): JsonResponse {
        $this->authorizeWorkspace($request);

        return response()->json([
            'session' => $this->queues->selectionActivityRecord($session),
        ]);
    }

    public function selectionActivityEvidence(
        Request $request,
        ApplicationStageEvidence $evidence,
    ): StreamedResponse {
        $this->authorizeWorkspace($request);
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);

        return Storage::disk($evidence->disk)->response(
            $evidence->path,
            $evidence->original_name,
            [
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin()
            && $request->user()->hasPortalPermission('manage_reviews'),
            403,
        );
    }

    private function isMonitoringRecord(ScholarshipApplication $application): bool
    {
        return in_array($application->status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated'], true)
            || $application->monitoringSubmissions()->exists()
            || $application->benefitReleaseRecords()->exists()
            || $application->supportDecisions()->exists();
    }
}
