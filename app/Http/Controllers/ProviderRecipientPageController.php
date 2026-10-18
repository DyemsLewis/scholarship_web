<?php

namespace App\Http\Controllers;

use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringCycle;
use App\Models\Scholarship;
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

        if ($request->user()->hasPortalPermission('manage_monitoring')) {
            return redirect()->route('provider.workspaces.monitoring.review');
        }

        if ($request->user()->hasPortalPermission('manage_benefit_releases')) {
            return redirect()->route('provider.workspaces.releases.issues');
        }

        if ($request->user()->hasPortalPermission('manage_recipients')) {
            return redirect()->route('provider.workspaces.recipients.active');
        }

        abort(403);
    }

    public function recipientMonitoringWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $programQuery = ['program_id' => $scholarship->id];

        if ($request->routeIs('provider.monitoring.releases')) {
            return redirect()->route('provider.workspaces.releases.upcoming', $programQuery);
        }

        if ($request->routeIs('provider.monitoring.outcomes')) {
            return redirect()->route('provider.workspaces.recipients.active', $programQuery);
        }

        if ($request->user()->hasPortalPermission('manage_monitoring')) {
            return redirect()->route('provider.workspaces.monitoring.review', $programQuery);
        }

        if ($request->user()->hasPortalPermission('manage_benefit_releases')) {
            return redirect()->route('provider.workspaces.releases.issues', $programQuery);
        }

        if ($request->user()->hasPortalPermission('manage_recipients')) {
            return redirect()->route('provider.workspaces.recipients.active', $programQuery);
        }

        abort(403);
    }

    public function monitoringCheckInRecord(Request $request, RecipientMonitoringCycle $cycle): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        $cycle->loadMissing('scholarship');
        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($cycle->scholarship), 403);

        return view('provider-monitoring-check-in-record', [
            'cycle' => $cycle,
        ]);
    }

    public function benefitReleaseRecord(Request $request, RecipientBenefitRelease $release): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        $release->loadMissing('scholarship');
        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($release->scholarship), 403);

        return view('provider-benefit-release-record', [
            'release' => $release,
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
