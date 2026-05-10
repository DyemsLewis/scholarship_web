<?php

namespace App\Http\Controllers;

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
                'is_admin',
                'role',
                'created_at',
            ]);

        return response()->json([
            'stats' => [
                'total_users' => $users->count(),
                'admins' => $users->where('role', 'admin')->count(),
                'applicants' => $users->where('role', 'applicant')->count(),
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
                'is_admin' => $user->is_admin,
                'role' => $user->role,
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
        ]);
    }
}
