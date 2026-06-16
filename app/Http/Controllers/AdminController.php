<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

    public function manageUsers(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-users');
    }

    public function reviews(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isAdmin(), 403);

        return view('admin-reviews');
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

        $users = User::query()
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->latest()
            ->get([
                'id',
                'email',
                'username',
                'role',
                'created_at',
            ]);

        return response()->json([
            'stats' => [
                'total_users' => $users->count(),
                'admins' => $users->where('role', 'admin')->count(),
                'applicants' => $users->where('role', 'applicant')->count(),
                'providers' => $users->where('role', 'provider')->count(),
                'recent_signups' => $users->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'users' => $users->map(fn (User $user) => [
                ...$user->publicPayload(),
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $users = User::query()
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->latest()
            ->get(['id', 'email', 'username', 'role', 'created_at']);
        $scholarships = Scholarship::query()->get(['id', 'title', 'provider_id', 'status', 'deadline', 'created_at']);
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'scholarship.provider.providerProfile'])
            ->latest('submitted_at')
            ->get();
        $applicationStatuses = $applications
            ->groupBy('status')
            ->map(fn ($items) => $items->count())
            ->all();
        $now = now();
        $upcomingDeadlineLimit = now()->addDays(30);

        return response()->json([
            'stats' => [
                'total_users' => $users->count(),
                'applicants' => $users->where('role', 'applicant')->count(),
                'providers' => $users->where('role', 'provider')->count(),
                'admins' => $users->where('role', 'admin')->count(),
                'recent_signups' => $users->where('created_at', '>=', now()->subDays(7))->count(),
                'scholarships' => $scholarships->count(),
                'published_scholarships' => $scholarships->where('status', 'published')->count(),
                'draft_scholarships' => $scholarships->where('status', 'draft')->count(),
                'applications' => $applications->count(),
                'recent_applications' => $applications->where('submitted_at', '>=', now()->subDays(7))->count(),
                'pending_providers' => $users
                    ->where('role', 'provider')
                    ->filter(fn (User $user) => $user->providerProfile?->verification_status === 'pending')
                    ->count(),
                'upcoming_deadlines' => $scholarships
                    ->where('status', 'published')
                    ->filter(fn (Scholarship $scholarship) => $scholarship->deadline && $scholarship->deadline->between($now, $upcomingDeadlineLimit))
                    ->count(),
                'expired_published' => $scholarships
                    ->where('status', 'published')
                    ->filter(fn (Scholarship $scholarship) => $scholarship->deadline && $scholarship->deadline->isPast())
                    ->count(),
            ],
            'application_statuses' => [
                'submitted' => $applicationStatuses['submitted'] ?? 0,
                'under_review' => $applicationStatuses['under_review'] ?? 0,
                'qualified' => $applicationStatuses['qualified'] ?? 0,
                'approved' => $applicationStatuses['approved'] ?? 0,
                'rejected' => $applicationStatuses['rejected'] ?? 0,
            ],
            'deadline_watch' => $scholarships
                ->where('status', 'published')
                ->filter(fn (Scholarship $scholarship) => $scholarship->deadline)
                ->sortBy('deadline')
                ->take(6)
                ->map(fn (Scholarship $scholarship) => [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'deadline' => $scholarship->deadline?->format('M d, Y'),
                    'days_left' => $scholarship->deadline ? now()->startOfDay()->diffInDays($scholarship->deadline->startOfDay(), false) : null,
                ])
                ->values(),
            'recent_users' => $users->take(5)->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
            'recent_applications_list' => $applications->take(5)->map(fn (ScholarshipApplication $application) => [
                'id' => $application->id,
                'applicant' => $application->applicant?->name,
                'scholarship' => $application->scholarship?->title,
                'status' => $application->status,
                'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ])->values(),
            'monthly_applications' => collect(range(5, 0))
                ->map(function (int $monthsAgo) use ($applications) {
                    $month = now()->subMonths($monthsAgo);

                    return [
                        'label' => $month->format('M Y'),
                        'total' => $applications
                            ->filter(fn (ScholarshipApplication $application) => $application->submitted_at?->format('Y-m') === $month->format('Y-m'))
                            ->count(),
                    ];
                })
                ->values(),
        ]);
    }

    public function reviewsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $providers = User::query()
            ->with('providerProfile')
            ->where('role', 'provider')
            ->latest()
            ->get(['id', 'email', 'username', 'role', 'created_at']);
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship.provider.providerProfile'])
            ->latest('submitted_at')
            ->limit(8)
            ->get();

        return response()->json([
            'stats' => [
                'providers' => $providers->count(),
                'pending_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'pending')->count(),
                'approved_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'approved')->count(),
                'rejected_providers' => $providers->filter(fn (User $user) => $user->providerProfile?->verification_status === 'rejected')->count(),
                'recent_applications' => $applications->count(),
            ],
            'providers' => $providers->map(fn (User $user) => [
                ...$user->publicPayload(),
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => [
                'id' => $application->id,
                'applicant' => $application->applicant?->name,
                'scholarship' => $application->scholarship?->title,
                'provider' => $application->scholarship?->provider?->name,
                'status' => $application->status,
                'documents_uploaded' => $application->documents->count(),
                'review_notes' => $application->review_notes,
                'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ])->values(),
        ]);
    }

    public function updateProviderVerification(Request $request, User $provider): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($provider->isProvider(), 404);

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:pending,approved,rejected'],
            'verification_notes' => ['nullable', 'string', 'max:1500'],
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

        PortalNotification::create([
            'user_id' => $provider->id,
            'type' => 'provider_verification',
            'title' => 'Provider verification updated',
            'message' => "Your provider account is now {$validated['verification_status']}.",
            'action_url' => '/provider',
        ]);

        return response()->json([
            'message' => 'Provider verification updated.',
            'provider' => $provider->fresh('providerProfile')->publicPayload(),
        ]);
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
            fputcsv($handle, ['ID', 'Name', 'Email', 'Username', 'Contact Number', 'Role', 'Created At']);

            User::query()
                ->with(['studentProfile', 'providerProfile', 'adminProfile'])
                ->orderBy('id')
                ->chunk(200, function ($users) use ($handle) {
                    foreach ($users as $user) {
                        fputcsv($handle, [
                            $user->id,
                            $user->name,
                            $user->email,
                            $user->username,
                            $user->contact_number,
                            $user->role,
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
            fputcsv($handle, ['ID', 'Scholarship', 'Provider', 'Applicant', 'Email', 'Status', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Applicant Notes', 'Review Notes']);

            ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship.provider.providerProfile'])
                ->orderBy('id')
                ->chunk(200, function ($applications) use ($handle) {
                    foreach ($applications as $application) {
                        fputcsv($handle, [
                            $application->id,
                            $application->scholarship?->title,
                            $application->scholarship?->provider?->provider_name ?? $application->scholarship?->provider?->name,
                            $application->applicant?->name,
                            $application->applicant?->email,
                            $application->status,
                            $application->submitted_at?->format('Y-m-d H:i:s'),
                            implode('; ', $application->document_checklist ?? []),
                            $application->documents->count(),
                            $application->notes,
                            $application->review_notes,
                        ]);
                    }
                });

            fclose($handle);
        }, 'scholarship-applications.csv', ['Content-Type' => 'text/csv']);
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
}
