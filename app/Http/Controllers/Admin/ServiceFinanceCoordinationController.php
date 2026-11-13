<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderServicePurchase;
use App\Queries\Admin\FinanceRecordQuery;
use App\Services\ServiceFinanceCoordination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ServiceFinanceCoordinationController extends Controller
{
    public function show(Request $request, ProviderServicePurchase $purchase, FinanceRecordQuery $records): JsonResponse
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('view_finance'), 403);

        return response()->json(['record' => $records->reviewRecord($purchase)]);
    }

    public function review(Request $request, ProviderServicePurchase $purchase, ServiceFinanceCoordination $service, FinanceRecordQuery $records): JsonResponse
    {
        $service->financeReview($request, $purchase);

        return response()->json(['message' => 'Financial review saved. Payment, refund, and delivery states are unchanged.', 'record' => $records->reviewRecord($purchase->fresh())]);
    }

    public function respond(Request $request, ProviderServicePurchase $purchase, ServiceFinanceCoordination $service): JsonResponse
    {
        $service->billingResponse($request, $purchase);

        return response()->json(['message' => 'Response returned to Finance for review.', 'coordination' => $service->payload($purchase->fresh())]);
    }
}
