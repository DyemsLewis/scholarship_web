<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
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
            ->latest()
            ->get([
                'id',
                'name',
                'first_name',
                'last_name',
                'middle_initial',
                'email',
                'username',
                'contact_number',
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'is_admin',
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
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'middle_initial' => $user->middle_initial,
                'email' => $user->email,
                'username' => $user->username,
                'contact_number' => $user->contact_number,
                'provider_name' => $user->provider_name,
                'provider_type' => $user->provider_type,
                'provider_website' => $user->provider_website,
                'provider_address' => $user->provider_address,
                'is_admin' => $user->is_admin,
                'role' => $user->role,
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
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
        $name = trim("{$validated['first_name']} {$middleInitial}. {$validated['last_name']}");

        $user = User::create([
            'name' => $name,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'email' => $validated['email'],
            'username' => $validated['username'],
            'contact_number' => $validated['contact_number'],
            'role' => $validated['role'],
            'is_admin' => $validated['role'] === 'admin',
            'password' => $validated['password'],
        ]);

        ActivityLog::record(
            $request->user(),
            'account_created',
            "{$request->user()->name} created {$user->role} account {$user->email}.",
            $request,
            ['created_user_id' => $user->id, 'created_user_role' => $user->role],
        );

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $user->only(['id', 'name', 'email', 'username', 'role', 'is_admin']),
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
}
