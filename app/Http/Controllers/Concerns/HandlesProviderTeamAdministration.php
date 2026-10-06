<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\ProviderWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

trait HandlesProviderTeamAdministration
{
    public function teamData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $owner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $accounts = User::query()
            ->with(['providerProfile', 'parentAccount.providerProfile'])
            ->where('role', 'provider')
            ->where('parent_account_id', $owner->id)
            ->orderBy('account_status')
            ->orderBy('account_title')
            ->orderBy('username')
            ->get();

        return response()->json([
            'organization' => [
                'id' => $owner->id,
                'name' => $owner->provider_name ?? $owner->name,
                'owner' => $owner->name,
            ],
            'accounts' => $accounts->map(fn (User $account) => $this->providerTeamAccountPayload($account))->values(),
            'available_permissions' => $this->grantableProviderPermissions($request->user()),
            'available_programs' => $this->providerAssignablePrograms($request->user()),
            'can_assign_all_programs' => ! $request->user()->hasLimitedProviderProgramAccess(),
        ]);
    }

    public function showTeamAccount(Request $request, User $account): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->authorizeProviderTeamAccount($request->user(), $account);

        return response()->json([
            'account' => $this->providerTeamAccountPayload($account->loadMissing('providerProfile')),
            'available_permissions' => $this->grantableProviderPermissions($request->user()),
            'available_programs' => $this->providerAssignablePrograms($request->user()),
            'can_assign_all_programs' => ! $request->user()->hasLimitedProviderProgramAccess(),
        ]);
    }

    public function storeTeamAccount(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $actor = $request->user();
        $owner = $actor->providerOrganizationOwner()->loadMissing('providerProfile');
        $validated = $this->validateProviderTeamAccount($request);
        $middleInitial = strtoupper($validated['middle_initial']);

        $account = DB::transaction(function () use ($validated, $middleInitial, $owner): User {
            $account = User::create([
                'parent_account_id' => $owner->id,
                'email' => $validated['email'],
                'username' => $validated['username'],
                'role' => 'provider',
                'account_title' => $validated['account_title'],
                'permissions' => array_values(array_unique($validated['permissions'])),
                'assigned_program_ids' => $validated['assigned_program_ids'],
                'password' => $validated['password'],
                'must_reset_password' => true,
                'password_reset_required_at' => now(),
            ]);

            $ownerProfile = $owner->providerProfile;
            $account->providerProfile()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_initial' => $middleInitial,
                'contact_number' => $validated['contact_number'],
                'provider_name' => $ownerProfile?->provider_name,
                'provider_type' => $ownerProfile?->provider_type,
                'provider_website' => $ownerProfile?->provider_website,
                'provider_address' => $ownerProfile?->provider_address,
                'provider_description' => $ownerProfile?->provider_description,
                'verification_status' => $ownerProfile?->verification_status ?? 'pending',
                'verification_notes' => $ownerProfile?->verification_notes,
                'verified_by' => $ownerProfile?->verified_by,
                'verified_at' => $ownerProfile?->verified_at,
            ]);

            return $account;
        });

        ActivityLog::record(
            $actor,
            'provider_team_account_created',
            "{$actor->name} created provider team account {$account->email}.",
            $request,
            [
                'created_user_id' => $account->id,
                'provider_id' => $owner->id,
                'permissions' => $account->permissions,
                'assigned_program_ids' => $account->assigned_program_ids,
            ],
        );

        $teamRole = self::PROVIDER_TEAM_ROLES[$account->account_title] ?? 'Team member';
        $providerName = $owner->providerProfile?->provider_name ?: $owner->name ?: 'provider organization';
        PortalNotification::create([
            'user_id' => $account->id,
            'type' => 'staff_account_created',
            'title' => 'Your provider staff account is ready',
            'message' => "Your {$providerName} {$teamRole} account has been created. Username: {$account->username}. Sign in using the temporary password, verify your email, and create a new password before entering the workspace.",
            'action_url' => '/login',
            'deduplication_key' => "staff_account_created:{$account->id}",
        ]);

        $emailVerificationSent = true;

        try {
            $account->sendEmailVerificationNotification();
        } catch (Throwable $error) {
            $emailVerificationSent = false;
            ActivityLog::record(
                $account,
                'email_verification_email_failed',
                "Email verification link could not be sent to {$account->email}.",
                $request,
                ['error' => $error->getMessage()],
            );
        }

        PortalNotification::updateOrCreate([
            'user_id' => $account->id,
            'type' => 'email_verification',
            'title' => 'Verify your email address',
        ], [
            'message' => $emailVerificationSent
                ? 'A verification link was sent to your email. Verify it before replacing your temporary password.'
                : 'Your email is not verified. Resend the verification link from the account setup page.',
            'action_url' => '/account/setup',
            'read_at' => null,
        ]);

        return response()->json([
            'message' => $emailVerificationSent
                ? 'Team account created. Share the temporary password securely; a verification email was sent to the team member.'
                : 'Team account created, but the verification email could not be sent. The team member can resend it after signing in.',
            'email_verification_sent' => $emailVerificationSent,
            'account' => $this->providerTeamAccountPayload($account->fresh('providerProfile')),
        ], 201);
    }

    public function updateTeamAccount(Request $request, User $account): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $actor = $request->user();
        $this->authorizeProviderTeamAccount($actor, $account);
        $validated = $this->validateProviderTeamAccount($request, $account);
        $middleInitial = strtoupper($validated['middle_initial']);
        $emailChanged = $account->email !== $validated['email'];

        DB::transaction(function () use ($account, $validated, $middleInitial, $emailChanged): void {
            $account->update([
                'email' => $validated['email'],
                'username' => $validated['username'],
                'account_title' => $validated['account_title'],
                'permissions' => array_values(array_unique($validated['permissions'])),
                'assigned_program_ids' => $validated['assigned_program_ids'],
                ...filled($validated['password'] ?? null) ? ['password' => $validated['password']] : [],
            ]);

            if ($emailChanged) {
                $account->forceFill(['email_verified_at' => null])->save();
            }

            $account->providerProfile()->updateOrCreate(['user_id' => $account->id], [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_initial' => $middleInitial,
                'contact_number' => $validated['contact_number'],
            ]);

            $this->unassignInaccessibleApplications($account->fresh());
        });

        ActivityLog::record(
            $actor,
            'provider_team_account_updated',
            "{$actor->name} updated provider team account {$account->email}.",
            $request,
            [
                'updated_user_id' => $account->id,
                'permissions' => $account->permissions,
                'assigned_program_ids' => $account->assigned_program_ids,
            ],
        );

        return response()->json([
            'message' => 'Team account updated successfully.',
            'account' => $this->providerTeamAccountPayload($account->fresh('providerProfile')),
        ]);
    }

    public function updateTeamAccountStatus(Request $request, User $account): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $actor = $request->user();
        $this->authorizeProviderTeamAccount($actor, $account);
        abort_if($actor->is($account), 422, 'You cannot suspend your own team account.');

        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
        $suspended = $validated['account_status'] === 'suspended';

        $account->forceFill([
            'account_status' => $validated['account_status'],
            'suspended_at' => $suspended ? now() : null,
            'suspended_by' => $suspended ? $actor->id : null,
            'suspension_reason' => $suspended ? 'Suspended by the provider organization.' : null,
        ])->save();

        $this->unassignInaccessibleApplications($account->fresh());

        ActivityLog::record(
            $actor,
            'provider_team_account_status_updated',
            "{$actor->name} marked {$account->email} as {$validated['account_status']}.",
            $request,
            ['updated_user_id' => $account->id, 'account_status' => $validated['account_status']],
        );

        return response()->json([
            'message' => $suspended ? 'Team account suspended.' : 'Team account reactivated.',
            'account' => $this->providerTeamAccountPayload($account->fresh('providerProfile')),
        ]);
    }

    public function team(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        if (ProviderWorkspace::usesTeamAdministratorWorkspace($request->user())) {
            return redirect()->route(ProviderWorkspace::TEAM_ADMINISTRATOR_ROUTE);
        }

        return view('provider-team');
    }

    public function teamAccountForm(Request $request, ?User $account = null): View|RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        if ($account) {
            $this->authorizeProviderTeamAccount($request->user(), $account);
        }

        return view('provider-team-account-form');
    }

    private function unassignInaccessibleApplications(User $account): void
    {
        $assignments = ScholarshipApplication::query()
            ->where('assigned_reviewer_id', $account->id);

        if (! $account->isActive() || ! $account->hasPortalPermission('verify_applications')) {
            $assignments->update(['assigned_reviewer_id' => null]);

            return;
        }

        if (! $account->hasLimitedProviderProgramAccess()) {
            return;
        }

        $programIds = $account->assignedProviderProgramIds();

        if ($programIds === []) {
            $assignments->update(['assigned_reviewer_id' => null]);

            return;
        }

        $assignments
            ->whereNotIn('scholarship_id', $programIds)
            ->update(['assigned_reviewer_id' => null]);
    }

    private function validateProviderTeamAccount(Request $request, ?User $account = null): array
    {
        $permissions = $this->grantableProviderPermissions($request->user());
        $requestedPermissions = collect($request->input('permissions', []))
            ->flatMap(fn ($permission): array => User::PROVIDER_LEGACY_PERMISSION_BUNDLES[$permission] ?? [$permission])
            ->unique()
            ->values()
            ->all();
        $request->merge(['permissions' => $requestedPermissions]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account?->id)],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($account?->id)],
            'contact_number' => ['required', 'string', 'max:30', new PhoneNumber],
            'account_title' => ['required', 'string', Rule::in(array_keys(self::PROVIDER_TEAM_ROLES))],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($permissions)],
            'program_access_mode' => ['required', Rule::in(['all', 'selected'])],
            'assigned_program_ids' => [
                Rule::excludeIf($request->input('program_access_mode') !== 'selected'),
                'required',
                'array',
                'min:1',
            ],
            'assigned_program_ids.*' => ['integer', 'distinct'],
            'password' => [$account ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);

        $assignedProgramIds = collect($validated['assigned_program_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($request->user()->hasLimitedProviderProgramAccess() && $validated['program_access_mode'] === 'all') {
            throw ValidationException::withMessages([
                'program_access_mode' => 'You can only assign programs that you can access.',
            ]);
        }

        if ($validated['program_access_mode'] === 'selected') {
            $validProgramCount = $this->providerScholarshipsQuery($request->user())
                ->whereIn('id', $assignedProgramIds)
                ->count();

            if ($validProgramCount !== $assignedProgramIds->count()) {
                throw ValidationException::withMessages([
                    'assigned_program_ids' => 'Select only programs owned by your organization.',
                ]);
            }
        }

        $validated['assigned_program_ids'] = $validated['program_access_mode'] === 'selected'
            ? $assignedProgramIds->all()
            : null;

        $preset = self::PROVIDER_TEAM_ROLE_PERMISSION_PRESETS[$validated['account_title']] ?? null;

        if ($preset === null) {
            return $validated;
        }

        $validated['permissions'] = array_values(array_intersect($preset, $permissions));

        if ($validated['permissions'] === []) {
            throw ValidationException::withMessages([
                'account_title' => 'You cannot assign this role with your current permissions.',
            ]);
        }

        return $validated;
    }

    private function grantableProviderPermissions(User $actor): array
    {
        if (! $actor->isManagedAccount()) {
            return User::PROVIDER_PERMISSIONS;
        }

        return array_values(array_intersect(
            User::PROVIDER_PERMISSIONS,
            $actor->effectivePortalPermissions(),
        ));
    }

    private function authorizeProviderTeamAccount(User $actor, User $account): void
    {
        abort_unless(
            $account->isProvider()
                && (int) $account->parent_account_id === $actor->providerOrganizationId(),
            404,
        );

        if ($actor->isManagedAccount()) {
            abort_if(
                array_diff(
                    array_intersect(User::PROVIDER_PERMISSIONS, $account->effectivePortalPermissions()),
                    array_intersect(User::PROVIDER_PERMISSIONS, $actor->effectivePortalPermissions()),
                ) !== [],
                403,
                'You cannot manage a team account with broader permissions than your own.',
            );

            if ($actor->hasLimitedProviderProgramAccess()) {
                abort_unless(
                    $account->hasLimitedProviderProgramAccess()
                        && array_diff(
                            $account->assignedProviderProgramIds(),
                            $actor->assignedProviderProgramIds(),
                        ) === [],
                    403,
                    'You cannot manage a team account with broader program access than your own.',
                );
            }
        }
    }


    private function providerTeamAccountPayload(User $account): array
    {
        $profile = $account->providerProfile;
        $middle = filled($profile?->middle_initial) ? ' '.strtoupper($profile->middle_initial).'.' : '';
        $name = trim(($profile?->first_name ?? '').$middle.' '.($profile?->last_name ?? ''));

        return [
            ...$this->providerStaffPayload($account),
            'name' => $name ?: ($account->username ?: $account->email),
            'team_role' => $account->account_title,
            'team_role_label' => self::PROVIDER_TEAM_ROLES[$account->account_title] ?? 'Team member',
            'program_access_mode' => $account->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            'assigned_program_ids' => $account->assignedProviderProgramIds(),
            'assigned_programs' => $account->hasLimitedProviderProgramAccess()
                ? Scholarship::query()
                    ->where('provider_id', $account->providerOrganizationId())
                    ->whereIn('id', $account->assignedProviderProgramIds())
                    ->orderBy('title')
                    ->get(['id', 'title', 'status'])
                    ->map(fn (Scholarship $scholarship) => [
                        'id' => $scholarship->id,
                        'title' => $scholarship->title,
                        'status' => $scholarship->status,
                    ])
                    ->values()
                : [],
            'created_at' => $account->created_at?->format('M d, Y'),
        ];
    }

    private function providerAssignablePrograms(User $actor): array
    {
        return $this->providerScholarshipsQuery($actor)
            ->orderBy('title')
            ->get(['id', 'title', 'status'])
            ->map(fn (Scholarship $scholarship) => [
                'id' => $scholarship->id,
                'title' => $scholarship->title,
                'status' => $scholarship->status,
            ])
            ->values()
            ->all();
    }

}
