<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Support\ProviderWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderRecipientPageController extends Controller
{
    public function recipientMonitoringDirectory(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        if (ProviderWorkspace::usesRecipientOfficerWorkspace($request->user())) {
            return redirect()->route(ProviderWorkspace::RECIPIENT_OFFICER_ROUTE, ['queue' => 'active']);
        }

        if (ProviderWorkspace::usesMonitoringOfficerWorkspace($request->user())) {
            return redirect()->route(ProviderWorkspace::MONITORING_OFFICER_ROUTE, ['queue' => 'review']);
        }

        if (ProviderWorkspace::usesBenefitReleaseOfficerWorkspace($request->user())) {
            return redirect()->route(ProviderWorkspace::BENEFIT_RELEASE_OFFICER_ROUTE, ['queue' => 'issues']);
        }

        return view('provider-recipient-monitoring-directory');
    }

    public function recipientMonitoringWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return view('provider-monitoring-workspace', [
            'scholarship' => $scholarship,
        ]);
    }

    public function recipientMonitoringPlanWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return view('provider-monitoring-plan', [
            'scholarship' => $scholarship,
        ]);
    }

    public function redirectLegacyProgramMonitoring(Request $request, Scholarship $scholarship): RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $routeName = match ($request->route('monitoringView')) {
            'plan' => 'provider.monitoring.plan',
            'academic' => 'provider.monitoring.academic',
            'releases' => 'provider.monitoring.releases',
            'outcomes' => 'provider.monitoring.outcomes',
            default => 'provider.monitoring.show',
        };

        return redirect()->route($routeName, $scholarship);
    }
}
