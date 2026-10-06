<?php

namespace App\Http\Controllers;

use App\Support\ProviderWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderWorkspacePageController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        if (! $request->user()->isManagedAccount()) {
            return view('provider-governance');
        }

        if ($workspaceRoute = ProviderWorkspace::preferredRouteName($request->user())) {
            return redirect()->route($workspaceRoute);
        }

        return view('provider');
    }

    public function profile(Request $request): View
    {
        abort_unless($request->user()?->isProvider(), 403);

        return view('provider-profile');
    }

    public function governance(Request $request): View
    {
        abort_unless($request->user()?->isProvider() && ! $request->user()->isManagedAccount(), 403);

        return view('provider-governance');
    }

    public function programs(Request $request): View
    {
        return $this->workspace($request, 'manage_programs', 'provider-program-coordinator-workspace');
    }

    public function reviews(Request $request): View
    {
        return $this->workspace($request, 'verify_applications', 'provider-application-reviewer-workspace');
    }

    public function selection(Request $request): View
    {
        return $this->workspace($request, 'manage_selection_activities', 'provider-selection-officer-workspace');
    }

    public function decisions(Request $request): View
    {
        return $this->workspace($request, 'record_final_decisions', 'provider-decision-officer-workspace');
    }

    public function recipients(Request $request): View
    {
        return $this->workspace($request, 'manage_recipients', 'provider-recipient-officer-workspace');
    }

    public function monitoring(Request $request): View
    {
        return $this->workspace($request, 'manage_monitoring', 'provider-monitoring-officer-workspace');
    }

    public function releases(Request $request): View
    {
        return $this->workspace($request, 'manage_benefit_releases', 'provider-benefit-release-officer-workspace');
    }

    public function organizationProfile(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-manager-workspace');
    }

    public function team(Request $request): View
    {
        return $this->workspace($request, 'manage_team', 'provider-team-administrator-workspace');
    }

    private function workspace(Request $request, string $permission, string $view): View
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission($permission), 403);

        return view($view);
    }
}
