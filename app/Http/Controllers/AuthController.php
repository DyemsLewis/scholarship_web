<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,username'],
            'number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'role' => ['required', 'string', 'in:applicant,provider'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if ($request->input('role') === 'provider') {
            $rules = [
                ...$rules,
                'provider_name' => ['required', 'string', 'max:255'],
                'provider_type' => ['required', 'string', Rule::in([
                    'school',
                    'foundation',
                    'government',
                    'company',
                    'non_profit',
                    'other',
                ])],
                'provider_website' => ['nullable', 'url', 'max:255'],
                'provider_address' => ['required', 'string', 'max:500'],
                'provider_description' => ['nullable', 'string', 'max:1000'],
            ];
        }

        $validated = $request->validate($rules);

        $middleInitial = strtoupper($validated['middle_initial']);

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
            'contact_number' => $validated['number'],
        ];

        if ($user->isProvider()) {
            $user->providerProfile()->create([
                ...$profile,
                'provider_name' => $validated['provider_name'],
                'provider_type' => $validated['provider_type'],
                'provider_website' => $validated['provider_website'] ?? null,
                'provider_address' => $validated['provider_address'],
                'provider_description' => $validated['provider_description'] ?? null,
            ]);
        } else {
            $user->studentProfile()->create($profile);
        }

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
            'redirect' => $user->isProvider() ? '/provider' : '/dashboard',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
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
                default => '/dashboard',
            },
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        $resetUrl = null;

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert([
                'email' => $validated['email'],
            ], [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            $resetUrl = url('/reset-password').'?token='.$token.'&email='.urlencode($validated['email']);

            ActivityLog::record(
                $user,
                'password_reset_requested',
                "{$user->name} requested a password reset link.",
                $request,
            );
        }

        return response()->json([
            'message' => 'If that email exists, a password reset link has been prepared.',
            'reset_url' => app()->isLocal() ? $resetUrl : null,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

        if (! $record || ! Hash::check($validated['token'], $record->token) || \Illuminate\Support\Carbon::parse($record->created_at)->lessThan(now()->subHour())) {
            return response()->json([
                'message' => 'The reset link is invalid or expired.',
            ], 422);
        }

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        ActivityLog::record(
            $user,
            'password_reset_completed',
            "{$user->name} reset their password.",
            $request,
        );

        return response()->json([
            'message' => 'Password reset successful. You can now log in.',
            'redirect' => '/login',
        ]);
    }
}
