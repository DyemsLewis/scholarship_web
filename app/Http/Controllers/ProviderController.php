<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipAnnouncement;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\ApplicationWorkflowService;
use App\Services\AcademicRecordOcrService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipBenefitService as SB;
use App\Services\ScholarshipEligibilityService;
use App\Services\ScholarshipEventService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationDecisionReason;
use App\Support\ApplicationSchedulePayload;
use App\Support\CsvExport;
use App\Support\LearnerProgramPath;
use App\Support\ReviewRubric;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use App\Support\Terms;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function programWorkspace(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

        return view('provider-program-workspace', [
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
            return redirect()->to(route('provider.profile').'#verification-documents');
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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
        ]);
    }

    public function showTeamAccount(Request $request, User $account): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->authorizeProviderTeamAccount($request->user(), $account);

        return response()->json([
            'account' => $this->providerTeamAccountPayload($account->loadMissing('providerProfile')),
            'available_permissions' => $this->grantableProviderPermissions($request->user()),
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
                'password' => $validated['password'],
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
            ],
        );

        $teamRole = self::PROVIDER_TEAM_ROLES[$account->account_title] ?? 'Team member';
        $providerName = $owner->providerProfile?->provider_name ?: $owner->name ?: 'provider organization';
        PortalNotification::create([
            'user_id' => $account->id,
            'type' => 'staff_account_created',
            'title' => 'Your provider staff account is ready',
            'message' => "Your {$providerName} {$teamRole} account has been created. Username: {$account->username}. Sign in using the temporary password provided to you. You can update your email, username, and contact details in Profile. Use Forgot Password if you need to change your password.",
            'action_url' => '/login',
            'deduplication_key' => "staff_account_created:{$account->id}",
        ]);

        return response()->json([
            'message' => 'Team account created. A welcome email was queued; share the temporary password securely.',
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
        });

        ActivityLog::record(
            $actor,
            'provider_team_account_updated',
            "{$actor->name} updated provider team account {$account->email}.",
            $request,
            ['updated_user_id' => $account->id, 'permissions' => $account->permissions],
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

        $scholarships = Scholarship::query()
            ->where('provider_id', $providerId)
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();
        $reviewQueue = $provider->hasPortalPermission('review_applications')
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified()
            ? ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship'])
                ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $providerId))
                ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted', 'interview'])
                ->latest('submitted_at')
                ->limit(3)
                ->get()
            : collect();

        return response()->json([
            'user' => [
                ...$provider->publicPayload(),
                'verification_documents_count' => $verificationDocumentsCount,
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'review_queue' => $reviewQueue->map(fn (ScholarshipApplication $application) => [
                'id' => $application->id,
                'detail_url' => route('provider.applications.show', $application, false),
                'applicant' => $application->applicant?->name,
                'scholarship' => $application->scholarship?->title,
                'status' => $application->status,
                'pending_documents' => $application->documents->where('status', 'pending')->count(),
                'submitted_at' => $application->submitted_at?->format('M d, Y'),
            ])->values(),
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
        $scholarships = Scholarship::query()
            ->where('provider_id', $providerId)
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $providerId))
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
            'filter' => ['sometimes', Rule::in(['pending_review', 'document_issues', 'active_stages', 'formal_application', 'decided', 'all'])],
            'sort' => ['sometimes', Rule::in(['priority', 'dss', 'documents', 'oldest'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);

        $providerId = $request->user()->providerOrganizationId();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filter = $validated['filter'] ?? 'pending_review';
        $sort = $validated['sort'] ?? 'priority';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $scholarships = Scholarship::query()
            ->where('provider_id', $providerId)
            ->withCount($this->providerProgramCountRelations())
            ->withAvg('applications as average_match_score', 'eligibility_score')
            ->withAvg('applications as average_dss_score', 'dss_score')
            ->latest()
            ->get();
        $reviewers = $this->providerApplicationReviewers($providerId);
        $applicationsBase = ScholarshipApplication::query()
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $providerId));

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
                    ->whereHas('application.scholarship', fn ($query) => $query->where('provider_id', $providerId))
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
        if ($filter === 'pending_review') {
            $query
                ->where(fn (Builder $query) => $query
                    ->where('workflow_stage', 'screening')
                    ->orWhereNull('workflow_stage'))
                ->whereNotIn('application_state', ['closed', 'withdrawn']);

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

        foreach (['pending_review', 'document_issues', 'active_stages', 'formal_application', 'decided'] as $filter) {
            $query = clone $baseQuery;
            $this->applyProviderApplicationFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        return $counts;
    }

    public function applicationDetailData(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);
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

        $event->update([
            'status' => 'completed',
            'updated_by' => $request->user()->id,
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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);
        abort_unless($event->scholarship_id === $scholarship->id, 404);
        abort_unless(ScholarshipSelectionPlan::isSchedulable($event->type), 404);

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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);
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
            'review_navigation' => $this->reviewNavigationPayload($updated),
        ]);
    }

    public function recordApplicationFinalOutcome(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
            'review_navigation' => $this->reviewNavigationPayload($updated),
        ]);
    }

    public function bulkAdvanceApplications(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

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

        abort_unless($application->scholarship?->provider_id === $providerId, 403);

        $validated = $request->validate([
            'assigned_reviewer_id' => ['nullable', 'integer'],
        ]);
        $reviewerId = $validated['assigned_reviewer_id'] ?? null;
        $reviewer = $reviewerId
            ? $this->providerApplicationReviewers($providerId)->firstWhere('id', $reviewerId)
            : null;

        if ($reviewerId && ! $reviewer) {
            throw ValidationException::withMessages([
                'assigned_reviewer_id' => 'Choose an active reviewer from this provider organization.',
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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
            $schedule = $application->schedules()->where('type', $validated['type'])->first();

            if ($schedule) {
                $schedule->update($scheduleData);
            } else {
                $schedule = $application->schedules()->create([
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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);
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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);
        abort_unless($document->applicant_id === $application->applicant_id, 403);
        abort_unless(in_array($document->document_type, ['academic_record', 'school_record'], true), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewApplicantProfilePhoto(Request $request, ScholarshipApplication $application)
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

        $applicant = $application->applicant;
        abort_unless($applicant?->isApplicant(), 404);

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

        if ($this->academicRecordOcrService->configured()) {
            $academicRecord = $applicant->applicantVerificationDocuments
                ->firstWhere('document_type', 'academic_record');

            if ($academicRecord?->ocr_status !== AcademicRecordOcrService::STATUS_SUCCEEDED) {
                throw ValidationException::withMessages([
                    'verification' => 'The academic record must have a successful scan before its result can be verified.',
                ]);
            }
        }

        DB::transaction(function () use ($applicant, $request): void {
            $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
                'verification_status' => 'approved',
                'verification_notes' => null,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);

            $applicant->applicantVerificationDocuments()
                ->whereIn('document_type', ['academic_record', 'school_record'])
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
            ],
        );

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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['request', 'resolve'])],
            'message' => [Rule::requiredIf($request->input('action') === 'request'), 'nullable', 'string', 'min:5', 'max:1500'],
        ]);
        $workflow = $this->workflowService->payload($application);

        if ($workflow['is_closed']) {
            throw ValidationException::withMessages([
                'action' => 'A correction cannot be requested after this application has been closed.',
            ]);
        }

        if ($validated['action'] === 'resolve' && ! in_array($application->correction_status, ['requested', 'submitted'], true)) {
            throw ValidationException::withMessages([
                'action' => 'There is no open correction request to resolve.',
            ]);
        }

        $application->update($validated['action'] === 'request'
            ? [
                'correction_status' => 'requested',
                'correction_message' => $validated['message'],
                'correction_response' => null,
                'correction_requested_by' => $request->user()->id,
                'correction_requested_at' => now(),
                'correction_responded_at' => null,
                'correction_resolved_at' => null,
                'application_state' => 'needs_correction',
            ]
            : [
                'correction_status' => 'resolved',
                'correction_resolved_at' => now(),
                'application_state' => match ($workflow['current_stage']) {
                    'screening' => 'under_review',
                    'decision' => 'awaiting_decision',
                    default => 'in_provider_process',
                },
            ]);

        $application->loadMissing(['applicant', 'scholarship']);
        $isRequest = $validated['action'] === 'request';
        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'application_correction',
            'title' => $isRequest ? 'Application correction requested' : 'Application correction accepted',
            'message' => $isRequest
                ? "The provider requested an update for your {$application->scholarship->title} application."
                : "The provider completed the correction review for your {$application->scholarship->title} application.",
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
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
            $application->stageProgresses()->where('stage_key', 'decision')->update([
                'status' => 'current',
                'result' => null,
                'completed_at' => null,
                'decided_at' => null,
                'decided_by' => null,
            ]);
            $application->update([
                'status' => 'approved',
                'application_state' => 'awaiting_decision',
                'workflow_stage' => 'decision',
                'final_outcome' => null,
                'decision_reason' => null,
                'review_notes' => $validated['note'] ?? $application->review_notes,
                'waitlist_position' => null,
                'waitlisted_at' => null,
            ]);
            $updated = $application->fresh();
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

        return response()->json([
            'message' => $message,
            'application' => $this->applicationPayload($updated->fresh(), true),
        ]);
    }

    public function updateApplicationStatus(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

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
            'outcome_notes' => ['nullable', 'string', 'max:2000'],
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
                'awarded' => $validated['status'] === 'distribution_scheduled',
                'distribution_scheduled' => $validated['status'] === 'disbursed',
                'disbursed' => $validated['status'] === 'renewed',
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
                    'review_notes' => $validated['review_notes'] ?? null,
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
            'awarded' => ['distribution_scheduled'],
            'distribution_scheduled' => ['disbursed'],
            'disbursed' => ['renewed'],
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
        abort_unless($document->application?->scholarship?->provider_id === $request->user()->providerOrganizationId(), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'accepted', 'rejected', 'needs_replacement'])],
            'review_notes' => [Rule::requiredIf(in_array($request->input('status'), ['rejected', 'needs_replacement'], true)), 'nullable', 'string', 'max:1000'],
        ]);

        $previousStatus = $document->status;
        $document->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

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
            ? "provider-applications-program-{$selectedScholarship->id}.csv"
            : 'provider-applications.csv';

        return response()->streamDownload(function () use ($providerId, $selectedScholarship) {
            $handle = fopen('php://output', 'w');
            CsvExport::writeRow($handle, ['ID', 'Scholarship', 'Applicant', 'Email', 'Contact Number', 'Status', 'DSS Score', 'DSS Recommendation', 'Eligibility Score', 'Decision Reason', 'Awarded Amount', 'Distribution Date', 'Distribution Instructions', 'Outcome Date', 'Outcome Notes', 'Readiness %', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Applicant Notes', 'Review Notes']);

            $query = ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship'])
                ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $providerId));

            if ($selectedScholarship) {
                $query->where('scholarship_id', $selectedScholarship->id);
            }

            $query->orderBy('id')
                ->chunk(200, function ($applications) use ($handle) {
                    foreach ($applications as $application) {
                        app(DecisionSupportService::class)->syncApplication($application);
                        $readiness = $this->documentReadiness($application);

                        CsvExport::writeRow($handle, [
                            $application->id,
                            $application->scholarship?->title,
                            $application->applicant?->name,
                            $application->applicant?->email,
                            $application->applicant?->contact_number,
                            $application->status,
                            $application->dss_score,
                            $application->dss_recommendation,
                            $application->eligibility_score,
                            $application->decision_reason,
                            $application->awarded_amount,
                            $application->distribution_scheduled_for?->format('Y-m-d'),
                            $application->distribution_instructions,
                            $application->outcome_at?->format('Y-m-d'),
                            $application->outcome_notes,
                            $readiness['percent'],
                            $application->submitted_at?->format('Y-m-d H:i:s'),
                            implode('; ', $application->document_checklist ?? []),
                            $application->documents->count().' uploaded',
                            $application->notes,
                            $application->review_notes,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function scholarships(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->providerOrganizationId())
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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);

        $scholarship->load(['announcements.publisher']);

        return response()->json([
            'scholarship' => [
                ...$this->scholarshipPayload(
                    $scholarship->loadCount($this->providerProgramCountRelations()),
                ),
                'announcements' => $scholarship->announcements
                    ->map(fn (ScholarshipAnnouncement $announcement) => $this->scholarshipAnnouncementPayload($announcement))
                    ->values(),
            ],
        ]);
    }

    public function duplicateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);
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
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user());
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request);
        $this->ensureScholarshipReadyForSubmission($validated, $benefits);
        $imagePath = $this->storeScholarshipImage($request);
        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['terms_accepted'], $validated['program_events']);
        $validated['description'] = (string) ($validated['description'] ?? '');
        $validated['status'] = $validated['status'] === 'draft' ? 'draft' : 'pending_review';

        if ($termsAccepted) {
            $validated['provider_terms_accepted_at'] = now();
            $validated['provider_terms_version'] = Terms::VERSION;
        }

        try {
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
        abort_unless($scholarship->provider_id === $request->user()->providerOrganizationId(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user(), $scholarship);
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request, $scholarship);
        $this->ensureScholarshipReadyForSubmission($validated, $benefits, $scholarship);
        $benefitsChanged = $benefits !== null && app(SB::class)->changed($scholarship, $benefits);
        $this->ensureScholarshipSelectionPlanIsStable($scholarship, $validated['selection_stages']);
        $oldImagePath = $scholarship->image_path;
        $imagePath = $this->storeScholarshipImage($request);
        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['terms_accepted'], $validated['program_events']);
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

        if (filled($applicationOpensAt) && filled($deadline)
            && CarbonImmutable::parse($applicationOpensAt)->startOfDay()->isAfter(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['application_opens_at'] = 'The application opening date must be on or before the deadline.';
        }

        if (filled($expectedResultsAt) && filled($deadline)
            && CarbonImmutable::parse($expectedResultsAt)->startOfDay()->isBefore(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['expected_results_at'] = 'The expected results date must be on or after the application deadline.';
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

        $selectionStages = ScholarshipSelectionPlan::normalize($value('selection_stages'));

        if (in_array('exam', $selectionStages, true)) {
            if (blank($value('exam_duration_minutes'))) {
                $errors['exam_duration_minutes'] = 'Add the exam duration.';
            }

            if (blank($value('exam_passing_score'))) {
                $errors['exam_passing_score'] = 'Add the exam passing score.';
            }
        }

        if (blank($value('review_rubric'))) {
            $errors['review_rubric'] = 'Add at least one review criterion.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'eligible_education_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_courses' => ['nullable', 'string', 'max:3000'],
            'eligible_school_types' => ['nullable', 'string', 'max:2000'],
            'eligible_year_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_locations' => ['nullable', 'string', 'max:3000'],
            'income_requirement' => ['nullable', 'string', 'max:100'],
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
            'exam_duration_minutes' => ['nullable', 'integer', 'between:15,480'],
            'exam_passing_score' => ['nullable', 'numeric', 'between:0,100'],
            'program_events' => ['nullable', 'string', 'max:20000', 'json'],
            'renewal_policy' => ['nullable', 'string', 'max:2000'],
            'return_service_contract' => ['nullable', 'string', 'max:3000'],
            'other_contract_terms' => ['nullable', 'string', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', new PhoneNumber],
            'application_opens_at' => ['nullable', 'date'],
            'expected_results_at' => ['nullable', 'date'],
            'official_program_url' => ['nullable', 'url:http,https', 'max:2048'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_department' => ['nullable', 'string', 'max:150'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'closed', 'rejected'])],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'terms_accepted' => $requiresCompleteSubmission ? ['accepted'] : ['nullable'],
            'review_rubric' => ['nullable', 'string', 'max:8000', 'json'],
            'application_questions' => ['nullable', 'string', 'max:10000', 'json'],
        ]);

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
        if (! in_array('exam', ScholarshipSelectionPlan::normalize($validated['selection_stages'] ?? null), true)) {
            $validated['exam_duration_minutes'] = null;
            $validated['exam_passing_score'] = null;
        }

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
            'eligibility',
            'eligible_education_levels',
            'eligible_courses',
            'eligible_school_types',
            'eligible_year_levels',
            'eligible_locations',
            'income_requirement',
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
            'exam_duration_minutes',
            'exam_passing_score',
            'renewal_policy',
            'return_service_contract',
            'other_contract_terms',
            'contact_email',
            'contact_number',
            'application_opens_at',
            'expected_results_at',
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
            'eligibility_breakdown' => $application->eligibility_breakdown,
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
            'requires_student_response' => false,
            'can_receive_student_response' => false,
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
            'scholarship_goal' => $profile?->scholarship_goal,
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
                ->whereIn('document_type', ['academic_record', 'school_record'])
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

        if (in_array($workflow['current_stage'], ['formal_application', 'exam', 'interview'], true)) {
            return ['pass_stage'];
        }

        return $workflow['current_stage'] === 'decision' ? ['selected'] : [];
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
            'eligibility' => $scholarship->eligibility,
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_courses' => $scholarship->eligible_courses,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
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
            'exam_duration_minutes' => $scholarship->exam_duration_minutes,
            'exam_passing_score' => $scholarship->exam_passing_score,
            'program_events' => $scholarship->events
                ->where('status', 'scheduled')
                ->sortBy('scheduled_at')
                ->map(fn (ScholarshipEvent $event): array => ScholarshipEventPayload::make($event))
                ->values(),
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'application_opens_at' => $scholarship->application_opens_at?->format('Y-m-d'),
            'expected_results_at' => $scholarship->expected_results_at?->format('Y-m-d'),
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
            'duration_minutes' => $scholarship->exam_duration_minutes,
            'passing_score' => $scholarship->exam_passing_score,
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

        return Scholarship::query()
            ->where('provider_id', $request->user()->providerOrganizationId())
            ->withCount($this->providerProgramCountRelations())
            ->findOrFail($scholarshipId);
    }

    private function reviewNavigationPayload(ScholarshipApplication $application): array
    {
        $remainingApplications = ScholarshipApplication::query()
            ->with('applicant')
            ->where('scholarship_id', $application->scholarship_id)
            ->where('id', '!=', $application->id)
            ->whereIn('status', self::REVIEW_DECISION_STATUSES)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
        $nextApplication = $remainingApplications->first();

        return [
            'remaining_count' => $remainingApplications->count(),
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

    private function providerApplicationReviewers(int $providerId)
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
                && $reviewer->hasPortalPermission('review_applications'))
            ->sortBy(fn (User $reviewer) => sprintf(
                '%d-%s',
                $reviewer->id === $providerId ? 0 : 1,
                strtolower($reviewer->email),
            ))
            ->values();
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
            'password' => [$account ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);

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
            'created_at' => $account->created_at?->format('M d, Y'),
        ];
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

    private function ensureStageParticipationReadyForApproval(ScholarshipApplication $application): void
    {
        $stageType = match ($application->status) {
            'exam_taken', 'exam_passed' => 'exam',
            'interview' => 'interview',
            default => null,
        };

        if ($stageType === null) {
            return;
        }

        $schedule = $application->schedules()->where('type', $stageType)->first();

        if ($schedule?->status === 'completed' && $schedule->attendance_status === 'attended') {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => 'Complete the '.ScholarshipSelectionPlan::label($stageType).' activity and mark the applicant as attended before approving them for the next stage.',
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

        if (in_array($status, ['rejected', 'not_awarded', 'exam_failed', 'interview_failed'], true) && filled($decisionReason)) {
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
            'for_exam' => 'Meets exam eligibility',
            'exam_completed' => 'Exam completed',
            'passed_exam' => 'Passed exam',
            'failed_exam' => 'Failed exam',
            'failed_interview' => 'Failed interview',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
