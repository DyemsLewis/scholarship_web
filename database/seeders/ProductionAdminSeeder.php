<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        $credentials = config('platform.bootstrap_admin', []);
        $validator = Validator::make($credentials, [
            'email' => ['required', 'email:rfc', 'max:255'],
            'username' => ['required', 'alpha_dash', 'min:3', 'max:50'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Configure a valid ADMIN_EMAIL, ADMIN_USERNAME, and strong ADMIN_PASSWORD before creating the production administrator.');
        }

        $password = (string) $credentials['password'];
        if (in_array(strtolower($password), ['password123', 'change-this-before-seeding', 'changeme'], true)) {
            throw new RuntimeException('Replace the placeholder ADMIN_PASSWORD before creating the production administrator.');
        }

        $existing = User::query()
            ->where('email', $credentials['email'])
            ->orWhere('username', $credentials['username'])
            ->first();

        if ($existing) {
            if (! $existing->isAdmin()) {
                throw new RuntimeException('The configured production administrator email or username already belongs to a non-admin account.');
            }

            $this->command?->info('The production administrator already exists; no credentials were changed.');

            return;
        }

        $admin = User::create([
            'email' => $credentials['email'],
            'username' => $credentials['username'],
            'role' => 'admin',
            'password' => $password,
            'account_status' => 'active',
            'must_reset_password' => false,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->adminProfile()->create([
            'first_name' => 'Portal',
            'last_name' => 'Administrator',
            'display_name' => 'Portal Administrator',
        ]);

        $this->command?->info('Production administrator created.');
    }
}
