<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\ActivityLog;
use App\Models\PendingRegistration;
use App\Models\PortalNotification;
use App\Models\User;
use App\Services\PasswordResetLinkService;
use App\Support\Terms;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate($this->registrationRules($request));
        $email = strtolower(trim($validated['email']));
        $username = trim($validated['username']);

        PendingRegistration::query()->where('expires_at', '<', now())->delete();

        $usernameConflict = PendingRegistration::query()
            ->where('username', $username)
            ->where('email', '!=', $email)
            ->exists();

        if ($usernameConflict) {
            throw ValidationException::withMessages([
                'username' => 'This username is already being used by another pending registration.',
            ]);
        }

        $verificationCode = $this->newRegistrationCode();
        $pending = PendingRegistration::query()->firstOrNew(['email' => $email]);
        $pending->fill([
            'token' => Str::random(64),
            'email' => $email,
            'username' => $username,
            'role' => $validated['role'],
            'payload' => collect($validated)->except([
                'email',
                'username',
                'password',
                'password_confirmation',
                'terms_accepted',
            ])->all(),
            'password_hash' => Hash::make($validated['password']),
            'code_hash' => Hash::make($verificationCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(PendingRegistration::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ])->save();

        if (! $this->sendRegistrationCode($pending, $verificationCode, $request)) {
            return response()->json([
                'message' => 'We could not send the verification code. Check the mail settings and try again.',
            ], 503);
        }

        ActivityLog::record(
            null,
            'registration_code_sent',
            "A registration code was sent to {$email}.",
            $request,
            ['email' => $email, 'role' => $pending->role],
        );

        return response()->json([
            'message' => 'We sent a six-digit verification code to your email.',
            'registration_token' => $pending->token,
            'email' => $this->maskEmail($pending->email),
            'expires_in' => PendingRegistration::CODE_TTL_MINUTES * 60,
            'resend_after' => PendingRegistration::RESEND_COOLDOWN_SECONDS,
        ], 202);
    }

    public function verifyRegistrationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
        ]);

        return DB::transaction(function () use ($validated, $request): JsonResponse {
            $pending = PendingRegistration::query()
                ->where('token', $validated['registration_token'])
                ->lockForUpdate()
                ->first();

            if (! $pending || $pending->expires_at->isPast()) {
                $pending?->delete();

                return response()->json([
                    'message' => 'This verification code has expired. Return to the registration form to request a new code.',
                    'restart_required' => true,
                ], 422);
            }

            if (! Hash::check($validated['code'], $pending->code_hash)) {
                $pending->increment('attempts');
                $remainingAttempts = max(0, PendingRegistration::MAX_ATTEMPTS - $pending->attempts);

                if ($remainingAttempts === 0) {
                    $pending->delete();

                    return response()->json([
                        'message' => 'Too many incorrect attempts. Return to the registration form to request a new code.',
                        'restart_required' => true,
                    ], 422);
                }

                return response()->json([
                    'message' => "The verification code is incorrect. {$remainingAttempts} attempt".($remainingAttempts === 1 ? '' : 's').' remaining.',
                    'attempts_remaining' => $remainingAttempts,
                ], 422);
            }

            if (User::query()->where('email', $pending->email)->exists()) {
                $pending->delete();

                return response()->json([
                    'message' => 'An account with this email already exists. Please log in instead.',
                    'restart_required' => true,
                ], 422);
            }

            if (User::query()->where('username', $pending->username)->exists()) {
                $pending->delete();

                return response()->json([
                    'message' => 'This username became unavailable. Return to registration and choose another username.',
                    'restart_required' => true,
                ], 422);
            }

            $profileData = $pending->payload;
            $user = User::create([
                'email' => $pending->email,
                'username' => $pending->username,
                'role' => $pending->role,
                'password' => $pending->password_hash,
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'terms_version' => Terms::VERSION,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $profile = [
                'first_name' => $profileData['first_name'],
                'last_name' => $profileData['last_name'],
                'middle_initial' => strtoupper($profileData['middle_initial']),
                'contact_number' => $profileData['number'],
            ];

            if ($user->isProvider()) {
                $user->providerProfile()->create([
                    ...$profile,
                    'provider_name' => $profileData['provider_name'],
                    'provider_type' => $profileData['provider_type'],
                    'provider_website' => $profileData['provider_website'] ?? null,
                    'provider_address' => $profileData['provider_address'],
                    'provider_description' => $profileData['provider_description'] ?? null,
                ]);
            } else {
                $user->studentProfile()->create($profile);
            }

            $pending->delete();

            ActivityLog::record(
                $user,
                'registered',
                "{$user->name} verified their email and registered as {$user->role}.",
                $request,
            );

            return response()->json([
                'message' => 'Email verified. Your account has been created successfully.',
                'redirect' => '/login?registered=1&verified=1',
                'email_verified' => true,
            ], 201);
        });
    }

    public function resendRegistrationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_token' => ['required', 'string', 'size:64'],
        ]);
        $pending = PendingRegistration::query()
            ->where('token', $validated['registration_token'])
            ->first();

        if (! $pending || $pending->expires_at->isPast()) {
            $pending?->delete();

            return response()->json([
                'message' => 'This registration has expired. Return to the form to start again.',
                'restart_required' => true,
            ], 422);
        }

        $availableAt = $pending->last_sent_at?->copy()->addSeconds(PendingRegistration::RESEND_COOLDOWN_SECONDS);

        if ($availableAt?->isFuture()) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'retry_after' => max(1, (int) ceil(now()->diffInSeconds($availableAt))),
            ], 429);
        }

        $verificationCode = $this->newRegistrationCode();
        $pending->update([
            'code_hash' => Hash::make($verificationCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(PendingRegistration::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ]);

        if (! $this->sendRegistrationCode($pending, $verificationCode, $request)) {
            return response()->json([
                'message' => 'We could not resend the verification code. Check the mail settings and try again.',
            ], 503);
        }

        return response()->json([
            'message' => 'A new verification code was sent to your email.',
            'expires_in' => PendingRegistration::CODE_TTL_MINUTES * 60,
            'resend_after' => PendingRegistration::RESEND_COOLDOWN_SECONDS,
        ]);
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

        if ($request->user()->isSuspended()) {
            ActivityLog::record(
                $request->user(),
                'login_blocked_suspended',
                "{$request->user()->name} attempted to log in while suspended.",
                $request,
            );

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account is suspended. Contact an administrator for help.',
            ], 403);
        }

        if ($request->user()->must_reset_password) {
            $reset = app(PasswordResetLinkService::class)->prepare($request->user(), $request);

            ActivityLog::record(
                $request->user(),
                'login_blocked_password_reset_required',
                "{$request->user()->name} attempted to log in with a required password reset.",
                $request,
                ['email_sent' => $reset['email_sent']],
            );

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => $reset['email_sent']
                    ? 'A password reset is required. We sent a reset link to your email.'
                    : 'A password reset is required, but the reset email could not be sent yet.',
                'reset_url' => $this->shouldExposeResetUrl() ? $reset['reset_url'] : null,
            ], 423);
        }

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
            'email_verified' => $request->user()->hasVerifiedEmail(),
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse|JsonResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(hash_equals((string) $hash, sha1($user->getEmailForVerification())), 403);

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
                'message' => 'Your email address has been verified successfully.',
                'action_url' => $user->isProvider() ? '/provider' : '/dashboard',
            ]);

            ActivityLog::record(
                $user,
                'email_verified',
                "{$user->name} verified their email address.",
                $request,
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Email verified successfully.',
                'email_verified' => true,
            ]);
        }

        if (! $request->user()) {
            return redirect('/login?verified=1');
        }

        $redirect = match (true) {
            $request->user()->isAdmin() => '/admin',
            $request->user()->isProvider() => '/provider',
            default => '/dashboard',
        };

        return redirect($redirect.'?verified=1');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email is already verified.',
                'email_verified' => true,
                'email_verification_sent' => false,
            ]);
        }

        $emailVerificationSent = $this->sendVerificationEmail($user, $request);
        $this->syncEmailVerificationReminder($user, $emailVerificationSent);

        ActivityLog::record(
            $user,
            'email_verification_resent',
            "{$user->name} requested another email verification link.",
            $request,
        );

        return response()->json([
            'message' => $emailVerificationSent
                ? 'Verification email sent. Please check your inbox.'
                : 'Unable to send the verification email right now. Check the mail settings and try again.',
            'email_verified' => false,
            'email_verification_sent' => $emailVerificationSent,
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
            $reset = app(PasswordResetLinkService::class)->prepare($user, $request);
            $resetUrl = $reset['reset_url'];

            ActivityLog::record(
                $user,
                'password_reset_requested',
                "{$user->name} requested a password reset link.",
                $request,
            );
        }

        return response()->json([
            'message' => 'If that email exists, a password reset link has been prepared.',
            'reset_url' => $this->shouldExposeResetUrl() ? $resetUrl : null,
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

        if (! $record || ! Hash::check($validated['token'], $record->token) || Carbon::parse($record->created_at)->lessThan(now()->subHour())) {
            return response()->json([
                'message' => 'The reset link is invalid or expired.',
            ], 422);
        }

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->forceFill([
            'password' => $validated['password'],
            'must_reset_password' => false,
            'password_reset_required_at' => null,
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

    private function shouldExposeResetUrl(): bool
    {
        return app()->isLocal() || app()->runningUnitTests();
    }

    private function registrationRules(Request $request): array
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
            'terms_accepted' => ['accepted'],
        ];

        if ($request->input('role') !== 'provider') {
            return $rules;
        }

        return [
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

    private function newRegistrationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendRegistrationCode(PendingRegistration $pending, string $code, Request $request): bool
    {
        try {
            Mail::to($pending->email)->send(new RegistrationVerificationCodeMail(
                $pending->payload['first_name'] ?? 'Applicant',
                $code,
                PendingRegistration::CODE_TTL_MINUTES,
            ));

            return true;
        } catch (Throwable $error) {
            ActivityLog::record(
                null,
                'registration_code_email_failed',
                "A registration code could not be sent to {$pending->email}.",
                $request,
                ['email' => $pending->email, 'error' => $error->getMessage()],
            );

            return false;
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        $hiddenLength = max(1, mb_strlen($local) - mb_strlen($visible));

        return $visible.str_repeat('*', $hiddenLength).'@'.$domain;
    }

    private function sendVerificationEmail(User $user, Request $request): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $error) {
            ActivityLog::record(
                $user,
                'email_verification_email_failed',
                "Email verification link could not be sent to {$user->email}.",
                $request,
                ['error' => $error->getMessage()],
            );

            return false;
        }
    }

    private function syncEmailVerificationReminder(User $user, bool $emailSent): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        PortalNotification::updateOrCreate([
            'user_id' => $user->id,
            'type' => 'email_verification',
            'title' => 'Verify your email address',
        ], [
            'message' => $emailSent
                ? 'A verification link was sent to your email. Verify your email to secure your account.'
                : 'Your email is not verified yet. Resend the verification link when mail settings are available.',
            'action_url' => null,
            'read_at' => null,
        ]);
    }
}
