<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetLinkService
{
    public function prepare(User $user, Request $request): array
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert([
            'email' => $user->email,
        ], [
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = url('/reset-password').'?token='.$token.'&email='.urlencode($user->email);
        $emailSent = true;

        try {
            Mail::raw(
                "Hello {$user->name},\n\nUse this link to reset your Scholarship Portal password:\n\n{$resetUrl}\n\nThis link expires in one hour. If you did not request this, you can ignore this email.",
                fn ($message) => $message
                    ->to($user->email)
                    ->subject('Scholarship Portal password reset'),
            );
        } catch (Throwable $error) {
            $emailSent = false;

            ActivityLog::record(
                $user,
                'password_reset_email_failed',
                "Password reset email could not be sent to {$user->email}.",
                $request,
                ['error' => $error->getMessage()],
            );
        }

        return [
            'reset_url' => $resetUrl,
            'email_sent' => $emailSent,
        ];
    }
}
