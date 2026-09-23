<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringSubmission;
use App\Models\RecipientSupportDecision;
use App\Models\Scholarship;
use App\Models\ScholarshipAnnouncement;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\AcademicRecordOcrService;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipBenefitService as SB;
use App\Services\ScholarshipEligibilityService;
use App\Services\ScholarshipEventService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationDecisionReason;
use App\Support\ApplicationSchedulePayload;
use App\Support\LearnerProgramPath;
use App\Support\PreScreeningHandoffRecord;
use App\Support\RecipientAgreement;
use App\Support\ReviewRubric;
use App\Support\ScholarshipEligibilityCondition;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use App\Support\Terms;
use App\Support\XlsxExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ProviderController extends Controller
{
    public function __construct(
        private readonly ApplicationWorkflowService $workflowService,
        private readonly AcademicRecordOcrService $academicRecordOcrService,
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    private const PROVIDER_TEAM_ROLES = [
        'manager' => 'Manager',
        'program_coordinator' => 'Program coordinator',
        'application_reviewer' => 'Application reviewer',
        'support_staff' => 'Support staff',
        'billing_staff' => 'Billing staff',
        'custom' => 'Custom role',
    ];

    private const PROVIDER_TEAM_ROLE_PERMISSION_PRESETS = [
        'manager' => ['manage_programs', 'review_applications', 'manage_reports', 'manage_profile', 'manage_team', 'manage_billing'],
        'program_coordinator' => ['manage_programs'],
        'application_reviewer' => ['review_applications'],
        'support_staff' => ['manage_reports'],
        'billing_staff' => ['manage_billing'],
    ];

    private const AWARD_SLOT_STATUSES = [
        'awarded',
        'distribution_scheduled',
        'disbursed',
        'renewed',
    ];

    private const PROVIDER_OBJECTIVES = [
        'education_access',
        'priority_skills',
        'future_talent',
        'community_development',
        'equity_inclusion',
        'academic_excellence',
        'education_partnerships',
        'other',
    ];

    private const RECIPIENT_COMMITMENT_TYPES = [
        'provider_briefing',
        'none',
        'renewal',
        'service',
        'activities',
        'reporting',
        'custom',
    ];

    private const REVIEW_DECISION_STATUSES = [
        'submitted',
        'under_review',
        'qualified',
        'shortlisted',
        'exam_taken',
        'exam_passed',
        'interview',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider');
    }

    public function programs(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-programs');
    }

    public function programEditDirectory(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-program-edit-directory');
    }

    public function programManageDirectory(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-program-manage-directory');
    }

    public function recipientMonitoringDirectory(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-recipient-monitoring-directory');
    }

    public function programWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return view('provider-program-workspace', [
            'scholarship' => $scholarship,
        ]);
    }

    public function programMonitoring(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        return view('provider-program-monitoring', [
            'scholarship' => $scholarship,
        ]);
    }

    public function programForm(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        $providerOwner = $request->user()->providerOrganizationOwner();

        if (! $providerOwner->hasVerifiedEmail() || ! $providerOwner->providerProfile?->isVerified()) {
            return redirect()->route('provider.profile.verification');
        }

        return view('provider-program-form');
    }

    public function applications(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-applications');
    }

    public function programApplications(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

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

    public function profile(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-profile');
    }

    public function team(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

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

    public function insights(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-insights');
    }

    public function dashboardData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user()
            ->loadMissing(['studentProfile', 'providerProfile', 'adminProfile']);
        $providerOwner = $provider->providerOrganizationOwner()->loadMissing('providerProfile');
        $providerId = $providerOwner->id;
        $verificationDocumentsCount = ProviderVerificationDocument::query()
            ->where('provider_id', $providerId)
            ->count();

        $canViewPrograms = $provider->hasPortalPermission('manage_programs')
            || $provider->hasPortalPermission('review_applications');
        $scholarships = $canViewPrograms
            ? $this->providerScholarshipsQuery($provider)
                ->withCount($this->providerProgramCountRelations())
                ->latest()
                ->get()
            : collect();
        $canReviewApplications = $provider->hasPortalPermission('review_applications')
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified();
        $applicationsBase = $this->providerApplicationsQuery($provider);
        $applicationWorkflowCounts = $canReviewApplications
            ? $this->providerApplicationFilterCounts($applicationsBase)
            : [
                'all' => 0,
                'needs_review' => 0,
                'waiting_activity' => 0,
                'ready_result' => 0,
                'final_decision' => 0,
            ];

        return response()->json([
            'user' => [
                ...$provider->publicPayload(),
                'verification_documents_count' => $verificationDocumentsCount,
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'application_workflow_counts' => [
                'needs_review' => (int) ($applicationWorkflowCounts['needs_review'] ?? 0),
                'waiting_activity' => (int) ($applicationWorkflowCounts['waiting_activity'] ?? 0),
                'ready_result' => (int) ($applicationWorkflowCounts['ready_result'] ?? 0),
                'final_decision' => (int) ($applicationWorkflowCounts['final_decision'] ?? 0),
                'all' => (int) ($applicationWorkflowCounts['all'] ?? 0),
            ],
        ]);
    }

    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $user = $request->user()->loadMissing(['providerProfile']);
        $providerOwner = $user->providerOrganizationOwner();
        $canManageVerification = $user->hasPortalPermission('manage_profile');

        return response()->json([
            'user' => [
                ...$this->providerStaffPayload($user),
                'verification_documents_count' => $providerOwner
                    ->providerVerificationDocuments()
                    ->count(),
            ],
            'verification_documents' => $canManageVerification
                ? $providerOwner
                    ->providerVerificationDocuments()
                    ->latest()
                    ->get()
                    ->map(fn (ProviderVerificationDocument $document) => $this->verificationDocumentPayload($document))
                    ->values()
                : [],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $user = $request->user();
        $providerOwner = $user->providerOrganizationOwner();
        $canManageOrganization = $user->hasPortalPermission('manage_profile');
        $profileSection = (string) $request->input('profile_section', 'all');

        $request->validate([
            'profile_section' => ['nullable', Rule::in(['all', 'organization', 'representative'])],
        ]);

        $editingRepresentative = in_array($profileSection, ['all', 'representative'], true);
        $editingOrganization = $canManageOrganization
            && in_array($profileSection, ['all', 'organization'], true);

        abort_if($profileSection === 'organization' && ! $canManageOrganization, 403);

        $rules = ['profile_section' => ['nullable', Rule::in(['all', 'organization', 'representative'])]];

        if ($editingRepresentative) {
            $rules += [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
                'contact_number' => ['required', 'string', 'max:30', new PhoneNumber],
            ];
        }

        if ($editingOrganization) {
            $rules += [
                'provider_name' => ['required', 'string', 'max:255'],
                'provider_type' => ['nullable', Rule::in(['school', 'foundation', 'government', 'company', 'non_profit', 'other'])],
                'provider_website' => ['nullable', 'string', 'max:255'],
                'provider_address' => ['nullable', 'string', 'max:500'],
                'provider_description' => ['nullable', 'string', 'max:1500'],
                'provider_contact_email' => ['nullable', 'email', 'max:255'],
                'provider_contact_number' => ['nullable', 'string', 'max:30', new PhoneNumber],
            ];
        }

        $validated = $request->validate($rules);

        $representativeProfile = $editingRepresentative ? [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => strtoupper($validated['middle_initial']),
            'contact_number' => $validated['contact_number'],
        ] : [];
        $emailChanged = $editingRepresentative
            && strcasecmp($user->email, $validated['email']) !== 0;
        $profile = $providerOwner->providerProfile;

        $organizationProfile = $editingOrganization ? [
            'provider_name' => $validated['provider_name'],
            'provider_type' => $validated['provider_type'] ?? null,
            'provider_website' => $validated['provider_website'] ?? null,
            'provider_address' => $validated['provider_address'] ?? null,
            'provider_description' => $validated['provider_description'] ?? null,
            'provider_contact_email' => strtolower(trim((string) ($validated['provider_contact_email']
                ?? $profile?->provider_contact_email
                ?? $providerOwner->email))),
            'provider_contact_number' => $validated['provider_contact_number']
                ?? $profile?->provider_contact_number
                ?? $profile?->contact_number
                ?? ($validated['contact_number'] ?? null),
            'verification_status' => $profile?->verification_status ?? 'pending',
            'verification_notes' => $profile?->verification_notes,
            'verified_by' => $profile?->verified_by,
            'verified_at' => $profile?->verified_at,
        ] : [];
        $organizationChanged = $editingOrganization
            && $profile?->verification_status === 'approved'
            && collect([
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
            ])->contains(fn (string $field): bool => $this->comparableScholarshipValue($profile?->{$field})
                !== $this->comparableScholarshipValue($organizationProfile[$field] ?? null));

        if ($organizationChanged) {
            $organizationProfile = [
                ...$organizationProfile,
                'verification_status' => 'pending',
                'verification_notes' => null,
                'verified_by' => null,
                'verified_at' => null,
            ];
        }

        DB::transaction(function () use (
            $user,
            $providerOwner,
            $validated,
            $representativeProfile,
            $organizationProfile,
            $profile,
            $editingRepresentative,
            $editingOrganization,
            $emailChanged,
            $organizationChanged,
        ): void {
            if ($editingRepresentative) {
                $user->fill([
                    'email' => $validated['email'],
                    'username' => $validated['username'],
                ]);

                if ($emailChanged) {
                    $user->email_verified_at = null;
                }

                $user->save();
            }

            $user->providerProfile()->updateOrCreate([
                'user_id' => $user->id,
            ], [
                ...$representativeProfile,
                ...$organizationProfile,
            ]);

            if ($editingOrganization && ! $providerOwner->is($user)) {
                $providerOwner->providerProfile()->updateOrCreate([
                    'user_id' => $providerOwner->id,
                ], [
                    'first_name' => $profile?->first_name,
                    'last_name' => $profile?->last_name,
                    'middle_initial' => $profile?->middle_initial,
                    'contact_number' => $profile?->contact_number,
                    ...$organizationProfile,
                ]);
            }

            if ($organizationChanged) {
                $providerOwner->providerVerificationDocuments()->update([
                    'status' => 'submitted',
                    'review_notes' => null,
                ]);
            }
        });

        if ($organizationChanged) {
            User::query()
                ->where('role', 'admin')
                ->where('account_status', 'active')
                ->get()
                ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
                ->each(fn (User $admin) => PortalNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'provider_profile_verification',
                    'title' => 'Verified provider profile changed',
                    'message' => "{$organizationProfile['provider_name']} changed verified organization details and needs another review.",
                    'action_url' => route('admin.providers.review.show', $providerOwner, false),
                ]));
        }

        if ($emailChanged) {
            $emailVerificationSent = false;

            try {
                $user->sendEmailVerificationNotification();
                $emailVerificationSent = true;
            } catch (Throwable $error) {
                ActivityLog::record(
                    $user,
                    'email_verification_email_failed',
                    "Email verification link could not be sent to {$user->email}.",
                    $request,
                    ['error' => $error->getMessage()],
                );
            }

            PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'email_verification',
                'title' => 'Verify your email address',
            ], [
                'message' => $emailVerificationSent
                    ? 'A verification link was sent to your new email address.'
                    : 'Your new email address is not verified. Resend the verification link from the portal.',
                'action_url' => null,
                'read_at' => null,
            ]);
        }

        ActivityLog::record(
            $user,
            'provider_profile_updated',
            ($providerOwner->providerProfile?->provider_name ?: $user->name ?: 'Provider').' updated profile details.',
            $request,
            ['provider_id' => $providerOwner->id, 'updated_by' => $user->id, 'profile_section' => $profileSection],
        );

        return response()->json([
            'message' => $organizationChanged
                ? 'Provider profile updated and returned for admin verification.'
                : ($emailChanged
                    ? 'Profile updated. Verify your new email address before publishing programs.'
                    : ($profileSection === 'organization'
                        ? 'Provider details updated successfully.'
                        : ($profileSection === 'representative'
                            ? 'Representative details updated successfully.'
                            : 'Provider profile updated successfully.'))),
            'user' => $this->providerStaffPayload($user->fresh(['providerProfile'])),
            'email_changed' => $emailChanged,
            'verification_reset' => $organizationChanged,
        ]);
    }

    public function uploadProviderLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_profile'), 403);

        $request->validate([
            'logo_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $providerOwner = $request->user()->providerOrganizationOwner();
        $profile = $providerOwner->providerProfile()->firstOrCreate([
            'user_id' => $providerOwner->id,
        ]);
        $oldLogoPath = $profile->logo_path;
        $logoPath = $this->storeProviderLogo($request);

        $profile->update(['logo_path' => $logoPath]);
        $this->deleteProviderLogoIfUnused($oldLogoPath);

        ActivityLog::record(
            $request->user(),
            'provider_logo_updated',
            ($profile->provider_name ?: $providerOwner->name ?: 'Provider').' updated the organization logo.',
            $request,
            ['provider_id' => $providerOwner->id],
        );

        return response()->json([
            'message' => 'Provider logo updated successfully.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
        ]);
    }

    public function uploadVerificationDocument(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'document_type' => ['required', Rule::in([
                'organization_registration',
                'authorization_letter',
                'valid_id',
                'school_or_office_proof',
                'other',
            ])],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'terms_accepted' => ['accepted'],
        ]);

        $file = $validated['document_file'];
        $providerOwner = $request->user()->providerOrganizationOwner();
        $path = $file->store("provider-verification/{$providerOwner->id}", 'local');

        $document = ProviderVerificationDocument::create([
            'provider_id' => $providerOwner->id,
            'uploaded_by' => $request->user()->id,
            'document_type' => $validated['document_type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'status' => 'submitted',
            'uploaded_at' => now(),
            'terms_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        $returnedToReview = $providerOwner->providerProfile?->verification_status === 'rejected';

        if ($returnedToReview) {
            $providerOwner->providerProfile()->update([
                'verification_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
            ]);
        }

        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'provider_verification_document',
                'title' => 'Provider document uploaded',
                'message' => "{$providerOwner->provider_name} uploaded a verification document.",
                'action_url' => '/admin/reviews',
            ]));

        ActivityLog::record(
            $request->user(),
            'provider_verification_document_uploaded',
            "{$request->user()->name} uploaded a provider verification document.",
            $request,
            ['document_id' => $document->id, 'document_type' => $document->document_type],
        );

        return response()->json([
            'message' => $returnedToReview
                ? 'Verification proof uploaded and returned for admin review.'
                : 'Verification proof uploaded for admin review.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
            'document' => $this->verificationDocumentPayload($document),
            'verification_documents' => $providerOwner
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ], 201);
    }

    public function deleteVerificationDocument(Request $request, ProviderVerificationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $providerOwner = $request->user()->providerOrganizationOwner();
        abort_unless($document->provider_id === $providerOwner->id, 403);
        $returnedToReview = $providerOwner->providerProfile?->verification_status === 'approved';

        DB::transaction(function () use ($document, $providerOwner, $returnedToReview): void {
            $document->delete();

            if ($returnedToReview) {
                $providerOwner->providerProfile()->update([
                    'verification_status' => 'pending',
                    'verification_notes' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
            }
        });

        if (Storage::disk('local')->exists($document->path)) {
            Storage::disk('local')->delete($document->path);
        }

        if ($returnedToReview) {
            User::query()
                ->where('role', 'admin')
                ->get()
                ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
                ->each(fn (User $admin) => PortalNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'provider_verification_document',
                    'title' => 'Provider proof changed',
                    'message' => "{$providerOwner->provider_name} removed verification proof and needs another review.",
                    'action_url' => '/admin/reviews',
                ]));
        }

        ActivityLog::record(
            $request->user(),
            'provider_verification_document_deleted',
            "{$request->user()->name} removed a provider verification document.",
            $request,
            [
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'provider_id' => $providerOwner->id,
                'returned_to_review' => $returnedToReview,
            ],
        );

        return response()->json([
            'message' => $returnedToReview
                ? 'Verification document removed. Publishing is paused until an admin reviews the provider account again.'
                : 'Verification document removed.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
            'returned_to_review' => $returnedToReview,
            'verification_documents' => $providerOwner
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ]);
    }

    public function downloadVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless(
            $request->user()?->isProvider()
                && $document->provider_id === $request->user()->providerOrganizationId(),
            403,
        );
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless(
            $request->user()?->isProvider()
                && $document->provider_id === $request->user()->providerOrganizationId(),
            403,
        );
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function insightsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $providerId = $request->user()->providerOrganizationId();
        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();
        $applications = $this->providerApplicationsQuery($request->user())
            ->with(['applicant.studentProfile', 'documents.reviewer', 'scholarship'])
            ->latest('submitted_at')
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));
        $recommendationCounts = $applications
            ->groupBy('dss_recommendation')
            ->map(fn ($items) => $items->count());
        $submitted = $applications->count();
        $completeApplications = $applications
            ->filter(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['percent'] === 100)
            ->count();
        $approved = $applications->where('status', 'approved')->count();
        $totalViews = $scholarships->sum(fn (Scholarship $scholarship) => $scholarship->views_count ?? 0);
        $totalSaves = $scholarships->sum(fn (Scholarship $scholarship) => $scholarship->bookmarks_count ?? 0);
        $missingDocuments = $applications
            ->flatMap(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['missing'])
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->map(fn (int $total, string $document) => [
                'document' => $document,
                'total' => $total,
            ])
            ->values();
        $documentIssues = $applications
            ->flatMap(fn (ScholarshipApplication $application) => $application->documents)
            ->filter(fn (ApplicationDocument $document) => in_array($document->status, ['pending', 'rejected', 'needs_replacement'], true))
            ->groupBy('document_name')
            ->map(fn ($items, string $document) => [
                'document' => $document,
                'total' => $items->count(),
                'pending' => $items->where('status', 'pending')->count(),
                'needs_replacement' => $items->where('status', 'needs_replacement')->count(),
                'rejected' => $items->where('status', 'rejected')->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->take(8);
        $documentStatusPriority = [
            'pending' => 0,
            'needs_replacement' => 1,
            'rejected' => 2,
            'accepted' => 3,
        ];
        $documentReviewPackets = $applications
            ->filter(fn (ScholarshipApplication $application) => $application->documents->isNotEmpty())
            ->map(function (ScholarshipApplication $application) use ($documentStatusPriority): array {
                $documents = $application->documents
                    ->sortBy(fn (ApplicationDocument $document) => $documentStatusPriority[$document->status ?? 'pending'] ?? 4)
                    ->values();
                $statusCounts = $documents->countBy(fn (ApplicationDocument $document) => $document->status ?? 'pending');
                $needsReview = (int) ($statusCounts['pending'] ?? 0)
                    + (int) ($statusCounts['needs_replacement'] ?? 0)
                    + (int) ($statusCounts['rejected'] ?? 0);

                return [
                    'application_id' => $application->id,
                    'application_status' => $application->status,
                    'applicant' => $application->applicant?->name,
                    'applicant_email' => $application->applicant?->email,
                    'scholarship' => $application->scholarship?->title,
                    'scholarship_image_url' => $application->scholarship
                        ? $this->scholarshipImageUrl($application->scholarship)
                        : asset('uploads/scholarship-default.jpg'),
                    'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
                    'files_count' => $documents->count(),
                    'needs_review_count' => $needsReview,
                    'accepted_count' => (int) ($statusCounts['accepted'] ?? 0),
                    'replacement_count' => (int) ($statusCounts['needs_replacement'] ?? 0),
                    'rejected_count' => (int) ($statusCounts['rejected'] ?? 0),
                    'documents' => $documents
                        ->take(4)
                        ->map(fn (ApplicationDocument $document) => $this->documentPayload($document))
                        ->values(),
                    'review_url' => route('provider.applications.show', [
                        'application' => $application,
                        'section' => 'documents',
                    ]),
                ];
            })
            ->sort(function (array $first, array $second): int {
                return ($second['needs_review_count'] <=> $first['needs_review_count'])
                    ?: ($second['application_id'] <=> $first['application_id']);
            })
            ->values();
        $documentReviewPerPage = 8;
        $documentReviewTotal = $documentReviewPackets->count();
        $documentReviewLastPage = max(1, (int) ceil($documentReviewTotal / $documentReviewPerPage));
        $documentReviewPage = min(
            max(1, $request->integer('document_page', 1)),
            $documentReviewLastPage,
        );
        $documentReviewQueue = [
            'data' => $documentReviewPackets->forPage($documentReviewPage, $documentReviewPerPage)->values(),
            'current_page' => $documentReviewPage,
            'last_page' => $documentReviewLastPage,
            'per_page' => $documentReviewPerPage,
            'total' => $documentReviewTotal,
        ];

        return response()->json([
            'user' => $request->user()->loadMissing(['providerProfile'])->publicPayload(),
            'summary' => [
                'programs' => $scholarships->count(),
                'published_programs' => $scholarships->where('status', 'published')->count(),
                'total_views' => $totalViews,
                'total_saves' => $totalSaves,
                'applications' => $submitted,
                'complete_applications' => $completeApplications,
                'approved_applications' => $approved,
                'average_dss_score' => round((float) $applications->avg('dss_score'), 1),
            ],
            'funnel' => [
                ['label' => 'Views', 'value' => $totalViews],
                ['label' => 'Saved', 'value' => $totalSaves],
                ['label' => 'Submitted', 'value' => $submitted],
                ['label' => 'Complete checklist', 'value' => $completeApplications],
                ['label' => 'Approved', 'value' => $approved],
            ],
            'program_insights' => $scholarships->map(function (Scholarship $scholarship) use ($applications) {
                $programApplications = $applications->filter(fn (ScholarshipApplication $application) => $application->scholarship_id === $scholarship->id);
                $completeApplications = $programApplications
                    ->filter(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['percent'] === 100)
                    ->count();

                return [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                    'views' => $scholarship->views_count ?? 0,
                    'saves' => $scholarship->bookmarks_count ?? 0,
                    'applications' => $programApplications->count(),
                    'complete_applications' => $completeApplications,
                    'average_match_score' => round((float) $programApplications->avg('eligibility_score'), 1),
                    'average_dss_score' => round((float) $programApplications->avg('dss_score'), 1),
                ];
            })->sortByDesc('applications')->values(),
            'top_missing_documents' => $missingDocuments,
            'document_issues' => $documentIssues,
            'document_review_queue' => $documentReviewQueue,
            'dss_summary' => [
                'average_score' => round((float) $applications->avg('dss_score'), 1),
                'highly_recommended' => $recommendationCounts['highly_recommended'] ?? 0,
                'recommended' => $recommendationCounts['recommended'] ?? 0,
                'needs_review' => $recommendationCounts['needs_review'] ?? 0,
                'not_recommended' => $recommendationCounts['not_recommended'] ?? 0,
            ],
        ]);
    }

    public function applicationsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'filter' => ['sometimes', Rule::in([
                'needs_review',
                'waiting_activity',
                'ready_result',
                'final_decision',
                'selected',
                'waitlisted',
                'pending_review',
                'document_issues',
                'active_stages',
                'formal_application',
                'decided',
                'all',
            ])],
            'sort' => ['sometimes', Rule::in(['priority', 'dss', 'documents', 'oldest'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);

        $providerId = $request->user()->providerOrganizationId();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filter = $validated['filter'] ?? 'needs_review';
        $sort = $validated['sort'] ?? 'priority';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->withAvg('applications as average_match_score', 'eligibility_score')
            ->withAvg('applications as average_dss_score', 'dss_score')
            ->latest()
            ->get();
        $reviewers = $this->providerApplicationReviewers($providerId, $request->user());
        $applicationsBase = $this->providerApplicationsQuery($request->user());

        if ($selectedScholarship) {
            $applicationsBase->where('scholarship_id', $selectedScholarship->id);
        }

        $statusCounts = (clone $applicationsBase)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $recommendationCounts = (clone $applicationsBase)
            ->selectRaw('dss_recommendation, count(*) as total')
            ->whereNotNull('dss_recommendation')
            ->groupBy('dss_recommendation')
            ->pluck('total', 'dss_recommendation');
        $stageCounts = (clone $applicationsBase)
            ->selectRaw("COALESCE(workflow_stage, 'screening') as workflow_stage, count(*) as total")
            ->whereNotIn('application_state', ['closed', 'withdrawn'])
            ->groupByRaw("COALESCE(workflow_stage, 'screening')")
            ->pluck('total', 'workflow_stage');
        $activityWaitingCounts = $this->providerActivityWaitingCounts($applicationsBase);
        $filterCounts = $this->providerApplicationFilterCounts($applicationsBase);
        $totalApplications = (int) ($filterCounts['all'] ?? 0);

        $applicationsQuery = (clone $applicationsBase)
            ->with([
                'applicant.studentProfile',
                'applicant.applicantVerificationDocuments',
                'documents.reviewer',
                'assignedReviewer.providerProfile',
                'statusHistories.actor',
                'schedules',
                'stageProgresses',
                'scholarship' => fn ($query) => $query
                    ->with(['events', 'benefits'])
                    ->withCount($this->providerProgramCountRelations()),
            ]);
        $this->applyProviderApplicationSearch($applicationsQuery, $search);
        $this->applyProviderApplicationFilter($applicationsQuery, $filter);
        $this->applyProviderApplicationSort($applicationsQuery, $sort);

        $applications = $applicationsQuery->paginate($perPage);
        $applications->getCollection()
            ->filter(fn (ScholarshipApplication $application): bool => $application->dss_score === null
                || blank($application->dss_recommendation)
                || blank($application->dss_breakdown))
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'reviewers' => $reviewers
                ->map(fn (User $reviewer) => $this->applicationReviewerPayload($reviewer, $providerId))
                ->values(),
            'stats' => [
                'scholarships' => $scholarships->count(),
                'applications' => $totalApplications,
                'drafts' => $scholarships->where('status', 'draft')->count(),
                'under_review' => $statusCounts['under_review'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
                'average_match_score' => round((float) (clone $applicationsBase)->avg('eligibility_score'), 1),
                'average_dss_score' => round((float) (clone $applicationsBase)->avg('dss_score'), 1),
                'pending_documents' => ApplicationDocument::query()
                    ->where('status', 'pending')
                    ->whereHas('application.scholarship', fn (Builder $query) => $this->applyProviderScholarshipScope($query, $request->user()))
                    ->count(),
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'selected_scholarship' => $selectedScholarship
                ? $this->scholarshipPayload($selectedScholarship)
                : null,
            'program_events' => $selectedScholarship
                ? $selectedScholarship->events
                    ->sortBy('scheduled_at')
                    ->map(fn (ScholarshipEvent $event) => ScholarshipEventPayload::make($event))
                    ->values()
                : [],
            'applications' => $applications->getCollection()
                ->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))
                ->values(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
                'from' => $applications->firstItem(),
                'to' => $applications->lastItem(),
            ],
            'filter_counts' => $filterCounts,
            'stage_counts' => $stageCounts,
            'activity_waiting_counts' => $activityWaitingCounts,
            'status_counts' => [
                'submitted' => $statusCounts['submitted'] ?? 0,
                'under_review' => $statusCounts['under_review'] ?? 0,
                'qualified' => $statusCounts['qualified'] ?? 0,
                'exam_qualified' => $statusCounts['exam_qualified'] ?? 0,
                'exam_scheduled' => $statusCounts['exam_scheduled'] ?? 0,
                'exam_taken' => $statusCounts['exam_taken'] ?? 0,
                'exam_passed' => $statusCounts['exam_passed'] ?? 0,
                'exam_failed' => $statusCounts['exam_failed'] ?? 0,
                'interview_failed' => $statusCounts['interview_failed'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
            ],
            'recommendation_counts' => [
                'highly_recommended' => $recommendationCounts['highly_recommended'] ?? 0,
                'recommended' => $recommendationCounts['recommended'] ?? 0,
                'needs_review' => $recommendationCounts['needs_review'] ?? 0,
                'low_priority' => $recommendationCounts['low_priority'] ?? 0,
                'not_recommended' => $recommendationCounts['not_recommended'] ?? 0,
            ],
            'program_performance' => $scholarships->map(function (Scholarship $scholarship) {
                return [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                    'applications' => (int) ($scholarship->applications_count ?? 0),
                    'average_match_score' => round((float) ($scholarship->average_match_score ?? 0), 1),
                    'average_dss_score' => round((float) ($scholarship->average_dss_score ?? 0), 1),
                    'saved_count' => $scholarship->bookmarks_count ?? 0,
                    'deadline' => $scholarship->deadline?->format('M d, Y'),
                    'days_left' => $scholarship->deadline ? now()->startOfDay()->diffInDays($scholarship->deadline->startOfDay(), false) : null,
                ];
            })->values(),
        ]);
    }

    private function applyProviderApplicationSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $likeSearch = '%'.$search.'%';

        $query->where(function (Builder $query) use ($likeSearch): void {
            $query
                ->whereHas('applicant', function (Builder $applicantQuery) use ($likeSearch): void {
                    $applicantQuery
                        ->where('email', 'like', $likeSearch)
                        ->orWhere('username', 'like', $likeSearch)
                        ->orWhereHas('studentProfile', fn (Builder $profileQuery) => $profileQuery
                            ->where('first_name', 'like', $likeSearch)
                            ->orWhere('last_name', 'like', $likeSearch));
                })
                ->orWhereHas('scholarship', fn (Builder $scholarshipQuery) => $scholarshipQuery
                    ->where('title', 'like', $likeSearch));
        });
    }

    private function applyProviderApplicationFilter(Builder $query, string $filter): void
    {
        if (in_array($filter, ['needs_review', 'pending_review'], true)) {
            $query
                ->where(fn (Builder $query) => $query
                    ->where('workflow_stage', 'screening')
                    ->orWhere(fn (Builder $legacyQuery) => $legacyQuery
                        ->whereNull('workflow_stage')
                        ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted'])))
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'waiting_activity') {
            $query
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $examQuery): void {
                            $examQuery
                                ->where('workflow_stage', 'exam')
                                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'exam')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $interviewQuery): void {
                            $interviewQuery
                                ->where('workflow_stage', 'interview')
                                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'interview')
                                    ->where('status', 'completed'));
                        });
                });

            return;
        }

        if ($filter === 'ready_result') {
            $query
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->where(function (Builder $query): void {
                    $query
                        ->where('workflow_stage', 'formal_application')
                        ->orWhere(function (Builder $examQuery): void {
                            $examQuery
                                ->where('workflow_stage', 'exam')
                                ->whereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'exam')
                                    ->where('status', 'completed'));
                        })
                        ->orWhere(function (Builder $interviewQuery): void {
                            $interviewQuery
                                ->where('workflow_stage', 'interview')
                                ->whereHas('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                                    ->where('type', 'interview')
                                    ->where('status', 'completed'));
                        });
                });

            return;
        }

        if ($filter === 'final_decision') {
            $query
                ->where('workflow_stage', 'decision')
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'selected') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'selected')
                    ->orWhereIn('status', ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated']);
            });

            return;
        }

        if ($filter === 'waitlisted') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('final_outcome', 'waitlisted')
                    ->orWhere('status', 'waitlisted');
            });

            return;
        }

        if ($filter === 'document_issues') {
            $driver = DB::connection()->getDriverName();
            $checklistLength = in_array($driver, ['mysql', 'mariadb'], true)
                ? 'COALESCE(JSON_LENGTH(scholarship_applications.document_checklist), 0)'
                : 'COALESCE(json_array_length(scholarship_applications.document_checklist), 0)';

            $query->where(function (Builder $query) use ($checklistLength): void {
                $query
                    ->whereHas('documents', fn (Builder $documentQuery) => $documentQuery
                        ->whereIn('status', ['pending', 'needs_replacement']))
                    ->orWhereRaw("{$checklistLength} > (
                        SELECT COUNT(*)
                        FROM application_documents
                        WHERE application_documents.scholarship_application_id = scholarship_applications.id
                          AND application_documents.status = 'accepted'
                    )");
            });

            return;
        }

        if ($filter === 'active_stages') {
            $query
                ->whereIn('workflow_stage', ['exam', 'interview'])
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'formal_application') {
            $query
                ->whereIn('workflow_stage', ['formal_application', 'decision'])
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

            return;
        }

        if ($filter === 'decided') {
            $query->where(function (Builder $query): void {
                $query
                    ->whereIn('application_state', ['closed', 'withdrawn'])
                    ->orWhereNotNull('final_outcome');
            });
        }
    }

    private function applyProviderApplicationSort(Builder $query, string $sort): void
    {
        if ($sort === 'dss') {
            $query->orderByDesc('dss_score')->orderBy('submitted_at');

            return;
        }

        if ($sort === 'documents') {
            $query
                ->withCount([
                    'documents as unresolved_documents_count' => fn (Builder $documentQuery) => $documentQuery
                        ->whereIn('status', ['pending', 'needs_replacement']),
                ])
                ->orderByDesc('unresolved_documents_count')
                ->orderBy('submitted_at');

            return;
        }

        if ($sort === 'oldest') {
            $query->orderBy('submitted_at')->orderBy('id');

            return;
        }

        $query
            ->orderByRaw("CASE
                WHEN correction_status = 'submitted' THEN 0
                WHEN COALESCE(workflow_stage, 'screening') = 'screening' AND application_state NOT IN ('closed', 'withdrawn') THEN 1
                WHEN workflow_stage IN ('exam', 'interview') AND application_state NOT IN ('closed', 'withdrawn') THEN 2
                WHEN workflow_stage IN ('formal_application', 'decision') AND application_state NOT IN ('closed', 'withdrawn') THEN 3
                ELSE 4
            END")
            ->orderByDesc('dss_score')
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    private function providerApplicationFilterCounts(Builder $baseQuery): array
    {
        $counts = ['all' => (clone $baseQuery)->count()];

        foreach (['needs_review', 'waiting_activity', 'ready_result', 'final_decision', 'selected', 'waitlisted'] as $filter) {
            $query = clone $baseQuery;
            $this->applyProviderApplicationFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        // Keep old URLs and dashboard links compatible while the UI moves to task-based queues.
        $counts['pending_review'] = $counts['needs_review'];

        foreach (['document_issues', 'active_stages', 'formal_application', 'decided'] as $filter) {
            $query = clone $baseQuery;
            $this->applyProviderApplicationFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        return $counts;
    }

    private function providerActivityWaitingCounts(Builder $baseQuery): Collection
    {
        return collect(['exam', 'interview'])->mapWithKeys(function (string $stage) use ($baseQuery): array {
            $query = (clone $baseQuery)
                ->where('workflow_stage', $stage)
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->whereDoesntHave('schedules', fn (Builder $scheduleQuery) => $scheduleQuery
                    ->where('type', $stage)
                    ->where('status', 'completed'));

            return [$stage => $query->count()];
        });
    }

    public function applicationDetailData(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $application->load(['applicant.studentProfile', 'documents.reviewer', 'assignedReviewer.providerProfile', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($application);
        $application = $application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'assignedReviewer.providerProfile', 'statusHistories.actor', 'scholarship']);

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'application' => $this->applicationPayload($application, true),
            'application_navigation' => $this->applicationSiblingNavigationPayload($application),
        ]);
    }

    public function upsertScholarshipEvent(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'venue' => [
                Rule::requiredIf(in_array($request->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'online_url' => [
                Rule::requiredIf(
                    in_array($request->input('mode'), ['online', 'hybrid'], true)
                ),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'instructions' => ['required', 'string', 'max:3000'],
        ]);

        [$event, $audienceCount] = $this->persistScholarshipEvent(
            $scholarship,
            $validated,
            $request->user(),
        );
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at']);
        $eventLabel = ScholarshipSelectionPlan::label($validated['type']);

        ActivityLog::record(
            $request->user(),
            'scholarship_event_published',
            "{$request->user()->name} published the {$eventLabel} schedule for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'audience_count' => $audienceCount,
            ],
        );

        return response()->json([
            'message' => $audienceCount > 0
                ? ucfirst($eventLabel)." schedule published to {$audienceCount} eligible applicant(s)."
                : ucfirst($eventLabel).' schedule saved. Eligible applicants will receive it when they reach this stage.',
            'event' => ScholarshipEventPayload::make($event->fresh()),
            'audience_count' => $audienceCount,
        ]);
    }

    public function completeScholarshipEvent(
        Request $request,
        Scholarship $scholarship,
        ScholarshipEvent $event,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($event->scholarship_id === $scholarship->id, 404);

        if ($event->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'event' => 'This event cannot be completed before its scheduled date and time.',
            ]);
        }

        $participantCount = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('workflow_stage', $event->type)
            ->count();

        $completedAt = now();

        $event->update([
            'status' => 'completed',
            'updated_by' => $request->user()->id,
        ]);

        ApplicationSchedule::query()
            ->where('type', $event->type)
            ->where('status', 'scheduled')
            ->whereHas('application', fn (Builder $query) => $query
                ->where('scholarship_id', $scholarship->id)
                ->where('workflow_stage', $event->type))
            ->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'updated_by' => $request->user()->id,
                'updated_at' => $completedAt,
            ]);

        ActivityLog::record(
            $request->user(),
            'scholarship_event_completed',
            "{$request->user()->name} completed the {$event->type} event for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'participant_count' => $participantCount,
            ],
        );

        return response()->json([
            'message' => ucfirst(ScholarshipSelectionPlan::label($event->type)).' schedule archived. Applicant results are recorded independently.',
            'event' => ScholarshipEventPayload::make($event->fresh()),
            'participant_count' => $participantCount,
        ]);
    }

    public function bulkUpdateScholarshipEventAttendance(
        Request $request,
        Scholarship $scholarship,
        ScholarshipEvent $event,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($event->scholarship_id === $scholarship->id, 404);
        abort_unless(ScholarshipSelectionPlan::isSchedulable($event->type), 404);

        if ($event->status !== 'completed') {
            throw ValidationException::withMessages([
                'event' => 'Mark the '.ScholarshipSelectionPlan::label($event->type).' activity as completed before recording applicant results.',
            ]);
        }

        $validated = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['required', 'integer', 'distinct'],
            'attendance_status' => ['required', Rule::in(['passed', 'failed'])],
            'attendance_notes' => ['nullable', 'string', 'max:1500'],
        ]);
        $applicationIds = collect($validated['application_ids'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $applications = ScholarshipApplication::query()
            ->with(['applicant', 'scholarship', 'schedules'])
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('id', $applicationIds)
            ->get();

        if ($applications->count() !== $applicationIds->count()) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applicants do not belong to this program.',
            ]);
        }

        $invalidStageApplication = $applications->first(function (ScholarshipApplication $application) use ($event): bool {
            $workflow = $this->workflowService->payload($application);

            return $workflow['current_stage'] !== $event->type || $workflow['is_closed'];
        });

        if ($invalidStageApplication) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applicants are no longer waiting for this activity result. Refresh the list and try again.',
            ]);
        }

        $result = $validated['attendance_status'] === 'passed' ? 'passed' : 'not_passed';
        $updatedApplications = collect();

        DB::transaction(function () use ($applications, $event, $request, $validated, $result, $updatedApplications): void {
            foreach ($applications as $application) {
                $schedule = $application->schedules->firstWhere('type', $event->type);

                if ($schedule) {
                    $schedule->update([
                        'status' => 'completed',
                        'attendance_status' => $validated['attendance_status'],
                        'attendance_notes' => $validated['attendance_notes'] ?? null,
                        'completed_at' => now(),
                        'updated_by' => $request->user()->id,
                    ]);
                }

                $updatedApplications->push($this->workflowService->recordStageResult(
                    $application,
                    $event->type,
                    $result,
                    $request->user(),
                    $validated['attendance_notes'] ?? null,
                ));
            }
        });

        foreach ($updatedApplications as $freshApplication) {
            $freshApplication->loadMissing(['applicant', 'scholarship']);
            app(ScholarshipEventService::class)->syncApplication($freshApplication);
            $nextWorkflow = $this->workflowService->payload($freshApplication);
            $notification = $this->workflowStageNotification(
                $freshApplication,
                $event->type,
                $result,
                $nextWorkflow['current_stage_label'],
            );

            PortalNotification::create([
                'user_id' => $freshApplication->applicant_id,
                'type' => 'application_status',
                'title' => $notification['title'],
                'message' => $notification['message'],
                'action_url' => route('dashboard.applications.show', $freshApplication, false),
            ]);
            app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_bulk_schedule_tracking_updated');
        }

        ActivityLog::record(
            $request->user(),
            'application_schedule_bulk_tracking_updated',
            "{$request->user()->name} updated {$event->type} results for {$applications->count()} applicant(s).",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'scholarship_event_id' => $event->id,
                'schedule_type' => $event->type,
                'result' => $result,
                'application_ids' => $applicationIds->all(),
            ],
        );

        return response()->json([
            'message' => "Updated {$applications->count()} applicant record(s).",
            'updated_count' => $applications->count(),
            'result' => $result,
            'attendance_status' => $validated['attendance_status'],
        ]);
    }

    public function decideApplication(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decision_reason' => [
                Rule::requiredIf($request->input('decision') === 'reject'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $workflow = $this->workflowService->payload($application);
        $stage = $workflow['current_stage'];

        if (! in_array($stage, ['screening', 'formal_application', 'exam', 'interview'], true)) {
            throw ValidationException::withMessages([
                'decision' => $stage === 'decision'
                    ? 'Record Selected, Waitlisted, or Not selected as the final outcome.'
                    : 'This application no longer needs a stage decision.',
            ]);
        }

        $request->merge([
            'result' => $validated['decision'] === 'approve' ? 'passed' : 'not_passed',
            'notes' => $validated['review_notes'] ?? null,
        ]);

        return $this->recordApplicationStageResult($request, $application, $stage);
    }

    public function recordApplicationStageResult(
        Request $request,
        ScholarshipApplication $application,
        string $stage,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(in_array($stage, ['screening', 'formal_application', 'exam', 'interview'], true), 404);

        $validated = $request->validate([
            'result' => ['required', Rule::in(ApplicationWorkflowService::RESULTS)],
            'decision_reason' => [
                Rule::requiredIf($request->input('result') === 'not_passed'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'notes' => ['nullable', 'string', 'max:1500'],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $notes = $validated['notes'] ?? $validated['review_notes'] ?? null;

        $this->ensureStageActivityCompleted($application, $stage);

        if ($stage === 'screening') {
            $rubric = $this->requireCompleteApplicationRubric($application, $validated['rubric_scores'] ?? null);

            if ($validated['result'] === 'passed') {
                $this->ensureApplicationDocumentsReadyForStatus($application, 'approved');
            }

            if ($rubric !== null) {
                $application->update([
                    'rubric_scores' => $rubric['scores'],
                    'rubric_total_score' => $rubric['total_score'],
                    'rubric_scored_by' => $request->user()->id,
                    'rubric_scored_at' => now(),
                ]);
            }
        }

        $previousStage = $this->workflowService->payload($application)['current_stage'];
        $updated = $this->workflowService->recordStageResult(
            $application,
            $stage,
            $validated['result'],
            $request->user(),
            $notes,
            $validated['decision_reason'] ?? null,
        );
        app(ScholarshipEventService::class)->syncApplication($updated);
        $updated = $updated->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'stageProgresses',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        $workflow = $this->workflowService->payload($updated);
        $notification = $this->workflowStageNotification(
            $updated,
            $previousStage,
            $validated['result'],
            $workflow['current_stage_label'],
        );

        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_status',
            'title' => $notification['title'],
            'message' => $notification['message'],
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_stage_result_recorded',
            "{$request->user()->name} recorded {$validated['result']} for {$stage} on application #{$updated->id}.",
            $request,
            ['application_id' => $updated->id, 'stage' => $stage, 'result' => $validated['result']],
        );
        ScholarshipFunnelEvent::record(
            $updated->applicant,
            "application_stage_{$stage}_{$validated['result']}",
            $updated->scholarship,
            $updated,
            'provider',
            [
                'stage' => $stage,
                'result' => $validated['result'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'canonical_decision_reason' => ApplicationDecisionReason::canonical($validated['decision_reason'] ?? null),
                'reviewed_by' => $request->user()->id,
                'rubric_total_score' => $updated->rubric_total_score,
            ],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_stage_result');

        return response()->json([
            'message' => $notification['title'].'.',
            'application' => $this->applicationPayload($updated, true),
            'review_navigation' => $this->reviewNavigationPayload($updated, $previousStage),
        ]);
    }

    public function recordApplicationFinalOutcome(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(ApplicationWorkflowService::FINAL_OUTCOMES)],
            'decision_reason' => [
                Rule::requiredIf($request->input('outcome') === 'not_selected'),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        if ($validated['outcome'] === 'selected') {
            $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'outcome');
        }

        $updated = $this->workflowService->recordFinalOutcome(
            $application,
            $validated['outcome'],
            $request->user(),
            $validated['notes'] ?? null,
            $validated['decision_reason'] ?? null,
        );

        if (array_key_exists('awarded_amount', $validated)) {
            $updated->update(['awarded_amount' => $validated['awarded_amount']]);
            $updated = $this->workflowService->refreshRecipientAgreementSnapshot($updated->fresh());
        }

        $updated = $updated->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'stageProgresses',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        $outcomeLabel = $this->workflowService->payload($updated)['final_outcome_label'];
        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_outcome',
            'title' => "Application outcome: {$outcomeLabel}",
            'message' => "The provider recorded {$outcomeLabel} as the final outcome for {$updated->scholarship?->title}. Open the application to review the details.",
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_final_outcome_recorded',
            "{$request->user()->name} recorded {$validated['outcome']} for application #{$updated->id}.",
            $request,
            ['application_id' => $updated->id, 'outcome' => $validated['outcome']],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_final_outcome');

        return response()->json([
            'message' => "Final outcome recorded as {$outcomeLabel}.",
            'application' => $this->applicationPayload($updated, true),
            'review_navigation' => $this->reviewNavigationPayload($updated, 'decision'),
        ]);
    }

    public function bulkAdvanceApplications(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:100'],
            'application_ids.*' => ['required', 'integer', 'distinct'],
            'target_stage' => ['required', Rule::in([
                'pass_prescreening',
                'pass_stage',
                'selected',
            ])],
        ]);
        $applicationIds = collect($validated['application_ids'])->map(fn ($id) => (int) $id)->values();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'schedules', 'scholarship.events'])
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('id', $applicationIds)
            ->orderBy('id')
            ->get();

        if ($applications->count() !== $applicationIds->unique()->count()) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more selected applications do not belong to this program.',
            ]);
        }

        $targetStage = $validated['target_stage'];
        $invalidApplications = $applications
            ->reject(function (ScholarshipApplication $application) use ($targetStage): bool {
                return in_array($targetStage, $this->bulkAdvanceTargets($application), true);
            });

        if ($invalidApplications->isNotEmpty()) {
            $applicantNames = $invalidApplications
                ->take(4)
                ->map(fn (ScholarshipApplication $application) => $application->applicant?->name ?: "Application #{$application->id}")
                ->implode(', ');
            $message = match ($targetStage) {
                'selected' => 'Only applicants at the final decision stage can be selected.',
                'pass_stage' => 'Only applicants at an active provider stage can be advanced.',
                default => 'Only pre-screening applications with accepted required files can be advanced.',
            };

            throw ValidationException::withMessages([
                'application_ids' => "{$message} Review: {$applicantNames}.",
            ]);
        }

        DB::transaction(function () use ($applications, $request, $scholarship, $targetStage): void {
            foreach ($applications as $application) {
                $workflow = $this->workflowService->payload($application);
                $stage = $workflow['current_stage'];

                if ($targetStage === 'selected') {
                    $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'application_ids');
                    $updated = $this->workflowService->recordFinalOutcome(
                        $application,
                        'selected',
                        $request->user(),
                        'Selected through the provider bulk decision list.',
                    );
                    $title = 'Application outcome: Selected';
                    $message = "The provider selected you for {$scholarship->title}. Open the application to review the result.";
                } else {
                    $updated = $this->workflowService->recordStageResult(
                        $application,
                        $stage,
                        'passed',
                        $request->user(),
                        'Passed through the provider bulk review list.',
                    );
                    $nextWorkflow = $this->workflowService->payload($updated);
                    $notification = $this->workflowStageNotification(
                        $updated,
                        $stage,
                        'passed',
                        $nextWorkflow['current_stage_label'],
                    );
                    $title = $notification['title'];
                    $message = $notification['message'];
                }

                app(ScholarshipEventService::class)->syncApplication($updated);

                PortalNotification::create([
                    'user_id' => $updated->applicant_id,
                    'type' => $targetStage === 'selected' ? 'application_outcome' : 'application_status',
                    'title' => $title,
                    'message' => $message,
                    'action_url' => route('dashboard.applications.show', $updated, false),
                ]);
                app(DecisionSupportService::class)->syncApplication($updated, 'provider_bulk_stage_result');
            }
        });

        ActivityLog::record(
            $request->user(),
            'applications_bulk_advanced',
            "{$request->user()->name} completed {$targetStage} for {$applications->count()} application(s).",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'target_stage' => $targetStage,
                'application_ids' => $applicationIds->all(),
            ],
        );

        $message = match ($targetStage) {
            'selected' => "Recorded {$applications->count()} selected recipient(s).",
            'pass_stage' => "Advanced {$applications->count()} applicant(s) to the next configured stage.",
            default => "Passed pre-screening for {$applications->count()} applicant(s).",
        };

        return response()->json([
            'message' => $message,
            'updated_count' => $applications->count(),
            'application_ids' => $applicationIds,
        ]);
    }

    public function assignApplicationReviewer(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $providerId = $request->user()->providerOrganizationId();

        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'assigned_reviewer_id' => ['nullable', 'integer'],
        ]);
        $reviewerId = $validated['assigned_reviewer_id'] ?? null;

        if ($reviewerId && (int) $reviewerId === (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'You cannot assign an application review to yourself.',
            ]);
        }

        $requestedReviewer = $reviewerId
            ? User::query()
                ->where('role', 'provider')
                ->where(function ($query) use ($providerId): void {
                    $query->whereKey($providerId)
                        ->orWhere('parent_account_id', $providerId);
                })
                ->find($reviewerId)
            : null;

        if ($requestedReviewer && $this->providerReviewerHasBroaderAccess($request->user(), $requestedReviewer)) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'You cannot assign a reviewer with broader permissions or program access than your own.',
            ]);
        }

        $reviewer = $reviewerId
            ? $this->providerApplicationReviewers($providerId, $request->user())->firstWhere('id', $reviewerId)
            : null;

        if ($reviewerId && ! $reviewer) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'Choose an active reviewer from this provider organization.',
            ]);
        }

        if ($reviewer && ! $reviewer->canAccessProviderProgram($application->scholarship)) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'This reviewer is not assigned to this program.',
            ]);
        }

        $previousReviewerId = $application->assigned_reviewer_id;
        $application->forceFill(['assigned_reviewer_id' => $reviewer?->id])->save();

        if ((int) $previousReviewerId !== (int) ($reviewer?->id)) {
            ActivityLog::record(
                $request->user(),
                'application_reviewer_assigned',
                $reviewer
                    ? "{$request->user()->name} assigned application #{$application->id} to {$reviewer->name}."
                    : "{$request->user()->name} removed the reviewer from application #{$application->id}.",
                $request,
                [
                    'application_id' => $application->id,
                    'previous_reviewer_id' => $previousReviewerId,
                    'assigned_reviewer_id' => $reviewer?->id,
                ],
            );

            if ($reviewer && $reviewer->id !== $request->user()->id) {
                PortalNotification::create([
                    'user_id' => $reviewer->id,
                    'type' => 'application_assignment',
                    'title' => 'Application assigned to you',
                    'message' => "Review {$application->applicant?->name}'s application for {$application->scholarship?->title}.",
                    'action_url' => route('provider.applications.show', $application, false),
                ]);
            }
        }

        $application = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'assignedReviewer.providerProfile',
            'statusHistories.actor',
            'scholarship' => fn ($query) => $query->withCount($this->providerProgramCountRelations()),
        ]);

        return response()->json([
            'message' => $reviewer ? 'Reviewer assigned.' : 'Application returned to the unassigned queue.',
            'application' => $this->applicationPayload($application),
        ]);
    }

    public function upsertApplicationSchedule(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'venue' => [
                Rule::requiredIf(in_array($request->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'online_url' => [
                Rule::requiredIf(
                    in_array($request->input('mode'), ['online', 'hybrid'], true)
                ),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'instructions' => ['required', 'string', 'max:3000'],
        ]);

        $application->loadMissing(['scholarship.events', 'applicant']);
        $this->ensureScheduleCanBePublished($application, $validated['type']);

        $eventLabel = $this->scheduleTypeLabel($validated['type']);
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at']);
        $scheduleData = [
            'title' => filled($validated['title'] ?? null) ? trim($validated['title']) : "{$eventLabel} schedule",
            'scheduled_at' => $scheduledAt,
            'mode' => $validated['mode'],
            'venue' => $validated['venue'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'online_url' => $validated['online_url'] ?? null,
            'instructions' => $validated['instructions'],
            'status' => 'scheduled',
            'attendance_status' => 'not_required',
            'attendance_notes' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'updated_by' => $request->user()->id,
        ];

        [$schedule, $announcementChanged] = DB::transaction(function () use (
            $application,
            $request,
            $validated,
            $scheduleData,
        ): array {
            $lockedApplication = ScholarshipApplication::query()
                ->with(['scholarship', 'stageProgresses'])
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureScheduleCanBePublished($lockedApplication, $validated['type']);
            $schedule = $lockedApplication->schedules()->where('type', $validated['type'])->first();

            if ($schedule) {
                $schedule->update($scheduleData);
            } else {
                $schedule = $lockedApplication->schedules()->create([
                    ...$scheduleData,
                    'type' => $validated['type'],
                    'created_by' => $request->user()->id,
                ]);
            }

            $announcementChanged = $schedule->wasRecentlyCreated || $schedule->wasChanged([
                'title',
                'scheduled_at',
                'mode',
                'venue',
                'location_address',
                'latitude',
                'longitude',
                'online_url',
                'instructions',
                'status',
            ]);

            return [$schedule, $announcementChanged];
        });

        ActivityLog::record(
            $request->user(),
            'application_schedule_published',
            "{$request->user()->name} published the {$eventLabel} schedule for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'schedule_type' => $schedule->type,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ],
        );

        if ($announcementChanged) {
            $destination = $schedule->mode === 'online'
                ? ' online'
                : ' at '.($schedule->venue ?: $schedule->location_address ?: 'the provider location');

            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => "{$eventLabel} schedule posted",
                'message' => "Your {$eventLabel} for {$application->scholarship?->title} is scheduled for {$scheduledAt->format('M d, Y h:i A')}{$destination}. Open the application to review the schedule details.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_published');

        return response()->json([
            'message' => "{$eventLabel} schedule published and the applicant was notified.",
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function updateApplicationScheduleTracking(
        Request $request,
        ScholarshipApplication $application,
        ApplicationSchedule $schedule,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless($schedule->scholarship_application_id === $application->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'attendance_status' => ['nullable', Rule::in(['pending', 'not_required'])],
            'attendance_notes' => ['nullable', 'string', 'max:1500'],
        ]);

        if ($validated['status'] === 'completed' && $schedule->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'status' => 'This activity cannot be marked complete before its scheduled date and time.',
            ]);
        }

        $previousScheduleStatus = $schedule->status;
        $schedule->update([
            'status' => $validated['status'],
            'attendance_status' => 'not_required',
            'attendance_notes' => $validated['attendance_notes'] ?? null,
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
            'cancelled_at' => $validated['status'] === 'cancelled' ? now() : null,
            'updated_by' => $request->user()->id,
        ]);
        $trackingChanged = $previousScheduleStatus !== $schedule->status
            || $schedule->wasChanged('attendance_notes');

        ActivityLog::record(
            $request->user(),
            'application_schedule_tracking_updated',
            "{$request->user()->name} updated {$schedule->type} tracking for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'status' => $schedule->status,
                'application_stage_unchanged' => true,
            ],
        );

        if ($trackingChanged) {
            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => $this->scheduleTypeLabel($schedule->type).' schedule updated',
                'message' => "The provider updated your {$this->scheduleTypeLabel($schedule->type)} schedule to {$schedule->status}. Open the application to review the details.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);

        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_tracking_updated');

        return response()->json([
            'message' => 'Schedule record updated. The applicant stage was not changed.',
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function viewApplicantProfileProof(
        Request $request,
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless($document->applicant_id === $application->applicant_id, 403);
        abort_unless(in_array($document->document_type, ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES, true), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewApplicantProfilePhoto(Request $request, ScholarshipApplication $application)
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $profile = $application->applicant?->studentProfile;
        abort_unless($profile?->profile_photo_path, 404);
        abort_unless(Storage::disk('local')->exists($profile->profile_photo_path), 404);

        return Storage::disk('local')->response(
            $profile->profile_photo_path,
            $profile->profile_photo_original_name ?: 'applicant-photo',
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function verifyApplicantProfile(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $applicant = $application->applicant;
        abort_unless($applicant?->isApplicant(), 404);

        $reviewedScale = $request->input('academic_grading_scale');
        $reviewedResultIsNumeric = AcademicRequirement::requiresNumeric($reviewedScale);
        $validated = $request->validate([
            'academic_grading_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'academic_result' => [
                Rule::requiredIf($reviewedResultIsNumeric),
                'nullable',
                'numeric',
                'gt:0',
                $reviewedScale === AcademicRequirement::SCALE_GRADE_POINT ? 'max:5' : 'max:100',
            ],
        ]);
        $reviewedAcademicResult = filled($validated['academic_grading_scale'] ?? null)
            ? [
                'grading_scale' => $validated['academic_grading_scale'],
                'gwa' => $reviewedResultIsNumeric ? (float) $validated['academic_result'] : null,
            ]
            : null;

        $verificationStatus = $applicant->applicantAcademicVerificationStatus();

        if ($verificationStatus === 'approved') {
            return response()->json([
                'message' => 'The applicant academic record is already verified.',
                'application' => $this->freshApplicationPayload($application),
            ]);
        }

        if ($verificationStatus === 'rejected') {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must replace the rejected academic record before it can be verified.',
            ]);
        }

        if ($verificationStatus === 'pending' && filled($applicant->studentProfile?->verification_notes)) {
            throw ValidationException::withMessages([
                'verification' => 'An administrator reopened this verification. Wait for a replacement academic record or an admin decision before verifying it again.',
            ]);
        }

        if (! $applicant->applicantVerificationDocuments()
            ->where('document_type', 'academic_record')
            ->exists()) {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must upload an academic record before it can be verified.',
            ]);
        }

        if (filled($applicant->studentProfile?->achievements)
            && ! $applicant->applicantVerificationDocuments()->where('document_type', 'achievement_evidence')->exists()) {
            throw ValidationException::withMessages([
                'verification' => 'The applicant must upload evidence for the listed achievement before the profile can be verified.',
            ]);
        }

        if ($this->academicRecordOcrService->configured()) {
            $academicRecord = $applicant->applicantVerificationDocuments
                ->firstWhere('document_type', 'academic_record');

            if ($academicRecord?->ocr_status !== AcademicRecordOcrService::STATUS_SUCCEEDED && $reviewedAcademicResult === null) {
                throw ValidationException::withMessages([
                    'verification' => 'The scan did not produce a usable result. Enter the verified academic result from the uploaded record before approving it.',
                ]);
            }
        }

        $academicRecord ??= $applicant->applicantVerificationDocuments
            ->firstWhere('document_type', 'academic_record');
        $previousAcademicResult = [
            'grading_scale' => $applicant->studentProfile?->grading_scale,
            'gwa' => $applicant->studentProfile?->gwa !== null
                ? (float) $applicant->studentProfile->gwa
                : null,
        ];
        $academicResultCorrected = $reviewedAcademicResult !== null
            && (
                $reviewedAcademicResult['grading_scale'] !== $previousAcademicResult['grading_scale']
                || $reviewedAcademicResult['gwa'] !== $previousAcademicResult['gwa']
                || $academicRecord?->ocr_status !== AcademicRecordOcrService::STATUS_SUCCEEDED
            );

        DB::transaction(function () use ($applicant, $request, $reviewedAcademicResult, $academicResultCorrected): void {
            $profileUpdates = [
                'verification_status' => 'approved',
                'verification_notes' => null,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ];

            if ($academicResultCorrected) {
                $profileUpdates = array_merge($profileUpdates, $reviewedAcademicResult, [
                    'academic_result_source' => 'provider_review',
                    'academic_result_extracted_at' => now(),
                ]);
            }

            $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], $profileUpdates);

            $applicant->applicantVerificationDocuments()
                ->whereIn('document_type', ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES)
                ->update([
                    'status' => 'approved',
                    'review_notes' => null,
                ]);
        });

        $providerOwner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $providerName = $providerOwner->providerProfile?->provider_name ?: $request->user()->name;

        ActivityLog::record(
            $request->user(),
            'applicant_profile_verified_by_provider',
            "{$request->user()->name} verified applicant {$applicant->name}'s academic record for {$application->scholarship?->title}.",
            $request,
            [
                'applicant_id' => $applicant->id,
                'application_id' => $application->id,
                'scholarship_id' => $application->scholarship_id,
                'provider_id' => $request->user()->providerOrganizationId(),
                'academic_result_corrected' => $academicResultCorrected,
                'previous_academic_result' => $previousAcademicResult,
                'verified_academic_result' => $academicResultCorrected ? $reviewedAcademicResult : null,
            ],
        );

        if ($academicResultCorrected) {
            ScholarshipApplication::query()
                ->where('applicant_id', $applicant->id)
                ->where(fn ($query) => $query
                    ->whereNull('application_state')
                    ->orWhereNotIn('application_state', ['closed', 'withdrawn']))
                ->get()
                ->each(fn (ScholarshipApplication $activeApplication) => app(DecisionSupportService::class)
                    ->syncApplication($activeApplication, 'provider_academic_result_corrected'));
        }

        PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'applicant_profile_verification',
            'title' => 'Academic record verified',
            'message' => "{$providerName} verified your academic record while reviewing your application for {$application->scholarship?->title}.",
            'action_url' => '/dashboard/profile',
        ]);

        return response()->json([
            'message' => 'Applicant academic record verified. Application approval remains a separate review decision.',
            'application' => $this->freshApplicationPayload($application),
        ]);
    }

    public function handleApplicationCorrection(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['request', 'resolve'])],
            'message' => [Rule::requiredIf($request->input('action') === 'request'), 'nullable', 'string', 'min:5', 'max:1500'],
            'targets' => ['sometimes', 'array', 'min:1', 'max:5'],
            'targets.*' => ['required', 'string', 'distinct', Rule::in(ApplicationWorkflowService::CORRECTION_TARGETS)],
        ]);
        $isRequest = $validated['action'] === 'request';
        $application = $isRequest
            ? $this->workflowService->requestCorrection(
                $application,
                $request->user(),
                $validated['message'],
                $validated['targets'] ?? ['other'],
            )
            : $this->workflowService->resolveCorrection($application);

        $application->loadMissing(['applicant', 'scholarship']);
        $targetLabels = collect($application->correction_targets ?? [])
            ->map(fn (string $target): string => $this->correctionTargetLabel($target))
            ->implode(', ');
        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'application_correction',
            'title' => $isRequest ? 'Application correction requested' : 'Application correction accepted',
            'message' => $isRequest
                ? "The provider requested an update for your {$application->scholarship->title} application".($targetLabels ? ": {$targetLabels}." : '.')
                : "The provider accepted your correction for {$application->scholarship->title}. You can continue from the current application stage.",
            'action_url' => route('dashboard.applications.show', $application, false),
        ]);
        ActivityLog::record(
            $request->user(),
            $isRequest ? 'application_correction_requested' : 'application_correction_resolved',
            $isRequest
                ? "{$request->user()->name} requested a correction for application #{$application->id}."
                : "{$request->user()->name} resolved the correction for application #{$application->id}.",
            $request,
            ['application_id' => $application->id],
        );

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship.events',
        ]);

        return response()->json([
            'message' => $isRequest ? 'Correction request sent to the applicant.' : 'Correction marked as resolved.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function handleApplicationWaitlist(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['waitlist', 'promote', 'restore'])],
            'note' => ['nullable', 'string', 'max:1500'],
        ]);

        $workflow = $this->workflowService->payload($application);

        if ($validated['action'] === 'waitlist' && $workflow['current_stage'] !== 'decision') {
            throw ValidationException::withMessages([
                'action' => 'Complete the configured provider stages before placing an applicant on the waitlist.',
            ]);
        }

        if (in_array($validated['action'], ['promote', 'restore'], true) && $workflow['final_outcome'] !== 'waitlisted') {
            throw ValidationException::withMessages([
                'action' => 'Only a waitlisted applicant can use this action.',
            ]);
        }

        if ($validated['action'] === 'restore') {
            $updated = $this->workflowService->restoreWaitlistedForDecision(
                $application,
                $request->user(),
                $validated['note'] ?? null,
            );
            $message = 'Applicant returned to final decision review.';
        } else {
            if ($validated['action'] === 'promote') {
                $this->ensureScholarshipAwardSlotAvailable($application, $application->status, 'awarded', 'action');
            }

            $updated = $this->workflowService->recordFinalOutcome(
                $application,
                $validated['action'] === 'promote' ? 'selected' : 'waitlisted',
                $request->user(),
                $validated['note'] ?? null,
            );
            $message = $validated['action'] === 'promote'
                ? 'Waitlisted applicant marked as selected.'
                : 'Applicant added to the waitlist.';
        }

        PortalNotification::create([
            'user_id' => $updated->applicant_id,
            'type' => 'application_outcome',
            'title' => 'Application outcome updated',
            'message' => $message,
            'action_url' => route('dashboard.applications.show', $updated, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'application_waitlist_updated',
            "{$request->user()->name} completed {$validated['action']} for application #{$updated->id}.",
            $request,
            [
                'application_id' => $updated->id,
                'action' => $validated['action'],
                'final_outcome' => $updated->final_outcome,
            ],
        );
        app(DecisionSupportService::class)->syncApplication($updated, 'provider_waitlist_updated');

        return response()->json([
            'message' => $message,
            'application' => $this->applicationPayload($updated->fresh(), true),
        ]);
    }

    public function updateApplicationStatus(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);

        $outcomeStatuses = ['awarded', 'not_awarded', 'disbursed', 'renewed'];
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'submitted',
                'under_review',
                'qualified',
                'shortlisted',
                'interview',
                'exam_qualified',
                'exam_scheduled',
                'exam_taken',
                'exam_passed',
                'exam_failed',
                'interview_failed',
                'approved',
                'waitlisted',
                'awarded',
                'distribution_scheduled',
                'not_awarded',
                'disbursed',
                'renewed',
                'benefits_terminated',
                'rejected',
            ])],
            'decision_reason' => [
                Rule::requiredIf(ApplicationDecisionReason::requiredForStatus($request->input('status'))),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'outcome_notes' => [
                Rule::requiredIf($request->input('status') === 'benefits_terminated'),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'outcome_at' => ['nullable', 'date'],
            'distribution_scheduled_for' => $request->input('status') === 'distribution_scheduled'
                ? ['required', 'date', 'after_or_equal:today']
                : ['nullable', 'date'],
            'distribution_instructions' => ['nullable', 'string', 'max:2000'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $workflow = $this->workflowService->payload($application);
        $application->refresh();
        $previousStatus = $application->status;
        $isPostSelectionUpdate = $workflow['final_outcome'] === 'selected'
            && match ($previousStatus) {
                'awarded' => in_array($validated['status'], ['distribution_scheduled', 'benefits_terminated'], true),
                'distribution_scheduled' => in_array($validated['status'], ['disbursed', 'benefits_terminated'], true),
                'disbursed' => in_array($validated['status'], ['renewed', 'benefits_terminated'], true),
                'renewed' => $validated['status'] === 'benefits_terminated',
                default => false,
            };

        if ($previousStatus !== $validated['status'] && ! $isPostSelectionUpdate) {
            throw ValidationException::withMessages([
                'status' => 'Use the current stage result or final outcome action so configured stages cannot be skipped.',
            ]);
        }

        $isOutcomeStatus = in_array($validated['status'], $outcomeStatuses, true);

        $requiredRubricResult = $request->attributes->get('provider_decision_validated', false)
            || array_key_exists('rubric_scores', $validated)
                ? $this->requireCompleteApplicationRubric($application, $validated['rubric_scores'] ?? null)
                : null;

        $decisionReason = array_key_exists('decision_reason', $validated)
            ? $validated['decision_reason']
            : $application->decision_reason;
        $reviewNotes = array_key_exists('review_notes', $validated)
            ? $validated['review_notes']
            : $application->review_notes;
        $outcomeNotes = array_key_exists('outcome_notes', $validated)
            ? $validated['outcome_notes']
            : $application->outcome_notes;
        $distributionScheduledFor = array_key_exists('distribution_scheduled_for', $validated)
            ? $validated['distribution_scheduled_for']
            : $application->distribution_scheduled_for?->toDateString();
        $distributionInstructions = array_key_exists('distribution_instructions', $validated)
            ? $validated['distribution_instructions']
            : $application->distribution_instructions;
        $waitlistPosition = $validated['status'] === 'waitlisted'
            ? ($application->waitlist_position ?: ((int) ScholarshipApplication::query()
                ->where('scholarship_id', $application->scholarship_id)
                ->where('status', 'waitlisted')
                ->max('waitlist_position') + 1))
            : null;

        if ($validated['status'] === 'distribution_scheduled'
            && ! in_array($previousStatus, ['awarded', 'distribution_scheduled'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Record the applicant as an award recipient before scheduling distribution.',
            ]);
        }

        if ($validated['status'] === 'disbursed') {
            if ($previousStatus !== 'distribution_scheduled' || blank($distributionScheduledFor)) {
                throw ValidationException::withMessages([
                    'status' => 'Schedule reward distribution before marking it as distributed.',
                ]);
            }

            if ($distributionScheduledFor > now()->toDateString()) {
                throw ValidationException::withMessages([
                    'status' => 'Reward distribution cannot be marked complete before its scheduled date.',
                ]);
            }
        }

        $outcomeAt = array_key_exists('outcome_at', $validated)
            ? $validated['outcome_at']
            : ($isOutcomeStatus && $previousStatus !== $validated['status'] ? now() : $application->outcome_at);
        $rubricResult = $requiredRubricResult;
        $applicantFacingChanged = $previousStatus !== $validated['status']
            || $this->comparableScholarshipValue($application->decision_reason) !== $this->comparableScholarshipValue($decisionReason)
            || $this->comparableScholarshipValue($application->awarded_amount) !== $this->comparableScholarshipValue($validated['awarded_amount'] ?? $application->awarded_amount)
            || $this->comparableScholarshipValue($application->outcome_notes) !== $this->comparableScholarshipValue($outcomeNotes)
            || $this->comparableScholarshipValue($application->outcome_at) !== $this->comparableScholarshipValue($outcomeAt)
            || $this->comparableScholarshipValue($application->distribution_scheduled_for) !== $this->comparableScholarshipValue($distributionScheduledFor)
            || $this->comparableScholarshipValue($application->distribution_instructions) !== $this->comparableScholarshipValue($distributionInstructions);
        $reviewNoteChanged = $this->comparableScholarshipValue($application->review_notes)
            !== $this->comparableScholarshipValue($reviewNotes);

        $applicationUpdate = [
            'status' => $validated['status'],
            'decision_reason' => $decisionReason,
            'review_notes' => $reviewNotes,
            'awarded_amount' => array_key_exists('awarded_amount', $validated) ? $validated['awarded_amount'] : $application->awarded_amount,
            'outcome_notes' => $outcomeNotes,
            'outcome_at' => $outcomeAt,
            'distribution_scheduled_for' => $distributionScheduledFor,
            'distribution_instructions' => $distributionInstructions,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rubric_scores' => $rubricResult ? $rubricResult['scores'] : $application->rubric_scores,
            'rubric_total_score' => $rubricResult ? $rubricResult['total_score'] : $application->rubric_total_score,
            'rubric_scored_by' => $rubricResult ? $request->user()->id : $application->rubric_scored_by,
            'rubric_scored_at' => $rubricResult && $rubricResult['completed'] > 0 ? now() : $application->rubric_scored_at,
            'waitlist_position' => $waitlistPosition,
            'waitlisted_at' => $validated['status'] === 'waitlisted'
                ? ($application->waitlisted_at ?? now())
                : null,
        ];

        DB::transaction(function () use (
            $application,
            $applicationUpdate,
            $previousStatus,
            $validated,
            $applicantFacingChanged,
            $reviewNoteChanged,
            $request,
        ): void {
            $currentStatus = ScholarshipApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->value('status');

            if ($currentStatus !== $previousStatus) {
                throw ValidationException::withMessages([
                    'status' => 'This application changed during review. Refresh the page before recording another decision.',
                ]);
            }

            $this->ensureScholarshipAwardSlotAvailable(
                $application,
                $previousStatus,
                $validated['status'],
            );
            $application->update($applicationUpdate);

            if ($applicantFacingChanged || $reviewNoteChanged) {
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $previousStatus,
                    'to_status' => $validated['status'],
                    'decision_reason' => $validated['decision_reason'] ?? null,
                    'review_notes' => $validated['status'] === 'benefits_terminated'
                        ? ($validated['outcome_notes'] ?? null)
                        : ($validated['review_notes'] ?? null),
                    'changed_at' => now(),
                ]);
            }
        });

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $application->applicant,
                "application_status_{$validated['status']}",
                $application->scholarship,
                $application,
                'provider',
                [
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'decision_reason' => $decisionReason,
                    'canonical_decision_reason' => ApplicationDecisionReason::canonical($decisionReason),
                    'awarded_amount' => $application->awarded_amount,
                    'reviewed_by' => $request->user()->id,
                    'rubric_total_score' => $application->rubric_total_score,
                ],
            );
        }

        ActivityLog::record(
            $request->user(),
            'application_status_updated',
            "{$request->user()->name} updated application #{$application->id} to {$validated['status']}.",
            $request,
            [
                'application_id' => $application->id,
                'status' => $validated['status'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'distribution_scheduled_for' => $distributionScheduledFor,
                'rubric_total_score' => $rubricResult['total_score'] ?? null,
            ],
        );

        if ($applicantFacingChanged) {
            PortalNotification::create(array_merge(
                ['user_id' => $application->applicant_id],
                $this->applicationStatusNotificationPayload($application, $validated['status'], $decisionReason, $isOutcomeStatus),
            ));
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship.events',
        ]);
        app(ScholarshipEventService::class)->syncApplication($freshApplication);
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_status_updated');

        return response()->json([
            'message' => $applicantFacingChanged ? 'Application status updated.' : 'Provider review saved.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    private function ensureScholarshipAwardSlotAvailable(
        ScholarshipApplication $application,
        string $previousStatus,
        string $nextStatus,
        string $errorKey = 'status',
    ): void {
        if (! in_array($nextStatus, self::AWARD_SLOT_STATUSES, true)
            || in_array($previousStatus, self::AWARD_SLOT_STATUSES, true)) {
            return;
        }

        $scholarship = Scholarship::query()
            ->whereKey($application->scholarship_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($scholarship->slots_available === null) {
            return;
        }

        $occupiedSlots = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('id', '!=', $application->id)
            ->whereIn('status', self::AWARD_SLOT_STATUSES)
            ->count();

        if ($occupiedSlots >= $scholarship->slots_available) {
            throw ValidationException::withMessages([
                $errorKey => 'All available award slots have already been filled. Increase the program slots or review an existing award before recording another recipient.',
            ]);
        }
    }

    private function ensureApplicationStatusTransition(
        ScholarshipApplication $application,
        string $nextStatus,
    ): void {
        $currentStatus = $application->status;
        $selectionStages = ScholarshipSelectionPlan::normalize($application->scholarship?->selection_stages);
        $approvalStatus = ScholarshipSelectionPlan::nextApprovalStatus($currentStatus, $selectionStages);
        $rejectionStatus = ScholarshipSelectionPlan::rejectionStatus($currentStatus);
        $allowed = match ($currentStatus) {
            'submitted' => array_filter(['under_review', $approvalStatus, $rejectionStatus]),
            'under_review' => array_filter(['qualified', 'shortlisted', $approvalStatus, $rejectionStatus]),
            'qualified' => array_filter(['shortlisted', $approvalStatus, $rejectionStatus]),
            'shortlisted' => array_filter([$approvalStatus, $rejectionStatus]),
            'exam_qualified' => ['exam_scheduled', 'exam_failed'],
            'exam_scheduled' => ['exam_taken', 'exam_failed'],
            'exam_taken' => array_filter(['exam_passed', $approvalStatus, $rejectionStatus]),
            'exam_passed' => array_filter([$approvalStatus, $rejectionStatus]),
            'interview' => ['approved', 'interview_failed'],
            'approved' => ['waitlisted', 'awarded', 'not_awarded'],
            'waitlisted' => ['approved', 'awarded', 'not_awarded'],
            'awarded' => ['distribution_scheduled', 'benefits_terminated'],
            'distribution_scheduled' => ['disbursed', 'benefits_terminated'],
            'disbursed' => ['renewed', 'benefits_terminated'],
            'renewed' => ['benefits_terminated'],
            default => [],
        };

        if (! in_array($nextStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "The application cannot move from {$this->statusLabel($currentStatus)} to {$this->statusLabel($nextStatus)}.",
            ]);
        }
    }

    private function ensureApplicationDocumentsReadyForStatus(
        ScholarshipApplication $application,
        string $nextStatus,
    ): void {
        $statusesRequiringAcceptedDocuments = [
            'qualified',
            'shortlisted',
            'exam_qualified',
            'exam_scheduled',
            'exam_taken',
            'exam_passed',
            'interview',
            'approved',
            'waitlisted',
            'awarded',
            'distribution_scheduled',
            'disbursed',
            'renewed',
        ];

        if (! in_array($nextStatus, $statusesRequiringAcceptedDocuments, true)) {
            return;
        }

        $readiness = app(ScholarshipEligibilityService::class)
            ->applicationDocumentReadiness($application);

        if ($readiness['ready']) {
            return;
        }

        $message = match (true) {
            $readiness['missing'] !== [] => 'The applicant must upload every required file before this application can advance.',
            $readiness['needs_attention'] !== [] => 'Resolve every rejected or replacement document before this application can advance.',
            default => 'Review and accept every required document before this application can advance.',
        };

        throw ValidationException::withMessages([
            'status' => $message,
        ]);
    }

    public function updateDocumentStatus(Request $request, ApplicationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $document->load('application.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($document->application?->scholarship), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'accepted', 'rejected', 'needs_replacement'])],
            'review_notes' => [Rule::requiredIf(in_array($request->input('status'), ['rejected', 'needs_replacement'], true)), 'nullable', 'string', 'max:1000'],
        ]);

        [$document, $previousStatus] = DB::transaction(function () use ($document, $validated, $request): array {
            $lockedApplication = ScholarshipApplication::query()
                ->with(['applicant', 'scholarship', 'stageProgresses'])
                ->whereKey($document->scholarship_application_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->workflowService->payload($lockedApplication)['is_closed']) {
                throw ValidationException::withMessages([
                    'status' => 'Document decisions are locked after the application is closed.',
                ]);
            }

            $lockedDocument = ApplicationDocument::query()
                ->whereKey($document->id)
                ->where('scholarship_application_id', $lockedApplication->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $lockedDocument->status;
            $lockedDocument->update([
                'status' => $validated['status'],
                'review_notes' => $validated['review_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $lockedDocument->setRelation('application', $lockedApplication);

            return [$lockedDocument, $previousStatus];
        });

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $document->application->applicant,
                "application_document_{$validated['status']}",
                $document->application->scholarship,
                $document->application,
                'provider',
                [
                    'document_id' => $document->id,
                    'document_name' => $document->document_name,
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'reviewed_by' => $request->user()->id,
                ],
            );
        }

        ActivityLog::record(
            $request->user(),
            'document_status_updated',
            "{$request->user()->name} marked {$document->document_name} as {$validated['status']} for application #{$document->application?->id}.",
            $request,
            [
                'application_id' => $document->application?->id,
                'document_id' => $document->id,
                'document_status' => $validated['status'],
            ],
        );

        $documentMessage = "{$document->document_name} was marked {$this->statusLabel($validated['status'])}.";

        if (in_array($validated['status'], ['rejected', 'needs_replacement'], true)) {
            $documentMessage .= " Reason: {$validated['review_notes']}";
        }

        PortalNotification::create([
            'user_id' => $document->application->applicant_id,
            'type' => 'document_review',
            'title' => 'Document review updated',
            'message' => $documentMessage,
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $document->application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_document_reviewed');

        return response()->json([
            'message' => 'Document status updated.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user();
        $providerId = $provider->providerOrganizationId();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filename = $selectedScholarship
            ? "provider-applications-program-{$selectedScholarship->id}.xlsx"
            : 'provider-applications.xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'provider-applications-');

        abort_if($tempPath === false, 500, 'Unable to prepare the applicant export.');

        $headers = [
            'Application ID',
            'Scholarship',
            'Applicant',
            'Email',
            'Contact Number',
            'Current Stage',
            'Final Outcome',
            'Match Score',
            'Match Recommendation',
            'Document Readiness %',
            'Documents Requiring Action',
            'Missing Required Documents',
            'Decision Reason',
            'Assigned Reviewer',
            'Waitlist Position',
            'Submitted At',
            'Review Notes',
        ];
        $query = $this->providerApplicationsQuery($provider)
            ->with(['applicant.studentProfile', 'documents', 'assignedReviewer', 'reviewer', 'scholarship']);

        if ($selectedScholarship) {
            $query->where('scholarship_id', $selectedScholarship->id);
        }

        $owner = $provider->providerOrganizationOwner();
        $owner->loadMissing('providerProfile');
        $providerName = $owner->providerProfile?->provider_name ?: $owner->name;
        $scope = $selectedScholarship?->title ?: 'All scholarship programs';
        $subtitle = "Provider: {$providerName} | Scope: {$scope} | Exported: ".now()->format('M d, Y h:i A');

        try {
            XlsxExport::create(
                $tempPath,
                'Applicant Review Report',
                $subtitle,
                $headers,
                $query->orderBy('id')->lazyById(200)->map(
                    fn (ScholarshipApplication $application): array => $this->providerApplicationExportRow($application),
                ),
            );
        } catch (Throwable $error) {
            @unlink($tempPath);
            throw $error;
        }

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function providerApplicationExportRow(ScholarshipApplication $application): array
    {
        $application->loadMissing(['applicant.studentProfile', 'documents', 'assignedReviewer', 'reviewer']);
        $dss = app(DecisionSupportService::class)->syncApplication($application, 'provider_export');
        $workflow = $this->workflowService->payload($application);
        $readiness = $this->documentReadiness($application);
        $documents = $application->documents->sortBy('id');
        $documentsRequiringAction = $documents
            ->filter(fn (ApplicationDocument $document): bool => $document->status !== 'accepted')
            ->map(fn (ApplicationDocument $document): string => "{$document->document_name} ({$this->documentStatusLabel($document->status)})")
            ->values()
            ->implode('; ');

        return [
            $application->id,
            $application->scholarship?->title,
            $application->applicant?->name,
            $application->applicant?->email,
            $application->applicant?->contact_number,
            $workflow['current_stage_label'] ?? null,
            $workflow['final_outcome_label'] ?? 'Not decided',
            $dss['score'] ?? $application->dss_score,
            $dss['label'] ?? Str::headline((string) $application->dss_recommendation),
            $readiness['accepted_percent'] ?? 0,
            $documentsRequiringAction,
            implode('; ', $readiness['missing'] ?? []),
            ApplicationDecisionReason::label($application->decision_reason),
            $application->assignedReviewer?->name ?? $application->reviewer?->name,
            $application->waitlist_position,
            $application->submitted_at?->format('Y-m-d H:i:s'),
            $application->review_notes,
        ];
    }

    private function documentStatusLabel(?string $status): string
    {
        return match ($status) {
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'needs_replacement' => 'Needs replacement',
            default => 'Pending review',
        };
    }

    public function scholarships(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->with('events')
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();

        return response()->json([
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function storeScholarshipAnnouncement(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'audience' => ['required', Rule::in([
                'active_applicants',
                'under_review',
                'qualified_applicants',
                'selected_recipients',
            ])],
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $recipientQuery = $scholarship->applications()
            ->with('applicant')
            ->whereHas('applicant', fn ($query) => $query
                ->where('role', 'applicant')
                ->where('account_status', 'active'));

        match ($validated['audience']) {
            'active_applicants' => $recipientQuery->whereNotIn('status', [
                'withdrawn',
                'rejected',
                'not_awarded',
                'exam_failed',
                'interview_failed',
                'disbursed',
                'renewed',
                'benefits_terminated',
            ]),
            'under_review' => $recipientQuery->whereIn('status', [
                'submitted',
                'under_review',
                'qualified',
                'shortlisted',
                'exam_qualified',
                'exam_scheduled',
                'exam_taken',
                'exam_passed',
                'interview',
            ]),
            'qualified_applicants' => $recipientQuery->whereIn('status', ['approved', 'waitlisted']),
            'selected_recipients' => $recipientQuery->whereIn('status', self::AWARD_SLOT_STATUSES),
        };

        $recipients = $recipientQuery->get()->unique('applicant_id')->values();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'audience' => 'No applicants currently match this audience.',
            ]);
        }

        $announcement = DB::transaction(function () use ($request, $scholarship, $validated, $recipients): ScholarshipAnnouncement {
            $announcement = $scholarship->announcements()->create([
                ...$validated,
                'recipient_count' => $recipients->count(),
                'published_by' => $request->user()->id,
                'published_at' => now(),
            ]);

            foreach ($recipients as $application) {
                PortalNotification::create([
                    'user_id' => $application->applicant_id,
                    'type' => 'program_announcement',
                    'title' => $validated['title'],
                    'message' => "{$scholarship->title}: {$validated['message']}",
                    'action_url' => route('dashboard.applications.show', $application, false),
                    'deduplication_key' => "program-announcement:{$announcement->id}:user:{$application->applicant_id}",
                ]);
            }

            return $announcement;
        });

        ActivityLog::record(
            $request->user(),
            'program_announcement_published',
            "{$request->user()->name} published an announcement for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'announcement_id' => $announcement->id,
                'audience' => $validated['audience'],
                'recipient_count' => $recipients->count(),
            ],
        );

        return response()->json([
            'message' => "Announcement sent to {$recipients->count()} applicant".($recipients->count() === 1 ? '.' : 's.'),
            'announcement' => $this->scholarshipAnnouncementPayload($announcement->load('publisher')),
        ], 201);
    }

    public function showScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $scholarship->load(['announcements.publisher', 'events']);
        $providerOwner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $canAccessApplicantWorkflow = $request->user()->hasPortalPermission('review_applications')
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified();
        $applicationsBase = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id);
        $workflowCounts = $canAccessApplicantWorkflow
            ? $this->providerApplicationFilterCounts($applicationsBase)
            : [];
        $activityStatuses = collect();

        if ($canAccessApplicantWorkflow) {
            $stageCounts = (clone $applicationsBase)
                ->selectRaw("COALESCE(workflow_stage, 'screening') as workflow_stage, count(*) as total")
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->groupByRaw("COALESCE(workflow_stage, 'screening')")
                ->pluck('total', 'workflow_stage');
            $activityWaitingCounts = $this->providerActivityWaitingCounts($applicationsBase);
            $activityStatuses = collect(ScholarshipSelectionPlan::normalize($scholarship->selection_stages))
                ->filter(fn (string $stage): bool => ScholarshipSelectionPlan::isSchedulable($stage))
                ->map(function (string $stage) use ($scholarship, $stageCounts, $activityWaitingCounts): array {
                    $event = $scholarship->events->firstWhere('type', $stage);

                    return [
                        'type' => $stage,
                        'label' => ScholarshipSelectionPlan::label($stage),
                        'active_applicants' => (int) ($stageCounts[$stage] ?? 0),
                        'waiting_applicants' => (int) ($activityWaitingCounts[$stage] ?? 0),
                        'event' => $event ? ScholarshipEventPayload::make($event) : null,
                    ];
                })
                ->values();
        }

        return response()->json([
            'scholarship' => [
                ...$this->scholarshipPayload(
                    $scholarship->loadCount($this->providerProgramCountRelations()),
                ),
                'announcements' => $scholarship->announcements
                    ->map(fn (ScholarshipAnnouncement $announcement) => $this->scholarshipAnnouncementPayload($announcement))
                    ->values(),
                'workflow_counts' => [
                    'needs_review' => (int) ($workflowCounts['needs_review'] ?? 0),
                    'waiting_activity' => (int) ($workflowCounts['waiting_activity'] ?? 0),
                    'ready_result' => (int) ($workflowCounts['ready_result'] ?? 0),
                    'final_decision' => (int) ($workflowCounts['final_decision'] ?? 0),
                    'all' => (int) ($workflowCounts['all'] ?? 0),
                ],
                'activity_statuses' => $activityStatuses,
            ],
        ]);
    }

    public function recipientMonitoringData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $applications = $this->selectedRecipientApplications($scholarship);
        $cycles = $scholarship->monitoringCycles()
            ->with(['creator', 'submissions.reviewer', 'submissions.reviews.reviewer'])
            ->get();
        $releases = $scholarship->benefitReleases()
            ->with(['creator', 'records.applicant', 'records.recorder'])
            ->get();
        $supportRecipients = $this->supportRecipientApplications($scholarship);
        $cyclePayloads = $cycles
            ->map(fn (RecipientMonitoringCycle $cycle): array => $this->recipientMonitoringCyclePayload($cycle, $applications))
            ->values();
        $releasePayloads = $releases
            ->map(fn (RecipientBenefitRelease $release): array => $this->recipientBenefitReleasePayload($release))
            ->values();
        $supportPayloads = $supportRecipients
            ->map(fn (ScholarshipApplication $application): array => $this->recipientSupportPayload($application, $cycles))
            ->values();

        return response()->json([
            'scholarship' => [
                ...$this->scholarshipPayload($scholarship),
                'selected_recipients_count' => $supportRecipients->count(),
            ],
            'academic_ocr' => $this->academicRecordOcrService->publicConfiguration(),
            'cycles' => $cyclePayloads,
            'release_candidates' => $this->recipientReleaseCandidatesPayload($applications, $cycles, now()),
            'benefit_releases' => $releasePayloads,
            'support_recipients' => $supportPayloads,
            'program_summary' => $this->recipientProgramSummaryPayload(
                $cyclePayloads,
                $releasePayloads,
                $supportPayloads,
            ),
        ]);
    }

    public function storeRecipientMonitoringCycle(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'period_type' => ['required', Rule::in(['semester', 'quarter', 'monthly', 'custom'])],
            'academic_period' => ['nullable', 'string', 'max:80'],
            'school_year' => ['nullable', 'string', 'max:30'],
            'opens_at' => ['nullable', 'date', 'after_or_equal:today'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'minimum_grade' => ['required', 'numeric'],
            'grading_scale' => ['required', Rule::in([
                AcademicRequirement::SCALE_PERCENTAGE,
                AcademicRequirement::SCALE_GRADE_POINT,
            ])],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['opens_at'] ?? null)
            && CarbonImmutable::parse($validated['due_at'])->isBefore(CarbonImmutable::parse($validated['opens_at']))) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date must be on or after the opening date.',
            ]);
        }

        $minimumGrade = (float) $validated['minimum_grade'];
        $validGrade = $validated['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
            ? $minimumGrade >= 1 && $minimumGrade <= 5
            : $minimumGrade >= 0 && $minimumGrade <= 100;

        if (! $validGrade) {
            throw ValidationException::withMessages([
                'minimum_grade' => $validated['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
                    ? 'Enter a grade point from 1.00 to 5.00.'
                    : 'Enter a percentage from 0 to 100.',
            ]);
        }

        $applications = $this->selectedRecipientApplications($scholarship);

        if ($applications->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'Select at least one scholarship recipient before publishing a monitoring period.',
            ]);
        }

        $cycle = DB::transaction(function () use ($request, $scholarship, $validated, $applications): RecipientMonitoringCycle {
            $cycle = $scholarship->monitoringCycles()->create([
                ...$validated,
                'created_by' => $request->user()->id,
                'status' => 'open',
                'published_at' => now(),
            ]);

            foreach ($applications as $application) {
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-monitoring:{$cycle->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_monitoring_request',
                    'title' => 'Academic progress update requested',
                    'message' => "{$scholarship->title}: upload your {$cycle->title} grade record by {$cycle->due_at->format('M d, Y')}.",
                    'action_url' => route('dashboard.applications.show', $application, false).'?section=monitoring',
                    'read_at' => null,
                ]);
            }

            return $cycle;
        });

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_cycle_published',
            "{$request->user()->name} published {$cycle->title} for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'monitoring_cycle_id' => $cycle->id,
                'recipient_count' => $applications->count(),
            ],
        );

        return response()->json([
            'message' => "Monitoring period published to {$applications->count()} selected recipient".($applications->count() === 1 ? '.' : 's.'),
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->load(['creator', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $applications,
            ),
        ], 201);
    }

    public function reviewRecipientMonitoringSubmission(
        Request $request,
        RecipientMonitoringSubmission $submission,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $submission->loadMissing(['cycle.scholarship', 'application.applicant']);
        $scholarship = $submission->cycle?->scholarship;
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['met', 'not_met', 'needs_correction', 'excused'])],
            'notes' => [
                Rule::requiredIf(in_array($request->input('decision'), ['not_met', 'needs_correction', 'excused'], true)),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
        ]);
        $grade = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grade
            : ($submission->ocr_grade ?? $submission->reported_grade);

        if (in_array($validated['decision'], ['met', 'not_met'], true) && $grade === null) {
            throw ValidationException::withMessages([
                'decision' => 'A readable or applicant-entered grade is required before confirming whether the requirement was met.',
            ]);
        }

        DB::transaction(function () use ($request, $submission, $validated): void {
            $now = now();
            $submission->update([
                'review_status' => $validated['decision'],
                'review_notes' => $validated['notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ]);
            $submission->reviews()->create([
                'reviewed_by' => $request->user()->id,
                'decision' => $validated['decision'],
                'notes' => $validated['notes'] ?? null,
                'decided_at' => $now,
            ]);
        });

        $application = $submission->application;
        $cycle = $submission->cycle;
        $decisionLabels = [
            'met' => 'Academic requirement confirmed',
            'not_met' => 'Academic requirement not met',
            'needs_correction' => 'Replacement grade record requested',
            'excused' => 'Monitoring exception approved',
        ];
        $decisionMessages = [
            'met' => "Your {$cycle->title} grade record was verified and meets the listed requirement.",
            'not_met' => "Your {$cycle->title} grade record was reviewed and does not meet the listed requirement. Open the record for the provider note.",
            'needs_correction' => "The provider requested a replacement or clarification for your {$cycle->title} grade record.",
            'excused' => "The provider approved an exception for your {$cycle->title} monitoring period.",
        ];
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-monitoring-review:{$submission->id}:{$validated['decision']}",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'recipient_monitoring_review',
            'title' => $decisionLabels[$validated['decision']],
            'message' => $decisionMessages[$validated['decision']],
            'action_url' => route('dashboard.applications.show', $application, false).'?section=monitoring',
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_submission_reviewed',
            "{$request->user()->name} recorded {$validated['decision']} for {$application->applicant?->name}'s {$cycle->title} submission.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $application->id,
                'monitoring_cycle_id' => $cycle->id,
                'submission_id' => $submission->id,
                'decision' => $validated['decision'],
            ],
        );

        $applications = $this->selectedRecipientApplications($scholarship);

        return response()->json([
            'message' => $decisionLabels[$validated['decision']].'.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load(['creator', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $applications,
            ),
        ]);
    }

    public function storeRecipientBenefitRelease(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'release_at' => ['required', 'date', 'after_or_equal:today'],
            'benefit_description' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'release_method' => ['required', Rule::in(['in_person', 'bank_transfer', 'e_wallet', 'other'])],
            'location' => [Rule::requiredIf($request->input('release_method') === 'in_person'), 'nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'requires_original_verification' => ['required', 'boolean'],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $releaseAt = CarbonImmutable::parse($validated['release_at']);
        $applications = $this->selectedRecipientApplications($scholarship);
        $selectedApplications = $applications->whereIn('id', $validated['recipient_ids'])->values();

        if ($selectedApplications->count() !== count($validated['recipient_ids'])) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'One or more selected recipients are not available for this program.',
            ]);
        }

        $cycles = $scholarship->monitoringCycles()
            ->with('submissions')
            ->get();
        $blocked = $selectedApplications
            ->map(function (ScholarshipApplication $application) use ($cycles, $releaseAt): ?string {
                $eligibility = $this->recipientBenefitReleaseEligibility($application, $cycles, $releaseAt);

                return $eligibility['eligible']
                    ? null
                    : "{$application->applicant?->name}: {$eligibility['reason']}";
            })
            ->filter()
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'Resolve these recipient requirements first: '.$blocked->implode(' '),
            ]);
        }

        $release = DB::transaction(function () use ($request, $scholarship, $validated, $selectedApplications): RecipientBenefitRelease {
            $release = $scholarship->benefitReleases()->create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'release_at' => $validated['release_at'],
                'benefit_description' => $validated['benefit_description'],
                'amount' => $validated['amount'] ?? null,
                'release_method' => $validated['release_method'],
                'location' => $validated['location'] ?? null,
                'instructions' => $validated['instructions'] ?? null,
                'requires_original_verification' => $validated['requires_original_verification'],
                'status' => 'scheduled',
                'published_at' => now(),
            ]);

            foreach ($selectedApplications as $application) {
                $release->records()->create([
                    'scholarship_application_id' => $application->id,
                    'applicant_id' => $application->applicant_id,
                    'status' => 'scheduled',
                ]);
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-benefit-release:{$release->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_benefit_release',
                    'title' => 'Benefit release scheduled',
                    'message' => "{$scholarship->title}: {$release->title} is scheduled for {$release->release_at->format('M d, Y h:i A')}.",
                    'action_url' => route('dashboard.applications.show', $application, false).'?section=monitoring',
                    'read_at' => null,
                ]);
            }

            return $release;
        });

        ActivityLog::record(
            $request->user(),
            'recipient_benefit_release_scheduled',
            "{$request->user()->name} scheduled {$release->title} for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'benefit_release_id' => $release->id,
                'recipient_count' => $selectedApplications->count(),
            ],
        );

        return response()->json([
            'message' => "Benefit release scheduled for {$selectedApplications->count()} recipient".($selectedApplications->count() === 1 ? '.' : 's.'),
            'release' => $this->recipientBenefitReleasePayload(
                $release->load(['creator', 'records.applicant', 'records.recorder']),
            ),
        ], 201);
    }

    public function recordRecipientBenefitRelease(
        Request $request,
        RecipientBenefitReleaseRecord $record,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $record->loadMissing(['release.scholarship', 'application.applicant']);
        $release = $record->release;
        $scholarship = $release?->scholarship;
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['prepared', 'released', 'missed', 'withheld'])],
            'originals_verified' => ['nullable', 'boolean'],
            'notes' => [
                Rule::requiredIf(in_array($request->input('status'), ['missed', 'withheld'], true)),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
            'receipt_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $status = $validated['status'];
        $originalsVerified = (bool) ($validated['originals_verified'] ?? false);

        if ($status === 'released' && $release->release_at?->isFuture()) {
            throw ValidationException::withMessages([
                'status' => 'The benefit cannot be marked released before its scheduled date and time.',
            ]);
        }

        if ($status === 'released' && $release->requires_original_verification && ! $originalsVerified) {
            throw ValidationException::withMessages([
                'originals_verified' => 'Confirm that the original documents were checked before recording this release.',
            ]);
        }

        $receipt = $request->file('receipt_proof');
        if ($status === 'released'
            && ! $receipt
            && blank($record->receipt_path)
            && blank($validated['notes'] ?? null)) {
            throw ValidationException::withMessages([
                'receipt_proof' => 'Upload acknowledgement proof or enter a note describing how receipt was confirmed.',
            ]);
        }

        $newReceiptPath = $receipt?->store("benefit-release-receipts/{$record->id}", 'local');
        $oldReceiptPath = $record->receipt_path;

        try {
            DB::transaction(function () use ($request, $record, $validated, $status, $originalsVerified, $receipt, $newReceiptPath): void {
                $record->update([
                    'status' => $status,
                    'originals_verified' => $originalsVerified,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_original_name' => $receipt?->getClientOriginalName() ?? $record->receipt_original_name,
                    'receipt_path' => $newReceiptPath ?? $record->receipt_path,
                    'receipt_mime_type' => $receipt?->getMimeType() ?? $record->receipt_mime_type,
                    'receipt_size' => $receipt?->getSize() ?? $record->receipt_size,
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                    'released_at' => $status === 'released' ? now() : null,
                ]);

                $statuses = $record->release->records()->pluck('status');
                $terminal = ['released', 'missed', 'withheld'];
                $releaseStatus = $statuses->every(fn (string $value): bool => in_array($value, $terminal, true))
                    ? 'completed'
                    : ($statuses->contains(fn (string $value): bool => $value !== 'scheduled') ? 'in_progress' : 'scheduled');
                $record->release->update(['status' => $releaseStatus]);
            });
        } catch (Throwable $error) {
            if ($newReceiptPath) {
                Storage::disk('local')->delete($newReceiptPath);
            }

            throw $error;
        }

        if ($newReceiptPath && filled($oldReceiptPath) && $oldReceiptPath !== $newReceiptPath) {
            Storage::disk('local')->delete($oldReceiptPath);
        }

        $statusLabels = [
            'prepared' => 'Benefit prepared',
            'released' => 'Benefit received',
            'missed' => 'Release appointment missed',
            'withheld' => 'Benefit release withheld',
        ];
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-benefit-release-result:{$record->id}:{$status}",
        ], [
            'user_id' => $record->applicant_id,
            'type' => 'recipient_benefit_release_result',
            'title' => $statusLabels[$status],
            'message' => "{$release->title}: the provider recorded your status as {$statusLabels[$status]}.",
            'action_url' => route('dashboard.applications.show', $record->application, false).'?section=monitoring',
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_benefit_release_recorded',
            "{$request->user()->name} recorded {$status} for {$record->application?->applicant?->name} in {$release->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'benefit_release_id' => $release->id,
                'benefit_release_record_id' => $record->id,
                'application_id' => $record->scholarship_application_id,
                'status' => $status,
            ],
        );

        return response()->json([
            'message' => $statusLabels[$status].'.',
            'release' => $this->recipientBenefitReleasePayload(
                $release->fresh()->load(['creator', 'records.applicant', 'records.recorder']),
            ),
        ]);
    }

    public function viewRecipientBenefitReleaseReceipt(
        Request $request,
        RecipientBenefitReleaseRecord $record,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $record->loadMissing('release.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($record->release?->scholarship), 403);
        abort_unless(filled($record->receipt_path) && Storage::disk('local')->exists($record->receipt_path), 404);

        return Storage::disk('local')->response($record->receipt_path, $record->receipt_original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function recipientSupportRecord(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $application->load([
            'scholarship.provider.providerProfile',
            'scholarship.monitoringCycles.creator',
            'applicant.studentProfile',
            'monitoringSubmissions.cycle',
            'monitoringSubmissions.reviewer',
            'monitoringSubmissions.reviews.reviewer',
            'benefitReleaseRecords.release',
            'benefitReleaseRecords.recorder',
            'supportDecisions.decider',
        ]);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(
            $application->final_outcome === 'selected'
                || in_array($application->status, [...self::AWARD_SLOT_STATUSES, 'benefits_terminated'], true),
            404,
        );

        return response()->json([
            'record' => $this->recipientSupportRecordPayload($application),
        ]);
    }

    public function recordRecipientSupportDecision(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $application->loadMissing([
            'scholarship.monitoringCycles',
            'applicant',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
        ]);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(
            $application->final_outcome === 'selected'
                || in_array($application->status, [...self::AWARD_SLOT_STATUSES, 'benefits_terminated'], true),
            422,
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['renewed', 'completed', 'terminated'])],
            'effective_on' => ['required', 'date', 'before_or_equal:today'],
            'support_ends_on' => [Rule::requiredIf($request->input('decision') === 'renewed'), 'nullable', 'date'],
            'next_review_on' => ['nullable', 'date'],
            'reason' => [
                Rule::requiredIf(in_array($request->input('decision'), ['completed', 'terminated'], true)),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'next_period_terms' => [
                Rule::requiredIf($request->input('decision') === 'renewed'),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'confirmed' => ['accepted'],
        ]);

        if ($application->student_response_status !== 'accepted') {
            throw ValidationException::withMessages([
                'decision' => 'The applicant must accept the recipient agreement before support can be renewed or closed.',
            ]);
        }

        $effectiveOn = CarbonImmutable::parse($validated['effective_on'])->startOfDay();
        $supportEndsOn = filled($validated['support_ends_on'] ?? null)
            ? CarbonImmutable::parse($validated['support_ends_on'])->startOfDay()
            : null;
        $nextReviewOn = filled($validated['next_review_on'] ?? null)
            ? CarbonImmutable::parse($validated['next_review_on'])->startOfDay()
            : null;

        if ($supportEndsOn && $supportEndsOn->isBefore($effectiveOn)) {
            throw ValidationException::withMessages([
                'support_ends_on' => 'The renewed support end date must be on or after the effective date.',
            ]);
        }

        if ($nextReviewOn && ($nextReviewOn->isBefore($effectiveOn) || ($supportEndsOn && $nextReviewOn->isAfter($supportEndsOn)))) {
            throw ValidationException::withMessages([
                'next_review_on' => 'The next review date must fall within the renewed support period.',
            ]);
        }

        $latestDecision = $application->supportDecisions->first();
        if ($latestDecision && in_array($latestDecision->decision, ['completed', 'terminated'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'This recipient support record is already closed and cannot receive another outcome.',
            ]);
        }

        if ($validated['decision'] === 'renewed') {
            $eligibility = $this->recipientSupportEligibility(
                $application,
                $application->scholarship->monitoringCycles,
                $effectiveOn,
            );

            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'decision' => $eligibility['reason'],
                ]);
            }
        }

        if ($validated['decision'] === 'completed') {
            $pendingReleases = $application->benefitReleaseRecords
                ->filter(function (RecipientBenefitReleaseRecord $record) use ($effectiveOn): bool {
                    $releaseAt = $record->release?->release_at;

                    return $releaseAt !== null
                        && $releaseAt->startOfDay()->lte($effectiveOn)
                        && in_array($record->status, ['scheduled', 'prepared'], true);
                });

            if ($pendingReleases->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'decision' => 'Record all benefit releases due by the completion date before closing recipient support.',
                ]);
            }
        }

        $previousStatus = $application->status;
        $nextStatus = match ($validated['decision']) {
            'renewed' => 'renewed',
            'terminated' => 'benefits_terminated',
            default => $previousStatus,
        };

        $decision = DB::transaction(function () use (
            $request,
            $application,
            $validated,
            $previousStatus,
            $nextStatus,
        ): RecipientSupportDecision {
            $currentStatus = ScholarshipApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->value('status');

            if ($currentStatus !== $previousStatus) {
                throw ValidationException::withMessages([
                    'decision' => 'This recipient record changed. Refresh the page before recording an outcome.',
                ]);
            }

            $decision = $application->supportDecisions()->create([
                'applicant_id' => $application->applicant_id,
                'decision' => $validated['decision'],
                'effective_on' => $validated['effective_on'],
                'support_ends_on' => $validated['support_ends_on'] ?? null,
                'next_review_on' => $validated['next_review_on'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'next_period_terms' => $validated['next_period_terms'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);

            if ($nextStatus !== $previousStatus) {
                $application->update([
                    'status' => $nextStatus,
                    'outcome_notes' => $validated['reason'] ?? $application->outcome_notes,
                    'outcome_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $previousStatus,
                    'to_status' => $nextStatus,
                    'review_notes' => $validated['reason'] ?? $validated['next_period_terms'] ?? null,
                    'changed_at' => now(),
                ]);
            }

            return $decision;
        });

        $decisionLabels = [
            'renewed' => 'Support renewed',
            'completed' => 'Support completed',
            'terminated' => 'Support ended early',
        ];
        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'recipient_support_decision',
            'title' => $decisionLabels[$validated['decision']],
            'message' => "{$application->scholarship->title}: {$decisionLabels[$validated['decision']]} effective {$decision->effective_on->format('M d, Y')}.",
            'action_url' => route('dashboard.applications.show', $application, false).'?section=monitoring',
            'deduplication_key' => "recipient-support-decision:{$decision->id}",
        ]);
        ActivityLog::record(
            $request->user(),
            'recipient_support_decision_recorded',
            "{$request->user()->name} recorded {$validated['decision']} support for {$application->applicant?->name}.",
            $request,
            [
                'scholarship_id' => $application->scholarship_id,
                'application_id' => $application->id,
                'recipient_support_decision_id' => $decision->id,
                'decision' => $validated['decision'],
            ],
        );

        $cycles = $application->scholarship->monitoringCycles()
            ->with('submissions')
            ->get();
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
        ]);

        return response()->json([
            'message' => $decisionLabels[$validated['decision']].'.',
            'recipient' => $this->recipientSupportPayload($freshApplication, $cycles),
        ]);
    }

    public function viewRecipientMonitoringSubmission(
        Request $request,
        RecipientMonitoringSubmission $submission,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $submission->loadMissing('cycle.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($submission->cycle?->scholarship), 403);
        abort_unless(Storage::disk('local')->exists($submission->path), 404);

        return Storage::disk('local')->response($submission->path, $submission->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function duplicateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        $this->ensureProviderCanPost($request);

        $copiedImagePath = $this->copyScholarshipImage($scholarship->image_path);

        try {
            $duplicate = DB::transaction(function () use ($request, $scholarship, $copiedImagePath): Scholarship {
                $duplicate = $scholarship->replicate([
                    'created_at',
                    'updated_at',
                ]);
                $duplicate->title = $this->duplicateScholarshipTitle($request->user()->providerOrganizationId(), $scholarship->title);
                $duplicate->image_path = $copiedImagePath;
                $duplicate->status = 'draft';
                $duplicate->views_count = 0;
                $duplicate->provider_terms_accepted_at = null;
                $duplicate->provider_terms_version = null;
                $duplicate->save();
                app(SB::class)->copy($scholarship, $duplicate);

                return $duplicate;
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($copiedImagePath);

            throw $error;
        }

        $duplicate->loadCount('bookmarks');
        $this->includeProgramInStaffScope($request->user(), $duplicate);

        ActivityLog::record(
            $request->user(),
            'scholarship_duplicated',
            "{$request->user()->name} duplicated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'duplicate_id' => $duplicate->id],
        );

        return response()->json([
            'message' => 'Program duplicated as a draft.',
            'scholarship' => $this->scholarshipPayload($duplicate),
        ], 201);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $validated = $this->normalizeScholarshipProviderObjectives($validated, $request);
        $validated = $this->normalizeScholarshipRecipientAgreement($validated, $request);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipEligibilityConditions($validated, $request);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user());
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request);
        $imagePath = $this->storeScholarshipImage($request);

        if ($imagePath === null && $request->boolean('use_provider_logo')) {
            $imagePath = $this->copyProviderLogoForScholarship($request->user());
        }
        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['use_provider_logo'], $validated['terms_accepted'], $validated['program_events']);
        $validated['description'] = (string) ($validated['description'] ?? '');
        $validated['status'] = $validated['status'] === 'draft' ? 'draft' : 'pending_review';

        if ($termsAccepted) {
            $validated['provider_terms_accepted_at'] = now();
            $validated['provider_terms_version'] = Terms::VERSION;
        }

        try {
            $this->ensureScholarshipReadyForSubmission($validated, $benefits, null, $imagePath);

            $scholarship = DB::transaction(function () use ($validated, $imagePath, $programEvents, $benefits, $request): Scholarship {
                $scholarship = Scholarship::create([
                    ...$validated,
                    'image_path' => $imagePath,
                    'provider_id' => $request->user()->providerOrganizationId(),
                ]);

                foreach ($programEvents ?? [] as $programEvent) {
                    $this->persistScholarshipEvent($scholarship, $programEvent, $request->user());
                }

                app(SB::class)->sync($scholarship, $benefits);

                return $scholarship;
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        $this->includeProgramInStaffScope($request->user(), $scholarship);

        ActivityLog::record(
            $request->user(),
            'scholarship_created',
            "{$request->user()->name} created scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => $scholarship->status === 'pending_review'
                ? 'Scholarship submitted for admin review.'
                : 'Scholarship draft saved.',
            'scholarship' => $this->scholarshipPayload($scholarship),
        ], 201);
    }

    public function updateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $validated = $this->normalizeScholarshipProviderObjectives($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipRecipientAgreement($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipEligibilityConditions($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user(), $scholarship);
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request, $scholarship);
        $benefitsChanged = $benefits !== null && app(SB::class)->changed($scholarship, $benefits);
        $this->ensureScholarshipSelectionPlanIsStable($scholarship, $validated['selection_stages']);
        $oldImagePath = $scholarship->image_path;
        $imagePath = $this->storeScholarshipImage($request);

        if ($imagePath === null && $request->boolean('use_provider_logo')) {
            $imagePath = $this->copyProviderLogoForScholarship($request->user());
        }

        try {
            $this->ensureScholarshipReadyForSubmission(
                $validated,
                $benefits,
                $scholarship,
                $imagePath ?: $scholarship->image_path,
            );
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['use_provider_logo'], $validated['terms_accepted'], $validated['program_events']);
        $validated['description'] = $request->has('description')
            ? (string) ($validated['description'] ?? '')
            : $scholarship->description;

        if ($termsAccepted) {
            $validated['provider_terms_accepted_at'] = now();
            $validated['provider_terms_version'] = Terms::VERSION;
        }

        if ($imagePath) {
            $validated['image_path'] = $imagePath;
        }

        $validated['status'] = $this->providerScholarshipStatus($scholarship, $validated['status'], $validated, $benefitsChanged);
        try {
            DB::transaction(function () use ($scholarship, $validated, $programEvents, $benefits, $request): void {
                $requestedSlots = array_key_exists('slots_available', $validated)
                    ? $validated['slots_available']
                    : $scholarship->slots_available;

                $this->ensureScholarshipAwardCapacity($scholarship, $requestedSlots);
                $scholarship->update($validated);

                if ($programEvents !== null) {
                    $submittedEventTypes = collect($programEvents)->pluck('type')->all();
                    $eventsToDelete = $scholarship->events()
                        ->whereIn('type', ['exam', 'interview', 'distribution']);

                    if ($submittedEventTypes !== []) {
                        $eventsToDelete->whereNotIn('type', $submittedEventTypes);
                    }

                    $this->deleteScholarshipEventsSafely($scholarship, $eventsToDelete->get());
                } else {
                    $eventsToDelete = $scholarship->events()
                        ->whereNotIn('type', ScholarshipSelectionPlan::normalize($scholarship->selection_stages))
                        ->get();

                    $this->deleteScholarshipEventsSafely($scholarship, $eventsToDelete);
                }

                foreach ($programEvents ?? [] as $programEvent) {
                    $this->persistScholarshipEvent($scholarship, $programEvent, $request->user());
                }

                app(SB::class)->sync($scholarship, $benefits);
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        if ($imagePath && $oldImagePath !== $imagePath) {
            $this->deleteScholarshipImageIfUnused($oldImagePath);
        }

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_updated',
            "{$request->user()->name} updated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => match ($scholarship->status) {
                'pending_review' => 'Scholarship submitted for admin review.',
                'closed' => 'Scholarship closed.',
                'published' => 'Published scholarship updated.',
                default => 'Scholarship draft saved.',
            },
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()),
        ]);
    }

    private function ensureScholarshipReadyForSubmission(
        array $validated,
        ?array $benefits,
        ?Scholarship $scholarship = null,
        ?string $imagePath = null,
    ): void {
        if (in_array($validated['status'], ['draft', 'closed'], true)) {
            return;
        }

        $value = static fn (string $field) => array_key_exists($field, $validated)
            ? $validated[$field]
            : $scholarship?->{$field};
        $errors = [];
        $deadline = $value('deadline');
        $applicationOpensAt = $value('application_opens_at');
        $expectedResultsAt = $value('expected_results_at');
        $supportStartsAt = $value('support_starts_at');
        $supportEndsAt = $value('support_ends_at');

        if (filled($applicationOpensAt) && filled($deadline)
            && CarbonImmutable::parse($applicationOpensAt)->startOfDay()->isAfter(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['application_opens_at'] = 'The application opening date must be on or before the deadline.';
        }

        if (filled($expectedResultsAt) && filled($deadline)
            && CarbonImmutable::parse($expectedResultsAt)->startOfDay()->isBefore(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['expected_results_at'] = 'The expected results date must be on or after the application deadline.';
        }

        if (filled($supportStartsAt) && filled($supportEndsAt)
            && CarbonImmutable::parse($supportEndsAt)->startOfDay()->isBefore(CarbonImmutable::parse($supportStartsAt)->startOfDay())) {
            $errors['support_ends_at'] = 'The support end date must be on or after the support start date.';
        }

        $this->addScholarshipEligibilityConsistencyErrors($errors, $value);

        if (blank($imagePath)) {
            $errors['image_file'] = 'Upload a program logo or reuse the provider logo before submitting for review.';
        }

        // Do not strand an older live program that predates the expanded form.
        // Its unchanged deadline remains editable, while a new invalid value is blocked.
        if ($scholarship?->status === 'published') {
            if (array_key_exists('deadline', $validated)) {
                $incomingDeadline = filled($deadline)
                    ? CarbonImmutable::parse($deadline)->toDateString()
                    : null;
                $existingDeadline = $scholarship->deadline?->toDateString();

                if ($incomingDeadline !== $existingDeadline) {
                    if ($incomingDeadline === null) {
                        $errors['deadline'] = 'Keep an application deadline on a published program.';
                    } elseif (CarbonImmutable::parse($incomingDeadline)->startOfDay()->isBefore(CarbonImmutable::today())) {
                        $errors['deadline'] = 'The application deadline cannot be in the past.';
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        if (blank($deadline)) {
            $errors['deadline'] = 'Add an application deadline before submitting the program for review.';
        } elseif (CarbonImmutable::parse($deadline)->startOfDay()->isBefore(CarbonImmutable::today())) {
            $errors['deadline'] = 'The application deadline cannot be in the past.';
        }

        if (blank($supportStartsAt)) {
            $errors['support_starts_at'] = 'Add the date when recipient support is expected to begin.';
        }

        if (blank($supportEndsAt)) {
            $errors['support_ends_at'] = 'Add the date when recipient support is expected to end.';
        }

        if (blank($value('category'))) {
            $errors['category'] = 'Choose a scholarship category before submitting for review.';
        }

        $hasBenefits = $benefits !== null
            ? $benefits !== []
            : ($scholarship?->benefitPayload() ?? []) !== [];

        if (! $hasBenefits) {
            $errors['benefits'] = 'Add at least one program benefit before submitting for review.';
        }

        if (blank($value('application_mode'))) {
            $errors['application_mode'] = 'Choose how applicants will be verified during the portal review.';
        }

        if (blank($value('eligibility'))) {
            $errors['eligibility'] = 'Describe who is eligible for this program.';
        }

        $hasFinderRule = collect([
            $value('eligible_education_levels'),
            $value('eligible_courses'),
            $value('eligible_school_types'),
            $value('eligible_year_levels'),
            $value('eligible_locations'),
            $value('minimum_gwa'),
        ])->contains(fn ($field): bool => filled($field))
            || ($value('income_requirement') !== null && $value('income_requirement') !== 'Any')
            || (bool) $value('exclude_current_scholarship_recipients')
            || in_array($value('minimum_grade_scale'), ['pass_fail', 'other'], true);

        if (! $hasFinderRule) {
            $errors['eligible_education_levels'] = 'Add at least one applicant matching rule.';
        }

        if ($value('application_mode') !== 'provider_review' && blank($value('requirements'))) {
            $errors['requirements'] = 'Add at least one document needed for portal pre-screening.';
        }

        if (blank($value('post_qualification_requirements'))) {
            $errors['post_qualification_requirements'] = 'List at least one document a qualified applicant must bring for the formal application.';
        }

        $handoffMode = $value('handoff_mode');

        if (blank($handoffMode)) {
            $errors['handoff_mode'] = 'Choose how qualified applicants continue with the provider.';
        }

        if (blank($value('handoff_instructions'))) {
            $errors['handoff_instructions'] = 'Add short instructions for qualified applicants.';
        }

        if ($handoffMode === 'onsite' && blank($value('handoff_location_address'))) {
            $errors['handoff_location_address'] = 'Add the address where qualified applicants must bring their documents.';
        }

        if ($handoffMode === 'online' && blank($value('handoff_url'))) {
            $errors['handoff_url'] = 'Add the official link qualified applicants should use.';
        }

        $handoffDeadline = $value('handoff_deadline');

        if (filled($handoffDeadline) && filled($deadline)
            && CarbonImmutable::parse($handoffDeadline)->startOfDay()->isBefore(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['handoff_deadline'] = 'The formal application deadline must be on or after the pre-screening deadline.';
        }

        foreach ([
            'location_name' => 'Add the program location name.',
            'location_address' => 'Add the program address.',
            'latitude' => 'Set the program location pin on the map.',
            'longitude' => 'Set the program location pin on the map.',
        ] as $field => $message) {
            if (blank($value($field))) {
                $errors[$field] = $message;
            }
        }

        if (blank($value('contact_email')) && blank($value('contact_number'))) {
            $errors['contact_email'] = 'Add an email address or contact number for applicant questions.';
        }

        if (blank($value('review_rubric'))) {
            $errors['review_rubric'] = 'Add at least one review criterion.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function addScholarshipEligibilityConsistencyErrors(array &$errors, callable $value): void
    {
        $conditions = ScholarshipEligibilityCondition::normalize((array) ($value('eligibility_conditions') ?? []));

        if ($conditions === []) {
            return;
        }

        $conditionKeys = collect($conditions)->pluck('key');

        if ($conditionKeys->contains('open_to_all')) {
            $educationLevels = collect(preg_split('/\r\n|\r|\n|,/', (string) $value('eligible_education_levels')))
                ->map(fn (string $level): string => Str::lower(trim($level)))
                ->filter()
                ->unique()
                ->values();
            $allEducationLevels = collect([
                'preschool',
                'elementary',
                'junior_high_school',
                'senior_high_school',
                'college',
                'tvet',
                'als',
            ]);
            $hasEducationRestriction = $educationLevels->isNotEmpty()
                && ($educationLevels->count() !== $allEducationLevels->count()
                    || $educationLevels->diff($allEducationLevels)->isNotEmpty());
            $hasFinderRestriction = $hasEducationRestriction
                || ! $this->isOpenScholarshipRule($value('eligible_courses'))
                || filled($value('eligible_school_types'))
                || ! $this->isOpenScholarshipRule($value('eligible_year_levels'))
                || ! $this->isOpenScholarshipRule($value('eligible_locations'))
                || ! $this->isOpenScholarshipRule($value('income_requirement'))
                || (bool) $value('exclude_current_scholarship_recipients')
                || filled($value('minimum_grade_scale'))
                || filled($value('minimum_gwa'));

            if (count($conditions) > 1 || $hasFinderRestriction) {
                $errors['eligibility_conditions'] = 'Open to all learners cannot be combined with other conditions or restrictive matching fields.';
            }
        }

        if ($conditionKeys->contains('academic_performance')
            && blank($value('minimum_grade_scale'))
            && blank($value('minimum_gwa'))) {
            $errors['minimum_grade_scale'] = 'Choose an academic grading rule or remove the academic requirement condition.';
        }

        if ($conditionKeys->contains('financial_need') && $this->isOpenScholarshipRule($value('income_requirement'))) {
            $errors['income_requirement'] = 'Choose a household-income range or remove the financial need condition.';
        }

        if ($conditionKeys->contains('location_coverage') && $this->isOpenScholarshipRule($value('eligible_locations'))) {
            $errors['eligible_locations'] = 'Add a covered location or remove the location coverage condition.';
        }

        if ($conditionKeys->contains('required_documents')) {
            if ($value('application_mode') === 'provider_review') {
                $errors['application_mode'] = 'Required documents cannot be checked in profile review only. Choose a portal document review method or remove the condition.';
            } elseif (blank($value('requirements'))) {
                $errors['requirements'] = 'Select at least one pre-screening file or remove the required documents condition.';
            }
        }

        if (collect($conditions)->contains(
            fn (array $condition): bool => $condition['source'] === 'provider_condition'
                && $condition['verification_type'] === 'automatic',
        )) {
            $errors['eligibility_conditions'] = 'A custom condition must be confirmed by the applicant or reviewed by the provider; it cannot be checked automatically.';
        }
    }

    private function isOpenScholarshipRule(mixed $value): bool
    {
        $normalized = Str::of((string) $value)->lower()->squish()->toString();

        return $normalized === '' || in_array($normalized, [
            'any',
            'all',
            'open to all',
            'nationwide',
            'no restriction',
            'no income requirement',
            'any income',
        ], true);
    }

    private function validateScholarship(Request $request): array
    {
        $requiresCompleteSubmission = ! in_array($request->input('status'), ['draft', 'closed'], true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'program_cycle' => ['nullable', 'string', 'max:100'],
            'description' => [
                Rule::requiredIf($requiresCompleteSubmission),
                'nullable',
                'string',
                'max:5000',
            ],
            'provider_objectives' => ['nullable', 'string', 'max:2000', 'json'],
            'provider_objective_notes' => ['nullable', 'string', 'max:1500'],
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'eligibility_conditions' => ['nullable', 'string', 'max:15000', 'json'],
            'eligible_education_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_courses' => ['nullable', 'string', 'max:3000'],
            'eligible_school_types' => ['nullable', 'string', 'max:2000'],
            'eligible_year_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_locations' => ['nullable', 'string', 'max:3000'],
            'income_requirement' => ['nullable', 'string', 'max:100'],
            'exclude_current_scholarship_recipients' => ['nullable', 'boolean'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'optional_requirements' => ['nullable', 'string', 'max:5000'],
            'post_qualification_requirements' => ['nullable', 'string', 'max:5000'],
            'handoff_mode' => ['nullable', Rule::in(['onsite', 'online', 'provider_contact'])],
            'handoff_instructions' => ['nullable', 'string', 'max:3000'],
            'handoff_deadline' => ['nullable', 'date'],
            'handoff_location_name' => ['nullable', 'string', 'max:255'],
            'handoff_location_address' => ['nullable', 'string', 'max:500'],
            'handoff_url' => ['nullable', 'url:http,https', 'max:2048'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'benefits' => ['nullable', 'string', 'max:20000', 'json'],
            'minimum_gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_grade_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'slots_available' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'application_mode' => ['nullable', Rule::in(['online', 'onsite', 'hybrid', 'provider_review'])],
            'selection_stages' => ['nullable', 'string', 'max:500', 'json'],
            'program_events' => ['nullable', 'string', 'max:20000', 'json'],
            'renewal_policy' => ['nullable', 'string', 'max:2000'],
            'return_service_contract' => ['nullable', 'string', 'max:3000'],
            'other_contract_terms' => ['nullable', 'string', 'max:3000'],
            'recipient_agreement' => ['nullable', 'string', 'max:6000', 'json'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', new PhoneNumber],
            'application_opens_at' => ['nullable', 'date'],
            'expected_results_at' => ['nullable', 'date'],
            'support_starts_at' => ['nullable', 'date'],
            'support_ends_at' => ['nullable', 'date', 'after_or_equal:support_starts_at'],
            'official_program_url' => ['nullable', 'url:http,https', 'max:2048'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_department' => ['nullable', 'string', 'max:150'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'closed', 'rejected'])],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'use_provider_logo' => ['nullable', 'boolean'],
            'terms_accepted' => $requiresCompleteSubmission ? ['accepted'] : ['nullable'],
            'review_rubric' => ['nullable', 'string', 'max:8000', 'json'],
            'application_questions' => ['nullable', 'string', 'max:10000', 'json'],
        ]);

        return $validated;
    }

    private function normalizeScholarshipProviderObjectives(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('provider_objectives')) {
            $validated['provider_objectives'] = $scholarship?->provider_objectives ?? [];

            return $validated;
        }

        $decoded = json_decode((string) ($validated['provider_objectives'] ?? '[]'), true);
        $validated['provider_objectives'] = collect(is_array($decoded) ? $decoded : [])
            ->filter(fn (mixed $objective): bool => is_string($objective)
                && in_array($objective, self::PROVIDER_OBJECTIVES, true))
            ->unique()
            ->values()
            ->all();

        return $validated;
    }

    private function normalizeScholarshipRecipientAgreement(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('recipient_agreement')) {
            $validated['recipient_agreement'] = $scholarship?->recipient_agreement;

            return $validated;
        }

        $decoded = json_decode((string) ($validated['recipient_agreement'] ?? '{}'), true);
        $agreement = validator(['agreement' => is_array($decoded) ? $decoded : []], [
            'agreement.commitment_type' => ['required', Rule::in(self::RECIPIENT_COMMITMENT_TYPES)],
            'agreement.duration' => ['nullable', 'string', 'max:500'],
            'agreement.noncompliance_consequence' => ['nullable', 'string', 'max:1000'],
            'agreement.exit_or_exception_process' => ['nullable', 'string', 'max:1000'],
        ])->validate()['agreement'];

        $validated['recipient_agreement'] = [
            'commitment_type' => $agreement['commitment_type'],
            'duration' => Str::squish((string) ($agreement['duration'] ?? '')) ?: null,
            'noncompliance_consequence' => Str::squish((string) ($agreement['noncompliance_consequence'] ?? '')) ?: null,
            'exit_or_exception_process' => Str::squish((string) ($agreement['exit_or_exception_process'] ?? '')) ?: null,
        ];

        return $validated;
    }

    private function applyProviderProgramContactDefaults(
        array $validated,
        User $actor,
        ?Scholarship $scholarship = null,
    ): array {
        $profile = $actor->providerOrganizationOwner()->providerProfile()->first();

        if (blank($validated['contact_email'] ?? null)) {
            $validated['contact_email'] = $scholarship?->contact_email
                ?: $profile?->provider_contact_email;
        }

        if (blank($validated['contact_number'] ?? null)) {
            $validated['contact_number'] = $scholarship?->contact_number
                ?: $profile?->provider_contact_number;
        }

        return $validated;
    }

    private function normalizeScholarshipAcademicRequirement(array $validated): array
    {
        $scale = $validated['minimum_grade_scale'] ?? null;

        if ($scale === '') {
            $scale = null;
        }

        if (! AcademicRequirement::requiresNumeric($scale)) {
            $validated['minimum_gwa'] = null;
        }

        if (blank($validated['minimum_gwa'] ?? null) && in_array($scale, ['percentage', 'grade_point'], true)) {
            $scale = null;
        }

        if ($scale === 'grade_point' && (float) $validated['minimum_gwa'] > 5) {
            throw ValidationException::withMessages([
                'minimum_gwa' => 'A GWA or GPA grade point must be between 0 and 5.',
            ]);
        }

        $validated['minimum_grade_scale'] = $scale;

        return $validated;
    }

    private function normalizeScholarshipProgramPaths(array $validated): array
    {
        if (array_key_exists('eligible_courses', $validated)) {
            $validated['eligible_courses'] = LearnerProgramPath::canonicalizeList($validated['eligible_courses']);
        }

        return $validated;
    }

    private function normalizeScholarshipRequirements(array $validated): array
    {
        foreach (['requirements', 'optional_requirements', 'post_qualification_requirements'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $normalized = collect(preg_split('/\r\n|\r|\n/', (string) ($validated[$field] ?? '')))
                ->map(fn (string $requirement): string => trim($requirement))
                ->filter()
                ->reject(fn (string $requirement): bool => Str::lower($requirement) === 'completed application form')
                ->unique(fn (string $requirement): string => Str::lower($requirement))
                ->implode("\n");

            $validated[$field] = $normalized !== '' ? $normalized : null;
        }

        return $validated;
    }

    private function normalizeScholarshipEligibilityConditions(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('eligibility_conditions')) {
            $validated['eligibility_conditions'] = $scholarship?->eligibility_conditions ?? [];

            return $validated;
        }

        $validated['eligibility_conditions'] = ScholarshipEligibilityCondition::fromJson(
            $validated['eligibility_conditions'] ?? null,
        );

        return $validated;
    }

    private function normalizeScholarshipReviewRubric(array $validated, Request $request, ?Scholarship $scholarship = null): array
    {
        if (! $request->has('review_rubric')) {
            $validated['review_rubric'] = $scholarship?->review_rubric ?? ReviewRubric::DEFAULT;

            return $validated;
        }

        $validated['review_rubric'] = ReviewRubric::fromJson($validated['review_rubric'] ?? null);

        return $validated;
    }

    private function normalizeScholarshipApplicationQuestions(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('application_questions')) {
            $validated['application_questions'] = $scholarship?->application_questions;

            return $validated;
        }

        $decoded = json_decode((string) ($validated['application_questions'] ?? '[]'), true);
        $questions = collect(is_array($decoded) ? $decoded : [])
            ->filter(fn (mixed $question): bool => is_array($question) && filled($question['prompt'] ?? null))
            ->values()
            ->all();
        $questions = validator(['questions' => $questions], [
            'questions' => ['array', 'max:5'],
            'questions.*.id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'questions.*.prompt' => ['required', 'string', 'max:300'],
            'questions.*.required' => ['nullable', 'boolean'],
        ])->validate()['questions'] ?? [];
        $usedIds = [];

        $validated['application_questions'] = collect($questions)
            ->map(function (array $question, int $index) use (&$usedIds): array {
                $prompt = Str::squish($question['prompt']);
                $id = $question['id'] ?? null;

                if (blank($id) || in_array($id, $usedIds, true)) {
                    $id = 'question_'.($index + 1).'_'.substr(sha1($prompt), 0, 8);
                }

                $usedIds[] = $id;

                return [
                    'id' => $id,
                    'prompt' => $prompt,
                    'required' => (bool) ($question['required'] ?? false),
                ];
            })
            ->values()
            ->all();

        return $validated;
    }

    private function normalizeScholarshipSelectionStages(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if ($request->has('selection_stages')) {
            $validated['selection_stages'] = ScholarshipSelectionPlan::normalize($validated['selection_stages'] ?? null);
        } elseif ($scholarship) {
            $validated['selection_stages'] = $scholarship->selection_stages;
        } else {
            $validated['selection_stages'] = ScholarshipSelectionPlan::DEFAULT;
        }

        return $validated;
    }

    private function normalizeScholarshipExamDetails(array $validated): array
    {
        // Exams are scheduled and explained by providers; scoring rules are not managed by the portal.
        $validated['exam_duration_minutes'] = null;
        $validated['exam_passing_score'] = null;

        return $validated;
    }

    private function normalizeScholarshipProgramEvents(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): ?array {
        if (! $request->has('program_events')) {
            return null;
        }

        $events = json_decode((string) ($validated['program_events'] ?? '[]'), true);
        $events = is_array($events) ? $events : [];
        $eventData = validator(['program_events' => $events], [
            'program_events' => ['array', 'max:2'],
            'program_events.*.type' => ['required', 'string', 'distinct', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'program_events.*.title' => ['nullable', 'string', 'max:255'],
            'program_events.*.scheduled_at' => ['required', 'date'],
            'program_events.*.mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'program_events.*.venue' => ['nullable', 'string', 'max:500'],
            'program_events.*.location_address' => ['nullable', 'string', 'max:1000'],
            'program_events.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:program_events.*.longitude'],
            'program_events.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:program_events.*.latitude'],
            'program_events.*.online_url' => ['nullable', 'url:http,https', 'max:2000'],
            'program_events.*.instructions' => ['required', 'string', 'max:3000'],
        ])->validate()['program_events'] ?? [];
        $selectionStages = ScholarshipSelectionPlan::normalize($validated['selection_stages'] ?? null);
        $scholarship?->loadMissing('events');

        $eventDates = [];
        $eventIndexes = [];

        foreach ($eventData as $index => $event) {
            if (! in_array($event['type'], $selectionStages, true)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.type" => 'Add this stage to the selection plan before setting its schedule.',
                ]);
            }

            if (in_array($event['mode'], ['onsite', 'hybrid'], true) && blank($event['venue'] ?? null)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.venue" => 'Add a venue for an on-site or hybrid schedule.',
                ]);
            }

            if (in_array($event['mode'], ['online', 'hybrid'], true) && blank($event['online_url'] ?? null)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.online_url" => 'Add the online meeting or assessment link.',
                ]);
            }

            $scheduledAt = CarbonImmutable::parse($event['scheduled_at']);
            $existingEvent = $scholarship?->events->firstWhere('type', $event['type']);
            $keepsExistingDate = $existingEvent?->scheduled_at?->format('Y-m-d H:i') === $scheduledAt->format('Y-m-d H:i');

            if ($scheduledAt->isBefore(now()->startOfMinute()) && ! $keepsExistingDate) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.scheduled_at" => 'Use a future date and time for a new schedule.',
                ]);
            }

            $eventDates[$event['type']] = $scheduledAt;
            $eventIndexes[$event['type']] = $index;
        }

        $previousType = null;

        foreach (collect($selectionStages)->filter(
            fn (string $stage): bool => ScholarshipSelectionPlan::isSchedulable($stage),
        ) as $eventType) {
            if (! isset($eventDates[$eventType])) {
                continue;
            }

            if ($previousType !== null && $eventDates[$eventType]->isBefore($eventDates[$previousType])) {
                $eventLabel = ScholarshipSelectionPlan::label($eventType);
                $previousLabel = ScholarshipSelectionPlan::label($previousType);

                throw ValidationException::withMessages([
                    "program_events.{$eventIndexes[$eventType]}.scheduled_at" => ucfirst($eventLabel)." must be scheduled after {$previousLabel}.",
                ]);
            }

            $previousType = $eventType;
        }

        return $eventData;
    }

    private function persistScholarshipEvent(
        Scholarship $scholarship,
        array $eventData,
        User $provider,
    ): array {
        $selectionStages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);

        if (! in_array($eventData['type'], $selectionStages, true)) {
            throw ValidationException::withMessages([
                'type' => 'Add this stage to the scholarship selection plan before publishing its schedule.',
            ]);
        }

        $eventLabel = ScholarshipSelectionPlan::label($eventData['type']);
        $event = $scholarship->events()->firstOrNew(['type' => $eventData['type']]);
        $announcementData = [
            'title' => filled($eventData['title'] ?? null) ? trim($eventData['title']) : ucfirst($eventLabel).' schedule',
            'scheduled_at' => CarbonImmutable::parse($eventData['scheduled_at']),
            'mode' => $eventData['mode'],
            'venue' => $eventData['venue'] ?? null,
            'location_address' => $eventData['location_address'] ?? null,
            'latitude' => $eventData['latitude'] ?? null,
            'longitude' => $eventData['longitude'] ?? null,
            'online_url' => $eventData['online_url'] ?? null,
            'instructions' => $eventData['instructions'],
        ];
        $event->fill($announcementData);
        $announcementChanged = $event->isDirty(array_keys($announcementData));

        $event->fill([
            'status' => $event->exists && $event->status === 'completed' && ! $announcementChanged
                ? 'completed'
                : 'scheduled',
            'updated_by' => $provider->id,
        ]);

        if (! $event->exists) {
            $event->created_by = $provider->id;
        }

        $event->save();

        return [
            $event,
            app(ScholarshipEventService::class)->syncEligibleApplications($event),
        ];
    }

    private function deleteScholarshipEventsSafely(
        Scholarship $scholarship,
        EloquentCollection $events,
    ): void {
        if ($events->isEmpty()) {
            return;
        }

        $activeScheduleTypes = ApplicationSchedule::query()
            ->where('status', 'scheduled')
            ->whereIn('type', $events->pluck('type'))
            ->whereHas('application', fn ($query) => $query->where('scholarship_id', $scholarship->id))
            ->pluck('type')
            ->unique()
            ->map(fn (string $type): string => ScholarshipSelectionPlan::label($type))
            ->implode(', ');

        if ($activeScheduleTypes !== '') {
            throw ValidationException::withMessages([
                'program_events' => "The {$activeScheduleTypes} schedule is already active for applicants. Update its details instead of removing it.",
            ]);
        }

        ScholarshipEvent::query()->whereKey($events->modelKeys())->delete();
    }

    private function providerScholarshipStatus(Scholarship $scholarship, string $requestedStatus, array $validated, bool $benefitsChanged = false): string
    {
        if ($requestedStatus === 'published' && $scholarship->status === 'published') {
            return $benefitsChanged || $this->scholarshipHasReviewableChanges($scholarship, $validated)
                ? 'pending_review'
                : 'published';
        }

        if ($requestedStatus === 'closed' && in_array($scholarship->status, ['published', 'closed'], true)) {
            return 'closed';
        }

        return $requestedStatus === 'draft' ? 'draft' : 'pending_review';
    }

    private function ensureScholarshipSelectionPlanIsStable(
        Scholarship $scholarship,
        mixed $selectionStages,
    ): void {
        $currentStages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);
        $nextStages = ScholarshipSelectionPlan::normalize($selectionStages);

        if ($currentStages === $nextStages || ! $scholarship->applications()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'selection_stages' => 'The review, exam, and interview path cannot change after applications have been submitted. Duplicate this program for a new intake instead.',
        ]);
    }

    private function ensureScholarshipAwardCapacity(
        Scholarship $scholarship,
        ?int $requestedSlots,
    ): void {
        if ($requestedSlots === null) {
            return;
        }

        Scholarship::query()
            ->whereKey($scholarship->id)
            ->lockForUpdate()
            ->firstOrFail();

        $occupiedSlots = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('status', self::AWARD_SLOT_STATUSES)
            ->count();

        if ($requestedSlots < $occupiedSlots) {
            throw ValidationException::withMessages([
                'slots_available' => "This program already has {$occupiedSlots} awarded applicant(s). Keep at least {$occupiedSlots} award slot(s).",
            ]);
        }
    }

    private function scholarshipHasReviewableChanges(Scholarship $scholarship, array $validated): bool
    {
        $reviewableFields = [
            'image_path',
            'title',
            'category',
            'program_cycle',
            'description',
            'provider_objectives',
            'provider_objective_notes',
            'eligibility',
            'eligible_education_levels',
            'eligible_courses',
            'eligible_school_types',
            'eligible_year_levels',
            'eligible_locations',
            'income_requirement',
            'exclude_current_scholarship_recipients',
            'location_name',
            'location_address',
            'latitude',
            'longitude',
            'requirements',
            'optional_requirements',
            'post_qualification_requirements',
            'handoff_mode',
            'handoff_instructions',
            'handoff_deadline',
            'handoff_location_name',
            'handoff_location_address',
            'handoff_url',
            'review_rubric',
            'application_questions',
            'award_amount',
            'minimum_gwa',
            'minimum_grade_scale',
            'slots_available',
            'application_mode',
            'selection_stages',
            'renewal_policy',
            'return_service_contract',
            'other_contract_terms',
            'recipient_agreement',
            'contact_email',
            'contact_number',
            'application_opens_at',
            'expected_results_at',
            'support_starts_at',
            'support_ends_at',
            'official_program_url',
            'contact_person',
            'contact_department',
            'deadline',
        ];

        foreach ($reviewableFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $currentValue = $field === 'selection_stages'
                ? ScholarshipSelectionPlan::normalize($scholarship->selection_stages)
                : $scholarship->getAttribute($field);
            $nextValue = $field === 'selection_stages'
                ? ScholarshipSelectionPlan::normalize($validated[$field])
                : $validated[$field];

            if ($this->comparableScholarshipValue($currentValue)
                !== $this->comparableScholarshipValue($nextValue)) {
                return true;
            }
        }

        return false;
    }

    private function comparableScholarshipValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    private function notifyAdminsScholarshipSubmitted(Request $request, Scholarship $scholarship): void
    {
        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'scholarship_review',
                'title' => 'Scholarship ready for review',
                'message' => "{$request->user()->name} submitted {$scholarship->title} for admin review.",
                'action_url' => '/admin/reviews',
            ]));
    }

    private function storeProviderLogo(Request $request): string
    {
        $file = $request->file('logo_file');
        $directory = public_path('uploads/providers');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $file->hashName();
        $file->move($directory, $filename);

        return "uploads/providers/{$filename}";
    }

    private function copyProviderLogoForScholarship(User $actor): ?string
    {
        $profile = $actor->providerOrganizationOwner()->providerProfile()->first();
        $logoPath = ltrim(str_replace('\\', '/', (string) $profile?->logo_path), '/');

        if (! str_starts_with($logoPath, 'uploads/providers/')) {
            return null;
        }

        $sourcePath = public_path($logoPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'png';
        $relativePath = 'uploads/scholarships/'.Str::uuid().".{$extension}";

        return copy($sourcePath, public_path($relativePath)) ? $relativePath : null;
    }

    private function deleteProviderLogoIfUnused(?string $logoPath): void
    {
        $normalizedPath = ltrim(str_replace('\\', '/', (string) $logoPath), '/');

        if (! str_starts_with($normalizedPath, 'uploads/providers/')
            || Scholarship::query()->where('image_path', $normalizedPath)->exists()) {
            return;
        }

        $absolutePath = public_path($normalizedPath);

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function storeScholarshipImage(Request $request): ?string
    {
        if (! $request->hasFile('image_file')) {
            return null;
        }

        $file = $request->file('image_file');
        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $file->hashName();
        $file->move($directory, $filename);

        return "uploads/scholarships/{$filename}";
    }

    private function copyScholarshipImage(?string $imagePath): ?string
    {
        $normalizedPath = $this->managedScholarshipImagePath($imagePath);

        if ($normalizedPath === null) {
            return null;
        }

        $sourcePath = public_path($normalizedPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $relativePath = 'uploads/scholarships/'.Str::uuid().".{$extension}";

        return copy($sourcePath, public_path($relativePath)) ? $relativePath : null;
    }

    private function deleteScholarshipImageIfUnused(?string $imagePath): void
    {
        $normalizedPath = $this->managedScholarshipImagePath($imagePath);

        if ($normalizedPath === null
            || Scholarship::query()->where('image_path', $normalizedPath)->exists()) {
            return;
        }

        $absolutePath = public_path($normalizedPath);

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function managedScholarshipImagePath(?string $imagePath): ?string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', (string) $imagePath), '/');

        return str_starts_with($normalizedPath, 'uploads/scholarships/')
            ? $normalizedPath
            : null;
    }

    private function applicationPayload(ScholarshipApplication $application, bool $includeApplicantProfile = false): array
    {
        $workflow = $this->workflowService->payload($application);
        $readiness = $this->documentReadiness($application);
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);
        $recipientAgreement = RecipientAgreement::payload($application);
        $application->loadMissing(['schedules', 'assignedReviewer.providerProfile']);
        $application->scholarship?->loadMissing('events');
        $latestDocumentUploadedAt = $application->documents
            ->pluck('uploaded_at')
            ->filter()
            ->sortDesc()
            ->first();
        $submittedAt = $application->submitted_at ?? $application->created_at;

        if ($includeApplicantProfile) {
            $application->loadMissing('applicant.applicantVerificationDocuments');
        }

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application),
            'status' => $application->status,
            'application_state' => $workflow['application_state'],
            'workflow_stage' => $workflow['current_stage'],
            'final_outcome' => $workflow['final_outcome'],
            'workflow' => $workflow,
            'submission_version' => (int) data_get($application->submission_snapshot, 'version', 1),
            'submitted_profile' => data_get($application->submission_snapshot, 'current.applicant'),
            'document_checklist' => $application->document_checklist ?? [],
            'optional_document_checklist' => $application->optional_document_checklist
                ?? app(ScholarshipEligibilityService::class)->optionalDocumentRequirements($application->scholarship),
            'document_readiness' => $readiness,
            'bulk_advance_targets' => $this->bulkAdvanceTargets($application, $readiness),
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'application_answers' => $application->application_answers ?? [],
            'eligibility_score' => $application->eligibility_score,
            'eligibility_breakdown' => AcademicRequirement::withReferenceEquivalence($application->eligibility_breakdown),
            'dss_score' => $dss['score'],
            'dss_recommendation' => $dss['recommendation'],
            'dss_breakdown' => $dss,
            'dss_explanation' => $decisionSupport->explainApplication($application, $dss),
            'rubric_review' => ReviewRubric::result(
                $application->review_rubric_snapshot ?: ($application->scholarship?->review_rubric ?? []),
                $application->rubric_scores ?? [],
            ),
            'rubric_scored_at' => $application->rubric_scored_at?->format('M d, Y h:i A'),
            'status_progress' => $decisionSupport->statusProgress($application),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'correction_status' => $application->correction_status,
            'correction_message' => $application->correction_message,
            'correction_targets' => $application->correction_targets ?? [],
            'correction_response' => $application->correction_response,
            'correction_requested_at' => $application->correction_requested_at?->format('M d, Y h:i A'),
            'correction_responded_at' => $application->correction_responded_at?->format('M d, Y h:i A'),
            'correction_resolved_at' => $application->correction_resolved_at?->format('M d, Y h:i A'),
            'withdrawal_reason' => $application->withdrawal_reason,
            'withdrawn_at' => $application->withdrawn_at?->format('M d, Y h:i A'),
            'waitlist_position' => $application->waitlist_position,
            'waitlisted_at' => $application->waitlisted_at?->format('M d, Y h:i A'),
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('Y-m-d'),
            'distribution_scheduled_for' => $application->distribution_scheduled_for?->format('Y-m-d'),
            'distribution_scheduled_label' => $application->distribution_scheduled_for?->format('M d, Y'),
            'distribution_instructions' => $application->distribution_instructions,
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
            'assigned_reviewer' => $application->assignedReviewer
                ? $this->applicationReviewerPayload(
                    $application->assignedReviewer,
                    $application->scholarship?->provider_id ?? $application->assignedReviewer->providerOrganizationId(),
                )
                : null,
            'waiting_days' => $submittedAt
                ? (int) $submittedAt->startOfDay()->diffInDays(now()->startOfDay())
                : 0,
            'latest_document_uploaded_at' => $latestDocumentUploadedAt?->format('M d, Y h:i A'),
            'documents_changed_since_review' => (bool) (
                $latestDocumentUploadedAt
                && $application->reviewed_at
                && $latestDocumentUploadedAt->gt($application->reviewed_at)
            ),
            'recipient_agreement' => $recipientAgreement,
            'requires_student_response' => $recipientAgreement['requires_response'] ?? false,
            'can_receive_student_response' => $recipientAgreement !== null,
            'pre_screening_handoff' => PreScreeningHandoffRecord::make($application, $workflow, $readiness, $dss),
            'schedules' => $application->schedules
                ->sortBy('scheduled_at')
                ->map(fn (ApplicationSchedule $schedule) => ApplicationSchedulePayload::make($schedule))
                ->values(),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'applicant' => $this->applicantPayload($application, $includeApplicantProfile),
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship)
                : null,
            'exam' => $application->scholarship
                && in_array('exam', ScholarshipSelectionPlan::normalize($application->scholarship->selection_stages), true)
                ? $this->examPayload($application->scholarship)
                : null,
        ];
    }

    private function freshApplicationPayload(ScholarshipApplication $application): array
    {
        return $this->applicationPayload($application->fresh()->load([
            'applicant.studentProfile',
            'applicant.applicantVerificationDocuments',
            'documents.reviewer',
            'assignedReviewer.providerProfile',
            'statusHistories.actor',
            'scholarship',
        ]), true);
    }

    private function applicantPayload(ScholarshipApplication $application, bool $includeProfileDetails): array
    {
        $applicant = $application->applicant;
        $profile = $applicant?->studentProfile;
        $payload = [
            'name' => $applicant?->name,
            'email' => $applicant?->email,
            'username' => $applicant?->username,
            'contact_number' => $applicant?->contact_number,
            'citizenship_status' => $profile?->citizenship_status,
            'education_level' => $profile?->education_level,
            'school' => $profile?->school,
            'school_type' => $profile?->school_type,
            'learner_reference_number' => $profile?->learner_reference_number,
            'course_or_strand' => $profile?->course_or_strand,
            'year_level' => $profile?->year_level,
            'academic_year' => $profile?->academic_year,
            'academic_term' => $profile?->academic_term,
            'gwa' => $profile?->gwa,
            'grading_scale' => $profile?->grading_scale,
            'academic_result_source' => $profile?->academic_result_source,
            'academic_result_extracted_at' => $profile?->academic_result_extracted_at?->format('M d, Y h:i A'),
            'academic_scan_required' => $this->academicRecordOcrService->configured(),
            'income_bracket' => $profile?->income_bracket,
            'household_size' => $profile?->household_size,
            'preferred_categories' => $profile?->preferred_categories,
            'preferred_locations' => $profile?->preferred_locations,
            'willing_to_relocate' => $profile?->willing_to_relocate,
            'support_needs' => $profile?->support_needs,
            'current_scholarship_status' => $profile?->current_scholarship_status,
            'current_scholarship_details' => $profile?->current_scholarship_details,
            'platform_active_scholarships' => $this->eligibilityService->activePlatformScholarships(
                $applicant,
                $application->scholarship_id,
            ),
            'scholarship_goal' => $profile?->scholarship_goal,
            'achievements' => $profile?->achievements,
            'activities_and_responsibilities' => $profile?->activities_and_responsibilities,
            'location' => collect([
                $profile?->barangay,
                $profile?->city,
                $profile?->province,
                $profile?->region,
            ])->filter()->implode(', '),
            'latitude' => $profile?->latitude,
            'longitude' => $profile?->longitude,
            'profile_verification_status' => $applicant?->applicantAcademicVerificationStatus() ?? 'unsubmitted',
            'profile_verified_at' => $applicant?->applicantAcademicVerificationStatus() === 'approved'
                ? $profile?->verified_at?->format('M d, Y')
                : null,
            'profile_photo_url' => $profile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application)
                : null,
        ];

        if (! $includeProfileDetails) {
            return $payload;
        }

        return array_merge($payload, [
            'first_name' => $profile?->first_name,
            'middle_initial' => $profile?->middle_initial,
            'last_name' => $profile?->last_name,
            'suffix' => $profile?->suffix,
            'gender' => $profile?->gender,
            'birthdate' => $profile?->birthdate?->format('M d, Y'),
            'age' => $profile?->birthdate?->age,
            'account_managed_by' => $profile?->account_managed_by,
            'enrollment_status' => $profile?->enrollment_status,
            'address' => $profile?->address,
            'profile_updated_at' => $profile?->updated_at?->format('M d, Y h:i A'),
            'profile_verification_notes' => $profile?->verification_notes,
            'guardian_name' => $profile?->guardian_name,
            'guardian_relationship' => $profile?->guardian_relationship,
            'guardian_contact' => $profile?->guardian_contact,
            'guardian_email' => $profile?->guardian_email,
            'guardian_is_account_owner' => (bool) $profile?->guardian_is_account_owner,
            'profile_proofs' => ($applicant?->applicantVerificationDocuments ?? collect())
                ->whereIn('document_type', ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES)
                ->sortByDesc('uploaded_at')
                ->map(fn (ApplicantVerificationDocument $document) => $this->applicantProfileProofPayload($application, $document))
                ->values(),
        ]);
    }

    private function documentReadiness(ScholarshipApplication $application): array
    {
        return app(ScholarshipEligibilityService::class)
            ->applicationDocumentReadiness($application);
    }

    private function bulkAdvanceTargets(ScholarshipApplication $application, ?array $readiness = null): array
    {
        if (in_array($application->correction_status, ['requested', 'submitted'], true)) {
            return [];
        }

        $readiness ??= $this->documentReadiness($application);

        if (! ($readiness['ready'] ?? false)) {
            return [];
        }

        $workflow = $this->workflowService->payload($application);

        if ($workflow['current_stage'] === 'screening') {
            return ['pass_prescreening'];
        }

        if ($workflow['current_stage'] === 'formal_application') {
            return ['pass_stage'];
        }

        if (ScholarshipSelectionPlan::isSchedulable($workflow['current_stage'])) {
            return $this->stageActivityIsComplete($application, $workflow['current_stage'])
                ? ['pass_stage']
                : [];
        }

        return $workflow['current_stage'] === 'decision' ? ['selected'] : [];
    }

    private function selectedRecipientApplications(Scholarship $scholarship): EloquentCollection
    {
        return ScholarshipApplication::query()
            ->with([
                'applicant.studentProfile',
                'monitoringSubmissions.reviewer',
                'monitoringSubmissions.reviews.reviewer',
                'supportDecisions.decider',
            ])
            ->where('scholarship_id', $scholarship->id)
            ->where(function (Builder $query): void {
                $query->where('final_outcome', 'selected')
                    ->orWhereIn('status', self::AWARD_SLOT_STATUSES);
            })
            ->where('status', '!=', 'benefits_terminated')
            ->where(function (Builder $query): void {
                $query->whereNull('student_response_status')
                    ->orWhere('student_response_status', '!=', 'declined');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (ScholarshipApplication $application): bool => ! in_array(
                $application->supportDecisions->first()?->decision,
                ['completed', 'terminated'],
                true,
            ));
    }

    private function supportRecipientApplications(Scholarship $scholarship): EloquentCollection
    {
        return ScholarshipApplication::query()
            ->with([
                'applicant.studentProfile',
                'monitoringSubmissions',
                'benefitReleaseRecords.release',
                'supportDecisions.decider',
            ])
            ->where('scholarship_id', $scholarship->id)
            ->where(function (Builder $query): void {
                $query->where('final_outcome', 'selected')
                    ->orWhereIn('status', [...self::AWARD_SLOT_STATUSES, 'benefits_terminated']);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('student_response_status')
                    ->orWhere('student_response_status', '!=', 'declined');
            })
            ->orderBy('id')
            ->get();
    }

    private function recipientSupportPayload(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
    ): array {
        $application->loadMissing([
            'applicant.studentProfile',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
        ]);
        $latestDecision = $application->supportDecisions->first();
        $eligibility = $this->recipientSupportEligibility($application, $cycles, now());
        $releasedCount = $application->benefitReleaseRecords->where('status', 'released')->count();
        $agreement = RecipientAgreement::payload($application);
        $supportStatus = $latestDecision?->decision
            ?? ($application->status === 'benefits_terminated' ? 'terminated' : 'active');

        return [
            'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'name' => $application->applicant?->name ?? 'Applicant',
            'email' => $application->applicant?->email,
            'application_url' => route('provider.applications.show', $application, false),
            'agreement_status' => $agreement['status'] ?? 'pending',
            'agreement_status_label' => $agreement['status_label'] ?? 'Awaiting response',
            'support_status' => $supportStatus,
            'support_status_label' => match ($supportStatus) {
                'renewed' => 'Renewed support',
                'completed' => 'Program completed',
                'terminated' => 'Support ended early',
                default => 'Active recipient',
            },
            'is_closed' => in_array($supportStatus, ['completed', 'terminated'], true),
            'renewal_eligible' => $eligibility['eligible'],
            'renewal_eligibility_label' => $eligibility['label'],
            'renewal_eligibility_reason' => $eligibility['reason'],
            'requirements_met' => $eligibility['requirements_met'],
            'requirements_total' => $eligibility['requirements_total'],
            'release_count' => $application->benefitReleaseRecords->count(),
            'released_count' => $releasedCount,
            'latest_decision' => $latestDecision ? $this->recipientSupportDecisionPayload($latestDecision) : null,
            'decisions' => $application->supportDecisions
                ->map(fn (RecipientSupportDecision $decision): array => $this->recipientSupportDecisionPayload($decision))
                ->values(),
        ];
    }

    private function recipientProgramSummaryPayload(
        Collection $cycles,
        Collection $releases,
        Collection $recipients,
    ): array {
        $today = now()->startOfDay();
        $attention = collect();

        foreach ($recipients as $recipient) {
            if (! $recipient['is_closed'] && $recipient['agreement_status'] !== 'accepted') {
                $attention->push([
                    'type' => 'agreement',
                    'type_label' => 'Agreement',
                    'title' => $recipient['name'],
                    'detail' => 'Recipient agreement is awaiting acceptance.',
                    'status_label' => $recipient['agreement_status_label'],
                    'application_url' => $recipient['application_url'],
                    'priority' => 2,
                ]);
            }
        }

        foreach ($cycles as $cycle) {
            foreach ($cycle['recipients'] as $recipient) {
                $submission = $recipient['submission'];
                $reviewStatus = data_get($submission, 'review_status');

                if ($submission && $reviewStatus === 'pending') {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Academic review',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].' has a submitted grade record waiting for review.',
                        'status_label' => 'Review needed',
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                } elseif ($submission && in_array($reviewStatus, ['not_met', 'needs_correction'], true)) {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Academic requirement',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].': '.data_get($submission, 'review_status_label').'.',
                        'status_label' => data_get($submission, 'review_status_label'),
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                } elseif (! $submission && $cycle['is_past_due']) {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Overdue record',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].' was due '.$cycle['due_label'].' and has no submission.',
                        'status_label' => 'Overdue',
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                }
            }
        }

        foreach ($releases as $release) {
            $releaseIsDue = filled($release['release_date'])
                && CarbonImmutable::parse($release['release_date'])->startOfDay()->lte($today);

            foreach ($release['records'] as $record) {
                if (in_array($record['status'], ['missed', 'withheld'], true)) {
                    $attention->push([
                        'type' => 'release',
                        'type_label' => 'Benefit release',
                        'title' => $record['name'],
                        'detail' => $release['title'].': '.$record['status_label'].'.',
                        'status_label' => $record['status_label'],
                        'application_url' => $record['application_url'],
                        'priority' => 1,
                    ]);
                } elseif ($releaseIsDue && in_array($record['status'], ['scheduled', 'prepared'], true)) {
                    $attention->push([
                        'type' => 'release',
                        'type_label' => 'Release result',
                        'title' => $record['name'],
                        'detail' => $release['title'].' is due and still needs a final release result.',
                        'status_label' => 'Result needed',
                        'application_url' => $record['application_url'],
                        'priority' => 2,
                    ]);
                }
            }
        }

        $upcoming = collect();
        foreach ($cycles as $cycle) {
            if ($cycle['status'] !== 'open' || blank($cycle['due_at'])) {
                continue;
            }

            $dueAt = CarbonImmutable::parse($cycle['due_at'])->startOfDay();
            if ($dueAt->lt($today)) {
                continue;
            }

            $upcoming->push([
                'type' => 'monitoring',
                'type_label' => 'Monitoring deadline',
                'title' => $cycle['title'],
                'detail' => $cycle['pending_count'].' awaiting upload; '.$cycle['submitted_count'].' received.',
                'date' => $cycle['due_at'],
                'date_label' => $cycle['due_label'],
            ]);
        }
        foreach ($releases as $release) {
            if (blank($release['release_date']) || $release['status'] === 'completed') {
                continue;
            }

            $releaseAt = CarbonImmutable::parse($release['release_date'])->startOfDay();
            if ($releaseAt->lt($today)) {
                continue;
            }

            $upcoming->push([
                'type' => 'release',
                'type_label' => 'Benefit release',
                'title' => $release['title'],
                'detail' => $release['pending_count'].' recipient records still pending.',
                'date' => $release['release_date'],
                'date_label' => $release['release_label'],
            ]);
        }

        $attention = $attention
            ->sortBy(fn (array $item): string => $item['priority'].'-'.$item['title'])
            ->values()
            ->map(function (array $item): array {
                unset($item['priority']);

                return $item;
            });
        $upcoming = $upcoming->sortBy('date')->values();

        return [
            'recipients' => [
                'total' => $recipients->count(),
                'active' => $recipients->where('is_closed', false)->count(),
                'agreement_pending' => $recipients
                    ->where('is_closed', false)
                    ->where('agreement_status', '!=', 'accepted')
                    ->count(),
                'renewal_ready' => $recipients
                    ->where('is_closed', false)
                    ->where('renewal_eligible', true)
                    ->count(),
            ],
            'outcomes' => [
                'active' => $recipients->where('support_status', 'active')->count(),
                'renewed' => $recipients->where('support_status', 'renewed')->count(),
                'completed' => $recipients->where('support_status', 'completed')->count(),
                'terminated' => $recipients->where('support_status', 'terminated')->count(),
            ],
            'monitoring' => [
                'periods' => $cycles->count(),
                'open_periods' => $cycles->where('status', 'open')->count(),
                'records_received' => $cycles->sum('submitted_count'),
                'records_reviewed' => $cycles->sum('reviewed_count'),
            ],
            'releases' => [
                'schedules' => $releases->count(),
                'released' => $releases->sum('released_count'),
                'pending' => $releases->sum('pending_count'),
                'exceptions' => $releases->sum('exception_count'),
            ],
            'attention_count' => $attention->count(),
            'upcoming_count' => $upcoming->count(),
            'attention' => $attention,
            'upcoming' => $upcoming,
        ];
    }

    private function recipientSupportRecordPayload(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'scholarship.provider.providerProfile',
            'scholarship.monitoringCycles.creator',
            'applicant.studentProfile',
            'monitoringSubmissions.cycle',
            'monitoringSubmissions.reviewer',
            'monitoringSubmissions.reviews.reviewer',
            'benefitReleaseRecords.release',
            'benefitReleaseRecords.recorder',
            'supportDecisions.decider',
        ]);

        $cycles = $application->scholarship->monitoringCycles;
        $support = $this->recipientSupportPayload($application, $cycles);
        $agreement = RecipientAgreement::payload($application);
        $monitoringRecords = $cycles->map(function (RecipientMonitoringCycle $cycle) use ($application): array {
            $submission = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $cycle->id);

            return [
                'id' => $cycle->id,
                'title' => $cycle->title,
                'period_label' => collect([$cycle->academic_period, $cycle->school_year])->filter()->implode(' - ')
                    ?: Str::headline($cycle->period_type),
                'requirement_label' => AcademicRequirement::requirementLabel($cycle->minimum_grade, $cycle->grading_scale),
                'due_label' => $cycle->due_at?->format('M d, Y'),
                'submission' => $submission
                    ? $this->recipientMonitoringSubmissionPayload($submission, $cycle)
                    : null,
            ];
        })->values();
        $releaseRecords = $application->benefitReleaseRecords
            ->sortByDesc(fn (RecipientBenefitReleaseRecord $record): int => $record->release?->release_at?->timestamp ?? 0)
            ->map(function (RecipientBenefitReleaseRecord $record): array {
                $release = $record->release;

                return [
                    'id' => $record->id,
                    'title' => $release?->title ?? 'Benefit release',
                    'benefit_description' => $release?->benefit_description,
                    'amount_label' => $release?->amount !== null
                        ? 'PHP '.number_format((float) $release->amount, 2)
                        : null,
                    'release_label' => $release?->release_at?->format('M d, Y h:i A'),
                    'method_label' => match ($release?->release_method) {
                        'bank_transfer' => 'Bank transfer',
                        'e_wallet' => 'E-wallet',
                        'other' => 'Other arrangement',
                        default => 'In person',
                    },
                    'location' => $release?->location,
                    'status' => $record->status,
                    'status_label' => match ($record->status) {
                        'released' => 'Released',
                        'prepared' => 'Prepared',
                        'missed' => 'Missed',
                        'withheld' => 'Withheld',
                        default => 'Scheduled',
                    },
                    'originals_verified' => $record->originals_verified,
                    'notes' => $record->notes,
                    'recorded_by' => $record->recorder?->name,
                    'recorded_at' => $record->recorded_at?->format('M d, Y h:i A'),
                    'receipt' => $record->receipt_path ? [
                        'id' => $record->id,
                        'original_name' => $record->receipt_original_name,
                        'size' => $record->receipt_size,
                        'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                    ] : null,
                ];
            })
            ->values();

        $timeline = collect();
        if ($agreement) {
            $agreementDate = $application->student_responded_at
                ?? $application->outcome_at
                ?? $application->submitted_at;
            $timeline->push([
                'type' => 'agreement',
                'title' => $agreement['status'] === 'accepted' ? 'Recipient agreement accepted' : 'Recipient agreement issued',
                'description' => $agreement['response_note']
                    ?: ($agreement['status'] === 'accepted'
                        ? 'The recipient accepted the recorded support terms.'
                        : 'The recipient has not accepted the support terms yet.'),
                'status' => $agreement['status'],
                'status_label' => $agreement['status_label'],
                'occurred_label' => $agreement['responded_at'] ?? $application->outcome_at?->format('M d, Y h:i A'),
                'sort_at' => $agreementDate?->timestamp ?? 0,
                'file' => null,
            ]);
        }

        foreach ($monitoringRecords as $record) {
            $submission = $record['submission'];
            $submitted = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $record['id']);
            $eventDate = $submitted?->reviewed_at ?? $submitted?->submitted_at;
            $timeline->push([
                'type' => 'monitoring',
                'title' => $record['title'],
                'description' => $submission
                    ? collect([
                        $submission['grade_label'] ? 'Recorded result: '.$submission['grade_label'].'.' : null,
                        'Requirement: '.$record['requirement_label'].'.',
                        $submission['review_notes'],
                    ])->filter()->implode(' ')
                    : 'Academic record requested; due '.$record['due_label'].'.',
                'status' => $submission['review_status'] ?? 'pending',
                'status_label' => $submission['review_status_label'] ?? 'Awaiting submission',
                'occurred_label' => $submission['reviewed_at'] ?? $submission['submitted_at'] ?? $record['due_label'],
                'sort_at' => $eventDate?->timestamp ?? 0,
                'file' => $submission ? [
                    'id' => $submission['id'],
                    'original_name' => $submission['original_name'],
                    'size' => $submission['size'],
                    'view_url' => $submission['view_url'],
                ] : null,
            ]);
        }

        foreach ($application->benefitReleaseRecords as $record) {
            $release = $record->release;
            $eventDate = $record->recorded_at ?? $release?->release_at;
            $timeline->push([
                'type' => 'release',
                'title' => $release?->title ?? 'Benefit release',
                'description' => collect([
                    $release?->benefit_description,
                    $release?->amount !== null ? 'Value: PHP '.number_format((float) $release->amount, 2).'.' : null,
                    $record->notes,
                ])->filter()->implode(' '),
                'status' => $record->status,
                'status_label' => match ($record->status) {
                    'released' => 'Released',
                    'prepared' => 'Prepared',
                    'missed' => 'Missed',
                    'withheld' => 'Withheld',
                    default => 'Scheduled',
                },
                'occurred_label' => $eventDate?->format('M d, Y h:i A'),
                'sort_at' => $eventDate?->timestamp ?? 0,
                'file' => $record->receipt_path ? [
                    'id' => $record->id,
                    'original_name' => $record->receipt_original_name,
                    'size' => $record->receipt_size,
                    'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                ] : null,
            ]);
        }

        foreach ($application->supportDecisions as $decision) {
            $payload = $this->recipientSupportDecisionPayload($decision);
            $timeline->push([
                'type' => 'decision',
                'title' => $payload['decision_label'],
                'description' => $payload['reason'] ?: $payload['next_period_terms'],
                'status' => $decision->decision,
                'status_label' => $payload['decision_label'],
                'occurred_label' => $payload['decided_at'],
                'sort_at' => $decision->decided_at?->timestamp ?? 0,
                'file' => null,
            ]);
        }

        $timeline = $timeline
            ->sortByDesc('sort_at')
            ->values()
            ->map(function (array $event): array {
                unset($event['sort_at']);

                return $event;
            });

        return [
            'recipient' => [
                'id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
                'application_url' => route('provider.applications.show', $application, false),
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $application->scholarship?->title,
                'provider_name' => $application->scholarship?->provider?->provider_name
                    ?? $application->scholarship?->provider?->name,
            ],
            'support' => $support,
            'agreement' => $agreement,
            'monitoring_records' => $monitoringRecords,
            'benefit_releases' => $releaseRecords,
            'decisions' => $application->supportDecisions
                ->map(fn (RecipientSupportDecision $decision): array => $this->recipientSupportDecisionPayload($decision))
                ->values(),
            'summary' => [
                'monitoring_total' => $monitoringRecords->count(),
                'monitoring_confirmed' => $application->monitoringSubmissions
                    ->whereIn('review_status', ['met', 'excused'])
                    ->count(),
                'release_total' => $releaseRecords->count(),
                'released_total' => $application->benefitReleaseRecords->where('status', 'released')->count(),
                'decision_total' => $application->supportDecisions->count(),
            ],
            'timeline' => $timeline,
        ];
    }

    private function recipientSupportEligibility(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
        mixed $effectiveOn,
    ): array {
        $latestDecision = $application->supportDecisions->first();
        if ($latestDecision && in_array($latestDecision->decision, ['completed', 'terminated'], true)) {
            return [
                'eligible' => false,
                'label' => 'Support closed',
                'reason' => 'This recipient support record is already closed.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        $monitoring = $this->recipientBenefitReleaseEligibility($application, $cycles, $effectiveOn);
        if (! $monitoring['eligible']) {
            return $monitoring;
        }

        $effectiveDate = CarbonImmutable::parse($effectiveOn)->endOfDay();
        $pendingReleases = $application->benefitReleaseRecords
            ->filter(function (RecipientBenefitReleaseRecord $record) use ($effectiveDate): bool {
                $releaseAt = $record->release?->release_at;

                return $releaseAt !== null
                    && $releaseAt->lte($effectiveDate)
                    && in_array($record->status, ['scheduled', 'prepared'], true);
            });

        if ($pendingReleases->isNotEmpty()) {
            return [
                'eligible' => false,
                'label' => 'Release record pending',
                'reason' => 'Record the result of all benefit releases due before renewing support.',
                'requirements_met' => $monitoring['requirements_met'],
                'requirements_total' => $monitoring['requirements_total'],
            ];
        }

        return [
            ...$monitoring,
            'label' => 'Ready for renewal',
            'reason' => $monitoring['requirements_total'] > 0
                ? 'Due monitoring requirements are confirmed and release records are up to date.'
                : 'No monitoring requirement is due and release records are up to date.',
        ];
    }

    private function recipientSupportDecisionPayload(RecipientSupportDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'decision' => $decision->decision,
            'decision_label' => match ($decision->decision) {
                'renewed' => 'Support renewed',
                'completed' => 'Program completed',
                'terminated' => 'Support ended early',
                default => Str::headline($decision->decision),
            },
            'effective_on' => $decision->effective_on?->format('Y-m-d'),
            'effective_label' => $decision->effective_on?->format('M d, Y'),
            'support_ends_on' => $decision->support_ends_on?->format('Y-m-d'),
            'support_ends_label' => $decision->support_ends_on?->format('M d, Y'),
            'next_review_on' => $decision->next_review_on?->format('Y-m-d'),
            'next_review_label' => $decision->next_review_on?->format('M d, Y'),
            'reason' => $decision->reason,
            'next_period_terms' => $decision->next_period_terms,
            'decided_by' => $decision->decider?->name,
            'decided_at' => $decision->decided_at?->format('M d, Y h:i A'),
        ];
    }

    private function recipientMonitoringCyclePayload(
        RecipientMonitoringCycle $cycle,
        EloquentCollection $applications,
    ): array {
        $cycle->loadMissing(['creator', 'submissions.reviewer', 'submissions.reviews.reviewer']);
        $isPastDue = $cycle->due_at?->isBefore(now()->startOfDay()) ?? false;
        $recipients = $applications->map(function (ScholarshipApplication $application) use ($cycle): array {
            $submission = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $cycle->id);
            $agreement = RecipientAgreement::payload($application);

            return [
                'application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'agreement_status' => $agreement['status'] ?? 'pending',
                'benefits_active' => $application->status !== 'benefits_terminated',
                'application_url' => route('provider.applications.show', $application, false),
                'submission' => $submission
                    ? $this->recipientMonitoringSubmissionPayload($submission, $cycle)
                    : null,
            ];
        })->values();

        return [
            'id' => $cycle->id,
            'title' => $cycle->title,
            'period_type' => $cycle->period_type,
            'academic_period' => $cycle->academic_period,
            'school_year' => $cycle->school_year,
            'opens_at' => $cycle->opens_at?->format('Y-m-d'),
            'opens_label' => $cycle->opens_at?->format('M d, Y'),
            'due_at' => $cycle->due_at?->format('Y-m-d'),
            'due_label' => $cycle->due_at?->format('M d, Y'),
            'minimum_grade' => $cycle->minimum_grade,
            'grading_scale' => $cycle->grading_scale,
            'requirement_label' => AcademicRequirement::requirementLabel($cycle->minimum_grade, $cycle->grading_scale),
            'instructions' => $cycle->instructions,
            'status' => $isPastDue ? 'closed' : $cycle->status,
            'is_past_due' => $isPastDue,
            'published_at' => $cycle->published_at?->format('M d, Y h:i A'),
            'created_by' => $cycle->creator?->name,
            'recipient_count' => $recipients->count(),
            'submitted_count' => $recipients->whereNotNull('submission')->count(),
            'pending_count' => $recipients->whereNull('submission')->count(),
            'reviewed_count' => $recipients->filter(
                fn (array $recipient): bool => filled(data_get($recipient, 'submission.reviewed_at')),
            )->count(),
            'action_needed_count' => $recipients->filter(
                fn (array $recipient): bool => in_array(
                    data_get($recipient, 'submission.review_status'),
                    ['not_met', 'needs_correction'],
                    true,
                ),
            )->count(),
            'recipients' => $recipients,
        ];
    }

    private function recipientMonitoringSubmissionPayload(
        RecipientMonitoringSubmission $submission,
        RecipientMonitoringCycle $cycle,
    ): array {
        $grade = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grade
            : ($submission->ocr_grade ?? $submission->reported_grade);
        $scale = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grading_scale
            : ($submission->ocr_grading_scale ?? $submission->reported_grading_scale);

        return [
            'id' => $submission->id,
            'original_name' => $submission->original_name,
            'size' => $submission->size,
            'submitted_at' => $submission->submitted_at?->format('M d, Y h:i A'),
            'ocr_status' => $submission->ocr_status,
            'ocr_provider' => $submission->ocr_provider,
            'ocr_grade' => $submission->ocr_grade,
            'ocr_grading_scale' => $submission->ocr_grading_scale,
            'ocr_label' => $submission->ocr_label,
            'ocr_message' => $submission->ocr_message,
            'grade' => $grade,
            'grading_scale' => $scale,
            'grade_source' => $submission->grade_source,
            'grade_label' => AcademicRequirement::studentLabel($grade, $scale),
            'review_status' => $submission->review_status ?? 'pending',
            'review_status_label' => match ($submission->review_status) {
                'met' => 'Requirement met',
                'not_met' => 'Requirement not met',
                'needs_correction' => 'Needs replacement',
                'excused' => 'Exception approved',
                default => 'Pending review',
            },
            'review_notes' => $submission->review_notes,
            'reviewed_by' => $submission->reviewer?->name,
            'reviewed_at' => $submission->reviewed_at?->format('M d, Y h:i A'),
            'reviews' => $submission->reviews->map(fn ($review): array => [
                'id' => $review->id,
                'decision' => $review->decision,
                'decision_label' => match ($review->decision) {
                    'met' => 'Requirement met',
                    'not_met' => 'Requirement not met',
                    'needs_correction' => 'Replacement requested',
                    'excused' => 'Exception approved',
                    default => Str::headline($review->decision),
                },
                'notes' => $review->notes,
                'reviewed_by' => $review->reviewer?->name,
                'decided_at' => $review->decided_at?->format('M d, Y h:i A'),
            ])->values(),
            'comparison' => AcademicRequirement::match(
                $grade,
                $scale,
                $cycle->minimum_grade,
                $cycle->grading_scale,
            ),
            'view_url' => route('provider.monitoring-submissions.view', $submission, false),
        ];
    }

    private function recipientReleaseCandidatesPayload(
        EloquentCollection $applications,
        EloquentCollection $cycles,
        mixed $releaseAt,
    ): Collection {
        return $applications->map(function (ScholarshipApplication $application) use ($cycles, $releaseAt): array {
            $eligibility = $this->recipientBenefitReleaseEligibility($application, $cycles, $releaseAt);

            return [
                'application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'eligible' => $eligibility['eligible'],
                'eligibility_label' => $eligibility['label'],
                'eligibility_reason' => $eligibility['reason'],
                'requirements_met' => $eligibility['requirements_met'],
                'requirements_total' => $eligibility['requirements_total'],
                'application_url' => route('provider.applications.show', $application, false),
            ];
        })->values();
    }

    private function recipientBenefitReleaseEligibility(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
        mixed $releaseAt,
    ): array {
        if ($application->student_response_status !== 'accepted') {
            return [
                'eligible' => false,
                'label' => 'Agreement pending',
                'reason' => 'The recipient agreement has not been accepted.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        if ($application->status === 'benefits_terminated') {
            return [
                'eligible' => false,
                'label' => 'Benefits stopped',
                'reason' => 'This recipient is no longer receiving program benefits.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        $releaseDate = CarbonImmutable::parse($releaseAt)->startOfDay();
        $applicableCycles = $cycles->filter(
            fn (RecipientMonitoringCycle $cycle): bool => in_array($cycle->status, ['open', 'closed'], true)
                && $cycle->due_at !== null
                && ! $cycle->due_at->isAfter($releaseDate),
        );
        $met = 0;
        $unresolved = [];

        foreach ($applicableCycles as $cycle) {
            $submission = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $cycle->id);
            $reviewStatus = $submission?->review_status;

            if (in_array($reviewStatus, ['met', 'excused'], true)) {
                $met++;

                continue;
            }

            $unresolved[] = match ($reviewStatus) {
                'not_met' => "{$cycle->title} was marked not met.",
                'needs_correction' => "{$cycle->title} needs a replacement record.",
                'pending' => "{$cycle->title} is waiting for provider review.",
                default => "{$cycle->title} has not been submitted.",
            };
        }

        $total = $applicableCycles->count();
        $eligible = $unresolved === [];

        return [
            'eligible' => $eligible,
            'label' => $eligible ? 'Ready for release' : 'Monitoring action needed',
            'reason' => $eligible
                ? ($total > 0 ? 'All monitoring requirements due before this release are confirmed.' : 'No monitoring requirement is due before this release.')
                : implode(' ', $unresolved),
            'requirements_met' => $met,
            'requirements_total' => $total,
        ];
    }

    private function recipientBenefitReleasePayload(RecipientBenefitRelease $release): array
    {
        $release->loadMissing(['creator', 'records.applicant', 'records.recorder']);
        $statusLabels = [
            'scheduled' => 'Scheduled',
            'prepared' => 'Prepared',
            'released' => 'Released',
            'missed' => 'Missed',
            'withheld' => 'Withheld',
        ];

        return [
            'id' => $release->id,
            'title' => $release->title,
            'release_at' => $release->release_at?->format('Y-m-d\TH:i'),
            'release_date' => $release->release_at?->format('Y-m-d'),
            'release_label' => $release->release_at?->format('M d, Y h:i A'),
            'benefit_description' => $release->benefit_description,
            'amount' => $release->amount,
            'amount_label' => $release->amount !== null ? 'PHP '.number_format((float) $release->amount, 2) : null,
            'release_method' => $release->release_method,
            'release_method_label' => match ($release->release_method) {
                'bank_transfer' => 'Bank transfer',
                'e_wallet' => 'E-wallet',
                'other' => 'Other arrangement',
                default => 'In person',
            },
            'location' => $release->location,
            'instructions' => $release->instructions,
            'requires_original_verification' => $release->requires_original_verification,
            'status' => $release->status,
            'status_label' => Str::headline($release->status),
            'published_at' => $release->published_at?->format('M d, Y h:i A'),
            'created_by' => $release->creator?->name,
            'recipient_count' => $release->records->count(),
            'released_count' => $release->records->where('status', 'released')->count(),
            'pending_count' => $release->records->whereIn('status', ['scheduled', 'prepared'])->count(),
            'exception_count' => $release->records->whereIn('status', ['missed', 'withheld'])->count(),
            'records' => $release->records->map(fn (RecipientBenefitReleaseRecord $record): array => [
                'id' => $record->id,
                'application_id' => $record->scholarship_application_id,
                'applicant_id' => $record->applicant_id,
                'name' => $record->applicant?->name ?? 'Applicant',
                'email' => $record->applicant?->email,
                'status' => $record->status,
                'status_label' => $statusLabels[$record->status] ?? Str::headline($record->status),
                'originals_verified' => $record->originals_verified,
                'notes' => $record->notes,
                'recorded_by' => $record->recorder?->name,
                'recorded_at' => $record->recorded_at?->format('M d, Y h:i A'),
                'released_at' => $record->released_at?->format('M d, Y h:i A'),
                'application_url' => route('provider.applications.show', $record->scholarship_application_id, false),
                'receipt' => $record->receipt_path ? [
                    'id' => $record->id,
                    'original_name' => $record->receipt_original_name,
                    'size' => $record->receipt_size,
                    'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                ] : null,
            ])->values(),
        ];
    }

    private function scholarshipPayload(Scholarship $scholarship): array
    {
        $scholarship->loadMissing('events');

        return [
            'id' => $scholarship->id,
            'image_path' => $scholarship->image_path,
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'program_cycle' => $scholarship->program_cycle,
            'description' => $scholarship->description,
            'provider_objectives' => $scholarship->provider_objectives ?? [],
            'provider_objective_notes' => $scholarship->provider_objective_notes,
            'eligibility' => $scholarship->eligibility,
            'eligibility_conditions' => $scholarship->eligibility_conditions ?? [],
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_courses' => $scholarship->eligible_courses,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
            'exclude_current_scholarship_recipients' => (bool) $scholarship->exclude_current_scholarship_recipients,
            'location_name' => $scholarship->location_name,
            'location_address' => $scholarship->location_address,
            'latitude' => $scholarship->latitude,
            'longitude' => $scholarship->longitude,
            'map_url' => $this->mapUrl($scholarship),
            'embed_map_url' => $this->embedMapUrl($scholarship),
            'requirements' => $scholarship->requirements,
            'optional_requirements' => $scholarship->optional_requirements,
            'post_qualification_requirements' => $scholarship->post_qualification_requirements,
            'handoff_mode' => $scholarship->handoff_mode,
            'handoff_instructions' => $scholarship->handoff_instructions,
            'handoff_deadline' => $scholarship->handoff_deadline?->format('Y-m-d'),
            'handoff_location_name' => $scholarship->handoff_location_name,
            'handoff_location_address' => $scholarship->handoff_location_address,
            'handoff_url' => $scholarship->handoff_url,
            'handoff_map_url' => $this->handoffMapUrl($scholarship),
            'review_rubric' => $scholarship->review_rubric ?? [],
            'application_questions' => $scholarship->application_questions ?? [],
            'benefits' => $scholarship->benefitPayload(),
            'benefit_summary' => $scholarship->benefitSummary(),
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
            'minimum_grade_label' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
            'slots_available' => $scholarship->slots_available,
            'applications_count' => $scholarship->applications_count ?? $scholarship->applications()->count(),
            'pending_review_applications_count' => $scholarship->pending_review_applications_count
                ?? $scholarship->applications()->whereIn('status', ['submitted', 'under_review'])->count(),
            'awarded_slots_count' => $scholarship->awarded_slots_count
                ?? $scholarship->applications()->whereIn('status', self::AWARD_SLOT_STATUSES)->count(),
            'application_mode' => $scholarship->application_mode,
            'selection_stages' => ScholarshipSelectionPlan::normalize($scholarship->selection_stages),
            'program_events' => $scholarship->events
                ->where('status', 'scheduled')
                ->sortBy('scheduled_at')
                ->map(fn (ScholarshipEvent $event): array => ScholarshipEventPayload::make($event))
                ->values(),
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
            'recipient_agreement' => $scholarship->recipient_agreement,
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'application_opens_at' => $scholarship->application_opens_at?->format('Y-m-d'),
            'expected_results_at' => $scholarship->expected_results_at?->format('Y-m-d'),
            'support_starts_at' => $scholarship->support_starts_at?->format('Y-m-d'),
            'support_ends_at' => $scholarship->support_ends_at?->format('Y-m-d'),
            'official_program_url' => $scholarship->official_program_url,
            'contact_person' => $scholarship->contact_person,
            'contact_department' => $scholarship->contact_department,
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => $scholarship->status,
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'views_count' => $scholarship->views_count,
            'created_at' => $scholarship->created_at?->format('M d, Y'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y'),
        ];
    }

    private function scholarshipAnnouncementPayload(ScholarshipAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'audience' => $announcement->audience,
            'audience_label' => match ($announcement->audience) {
                'active_applicants' => 'All active applicants',
                'under_review' => 'Applicants under review',
                'qualified_applicants' => 'Qualified applicants and alternates',
                'selected_recipients' => 'Selected recipients',
                default => Str::headline($announcement->audience),
            },
            'title' => $announcement->title,
            'message' => $announcement->message,
            'recipient_count' => $announcement->recipient_count,
            'publisher' => $announcement->publisher?->name,
            'published_at' => $announcement->published_at?->format('M d, Y h:i A'),
        ];
    }

    private function examPayload(Scholarship $scholarship): array
    {
        $scholarship->loadMissing('events');
        $event = $scholarship->events->firstWhere('type', 'exam');

        return [
            'title' => $event?->title ?: "{$scholarship->title} exam",
            'assessment_type' => 'qualifying_exam',
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'description' => 'This exam is conducted and graded by the scholarship provider outside the portal.',
            'delivery_mode' => $event?->mode ?? 'provider_managed',
            'venue' => $event?->venue ?: $event?->location_address,
            'instructions' => $event?->instructions,
        ];
    }

    private function duplicateScholarshipTitle(int $providerId, string $title): string
    {
        $baseTitle = preg_replace('/\s+\(Copy(?:\s+\d+)?\)$/', '', $title) ?: $title;
        $candidate = "{$baseTitle} (Copy)";
        $counter = 2;

        while (
            Scholarship::query()
                ->where('provider_id', $providerId)
                ->where('title', $candidate)
                ->exists()
        ) {
            $candidate = "{$baseTitle} (Copy {$counter})";
            $counter++;
        }

        return $candidate;
    }

    private function requestedProviderScholarship(Request $request): ?Scholarship
    {
        $scholarshipId = $request->integer('scholarship_id');

        if (! $scholarshipId) {
            return null;
        }

        return $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->findOrFail($scholarshipId);
    }

    private function providerScholarshipsQuery(User $actor): Builder
    {
        return $this->applyProviderScholarshipScope(Scholarship::query(), $actor);
    }

    private function applyProviderScholarshipScope(Builder $query, User $actor): Builder
    {
        $query->where('provider_id', $actor->providerOrganizationId());

        if ($actor->hasLimitedProviderProgramAccess()) {
            $query->whereIn('scholarships.id', $actor->assignedProviderProgramIds());
        }

        return $query;
    }

    private function providerApplicationsQuery(User $actor): Builder
    {
        return ScholarshipApplication::query()
            ->whereHas('scholarship', fn (Builder $query) => $this->applyProviderScholarshipScope($query, $actor));
    }

    private function includeProgramInStaffScope(User $actor, Scholarship $scholarship): void
    {
        if (! $actor->hasLimitedProviderProgramAccess()) {
            return;
        }

        $actor->forceFill([
            'assigned_program_ids' => collect($actor->assignedProviderProgramIds())
                ->push($scholarship->id)
                ->unique()
                ->values()
                ->all(),
        ])->save();
    }

    private function reviewNavigationPayload(
        ScholarshipApplication $application,
        ?string $reviewedStage = null,
    ): array {
        $remainingApplicationsQuery = ScholarshipApplication::query()
            ->with(['applicant', 'scholarship', 'stageProgresses'])
            ->where('scholarship_id', $application->scholarship_id)
            ->where('id', '!=', $application->id);

        if ($reviewedStage === null) {
            $remainingApplicationsQuery->whereIn('status', self::REVIEW_DECISION_STATUSES);
        } else {
            $remainingApplicationsQuery->where(function (Builder $query) use ($reviewedStage): void {
                $query->where('workflow_stage', $reviewedStage)
                    ->orWhereNull('workflow_stage');
            });
        }

        $remainingApplications = $remainingApplicationsQuery
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        if ($reviewedStage !== null) {
            $remainingApplications = $remainingApplications
                ->filter(function (ScholarshipApplication $candidate) use ($reviewedStage): bool {
                    $workflow = $this->workflowService->payload($candidate);

                    return ! $workflow['is_closed'] && $workflow['current_stage'] === $reviewedStage;
                })
                ->values();
        }

        $nextApplication = $remainingApplications->first();

        return [
            'remaining_count' => $remainingApplications->count(),
            'stage' => $reviewedStage,
            'stage_label' => $reviewedStage !== null
                ? ScholarshipSelectionPlan::label($reviewedStage)
                : 'Review',
            'list_url' => route('provider.applications', [
                'scholarship_id' => $application->scholarship_id,
            ], false),
            'next_application' => $nextApplication ? [
                'id' => $nextApplication->id,
                'applicant_name' => $nextApplication->applicant?->name ?: "Application #{$nextApplication->id}",
                'url' => route('provider.applications.show', $nextApplication, false),
            ] : null,
        ];
    }

    private function applicationSiblingNavigationPayload(ScholarshipApplication $application): array
    {
        $applications = ScholarshipApplication::query()
            ->with('applicant')
            ->where('scholarship_id', $application->scholarship_id)
            ->latest('submitted_at')
            ->latest('id')
            ->get();
        $position = $applications->search(fn (ScholarshipApplication $item) => $item->is($application));
        $position = $position === false ? 0 : $position;
        $navigationItem = static fn (?ScholarshipApplication $item): ?array => $item ? [
            'id' => $item->id,
            'applicant_name' => $item->applicant?->name ?: "Application #{$item->id}",
            'url' => route('provider.applications.show', $item, false),
        ] : null;

        return [
            'position' => $applications->isEmpty() ? 0 : $position + 1,
            'total' => $applications->count(),
            'previous_application' => $navigationItem($applications->get($position - 1)),
            'next_application' => $navigationItem($applications->get($position + 1)),
        ];
    }

    private function providerProgramCountRelations(): array
    {
        return [
            'bookmarks',
            'applications',
            'applications as pending_review_applications_count' => fn ($query) => $query
                ->whereIn('status', ['submitted', 'under_review']),
            'applications as awarded_slots_count' => fn ($query) => $query
                ->whereIn('status', self::AWARD_SLOT_STATUSES),
        ];
    }

    private function providerApplicationReviewers(int $providerId, ?User $assigner = null)
    {
        return User::query()
            ->with(['providerProfile', 'parentAccount.providerProfile'])
            ->where('role', 'provider')
            ->where(function ($query) use ($providerId): void {
                $query->whereKey($providerId)
                    ->orWhere('parent_account_id', $providerId);
            })
            ->get()
            ->filter(fn (User $reviewer) => $reviewer->isActive()
                && $reviewer->providerOrganizationOwner()->isActive()
                && $reviewer->hasPortalPermission('review_applications')
                && (! $assigner || $reviewer->id !== $assigner->id)
                && (! $assigner || ! $this->providerReviewerHasBroaderAccess($assigner, $reviewer)))
            ->sortBy(fn (User $reviewer) => sprintf(
                '%d-%s',
                $reviewer->id === $providerId ? 0 : 1,
                strtolower($reviewer->email),
            ))
            ->values();
    }

    private function providerReviewerHasBroaderAccess(User $assigner, User $reviewer): bool
    {
        $assignerPermissions = $assigner->isManagedAccount()
            ? array_values(array_intersect(User::PROVIDER_PERMISSIONS, $assigner->permissions ?? []))
            : User::PROVIDER_PERMISSIONS;
        $reviewerPermissions = $reviewer->isManagedAccount()
            ? array_values(array_intersect(User::PROVIDER_PERMISSIONS, $reviewer->permissions ?? []))
            : User::PROVIDER_PERMISSIONS;

        if (array_diff($reviewerPermissions, $assignerPermissions) !== []) {
            return true;
        }

        if (! $assigner->hasLimitedProviderProgramAccess()) {
            return false;
        }

        return ! $reviewer->hasLimitedProviderProgramAccess()
            || array_diff(
                $reviewer->assignedProviderProgramIds(),
                $assigner->assignedProviderProgramIds(),
            ) !== [];
    }

    private function unassignInaccessibleApplications(User $account): void
    {
        $assignments = ScholarshipApplication::query()
            ->where('assigned_reviewer_id', $account->id);

        if (! $account->isActive() || ! $account->hasPortalPermission('review_applications')) {
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

    private function applicationReviewerPayload(User $reviewer, int $providerId): array
    {
        $reviewer->loadMissing('providerProfile');
        $profile = $reviewer->providerProfile;
        $contactName = collect([
            $profile?->first_name,
            filled($profile?->middle_initial) ? strtoupper($profile->middle_initial).'.' : null,
            $profile?->last_name,
        ])->filter()->implode(' ');
        $isOwner = $reviewer->id === $providerId;

        return [
            'id' => $reviewer->id,
            'name' => $isOwner
                ? ($profile?->provider_name ?: $contactName ?: $reviewer->username ?: $reviewer->email)
                : ($contactName ?: $reviewer->username ?: $reviewer->email),
            'role_label' => $isOwner
                ? 'Provider owner'
                : (self::PROVIDER_TEAM_ROLES[$reviewer->account_title] ?? 'Team member'),
            'program_access_mode' => $reviewer->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            'assigned_program_ids' => $reviewer->assignedProviderProgramIds(),
        ];
    }

    private function scholarshipImageUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return asset('uploads/scholarship-default.jpg');
    }

    private function ensureProviderCanPost(Request $request): void
    {
        $providerOwner = $request->user()->providerOrganizationOwner();

        abort_unless(
            $providerOwner->hasVerifiedEmail(),
            403,
            'Verify your email address before submitting a scholarship.'
        );

        if ($providerOwner->providerProfile?->isVerified()) {
            return;
        }

        abort(403, 'Your provider account must be approved by an admin before posting scholarships.');
    }

    private function validateProviderTeamAccount(Request $request, ?User $account = null): array
    {
        $permissions = $this->grantableProviderPermissions($request->user());

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

        return array_values(array_intersect(User::PROVIDER_PERMISSIONS, $actor->permissions ?? []));
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
                array_diff($account->permissions ?? [], $actor->permissions ?? []) !== [],
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

    private function providerStaffPayload(User $user): array
    {
        $owner = $user->providerOrganizationOwner()->loadMissing('providerProfile');
        $profile = $owner->providerProfile;

        return [
            ...$user->publicPayload(),
            'provider_name' => $profile?->provider_name,
            'provider_type' => $profile?->provider_type,
            'provider_website' => $profile?->provider_website,
            'provider_address' => $profile?->provider_address,
            'provider_description' => $profile?->provider_description,
            'provider_contact_email' => $profile?->provider_contact_email,
            'provider_contact_number' => $profile?->provider_contact_number,
            'verification_status' => $profile?->verification_status,
            'verification_notes' => $profile?->verification_notes,
        ];
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

    private function mapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return "https://www.openstreetmap.org/?mlat={$scholarship->latitude}&mlon={$scholarship->longitude}#map=15/{$scholarship->latitude}/{$scholarship->longitude}";
        }

        $query = $scholarship->location_address ?: $scholarship->location_name;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }

    private function embedMapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return "https://www.openstreetmap.org/export/embed.html?marker={$scholarship->latitude},{$scholarship->longitude}&layer=mapnik";
        }

        return null;
    }

    private function handoffMapUrl(Scholarship $scholarship): ?string
    {
        $query = $scholarship->handoff_location_address ?: $scholarship->handoff_location_name;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }

    private function documentPayload(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('documents.view', $document),
            'download_url' => route('documents.download', $document),
        ];
    }

    private function verificationDocumentPayload(ProviderVerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'ocr_status' => $document->ocr_status ?? AcademicRecordOcrService::STATUS_NOT_REQUESTED,
            'ocr_provider' => $document->ocr_provider,
            'ocr_grade' => $document->ocr_grade,
            'ocr_grading_scale' => $document->ocr_grading_scale,
            'ocr_label' => $document->ocr_label,
            'ocr_message' => $document->ocr_message,
            'ocr_processed_at' => $document->ocr_processed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('provider.verification-documents.view', $document),
            'download_url' => route('provider.verification-documents.download', $document),
        ];
    }

    private function applicantProfileProofPayload(
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ): array {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'ocr_status' => $document->ocr_status ?? AcademicRecordOcrService::STATUS_NOT_REQUESTED,
            'ocr_provider' => $document->ocr_provider,
            'ocr_grade' => $document->ocr_grade,
            'ocr_grading_scale' => $document->ocr_grading_scale,
            'ocr_label' => $document->ocr_label,
            'ocr_message' => $document->ocr_message,
            'ocr_processed_at' => $document->ocr_processed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('provider.applications.profile-proofs.view', [$application, $document]),
        ];
    }

    private function timelinePayload(ScholarshipApplication $application): array
    {
        if ($application->statusHistories->isEmpty()) {
            return [[
                'id' => "submitted-{$application->id}",
                'from_status' => null,
                'to_status' => $application->status,
                'decision_reason' => $application->decision_reason,
                'review_notes' => 'Application record created.',
                'actor' => $application->applicant?->name ?? 'Applicant',
                'changed_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ]];
        }

        return $application->statusHistories
            ->sortBy('changed_at')
            ->map(fn (ApplicationStatusHistory $history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'decision_reason' => $history->decision_reason,
                'review_notes' => $history->review_notes,
                'actor' => $history->actor?->name ?? 'System',
                'changed_at' => $history->changed_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function ensureScheduleCanBePublished(ScholarshipApplication $application, string $type): void
    {
        $workflow = $this->workflowService->payload($application);

        if (! ScholarshipSelectionPlan::isSchedulable($type) || $workflow['current_stage'] !== $type) {
            throw ValidationException::withMessages([
                'type' => 'This applicant is not currently at that scheduled stage.',
            ]);
        }
    }

    private function stageActivityIsComplete(ScholarshipApplication $application, string $stage): bool
    {
        if (! ScholarshipSelectionPlan::isSchedulable($stage)) {
            return true;
        }

        if ($application->relationLoaded('schedules')) {
            return $application->schedules->contains(fn (ApplicationSchedule $schedule): bool => (
                $schedule->type === $stage && $schedule->status === 'completed'
            ));
        }

        return $application->schedules()
            ->where('type', $stage)
            ->where('status', 'completed')
            ->exists();
    }

    private function ensureStageActivityCompleted(ScholarshipApplication $application, string $stage): void
    {
        if ($this->stageActivityIsComplete($application, $stage)) {
            return;
        }

        throw ValidationException::withMessages([
            'result' => 'Mark the '.ScholarshipSelectionPlan::label($stage).' activity as completed before recording an applicant result.',
        ]);
    }

    private function requireCompleteApplicationRubric(
        ScholarshipApplication $application,
        ?array $scores,
    ): ?array {
        $rubric = $application->review_rubric_snapshot
            ?: ($application->scholarship?->review_rubric ?? []);

        if ($rubric === []) {
            return null;
        }

        if ($scores === null) {
            throw ValidationException::withMessages([
                'rubric_scores' => 'Score every provider review criterion before saving the review.',
            ]);
        }

        $result = ReviewRubric::result($rubric, $scores);

        if (! $result['is_complete']) {
            throw ValidationException::withMessages([
                'rubric_scores' => 'Score every provider review criterion before saving the review.',
            ]);
        }

        return $result;
    }

    private function applicationStatusForEventResult(
        ScholarshipApplication $application,
        string $eventType,
        string $result,
    ): ?string {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'disbursed' : null;
        }

        if ($result === 'failed') {
            return $eventType === 'exam' ? 'exam_failed' : 'interview_failed';
        }

        return $eventType === 'exam'
            ? ScholarshipSelectionPlan::nextApprovalStatus('exam_taken', $application->scholarship?->selection_stages)
            : 'approved';
    }

    private function decisionReasonForEventResult(string $eventType, string $result, ?string $nextStatus): ?string
    {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'award_released' : null;
        }

        if ($result === 'failed') {
            return $eventType === 'exam' ? 'failed_exam' : 'failed_interview';
        }

        return match ($nextStatus) {
            'interview' => 'passed_exam',
            'approved' => 'qualified_for_formal_application',
            default => null,
        };
    }

    private function defaultReviewNoteForEventResult(string $eventType, string $result): string
    {
        $stage = $eventType === 'exam' ? 'exam' : ($eventType === 'interview' ? 'interview' : 'reward distribution');

        return match ($result) {
            'passed' => "Applicant passed the scholarship {$stage}.",
            'failed' => "Applicant did not pass the scholarship {$stage}.",
            'received' => 'Applicant received the scholarship benefits.',
            default => 'No distribution tracking was required for this applicant.',
        };
    }

    private function eventResultNotificationTitle(string $eventType, string $result): string
    {
        if ($eventType === 'distribution') {
            return $result === 'received' ? 'Scholarship benefits received' : 'Distribution record updated';
        }

        return ucfirst($eventType).' '.($result === 'passed' ? 'passed' : 'not passed');
    }

    private function eventResultNotificationMessage(
        ScholarshipApplication $application,
        string $eventType,
        string $result,
    ): string {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';

        if ($eventType === 'distribution') {
            return $result === 'received'
                ? "The provider recorded that you received the scholarship benefits for {$programTitle}."
                : "The provider updated your distribution record for {$programTitle}.";
        }

        if ($result === 'failed') {
            return "Your application for {$programTitle} did not pass the scholarship {$eventType}. Open the application to review the provider note.";
        }

        return $application->status === 'interview'
            ? "You passed the scholarship exam for {$programTitle}. Your application will proceed to the interview stage."
            : "You passed the scholarship {$eventType} for {$programTitle}. Your application has advanced to the next stage.";
    }

    private function workflowStageNotification(
        ScholarshipApplication $application,
        string $stage,
        string $result,
        string $nextStageLabel,
    ): array {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';
        $stageLabel = match ($stage) {
            'screening' => 'pre-screening',
            'formal_application' => 'formal application',
            'exam' => 'exam',
            'interview' => 'interview',
            default => 'application stage',
        };

        if ($result === 'not_passed') {
            $reasonLabel = filled($application->decision_reason)
                ? Str::headline($application->decision_reason)
                : 'Provider decision';
            $reviewNote = filled($application->review_notes)
                ? ' '.$application->review_notes
                : '';

            return [
                'title' => ucfirst($stageLabel).' not passed',
                'message' => "Your application for {$programTitle} did not pass the {$stageLabel}. Reason: {$reasonLabel}.{$reviewNote}",
            ];
        }

        return [
            'title' => $stage === 'screening' ? 'Pre-screening passed' : ucfirst($stageLabel).' passed',
            'message' => "You passed the {$stageLabel} for {$programTitle}. Your next step is {$nextStageLabel}.",
        ];
    }

    private function scheduleTypeLabel(string $type): string
    {
        return match ($type) {
            'exam' => 'exam',
            'interview' => 'interview',
            'distribution' => 'award release',
            default => 'activity',
        };
    }

    private function correctionTargetLabel(string $target): string
    {
        return match ($target) {
            'profile' => 'profile information',
            'academic_record' => 'academic record',
            'application_files' => 'application files',
            'application_answers' => 'application answers',
            default => 'other application information',
        };
    }

    private function scheduleApplicationStatus(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'interview',
            'distribution' => 'distribution_scheduled',
            default => 'under_review',
        };
    }

    private function scheduleDecisionReason(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'for_interview',
            'distribution' => 'distribution_scheduled',
            default => 'other',
        };
    }

    private function applicationStatusNotificationPayload(
        ScholarshipApplication $application,
        string $status,
        ?string $decisionReason,
        bool $isOutcomeStatus
    ): array {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';
        $actionUrl = route('dashboard.applications.show', $application, false);
        $distributionDate = $application->distribution_scheduled_for?->format('M d, Y');

        if ($status === 'under_review' && $decisionReason === 'missing_documents') {
            return [
                'type' => 'application_status',
                'title' => 'Documents needed',
                'message' => "Your application for {$programTitle} needs updated documents. Please review the provider note.",
                'action_url' => $actionUrl,
            ];
        }

        $payload = $isOutcomeStatus
            ? match ($status) {
                'awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship award confirmed',
                    'message' => "The provider selected you for {$programTitle} after its formal application process. Open your application for award and distribution updates.",
                ],
                'not_awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Formal application result',
                    'message' => "The provider completed its formal process for {$programTitle}, but your application was not selected. Review the provider note for details.",
                ],
                'disbursed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship reward distributed',
                    'message' => "The scholarship reward for {$programTitle} has been marked as distributed.",
                ],
                'renewed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship renewed',
                    'message' => "Your scholarship support for {$programTitle} has been renewed.",
                ],
                default => [
                    'type' => 'application_outcome',
                    'title' => 'Application outcome recorded',
                    'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
                ],
            }
        : match ($status) {
            'submitted' => [
                'type' => 'application_status',
                'title' => 'Application returned to submitted',
                'message' => "Your application for {$programTitle} was returned to submitted status.",
            ],
            'under_review' => [
                'type' => 'application_status',
                'title' => 'Application review started',
                'message' => "Your application for {$programTitle} is now under provider review.",
            ],
            'qualified' => [
                'type' => 'application_status',
                'title' => 'Application qualified',
                'message' => "Your application for {$programTitle} has been marked qualified for provider review.",
            ],
            'shortlisted' => [
                'type' => 'application_status',
                'title' => 'Application shortlisted',
                'message' => "Your application for {$programTitle} has been shortlisted for the next review step.",
            ],
            'interview' => [
                'type' => 'application_status',
                'title' => 'Interview or follow-up needed',
                'message' => "Your application for {$programTitle} was moved to interview or follow-up screening.",
            ],
            'exam_qualified' => [
                'type' => 'application_status',
                'title' => 'Qualified for exam',
                'message' => "Your application for {$programTitle} passed initial screening and is qualified for the scholarship exam.",
            ],
            'exam_scheduled' => [
                'type' => 'application_status',
                'title' => 'Scholarship exam scheduled',
                'message' => "Your scholarship exam for {$programTitle} has been scheduled. Check provider notes for instructions.",
            ],
            'exam_taken' => [
                'type' => 'application_status',
                'title' => 'Exam marked taken',
                'message' => "Your scholarship exam for {$programTitle} was marked as taken.",
            ],
            'exam_passed' => [
                'type' => 'application_status',
                'title' => 'Exam passed',
                'message' => "You passed the scholarship exam for {$programTitle}. Your application will proceed to final review.",
            ],
            'exam_failed' => [
                'type' => 'application_status',
                'title' => 'Exam not passed',
                'message' => "Your application for {$programTitle} did not pass the scholarship exam. Review the provider note for details.",
            ],
            'interview_failed' => [
                'type' => 'application_status',
                'title' => 'Interview not passed',
                'message' => "Your application for {$programTitle} did not advance after the interview. Review the provider note for details.",
            ],
            'approved' => [
                'type' => 'application_status',
                'title' => 'Qualified for formal application',
                'message' => "You passed pre-screening for {$programTitle}. Open your submission to review the documents and instructions for continuing with the provider. This is not yet a final scholarship award.",
            ],
            'waitlisted' => [
                'type' => 'application_outcome',
                'title' => 'Added to the alternate recipient list',
                'message' => "You remain eligible for {$programTitle}, but the provider placed you on its waitlist. You will be notified if a slot becomes available.",
            ],
            'distribution_scheduled' => [
                'type' => 'application_outcome',
                'title' => 'Reward distribution scheduled',
                'message' => "Your scholarship reward for {$programTitle} is scheduled for {$distributionDate}. Open the application to review provider instructions.",
            ],
            'benefits_terminated' => [
                'type' => 'application_outcome',
                'title' => 'Scholarship benefits stopped',
                'message' => "The provider stopped future scholarship benefits for {$programTitle}. Open your application to review the recorded reason and provider explanation.",
            ],
            'rejected' => [
                'type' => 'application_status',
                'title' => 'Pre-screening not qualified',
                'message' => "Your submission for {$programTitle} did not qualify for the next stage. Review the provider note for details.",
            ],
            default => [
                'type' => 'application_status',
                'title' => 'Application status updated',
                'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
            ],
        };

        if (in_array($status, ['rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'], true) && filled($decisionReason)) {
            $payload['message'] .= " Reason: {$this->statusLabel($decisionReason)}.";
        }

        return array_merge($payload, ['action_url' => $actionUrl]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Qualified for formal application',
            'waitlisted' => 'Waitlisted alternate',
            'withdrawn' => 'Withdrawn',
            'rejected' => 'Not qualified',
            'exam_qualified' => 'Qualified for exam',
            'exam_scheduled' => 'Exam scheduled',
            'exam_taken' => 'Exam taken',
            'exam_passed' => 'Passed exam',
            'exam_failed' => 'Failed exam',
            'interview_failed' => 'Failed interview',
            'benefits_terminated' => 'Benefits stopped',
            'for_exam' => 'Meets exam eligibility',
            'exam_completed' => 'Exam completed',
            'passed_exam' => 'Passed exam',
            'failed_exam' => 'Failed exam',
            'failed_interview' => 'Failed interview',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
