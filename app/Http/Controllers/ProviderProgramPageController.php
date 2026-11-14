<?php

namespace App\Http\Controllers;

use App\Models\Scholarship;
use App\Support\ProviderWorkspace;
use App\Support\ReviewRubric;
use App\Support\SelectionScoringPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderProgramPageController extends Controller
{
    public function programs(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return redirect()->route(ProviderWorkspace::PROGRAM_COORDINATOR_ROUTE, $request->query());
    }

    public function programWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return redirect()->route('provider.programs.show', $scholarship);
    }

    public function programForm(Request $request, ?Scholarship $scholarship = null): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        $providerOwner = $request->user()->providerOrganizationOwner();

        if ($scholarship) {
            abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        }

        if (! $providerOwner->hasVerifiedEmail() || ! $providerOwner->providerProfile?->isVerified()) {
            return view('provider-program-access', ['programAccess' => [
                'organization' => $providerOwner->provider_name ?: $providerOwner->name,
                'email_verified' => $providerOwner->hasVerifiedEmail(),
                'verification_status' => $providerOwner->providerProfile?->verification_status ?: 'pending',
                'can_manage_profile' => $request->user()->hasPortalPermission('manage_profile'),
                'is_edit' => (bool) $scholarship,
            ]]);
        }

        if ($scholarship) {
            if (! $request->route('step')) {
                return redirect()->route('provider.programs.edit.step', [
                    'scholarship' => $scholarship,
                    'step' => 'basics',
                ]);
            }
        }

        return view('provider-program-form', [
            'scoringPresets' => SelectionScoringPolicy::builderPresets($scholarship?->review_rubric ?: ReviewRubric::DEFAULT),
        ]);
    }

}
