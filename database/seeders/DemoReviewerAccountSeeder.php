<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoReviewerAccountSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()
            ->where('email', env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'))
            ->where('role', 'provider')
            ->whereNull('parent_account_id')
            ->with('providerProfile')
            ->firstOrFail();

        $reviewer = DB::transaction(function () use ($owner): User {
            $reviewer = User::query()->updateOrCreate([
                'email' => 'tulay.reviewer@scholarship.test',
            ], [
                'parent_account_id' => $owner->id,
                'username' => 'tulay.reviewer',
                'role' => 'provider',
                'account_title' => 'application_reviewer',
                'permissions' => ['verify_applications'],
                'assigned_program_ids' => null,
                'password' => env('DEMO_PASSWORD', 'password123'),
                'account_status' => 'active',
                'must_reset_password' => false,
                'password_reset_required_at' => null,
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'terms_version' => Terms::VERSION,
            ]);
            $reviewer->forceFill(['email_verified_at' => now()])->save();

            $ownerProfile = $owner->providerProfile;
            $reviewer->providerProfile()->updateOrCreate([
                'user_id' => $reviewer->id,
            ], [
                'first_name' => 'Rafael',
                'middle_initial' => 'C',
                'last_name' => 'Torres',
                'contact_number' => '09170000013',
                'provider_name' => $ownerProfile?->provider_name,
                'provider_type' => $ownerProfile?->provider_type,
                'provider_website' => $ownerProfile?->provider_website,
                'provider_address' => $ownerProfile?->provider_address,
                'provider_description' => $ownerProfile?->provider_description,
                'logo_path' => $ownerProfile?->logo_path,
                'provider_contact_email' => $ownerProfile?->provider_contact_email,
                'provider_contact_number' => $ownerProfile?->provider_contact_number,
                'verification_status' => $ownerProfile?->verification_status ?? 'approved',
                'verification_notes' => $ownerProfile?->verification_notes,
                'verified_by' => $ownerProfile?->verified_by,
                'verified_at' => $ownerProfile?->verified_at,
            ]);

            return $reviewer;
        });

        $this->command?->info("Reviewer account ready: {$reviewer->username}");
    }
}
