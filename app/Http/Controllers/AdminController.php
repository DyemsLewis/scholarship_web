<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\PasswordResetLinkService;
use App\Support\AcademicRequirement;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AdminController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin');
    }

    public function dashboardData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $today = now()->startOfDay();
        $deadlineLimit = now()->addDays(30)->endOfDay();
        $recentUsers = User::query()
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->latest()
            ->limit(4)
            ->get(['id', 'email', 'username', 'role', 'created_at']);
        $recentScholarships = Scholarship::query()
            ->with('provider.providerProfile')
            ->latest('updated_at')
            ->limit(3)
            ->get();

        return response()->json([
            'stats' => [
                'pending_providers' => User::query()
                    ->where('role', 'provider')
                    ->whereHas('providerProfile', fn ($query) => $query->where('verification_status', 'pending'))
                    ->count(),
                'documents_pending_review' => ApplicationDocument::query()->where('status', 'pending')->count(),
                'documents_needing_replacement' => ApplicationDocument::query()->where('status', 'needs_replacement')->count(),
                'upcoming_deadlines' => Scholarship::query()
                    ->where('status', 'published')
                    ->whereDate('deadline', '>=', $today->toDateString())
                    ->whereDate('deadline', '<=', $deadlineLimit->toDateString())
                    ->count(),
                'expired_published' => Scholarship::query()
                    ->where('status', 'published')
                    ->whereDate('deadline', '<', $today->toDateString())
                    ->count(),
                'needs_review_applications' => ScholarshipApplication::query()
                    ->where('dss_recommendation', 'needs_review')
                    ->count(),
            ],
            'recent_users' => $recentUsers->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
            'recent_scholarships' => $recentScholarships->map(fn (Scholarship $scholarship) => [
                'id' => $scholarship->id,
                'title' => $scholarship->title,
                'provider' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
                'image_url' => $this->scholarshipImageUrl($scholarship),
                'status' => $scholarship->status,
                'updated_at' => $scholarship->updated_at?->format('M d, Y'),
            ])->values(),
        ]);
    }

    public function manageUsers(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-users');
    }

    public function accountForm(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-account-form');
    }

    public function profile(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-profile');
    }

    public function reviews(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-reviews');
    }

    public function providerReview(Request $request, User $provider): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($provider->isProvider(), 404);

        return view('admin-provider-review', [
            'provider' => $provider,
        ]);
    }

    public function applicantReview(Request $request, User $applicant): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($applicant->isApplicant(), 404);

        return view('admin-applicant-review', [
            'applicant' => $applicant,
        ]);
    }

    public function scholarshipReview(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-program-review', [
            'scholarship' => $scholarship,
        ]);
    }

    public function logs(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-logs');
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', Rule::in(['all', 'applicant', 'provider', 'admin'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $role = $validated['role'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 10);
        $roleCounts = User::query()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');
        $totalUsers = (int) $roleCounts->sum();

        $query = User::query()
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->latest();

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        if ($search !== '') {
            $likeSearch = '%'.$search.'%';

            $query->where(function ($query) use ($likeSearch): void {
                $query
                    ->where('account_status', 'like', $likeSearch)
                    ->orWhere('email', 'like', $likeSearch)
                    ->orWhere('username', 'like', $likeSearch)
                    ->orWhere('role', 'like', $likeSearch)
                    ->orWhereHas('studentProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                    )
                    ->orWhereHas('providerProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                        ->orWhere('provider_name', 'like', $likeSearch)
                    )
                    ->orWhereHas('adminProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                        ->orWhere('display_name', 'like', $likeSearch)
                    );
            });
        }

        $users = $query->paginate($perPage);

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'admins' => (int) ($roleCounts['admin'] ?? 0),
                'applicants' => (int) ($roleCounts['applicant'] ?? 0),
                'providers' => (int) ($roleCounts['provider'] ?? 0),
                'recent_signups' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'suspended_users' => User::query()->where('account_status', 'suspended')->count(),
                'password_resets_required' => User::query()->where('must_reset_password', true)->count(),
            ],
            'users' => $users->getCollection()->map(fn (User $user) => [
                ...$user->publicPayload(),
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function showUser(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json([
            'user' => $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'verification_documents' => $user->isApplicant()
                ? $user->applicantVerificationDocuments()
                    ->latest('uploaded_at')
                    ->get()
                    ->map(fn (ApplicantVerificationDocument $document) => $this->applicantVerificationDocumentPayload($document))
                    ->values()
                : [],
        ]);
    }

    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $user = $request->user();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
        ]);

        $middleInitial = strtoupper($validated['middle_initial']);

        $user->update([
            'email' => $validated['email'],
            'username' => $validated['username'],
        ]);

        $user->adminProfile()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
            'display_name' => $validated['display_name'],
        ]);

        ActivityLog::record(
            $user,
            'admin_profile_updated',
            "{$validated['display_name']} updated their admin profile.",
            $request,
            ['admin_id' => $user->id],
        );

        return response()->json([
            'message' => 'Admin profile updated successfully.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function providerReviewData(Request $request, User $provider): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($provider->isProvider(), 404);

        $provider->loadMissing(['providerProfile', 'providerVerificationDocuments']);

        return response()->json([
            'provider' => $this->providerReviewPayload($provider),
        ]);
    }

    public function applicantReviewData(Request $request, User $applicant): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($applicant->isApplicant(), 404);

        $applicant->loadMissing(['studentProfile', 'applicantVerificationDocuments']);

        return response()->json([
            'applicant' => $this->applicantReviewPayload($applicant),
        ]);
    }

    public function reviewsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $providers = User::query()
            ->with(['providerProfile', 'providerVerificationDocuments'])
            ->where('role', 'provider')
            ->latest()
            ->get(['id', 'email', 'username', 'role', 'created_at']);
        $applicants = User::query()
            ->with(['studentProfile', 'applicantVerificationDocuments'])
            ->where('role', 'applicant')
            ->latest()
            ->get(['id', 'email', 'username', 'role', 'created_at']);
        $scholarships = Scholarship::query()
            ->with(['provider.providerProfile', 'events'])
            ->withCount('bookmarks')
            ->whereIn('status', ['pending_review', 'rejected', 'published'])
            ->latest('updated_at')
            ->limit(12)
            ->get();

        return response()->json([
            'stats' => [
                'providers' => $providers->count(),
                'pending_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'pending')->count(),
                'approved_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'approved')->count(),
                'rejected_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'rejected')->count(),
                'applicants' => $applicants->count(),
                'pending_applicants' => $applicants->filter(fn (User $user) => $user->applicantVerificationDocuments->isNotEmpty()
                    && ! in_array($user->studentProfile?->verification_status, ['approved', 'rejected'], true))->count(),
                'approved_applicants' => $applicants->filter(fn (User $user) => $user->studentProfile?->verification_status === 'approved')->count(),
                'rejected_applicants' => $applicants->filter(fn (User $user) => $user->studentProfile?->verification_status === 'rejected')->count(),
                'unsubmitted_applicants' => $applicants->filter(fn (User $user) => $user->applicantVerificationDocuments->isEmpty())->count(),
                'applicant_proofs' => $applicants->sum(fn (User $user) => $user->applicantVerificationDocuments->count()),
                'pending_programs' => $scholarships->where('status', 'pending_review')->count(),
                'published_programs' => $scholarships->where('status', 'published')->count(),
                'rejected_programs' => $scholarships->where('status', 'rejected')->count(),
            ],
            'providers' => $providers->map(fn (User $user) => $this->providerReviewPayload($user))->values(),
            'applicants' => $applicants->map(fn (User $user) => $this->applicantReviewPayload($user))->values(),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipReviewPayload($scholarship))->values(),
        ]);
    }

    public function scholarshipReviewData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $scholarship->loadMissing(['provider.providerProfile', 'events']);
        $scholarship->loadCount('bookmarks');

        return response()->json([
            'scholarship' => $this->scholarshipReviewPayload($scholarship),
        ]);
    }

    public function updateScholarshipReview(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending_review', 'published', 'rejected'])],
            'review_notes' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:1500'],
        ]);

        $previousStatus = $scholarship->status;
        $scholarship->update([
            'status' => $validated['status'],
        ]);

        ActivityLog::record(
            $request->user(),
            'scholarship_review_updated',
            "{$request->user()->name} marked scholarship {$scholarship->title} as {$validated['status']}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'from_status' => $previousStatus,
                'to_status' => $validated['status'],
                'review_notes' => $validated['review_notes'] ?? null,
            ],
        );

        $reviewMessage = "Your scholarship {$scholarship->title} is now {$this->labelFromKey($validated['status'])}.";

        if ($validated['status'] === 'rejected') {
            $reviewMessage .= " Reason: {$validated['review_notes']}";
        }

        PortalNotification::create([
            'user_id' => $scholarship->provider_id,
            'type' => 'scholarship_review',
            'title' => 'Scholarship review updated',
            'message' => $reviewMessage,
            'action_url' => '/provider/programs',
        ]);

        return response()->json([
            'message' => 'Scholarship review updated.',
            'scholarship' => $this->scholarshipReviewPayload(
                $scholarship->fresh(['provider.providerProfile', 'events']),
            ),
        ]);
    }

    public function updateProviderVerification(Request $request, User $provider): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($provider->isProvider(), 404);

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:pending,approved,rejected'],
            'verification_notes' => [Rule::requiredIf($request->input('verification_status') === 'rejected'), 'nullable', 'string', 'max:1500'],
        ]);

        $provider->providerProfile()->updateOrCreate([
            'user_id' => $provider->id,
        ], [
            'verification_status' => $validated['verification_status'],
            'verification_notes' => $validated['verification_notes'] ?? null,
            'verified_by' => $request->user()->id,
            'verified_at' => $validated['verification_status'] === 'pending' ? null : now(),
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_verification_updated',
            "{$request->user()->name} marked provider {$provider->name} as {$validated['verification_status']}.",
            $request,
            ['provider_id' => $provider->id, 'verification_status' => $validated['verification_status']],
        );

        $verificationMessage = "Your provider account is now {$validated['verification_status']}.";

        if ($validated['verification_status'] === 'rejected') {
            $verificationMessage .= " Reason: {$validated['verification_notes']}";
        }

        PortalNotification::create([
            'user_id' => $provider->id,
            'type' => 'provider_verification',
            'title' => 'Provider verification updated',
            'message' => $verificationMessage,
            'action_url' => '/provider',
        ]);

        return response()->json([
            'message' => 'Provider verification updated.',
            'provider' => $this->providerReviewPayload(
                $provider->fresh(['providerProfile', 'providerVerificationDocuments']),
            ),
        ]);
    }

    public function updateApplicantVerification(Request $request, User $applicant): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($applicant->isApplicant(), 404);

        $validated = $request->validate([
            'verification_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'verification_notes' => [Rule::requiredIf($request->input('verification_status') === 'rejected'), 'nullable', 'string', 'max:1500'],
        ]);

        if ($validated['verification_status'] === 'approved' && ! $applicant->applicantVerificationDocuments()->exists()) {
            return response()->json([
                'message' => 'The applicant must upload at least one proof before the profile can be verified.',
            ], 422);
        }

        $documentStatus = match ($validated['verification_status']) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => 'submitted',
        };
        $notes = $validated['verification_status'] === 'rejected'
            && filled($validated['verification_notes'] ?? null)
            ? trim($validated['verification_notes'])
            : null;

        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'verification_status' => $validated['verification_status'],
            'verification_notes' => $notes,
            'verified_by' => $validated['verification_status'] === 'pending' ? null : $request->user()->id,
            'verified_at' => $validated['verification_status'] === 'approved' ? now() : null,
        ]);

        $applicant->applicantVerificationDocuments()->update([
            'status' => $documentStatus,
            'review_notes' => $notes,
        ]);

        ActivityLog::record(
            $request->user(),
            'applicant_profile_verification_updated',
            "{$request->user()->name} marked applicant {$applicant->name} as {$validated['verification_status']}.",
            $request,
            [
                'applicant_id' => $applicant->id,
                'verification_status' => $validated['verification_status'],
            ],
        );

        $message = "Your applicant profile verification is now {$validated['verification_status']}.";

        if ($validated['verification_status'] === 'rejected' && $notes) {
            $message .= " Review note: {$notes}";
        }

        PortalNotification::create([
            'user_id' => $applicant->id,
            'type' => 'applicant_profile_verification',
            'title' => 'Profile verification updated',
            'message' => $message,
            'action_url' => '/dashboard/profile',
        ]);

        return response()->json([
            'message' => 'Applicant profile verification updated.',
            'user' => $applicant->fresh(['studentProfile'])->publicPayload(),
            'verification_documents' => $applicant->applicantVerificationDocuments()
                ->latest('uploaded_at')
                ->get()
                ->map(fn (ApplicantVerificationDocument $document) => $this->applicantVerificationDocumentPayload($document))
                ->values(),
        ]);
    }

    public function viewApplicantVerificationDocument(Request $request, ApplicantVerificationDocument $document)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadProviderVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function storeUser(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,username'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'role' => ['required', 'string', 'in:applicant,provider,admin'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $middleInitial = strtoupper($validated['middle_initial']);
        $displayName = trim("{$validated['first_name']} {$middleInitial}. {$validated['last_name']}");

        $user = User::create([
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => $validated['role'],
            'password' => $validated['password'],
        ]);

        $profile = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
        ];

        match ($user->role) {
            'admin' => $user->adminProfile()->create([
                ...$profile,
                'display_name' => $displayName,
            ]),
            'provider' => $user->providerProfile()->create([
                ...$profile,
                'provider_name' => $displayName,
                'verification_status' => 'approved',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]),
            default => $user->studentProfile()->create($profile),
        };

        ActivityLog::record(
            $request->user(),
            'account_created',
            "{$request->user()->name} created {$user->role} account {$user->email}.",
            $request,
            ['created_user_id' => $user->id, 'created_user_role' => $user->role],
        );

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'role' => ['required', 'string', 'in:applicant,provider,admin'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $previousRole = $user->role;

        if ($previousRole === 'admin' && $validated['role'] !== 'admin' && $this->lastActiveAdminWouldBeLost($user)) {
            return response()->json([
                'message' => 'At least one active admin account must remain.',
            ], 422);
        }

        $middleInitial = strtoupper($validated['middle_initial']);
        $displayName = trim("{$validated['first_name']} {$middleInitial}. {$validated['last_name']}");
        $userPayload = [
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => $validated['role'],
        ];

        if (filled($validated['password'] ?? null)) {
            $userPayload['password'] = $validated['password'];
        }

        $user->update($userPayload);

        $profile = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
        ];

        match ($user->role) {
            'admin' => $user->adminProfile()->updateOrCreate([
                'user_id' => $user->id,
            ], [
                ...$profile,
                'display_name' => $displayName,
            ]),
            'provider' => $user->providerProfile()->updateOrCreate([
                'user_id' => $user->id,
            ], [
                ...$profile,
                'provider_name' => $user->providerProfile?->provider_name ?: $displayName,
                'verification_status' => $user->providerProfile?->verification_status ?: 'approved',
                'verified_by' => $user->providerProfile?->verified_by ?: $request->user()->id,
                'verified_at' => $user->providerProfile?->verified_at ?: now(),
            ]),
            default => $user->studentProfile()->updateOrCreate([
                'user_id' => $user->id,
            ], $profile),
        };

        ActivityLog::record(
            $request->user(),
            'account_updated',
            "{$request->user()->name} updated {$user->role} account {$user->email}.",
            $request,
            [
                'updated_user_id' => $user->id,
                'previous_role' => $previousRole,
                'current_role' => $user->role,
            ],
        );

        return response()->json([
            'message' => 'Account updated successfully.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'suspended'])],
            'suspension_reason' => ['nullable', 'required_if:account_status,suspended', 'string', 'max:1000'],
        ]);

        $status = $validated['account_status'];

        if ($status === 'suspended') {
            if ($user->is($request->user())) {
                return response()->json([
                    'message' => 'You cannot suspend your own admin account.',
                ], 422);
            }

            if ($this->lastActiveAdminWouldBeLost($user)) {
                return response()->json([
                    'message' => 'At least one active admin account must remain.',
                ], 422);
            }

            $user->forceFill([
                'account_status' => 'suspended',
                'suspended_at' => now(),
                'suspended_by' => $request->user()->id,
                'suspension_reason' => $validated['suspension_reason'],
            ])->save();
        } else {
            $user->forceFill([
                'account_status' => 'active',
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ])->save();
        }

        ActivityLog::record(
            $request->user(),
            'account_status_updated',
            "{$request->user()->name} marked {$user->email} as {$status}.",
            $request,
            ['updated_user_id' => $user->id, 'account_status' => $status],
        );

        PortalNotification::create([
            'user_id' => $user->id,
            'type' => 'account_status',
            'title' => $status === 'suspended' ? 'Account suspended' : 'Account reactivated',
            'message' => $status === 'suspended'
                ? 'Your account has been suspended. Contact an administrator for help.'
                : 'Your account has been reactivated. You can sign in again.',
            'action_url' => $status === 'active' ? '/login' : null,
        ]);

        return response()->json([
            'message' => $status === 'suspended' ? 'Account suspended.' : 'Account reactivated.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function forcePasswordReset(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $user->forceFill([
            'must_reset_password' => true,
            'password_reset_required_at' => now(),
        ])->save();

        $reset = app(PasswordResetLinkService::class)->prepare($user, $request);

        ActivityLog::record(
            $request->user(),
            'account_password_reset_forced',
            "{$request->user()->name} forced a password reset for {$user->email}.",
            $request,
            ['updated_user_id' => $user->id, 'email_sent' => $reset['email_sent']],
        );

        PortalNotification::create([
            'user_id' => $user->id,
            'type' => 'password_reset_required',
            'title' => 'Password reset required',
            'message' => 'An administrator requires you to reset your password before signing in again.',
            'action_url' => null,
        ]);

        return response()->json([
            'message' => $reset['email_sent']
                ? 'Password reset required. A reset link was sent to the user.'
                : 'Password reset required, but the reset email could not be sent yet.',
            'reset_url' => $this->shouldExposeResetUrl() ? $reset['reset_url'] : null,
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function verifyUserEmail(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));

            PortalNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'email_verification')
                ->where('title', 'Verify your email address')
                ->update(['read_at' => now()]);

            PortalNotification::create([
                'user_id' => $user->id,
                'type' => 'email_verified',
                'title' => 'Email verified',
                'message' => 'An administrator verified your email address.',
                'action_url' => $user->isProvider() ? '/provider' : '/dashboard',
            ]);
        }

        ActivityLog::record(
            $request->user(),
            'account_email_verified_by_admin',
            "{$request->user()->name} verified {$user->email}.",
            $request,
            ['updated_user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Email marked as verified.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function resendUserVerificationEmail(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email is already verified.',
                'email_verification_sent' => false,
                'user' => $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            ]);
        }

        $emailSent = true;

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $error) {
            $emailSent = false;

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
            'message' => $emailSent
                ? 'A verification link was sent to your email. Verify your email to secure your account.'
                : 'Your email is not verified yet. Ask an administrator to resend the link when mail settings are available.',
            'action_url' => null,
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'account_email_verification_resent_by_admin',
            "{$request->user()->name} resent email verification for {$user->email}.",
            $request,
            ['updated_user_id' => $user->id, 'email_sent' => $emailSent],
        );

        return response()->json([
            'message' => $emailSent
                ? 'Verification email sent.'
                : 'Unable to send the verification email right now.',
            'email_verification_sent' => $emailSent,
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function logEntries(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:25'],
            'action' => ['sometimes', 'string', 'max:80'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $action = $validated['action'] ?? 'all';
        $baseQuery = ActivityLog::query();
        $actions = (clone $baseQuery)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->orderBy('action')
            ->pluck('total', 'action');

        $query = ActivityLog::query()->latest();

        if ($action !== 'all') {
            $query->where('action', $action);
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'entries' => $logs->getCollection()->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'actor_name' => $log->actor_name ?? 'System',
                'actor_role' => $log->actor_role,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'metadata' => $log->metadata,
                'metadata_summary' => $this->metadataSummary($log->metadata ?? []),
                'created_at' => $log->created_at?->format('M d, Y h:i A'),
            ])->values(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
            'filters' => [
                'all' => ActivityLog::query()->count(),
                ...$actions->all(),
            ],
        ]);
    }

    public function exportUsers(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Email Verified', 'Username', 'Contact Number', 'Role', 'Account Status', 'Must Reset Password', 'Created At']);

            User::query()
                ->with(['studentProfile', 'providerProfile', 'adminProfile'])
                ->orderBy('id')
                ->chunk(200, function ($users) use ($handle) {
                    foreach ($users as $user) {
                        fputcsv($handle, [
                            $user->id,
                            $user->name,
                            $user->email,
                            $user->hasVerifiedEmail() ? 'Yes' : 'No',
                            $user->username,
                            $user->contact_number,
                            $user->role,
                            $user->account_status ?? 'active',
                            $user->must_reset_password ? 'Yes' : 'No',
                            $user->created_at?->format('Y-m-d H:i:s'),
                        ]);
                    }
                });

            fclose($handle);
        }, 'scholarship-users.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Scholarship', 'Provider', 'Applicant', 'Email', 'Status', 'DSS Score', 'DSS Recommendation', 'Eligibility Score', 'Decision Reason', 'Awarded Amount', 'Distribution Date', 'Distribution Instructions', 'Outcome Date', 'Outcome Notes', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Pending Documents', 'Applicant Notes', 'Review Notes']);

            ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship.provider.providerProfile'])
                ->orderBy('id')
                ->chunk(200, function ($applications) use ($handle) {
                    foreach ($applications as $application) {
                        app(DecisionSupportService::class)->syncApplication($application);
                        fputcsv($handle, [
                            $application->id,
                            $application->scholarship?->title,
                            $application->scholarship?->provider?->provider_name ?? $application->scholarship?->provider?->name,
                            $application->applicant?->name,
                            $application->applicant?->email,
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
                            $application->submitted_at?->format('Y-m-d H:i:s'),
                            implode('; ', $application->document_checklist ?? []),
                            $application->documents->count(),
                            $application->documents->where('status', 'pending')->count(),
                            $application->notes,
                            $application->review_notes,
                        ]);
                    }
                });

            fclose($handle);
        }, 'scholarship-applications.csv', ['Content-Type' => 'text/csv']);
    }

    private function lastActiveAdminWouldBeLost(User $user): bool
    {
        if (! $user->isAdmin() || ! $user->isActive()) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('account_status', 'active')
            ->whereKeyNot($user->id)
            ->doesntExist();
    }

    private function shouldExposeResetUrl(): bool
    {
        return app()->isLocal() || app()->runningUnitTests();
    }

    private function metadataSummary(array $metadata): string
    {
        if ($metadata === []) {
            return '';
        }

        return collect($metadata)
            ->map(fn ($value, string $key) => "{$key}: {$value}")
            ->implode(' | ');
    }

    private function scholarshipReviewPayload(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id,
            'provider_id' => $scholarship->provider_id,
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'description' => $scholarship->description,
            'eligibility' => $scholarship->eligibility,
            'provider' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
            'provider_email' => $scholarship->provider?->email,
            'provider_type' => $scholarship->provider?->provider_type,
            'provider_website' => $scholarship->provider?->provider_website,
            'provider_address' => $scholarship->provider?->provider_address,
            'provider_verification_status' => $scholarship->provider?->providerProfile?->verification_status,
            'status' => $scholarship->status,
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
            'minimum_grade_label' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
            'deadline' => $scholarship->deadline?->format('M d, Y'),
            'requirements' => $scholarship->requirements,
            'review_rubric' => $scholarship->review_rubric ?? [],
            'eligible_courses' => $scholarship->eligible_courses,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
            'location_name' => $scholarship->location_name,
            'location_address' => $scholarship->location_address,
            'latitude' => $scholarship->latitude,
            'longitude' => $scholarship->longitude,
            'map_url' => $this->mapUrl($scholarship),
            'slots_available' => $scholarship->slots_available,
            'application_mode' => $scholarship->application_mode,
            'selection_stages' => ScholarshipSelectionPlan::normalize($scholarship->selection_stages),
            'program_events' => $scholarship->events
                ->sortBy('scheduled_at')
                ->map(fn ($event) => ScholarshipEventPayload::make($event))
                ->values(),
            'renewal_policy' => $scholarship->renewal_policy,
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'bookmarks_count' => $scholarship->bookmarks_count ?? 0,
            'views_count' => $scholarship->views_count,
            'created_at' => $scholarship->created_at?->format('M d, Y h:i A'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y h:i A'),
        ];
    }

    private function scholarshipImageUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return asset('uploads/scholarship-default.jpg');
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

    private function documentPayload(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'download_url' => route('documents.download', $document),
        ];
    }

    private function providerReviewPayload(User $provider): array
    {
        return [
            ...$provider->publicPayload(),
            'verification_documents' => $provider->providerVerificationDocuments
                ->sortByDesc('created_at')
                ->map(fn (ProviderVerificationDocument $document) => $this->verificationDocumentPayload($document))
                ->values(),
            'created_at' => $provider->created_at?->format('M d, Y'),
        ];
    }

    private function applicantReviewPayload(User $applicant): array
    {
        return [
            ...$applicant->publicPayload(),
            'verification_documents' => $applicant->applicantVerificationDocuments
                ->sortByDesc('uploaded_at')
                ->map(fn (ApplicantVerificationDocument $document) => $this->applicantVerificationDocumentPayload($document))
                ->values(),
            'created_at' => $applicant->created_at?->format('M d, Y'),
        ];
    }

    private function verificationDocumentPayload(ProviderVerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'download_url' => route('admin.provider-verification-documents.download', $document),
        ];
    }

    private function applicantVerificationDocumentPayload(ApplicantVerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('admin.applicant-verification-documents.view', $document),
        ];
    }

    private function labelFromKey(string $value): string
    {
        return str($value)->replace('_', ' ')->title()->toString();
    }
}
