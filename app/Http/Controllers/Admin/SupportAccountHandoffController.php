<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportAccountHandoff;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Admin\SupportCaseQuery;
use App\Services\SupportAccountHandoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupportAccountHandoffController extends Controller
{
    public function store(Request $request, SupportReport $report, SupportAccountHandoffService $service): JsonResponse
    {
        $service->refer($request, $report);

        return response()->json(['message' => 'Sent to Account Manager. Support remains responsible for the final reply.',
            'support_case' => app(SupportCaseQuery::class)->record($report->fresh())]);
    }

    public function cancel(Request $request, SupportReport $report, SupportAccountHandoff $handoff, SupportAccountHandoffService $service): JsonResponse
    {
        abort_unless($handoff->support_report_id === $report->id, 404);
        $service->finish($request, $handoff, true);

        return response()->json(['message' => 'Account task cancelled. The case is back with Support.',
            'support_case' => app(SupportCaseQuery::class)->record($report->fresh())]);
    }

    public function complete(Request $request, User $user, SupportAccountHandoff $handoff, SupportAccountHandoffService $service): JsonResponse
    {
        $service->authorize($request->user(), 'manage_accounts');
        abort_unless($handoff->user_id === $user->id, 404);
        abort_if($user->isAdmin(), 403);
        $service->finish($request, $handoff, false);

        return response()->json(['message' => 'Result returned to Support. This does not close the case or change account access.',
            'account_handoffs' => $service->forAccount($user)]);
    }
}
