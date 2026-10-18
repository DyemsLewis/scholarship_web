<?php

namespace App\Http\Controllers;

use App\Models\ApplicationStageSession;
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

        if (ProviderWorkspace::usesProgramCoordinatorWorkspace($request->user())) {
            return view('provider-program-coordinator-dashboard');
        }

        if (ProviderWorkspace::usesApplicationReviewerWorkspace($request->user())) {
            return view('provider-application-reviewer-dashboard');
        }

        if (ProviderWorkspace::usesSelectionOfficerWorkspace($request->user())) {
            return view('provider-selection-officer-dashboard');
        }

        if (ProviderWorkspace::usesDecisionOfficerWorkspace($request->user())) {
            return view('provider-decision-officer-dashboard');
        }

        if (ProviderWorkspace::usesRecipientOfficerWorkspace($request->user())) {
            return view('provider-recipient-officer-dashboard');
        }

        if (ProviderWorkspace::usesMonitoringOfficerWorkspace($request->user())) {
            return view('provider-monitoring-officer-dashboard');
        }

        if (ProviderWorkspace::usesBenefitReleaseOfficerWorkspace($request->user())) {
            return view('provider-benefit-release-officer-dashboard');
        }

        if (ProviderWorkspace::usesOrganizationProfileManagerWorkspace($request->user())) {
            return view('provider-organization-profile-manager-dashboard');
        }

        if (ProviderWorkspace::usesTeamAdministratorWorkspace($request->user())) {
            return view('provider-team-administrator-dashboard');
        }

        if (ProviderWorkspace::usesSupportStaffWorkspace($request->user())) {
            return view('provider-support-staff-dashboard');
        }

        if (ProviderWorkspace::usesBillingStaffWorkspace($request->user())) {
            return view('provider-billing-staff-dashboard');
        }

        return view('provider');
    }

    public function profile(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        if (! $request->user()->hasPortalPermission('manage_profile')) {
            return redirect()->route('provider.index');
        }

        $route = match (true) {
            $request->routeIs('provider.profile.verification') => 'provider.workspaces.organization-profile.verification',
            $request->routeIs('provider.profile.representative') => 'provider.workspaces.organization-profile.contact',
            $request->routeIs('provider.profile.details') => 'provider.workspaces.organization-profile.public',
            default => 'provider.workspaces.organization-profile',
        };

        return redirect()->route($route);
    }

    public function governance(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isProvider() && ! $request->user()->isManagedAccount(), 403);

        if ($request->user()->hasPortalPermission('manage_team')) {
            return redirect()->route(ProviderWorkspace::TEAM_ADMINISTRATOR_ROUTE);
        }

        if ($request->user()->hasPortalPermission('manage_profile')) {
            return redirect()->route(ProviderWorkspace::ORGANIZATION_PROFILE_MANAGER_ROUTE);
        }

        return redirect()->route('provider.index');
    }

    public function programs(Request $request): View
    {
        return $this->workspace($request, 'manage_programs', 'provider-program-coordinator-workspace');
    }

    public function reviews(Request $request): View
    {
        return $this->workspace($request, 'verify_applications', 'provider-application-reviewer-workspace');
    }

    public function reviewDashboard(Request $request): View
    {
        return $this->workspace($request, 'verify_applications', 'provider-application-reviewer-dashboard');
    }

    public function selection(Request $request): View
    {
        return $this->workspace($request, 'manage_selection_activities', 'provider-selection-officer-workspace');
    }

    public function selectionDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_selection_activities', 'provider-selection-officer-dashboard');
    }

    public function selectionSessionCreate(Request $request): View
    {
        return $this->workspace($request, 'manage_selection_activities', 'provider-selection-session-form');
    }

    public function selectionSessionEdit(Request $request, ApplicationStageSession $session): View
    {
        $this->authorizeSelectionSession($request, $session);
        abort_unless($session->status === 'draft', 422, 'Only draft sessions can be edited.');

        return view('provider-selection-session-form', compact('session'));
    }

    public function selectionSessionRecord(Request $request, ApplicationStageSession $session): View
    {
        $this->authorizeSelectionSession($request, $session);

        return view('provider-selection-session-record', compact('session'));
    }

    public function decisions(Request $request): View
    {
        return $this->workspace($request, 'record_final_decisions', 'provider-decision-officer-workspace');
    }

    public function decisionDashboard(Request $request): View
    {
        return $this->workspace($request, 'record_final_decisions', 'provider-decision-officer-dashboard');
    }

    public function recipients(Request $request): View
    {
        return $this->workspace($request, 'manage_recipients', 'provider-recipient-officer-workspace');
    }

    public function recipientDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_recipients', 'provider-recipient-officer-dashboard');
    }

    public function monitoring(Request $request): View
    {
        return $this->workspace($request, 'manage_monitoring', 'provider-monitoring-officer-workspace');
    }

    public function monitoringDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_monitoring', 'provider-monitoring-officer-dashboard');
    }

    public function releases(Request $request): View
    {
        return $this->workspace($request, 'manage_benefit_releases', 'provider-benefit-release-officer-workspace');
    }

    public function releaseDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_benefit_releases', 'provider-benefit-release-officer-dashboard');
    }

    public function releaseSchedule(Request $request): View
    {
        return $this->workspace($request, 'manage_benefit_releases', 'provider-benefit-release-schedule');
    }

    public function organizationProfile(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-manager-workspace');
    }

    public function organizationProfileDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-manager-dashboard');
    }

    public function organizationProfilePublic(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-public');
    }

    public function organizationProfileContact(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-contact');
    }

    public function organizationProfileVerification(Request $request): View
    {
        return $this->workspace($request, 'manage_profile', 'provider-organization-profile-verification');
    }

    public function team(Request $request): View
    {
        return $this->workspace($request, 'manage_team', 'provider-team-administrator-workspace');
    }

    public function teamDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_team', 'provider-team-administrator-dashboard');
    }

    public function supportDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_reports', 'provider-support-staff-dashboard');
    }

    public function billingDashboard(Request $request): View
    {
        return $this->workspace($request, 'manage_billing', 'provider-billing-staff-dashboard');
    }

    private function workspace(Request $request, string $permission, string $view): View
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission($permission), 403);

        return view($view);
    }

    private function authorizeSelectionSession(Request $request, ApplicationStageSession $session): void
    {
        $actor = $request->user();
        abort_unless($actor?->isProvider(), 403);
        abort_unless($actor->hasPortalPermission('manage_selection_activities'), 403);
        abort_unless($actor->canAccessProviderProgram($session->scholarship), 403);
    }
}
