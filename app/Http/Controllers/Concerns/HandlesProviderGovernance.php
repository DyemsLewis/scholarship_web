<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait HandlesProviderGovernance
{
    public function governanceData(Request $request): JsonResponse
    {
        $owner = $request->user();
        $this->authorizeProviderOrganizationOwner($owner);
        $owner->loadMissing('providerProfile');

        $profile = $owner->providerProfile;
        $team = User::query()
            ->with('providerProfile')
            ->where('role', 'provider')
            ->where('parent_account_id', $owner->id)
            ->orderBy('account_status')
            ->orderBy('account_title')
            ->get();
        $activeTeam = $team->filter(fn (User $member): bool => $member->isActive());
        $soloOperator = $owner->providerOperatingMode() === 'solo_operator';

        $coverage = collect(self::PROVIDER_GOVERNANCE_RESPONSIBILITIES)
            ->map(function (array $responsibility, string $permission) use ($activeTeam, $owner, $soloOperator): array {
                $members = $activeTeam
                    ->filter(fn (User $member): bool => $member->hasPortalPermission($permission))
                    ->map(fn (User $member): array => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'role' => self::PROVIDER_TEAM_ROLES[$member->account_title] ?? 'Team member',
                    ])
                    ->values();

                if ($soloOperator && $owner->hasPortalPermission($permission)) {
                    $members->prepend([
                        'id' => $owner->id,
                        'name' => $owner->name,
                        'role' => 'Representative · Solo operator',
                    ]);
                }

                return [
                    'permission' => $permission,
                    ...$responsibility,
                    'status' => $members->isEmpty() ? 'unassigned' : 'covered',
                    'members' => $members,
                ];
            })
            ->values();

        $profileFields = collect([
            $profile?->provider_name,
            $profile?->provider_type,
            $profile?->provider_address,
            $profile?->provider_description,
            $profile?->provider_contact_email,
            $profile?->provider_contact_number,
            $profile?->legal_name,
            $profile?->representative_position,
        ]);
        $profileCompleted = $profileFields->filter(fn ($value): bool => filled($value))->count();
        $profileProgress = (int) round(($profileCompleted / max($profileFields->count(), 1)) * 100);
        $verificationDocumentCount = $owner->providerVerificationDocuments()->count();
        $broadAccessCount = $activeTeam
            ->filter(fn (User $member): bool => count(array_intersect(
                self::PROVIDER_PROGRAM_WORKFLOW_PERMISSIONS,
                $member->effectivePortalPermissions(),
            )) >= 4)
            ->count();
        $unverifiedStaffCount = $activeTeam
            ->filter(fn (User $member): bool => ! $member->hasVerifiedEmail())
            ->count();
        $coverageCount = $coverage->where('status', 'covered')->count();
        $coverageTotal = $coverage->count();
        $gapCount = $coverageTotal - $coverageCount;
        $verificationStatus = $profile?->verification_status ?? 'pending';

        $teamIds = $team->pluck('id')->prepend($owner->id)->all();
        $recentGovernanceActivity = ActivityLog::query()
            ->whereIn('user_id', $teamIds)
            ->whereIn('action', [
                'provider_team_account_created',
                'provider_team_account_updated',
                'provider_team_account_status_updated',
                'provider_profile_updated',
                'provider_verification_document_uploaded',
                'provider_verification_document_deleted',
                'provider_operating_mode_updated',
            ])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (ActivityLog $activity): array => [
                'id' => $activity->id,
                'action' => $activity->action,
                'description' => $activity->description,
                'actor' => $activity->actor_name,
                'occurred_at' => $activity->created_at?->format('M d, Y h:i A'),
            ])
            ->values();

        $nextAction = match (true) {
            ! $owner->hasVerifiedEmail() => [
                'title' => 'Verify the representative email',
                'description' => 'Confirm the account before managing organization access.',
                'href' => '/account/setup',
                'label' => 'Verify account',
            ],
            $profileProgress < 100 => [
                'title' => 'Complete the organization record',
                'description' => 'Finish the public, legal, and representative details.',
                'href' => '/provider/profile/details',
                'label' => 'Complete profile',
            ],
            $verificationStatus !== 'approved' || $verificationDocumentCount === 0 => [
                'title' => 'Finish organization verification',
                'description' => 'Review the verification status and supporting records.',
                'href' => '/provider/profile/verification',
                'label' => 'Open verification',
            ],
            $activeTeam->isEmpty() && ! $soloOperator => [
                'title' => 'Assign the first operational role',
                'description' => 'Start with a program coordinator instead of sharing this account.',
                'href' => '/provider/team/accounts/create',
                'label' => 'Add team member',
            ],
            $gapCount > 0 && ! $soloOperator => [
                'title' => "Assign {$gapCount} uncovered responsibilities",
                'description' => 'Give each workflow a clear owner before applications move through it.',
                'href' => '/provider/team',
                'label' => 'Review coverage',
            ],
            $unverifiedStaffCount > 0 => [
                'title' => 'Complete staff account setup',
                'description' => "{$unverifiedStaffCount} active account".($unverifiedStaffCount === 1 ? ' is' : 's are').' still unverified.',
                'href' => '/provider/team',
                'label' => 'Review accounts',
            ],
            $broadAccessCount > 0 => [
                'title' => 'Review broad operational access',
                'description' => "{$broadAccessCount} team account".($broadAccessCount === 1 ? ' spans' : 's span').' several workflows.',
                'href' => '/provider/team',
                'label' => 'Review access',
            ],
            default => [
                'title' => 'Governance setup is in good order',
                'description' => 'Review team access whenever responsibilities or staffing change.',
                'href' => '/provider/team',
                'label' => 'View team access',
            ],
        };

        return response()->json([
            'organization' => [
                'name' => $profile?->provider_name ?: $owner->name,
                'type' => $profile?->provider_type,
                'verification_status' => $verificationStatus,
                'verification_status_label' => Str::headline($verificationStatus),
                'profile_progress' => $profileProgress,
                'verification_document_count' => $verificationDocumentCount,
                'program_count' => $owner->providerScholarships()->count(),
                'published_program_count' => $owner->providerScholarships()->where('status', 'published')->count(),
            ],
            'representative' => [
                'name' => $owner->name,
                'email' => $owner->email,
                'position' => $profile?->representative_position,
                'email_verified' => $owner->hasVerifiedEmail(),
            ],
            'operating_mode' => $owner->providerOperatingMode(),
            'coverage' => $coverage,
            'summary' => [
                'coverage_count' => $coverageCount,
                'coverage_total' => $coverageTotal,
                'gap_count' => $gapCount,
                'active_staff_count' => $activeTeam->count(),
                'suspended_staff_count' => $team->where('account_status', 'suspended')->count(),
                'unverified_staff_count' => $unverifiedStaffCount,
                'broad_access_count' => $broadAccessCount,
            ],
            'next_action' => $nextAction,
            'recent_activity' => $recentGovernanceActivity,
        ]);
    }

    public function updateOperatingMode(Request $request): JsonResponse
    {
        $owner = $request->user();
        $this->authorizeProviderOrganizationOwner($owner);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['governance', 'solo_operator'])],
            'current_password' => ['required', 'string', 'current_password'],
        ]);
        $permissions = $validated['mode'] === 'solo_operator'
            ? User::PROVIDER_PERMISSIONS
            : User::PROVIDER_GOVERNANCE_PERMISSIONS;

        $owner->forceFill(['permissions' => $permissions])->save();
        $this->unassignInaccessibleApplications($owner->fresh());

        ActivityLog::record(
            $owner,
            'provider_operating_mode_updated',
            $validated['mode'] === 'solo_operator'
                ? "{$owner->name} enabled Solo operator mode for the provider account."
                : "{$owner->name} returned the provider account to Governance mode.",
            $request,
            ['operating_mode' => $validated['mode']],
        );

        return response()->json([
            'message' => $validated['mode'] === 'solo_operator'
                ? 'Solo operator mode enabled. Operational workspaces are now available to this account.'
                : 'Governance mode enabled. Operational workspaces are now assigned through team roles.',
            'user' => $owner->fresh(['providerProfile'])->publicPayload(),
        ]);
    }

    private function authorizeProviderOrganizationOwner(?User $user): void
    {
        abort_unless($user?->isProvider() && ! $user->isManagedAccount(), 403);
    }

}
