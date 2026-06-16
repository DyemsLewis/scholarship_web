<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MobileApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,username'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $middleInitial = strtoupper($validated['middle_initial']);

        $user = User::create([
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => 'applicant',
            'password' => $validated['password'],
        ]);

        $user->studentProfile()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
        ]);

        ActivityLog::record(
            $user,
            'mobile_registered',
            "{$user->name} registered from the mobile app.",
            $request,
        );

        return response()->json([
            'message' => 'Registration complete.',
            'token' => $this->createToken($user),
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            ActivityLog::record(
                null,
                'mobile_login_failed',
                "Failed mobile login attempt for {$validated['email']}.",
                $request,
                ['email' => $validated['email']],
            );

            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 422);
        }

        if ($user->role !== 'applicant') {
            return response()->json([
                'message' => 'Only applicant accounts can use the mobile app.',
            ], 403);
        }

        ActivityLog::record(
            $user,
            'mobile_login',
            "{$user->name} logged in from the mobile app.",
            $request,
        );

        return response()->json([
            'message' => 'Login successful.',
            'token' => $this->createToken($user),
            'user' => $this->userPayload($user),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'user' => $this->userPayload($user),
            'stats' => [
                'available_scholarships' => 0,
                'applications' => 0,
                'saved' => 0,
            ],
            'next_steps' => [
                'Complete your applicant profile.',
                'Watch for scholarship listings.',
                'Prepare supporting documents.',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        ActivityLog::record(
            $user,
            'mobile_logout',
            "{$user->name} logged out from the mobile app.",
            $request,
        );

        $token->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function createToken(User $user): string
    {
        $plainToken = Str::random(80);

        MobileApiToken::create([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
        ]);

        return $plainToken;
    }

    private function resolveToken(Request $request): array
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return [null, null];
        }

        $token = MobileApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return [null, null];
        }

        if ($token->user?->role !== 'applicant') {
            return [null, null];
        }

        return [$token, $token->user];
    }

    private function userPayload(User $user): array
    {
        return $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload();
    }
}
