<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinanceRecordRequest;
use App\Models\ProviderServicePurchase;
use App\Queries\Admin\Dashboards\FinanceOfficerDashboardQuery;
use App\Queries\Admin\FinanceRecordQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceOfficerWorkspaceController extends Controller
{
    public function __construct(
        private readonly FinanceRecordQuery $records,
        private readonly FinanceOfficerDashboardQuery $dashboard,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-finance-overview');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json(['summary' => $this->records->summary()]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json($this->dashboard->get());
    }

    public function payments(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-finance-payments');
    }

    public function paymentData(FinanceRecordRequest $request): JsonResponse
    {
        return response()->json($this->records->payments($request->validated()));
    }

    public function exceptions(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-finance-exceptions');
    }

    public function exceptionData(FinanceRecordRequest $request): JsonResponse
    {
        return response()->json($this->records->exceptions($request->validated()));
    }

    public function receipt(Request $request, ProviderServicePurchase $purchase): View
    {
        $this->authorizeWorkspace($request);
        abort_unless($purchase->status === 'paid' && $purchase->paid_at, 404);

        return view('admin-finance-receipt', ['purchase' => $purchase]);
    }

    public function receiptData(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeWorkspace($request);
        abort_unless($purchase->status === 'paid' && $purchase->paid_at, 404);

        return response()->json(['receipt' => $this->records->receipt($purchase)]);
    }

    public function legacyReceipts(Request $request): RedirectResponse
    {
        $this->authorizeWorkspace($request);

        return redirect()->route('admin.workspaces.finance.payments');
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin()
            && $request->user()->hasPortalPermission('view_finance'),
            403,
        );
    }
}
