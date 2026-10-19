<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillingQueueRequest;
use App\Models\ProviderServicePurchase;
use App\Queries\Admin\BillingWorkQueue;
use App\Queries\Admin\Dashboards\BillingOfficerDashboardQuery;
use App\Support\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(BillingQueueRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $queue = $filters['queue'] ?? 'ready';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'wb');
            CsvExport::writeRow($handle, [
                'Reference', 'Provider', 'Email', 'Service', 'Amount', 'Currency', 'Delivery status',
                'Assigned to', 'Priority', 'Paid at', 'Target due', 'Closed at', 'Revision count',
                'Cancellation status', 'Refund status',
            ]);

            foreach ($this->queue->exportRows($filters) as $purchase) {
                CsvExport::writeRow($handle, [
                    $purchase->reference_number,
                    $purchase->provider?->provider_name ?: $purchase->provider?->name,
                    $purchase->provider?->email,
                    $purchase->plan_name,
                    $purchase->amount,
                    $purchase->currency,
                    $purchase->fulfillment_status,
                    $purchase->assignee?->name,
                    $purchase->priority,
                    $purchase->paid_at?->toISOString(),
                    $purchase->target_due_at?->toISOString(),
                    $purchase->closed_at?->toISOString(),
                    $purchase->revision_count,
                    $purchase->cancellation_status,
                    $purchase->refund_status,
                ]);
            }

            fclose($handle);
        }, "provider-services-{$queue}-".now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(Request $request, ProviderServicePurchase $purchase, ?string $section = null): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($purchase->status === 'paid', 404);
        abort_unless($section === null || in_array($section, ['delivery', 'files', 'updates', 'meeting', 'closeout', 'history'], true), 404);

        $workspacePage = match ($section) {
            'delivery' => 'adminServiceDelivery',
            'files' => 'adminServiceFiles',
            'updates' => 'adminServiceUpdates',
            'meeting' => 'adminServiceMeeting',
            'closeout' => 'adminServiceCloseout',
            'history' => 'adminServiceHistory',
            default => 'adminServiceOverview',
        };

        return view('admin-billing-request-record', compact('purchase', 'workspacePage'));
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
