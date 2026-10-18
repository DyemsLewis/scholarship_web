<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillingQueueRequest;
use App\Models\ProviderServicePurchase;
use App\Queries\Admin\BillingWorkQueue;
use App\Queries\Admin\Dashboards\BillingOfficerDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingOfficerWorkspaceController extends Controller
{
    public function __construct(
        private readonly BillingWorkQueue $queue,
        private readonly BillingOfficerDashboardQuery $dashboard,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-billing-overview');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json(['summary' => $this->queue->summary()]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json($this->dashboard->get());
    }

    public function queue(Request $request, string $queue): View
    {
        $this->authorizeWorkspace($request);
        abort_unless(in_array($queue, ['ready', 'active', 'waiting', 'completed'], true), 404);

        return view('admin-billing-request-directory');
    }

    public function data(BillingQueueRequest $request): JsonResponse
    {
        return response()->json($this->queue->directory($request->validated()));
    }

    public function show(Request $request, ProviderServicePurchase $purchase, ?string $section = null): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($purchase->status === 'paid', 404);
        abort_unless($section === null || in_array($section, ['delivery', 'files', 'updates', 'meeting'], true), 404);

        return view('admin-billing-request-record', ['purchase' => $purchase]);
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin()
            && $request->user()->hasPortalPermission('manage_billing'),
            403,
        );
    }
}
