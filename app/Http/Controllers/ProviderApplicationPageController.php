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

        if (ProviderWorkspace::usesApplicationReviewerWorkspace($request->user())) {
            $workspaceQuery = [];

            if ($request->filled('scholarship_id')) {
                $workspaceQuery['program_id'] = $request->integer('scholarship_id');
            }

            return redirect()->route(ProviderWorkspace::APPLICATION_REVIEWER_ROUTE, $workspaceQuery);
        }

        if (ProviderWorkspace::usesSelectionOfficerWorkspace($request->user())) {
            $workspaceQuery = [
                'queue' => $request->routeIs('provider.applications.results') ? 'results' : 'setup',
            ];

            if ($request->filled('scholarship_id')) {
                $workspaceQuery['program_id'] = $request->integer('scholarship_id');
            }

            return redirect()->route(ProviderWorkspace::SELECTION_OFFICER_ROUTE, $workspaceQuery);
        }

        if (ProviderWorkspace::usesDecisionOfficerWorkspace($request->user())) {
            $workspaceQuery = [
                'queue' => $request->routeIs('provider.applications.waitlist-directory')
                    || $request->query('filter') === 'waitlisted'
                        ? 'waitlist'
                        : 'pending',
            ];

            if ($request->filled('scholarship_id')) {
                $workspaceQuery['program_id'] = $request->integer('scholarship_id');
            }

            return redirect()->route(ProviderWorkspace::DECISION_OFFICER_ROUTE, $workspaceQuery);
        }

        if (ProviderWorkspace::usesRecipientOfficerWorkspace($request->user())) {
            $workspaceQuery = ['queue' => 'awaiting'];

            if ($request->filled('scholarship_id')) {
                $workspaceQuery['program_id'] = $request->integer('scholarship_id');
            }

            return redirect()->route(ProviderWorkspace::RECIPIENT_OFFICER_ROUTE, $workspaceQuery);
        }

        if ($request->filled('scholarship_id')) {
            $scholarshipId = filter_var($request->query('scholarship_id'), FILTER_VALIDATE_INT);
            abort_unless($scholarshipId !== false && $scholarshipId > 0, 404);

            $scholarship = Scholarship::query()->findOrFail($scholarshipId);
            abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

            $workspace = match (true) {
                $request->routeIs('provider.applications.activities') => 'activities',
                $request->routeIs('provider.applications.results') => 'results',
                $request->routeIs('provider.applications.decisions') => 'decisions',
                $request->routeIs('provider.applications.recipients') => 'recipients',
                $request->routeIs('provider.applications.waitlist-directory') => 'waitlist',
                in_array($request->query('filter'), ['waiting_activity', 'active_stages', 'formal_application'], true) => 'activities',
                $request->query('filter') === 'ready_result' => 'results',
                $request->query('filter') === 'final_decision' => 'decisions',
                $request->query('filter') === 'selected' => 'recipients',
                $request->query('filter') === 'waitlisted' => 'waitlist',
                default => 'review',
            };

            return redirect()->route("provider.programs.applications.{$workspace}", $scholarship);
        }

        return view('provider-applications');
    }

    public function programApplications(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if (ProviderWorkspace::usesApplicationReviewerWorkspace($request->user())
            && $request->routeIs('provider.programs.applications', 'provider.programs.applications.review')) {
            return redirect()->route(ProviderWorkspace::APPLICATION_REVIEWER_ROUTE, [
                'program_id' => $scholarship->id,
            ]);
        }

        if (ProviderWorkspace::usesSelectionOfficerWorkspace($request->user())
            && $request->routeIs(
                'provider.programs.applications',
                'provider.programs.applications.activities',
                'provider.programs.applications.results',
            )) {
            return redirect()->route(ProviderWorkspace::SELECTION_OFFICER_ROUTE, [
                'queue' => $request->routeIs('provider.programs.applications.results') ? 'results' : 'setup',
                'program_id' => $scholarship->id,
            ]);
        }

        if (ProviderWorkspace::usesDecisionOfficerWorkspace($request->user())
            && $request->routeIs(
                'provider.programs.applications',
                'provider.programs.applications.decisions',
                'provider.programs.applications.waitlist',
            )) {
            return redirect()->route(ProviderWorkspace::DECISION_OFFICER_ROUTE, [
                'queue' => $request->routeIs('provider.programs.applications.waitlist') ? 'waitlist' : 'pending',
                'program_id' => $scholarship->id,
            ]);
        }

        if (ProviderWorkspace::usesRecipientOfficerWorkspace($request->user())
            && $request->routeIs(
                'provider.programs.applications',
                'provider.programs.applications.recipients',
            )) {
            return redirect()->route(ProviderWorkspace::RECIPIENT_OFFICER_ROUTE, [
                'queue' => 'awaiting',
                'program_id' => $scholarship->id,
            ]);
        }

        if ($request->routeIs('provider.programs.applications')) {
            return redirect()->route('provider.programs.applications.review', $scholarship);
        }

        return view('provider-applications', [
            'scholarship' => $scholarship,
        ]);
    }

    public function applicationDetail(Request $request, ScholarshipApplication $application): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        return view('provider-application-detail', [
            'application' => $application,
        ]);
    }
}
