<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Rules\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class ProviderAccountController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorizeProvider($request);

        return view('provider-account');
    }

    public function data(Request $request): JsonResponse
    {
        $user = $this->authorizeProvider($request);

        return response()->json([
            'account' => $this->accountPayload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->authorizeProvider($request);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', new PhoneNumber],
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $emailChanged = $user->email !== $validated['email'];

        DB::transaction(function () use ($user, $validated, $emailChanged): void {
            $user->forceFill([
                'email' => $validated['email'],
                'username' => $validated['username'],
                ...($emailChanged ? ['email_verified_at' => null] : []),
            ])->save();

            $user->providerProfile()->updateOrCreate(['user_id' => $user->id], [
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'middle_initial' => filled($validated['middle_initial'] ?? null)
                    ? strtoupper($validated['middle_initial'])
                    : null,
                'contact_number' => trim($validated['contact_number']),
            ]);
        });

        ActivityLog::record(
            $user,
            'provider_account_updated',
            "{$user->fresh('providerProfile')->name} updated their provider account.",
            $request,
            ['provider_user_id' => $user->id, 'email_changed' => $emailChanged],
        );

        $verificationEmailSent = null;
        if ($emailChanged) {
            $verificationEmailSent = true;

            try {
                $user->fresh()->sendEmailVerificationNotification();
            } catch (Throwable $error) {
                $verificationEmailSent = false;
                report($error);
            }
        }

        return response()->json([
            'message' => $emailChanged
                ? 'Account updated. Verify the new email address before your next sign-in.'
                : 'Account details updated successfully.',
            'email_verification_sent' => $verificationEmailSent,
            'account' => $this->accountPayload($user->fresh('providerProfile')),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->authorizeProvider($request);
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
            'must_reset_password' => false,
            'password_reset_required_at' => null,
        ])->save();

        ActivityLog::record(
            $user,
            'provider_password_updated',
            "{$user->name} changed their provider account password.",
            $request,
            ['provider_user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    private function authorizeProvider(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->isProvider(), 403);

        return $user->loadMissing('providerProfile');
    }

    private function accountPayload(User $user): array
    {
        $user->loadMissing('providerProfile');
        $owner = $user->providerOrganizationOwner()->loadMissing('providerProfile');
        $profile = $user->providerProfile;
        $personalName = collect([
            $profile?->first_name,
            $profile?->middle_initial ? strtoupper($profile->middle_initial).'.' : null,
            $profile?->last_name,
        ])->filter()->implode(' ');

        return [
            'id' => $user->id,
            'name' => $personalName ?: ($user->username ?: $user->email),
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'middle_initial' => $profile?->middle_initial,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'username' => $user->username,
            'contact_number' => $profile?->contact_number,
            'role_label' => $user->isManagedAccount()
                ? Str::headline($user->account_title ?: 'team member')
                : 'Organization owner',
            'organization_name' => $owner->providerProfile?->provider_name ?: $owner->name,
        ];
    }
}
