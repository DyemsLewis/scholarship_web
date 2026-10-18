<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Support\ProviderWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderApplicationPageController extends Controller
{
    public function applications(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        if ($request->filled('scholarship_id')) {
            $scholarshipId = filter_var($request->query('scholarship_id'), FILTER_VALIDATE_INT);
            abort_unless($scholarshipId !== false && $scholarshipId > 0, 404);

            $scholarship = Scholarship::query()->findOrFail($scholarshipId);
            abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        }

        return $this->redirectToFocusedWorkspace($request, $scholarship ?? null);
    }

    public function programApplications(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return $this->redirectToFocusedWorkspace($request, $scholarship);
    }

    public function applicationDetail(Request $request, ScholarshipApplication $application): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $workspace = $this->applicationWorkspace($request, $application);

        if ($workspace === 'review') {
            return view('provider-application-review-record', [
                'application' => $application,
            ]);
        }

        if ($workspace === 'selection') {
            return view('provider-selection-activity-record', [
                'application' => $application,
            ]);
        }

        if ($workspace === 'decision') {
            return view('provider-final-decision-record', [
                'application' => $application,
            ]);
        }

        if ($workspace === 'recipient') {
            return view('provider-recipient-record', [
                'application' => $application,
            ]);
        }

        abort(403);
    }

    private function redirectToFocusedWorkspace(Request $request, ?Scholarship $scholarship = null): RedirectResponse
    {
        $user = $request->user();
        $filter = (string) $request->query('filter', '');
        $programId = $scholarship?->id;
        $query = $programId ? ['program_id' => $programId] : [];

        if ($request->routeIs('provider.applications.activities', 'provider.programs.applications.activities')
            || in_array($filter, ['waiting_activity', 'active_stages', 'formal_application'], true)) {
            return redirect()->route('provider.workspaces.selection.unassigned', $query);
        }

        if ($request->routeIs('provider.applications.results', 'provider.programs.applications.results')
            || $filter === 'ready_result') {
            return redirect()->route('provider.workspaces.selection.results', $query);
        }

        if ($request->routeIs('provider.applications.decisions', 'provider.programs.applications.decisions')
            || $filter === 'final_decision') {
            return redirect()->route('provider.workspaces.decisions.pending', $query);
        }

        if ($request->routeIs('provider.applications.waitlist-directory', 'provider.programs.applications.waitlist')
            || $filter === 'waitlisted') {
            return redirect()->route('provider.workspaces.decisions.waitlist', $query);
        }

        if ($request->routeIs('provider.applications.recipients', 'provider.programs.applications.recipients')
            || $filter === 'selected') {
            return redirect()->route('provider.workspaces.recipients.agreements', $query);
        }

        if ($request->routeIs('provider.applications.review', 'provider.programs.applications.review')
            || $user->hasPortalPermission('verify_applications')) {
            return redirect()->route('provider.workspaces.reviews.assigned', $query);
        }

        if ($user->hasPortalPermission('manage_selection_activities')) {
            return redirect()->route('provider.workspaces.selection.unassigned', $query);
        }

        if ($user->hasPortalPermission('record_final_decisions')) {
            return redirect()->route('provider.workspaces.decisions.pending', $query);
        }

        if ($user->hasPortalPermission('manage_recipients')) {
            return redirect()->route('provider.workspaces.recipients.agreements', $query);
        }

        abort(403);
    }

    private function applicationWorkspace(Request $request, ScholarshipApplication $application): ?string
    {
        $user = $request->user();
        $returnTo = (string) $request->query('return_to', '');
        $requested = (string) $request->query('workspace', '');

        $requested = match (true) {
            str_starts_with($returnTo, ProviderWorkspace::APPLICATION_REVIEWER_PATH) => 'review',
            str_starts_with($returnTo, ProviderWorkspace::SELECTION_OFFICER_PATH) => 'selection',
            str_starts_with($returnTo, ProviderWorkspace::DECISION_OFFICER_PATH) => 'decision',
            str_starts_with($returnTo, ProviderWorkspace::RECIPIENT_OFFICER_PATH) => 'recipient',
            default => $requested,
        };

        $permissions = [
            'review' => 'verify_applications',
            'selection' => 'manage_selection_activities',
            'decision' => 'record_final_decisions',
            'recipient' => 'manage_recipients',
        ];

        if (isset($permissions[$requested]) && $user->hasPortalPermission($permissions[$requested])) {
            return $requested;
        }

        if (ProviderWorkspace::usesApplicationReviewerWorkspace($user)) {
            return 'review';
        }

        if (ProviderWorkspace::usesSelectionOfficerWorkspace($user)) {
            return 'selection';
        }

        if (ProviderWorkspace::usesDecisionOfficerWorkspace($user)) {
            return 'decision';
        }

        if (ProviderWorkspace::usesRecipientOfficerWorkspace($user)) {
            return 'recipient';
        }

        return match ($application->workflow_stage) {
            'screening' => $user->hasPortalPermission('verify_applications') ? 'review' : null,
            'formal_application', 'exam', 'interview' => $user->hasPortalPermission('manage_selection_activities') ? 'selection' : null,
            'decision' => $user->hasPortalPermission('record_final_decisions') ? 'decision' : null,
            'complete' => $application->final_outcome === 'selected' && $user->hasPortalPermission('manage_recipients')
                ? 'recipient'
                : ($user->hasPortalPermission('record_final_decisions') ? 'decision' : null),
            default => $user->hasPortalPermission('verify_applications') ? 'review' : null,
        };
    }
}
