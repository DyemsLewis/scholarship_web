<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,username'],
            'number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'role' => ['required', 'string', 'in:applicant,provider'],
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
            'contact_number' => $validated['number'],
            'role' => $validated['role'],
            'is_admin' => false,
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        ActivityLog::record(
            $user,
            'registered',
            "{$user->name} registered as {$user->role}.",
            $request,
        );

        return response()->json([
            'message' => 'Registration complete. You are now signed in.',
            'redirect' => $user->isProvider() ? '/provider' : '/',
            'user' => $user->only(['id', 'name', 'email', 'username', 'role', 'is_admin']),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $remember)) {
            ActivityLog::record(
                null,
                'login_failed',
                "Failed login attempt for {$credentials['email']}.",
                $request,
                ['email' => $credentials['email']],
            );

            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 422);
        }

        $request->session()->regenerate();
        ActivityLog::record(
            $request->user(),
            'login',
            "{$request->user()->name} logged in.",
            $request,
        );

        return response()->json([
            'message' => 'Login successful.',
            'redirect' => match (true) {
                $request->user()->isAdmin() => '/admin',
                $request->user()->isProvider() => '/provider',
                default => '/',
            },
            'user' => $request->user()->only(['id', 'name', 'email', 'username', 'role', 'is_admin']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        ActivityLog::record(
            $user,
            'logout',
            "{$user?->name} logged out.",
            $request,
        );

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
