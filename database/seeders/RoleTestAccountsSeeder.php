<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RoleTestAccountsSeeder extends Seeder
{
    /**
     * Create verified local accounts for checking role-based navigation and access.
     */
    public function run(): void
    {
        $password = env('DEMO_PASSWORD', 'password123');

        $adminOwner = User::query()
            ->where('role', 'admin')
            ->whereNull('parent_account_id')
            ->first();
        $providerOwner = User::query()
            ->where('email', env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'))
            ->where('role', 'provider')
            ->whereNull('parent_account_id')
            ->with('providerProfile')
            ->first();

        if (! $adminOwner || ! $providerOwner) {
            throw new RuntimeException('Seed the demo admin and Tulay Aral provider before creating role test accounts.');
        }

        DB::transaction(function () use ($adminOwner, $providerOwner, $password): void {
            foreach ($this->providerAccounts() as $account) {
                $user = $this->seedManagedUser(
                    owner: $providerOwner,
                    role: 'provider',
                    account: $account,
                    password: $password,
                );

                $ownerProfile = $providerOwner->providerProfile;
                $user->providerProfile()->updateOrCreate([
                    'user_id' => $user->id,
                ], [
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'middle_initial' => $account['middle_initial'],
                    'contact_number' => $account['contact_number'],
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
            }

            foreach ($this->adminAccounts() as $account) {
                $user = $this->seedManagedUser(
                    owner: $adminOwner,
                    role: 'admin',
                    account: $account,
                    password: $password,
                );

                $user->adminProfile()->updateOrCreate([
                    'user_id' => $user->id,
                ], [
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'middle_initial' => $account['middle_initial'],
                    'contact_number' => $account['contact_number'],
                    'display_name' => "{$account['first_name']} {$account['middle_initial']}. {$account['last_name']}",
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function seedManagedUser(User $owner, string $role, array $account, string $password): User
    {
        $user = User::query()->updateOrCreate([
            'email' => $account['email'],
        ], [
            'parent_account_id' => $owner->id,
            'username' => $account['username'],
            'role' => $role,
            'account_title' => $account['account_title'],
            'permissions' => $account['permissions'],
            'assigned_program_ids' => null,
            'password' => $password,
            'account_status' => 'active',
            'must_reset_password' => false,
            'password_reset_required_at' => null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** @return array<int, array<string, mixed>> */
    private function providerAccounts(): array
    {
        return [
            [
                'email' => 'tulay.manager@scholarship.test',
                'username' => 'tulay.manager',
                'first_name' => 'Marco',
                'middle_initial' => 'A',
                'last_name' => 'Villanueva',
                'contact_number' => '09170000011',
                'account_title' => 'manager',
                'permissions' => ['manage_programs', 'review_applications', 'manage_reports', 'manage_profile', 'manage_team', 'manage_billing'],
            ],
            [
                'email' => 'tulay.programs@scholarship.test',
                'username' => 'tulay.programs',
                'first_name' => 'Patricia',
                'middle_initial' => 'B',
                'last_name' => 'Gomez',
                'contact_number' => '09170000012',
                'account_title' => 'program_coordinator',
                'permissions' => ['manage_programs'],
            ],
            [
                'email' => 'tulay.reviewer@scholarship.test',
                'username' => 'tulay.reviewer',
                'first_name' => 'Rafael',
                'middle_initial' => 'C',
                'last_name' => 'Torres',
                'contact_number' => '09170000013',
                'account_title' => 'application_reviewer',
                'permissions' => ['review_applications'],
            ],
            [
                'email' => 'tulay.support@scholarship.test',
                'username' => 'tulay.support',
                'first_name' => 'Sofia',
                'middle_initial' => 'D',
                'last_name' => 'Lim',
                'contact_number' => '09170000014',
                'account_title' => 'support_staff',
                'permissions' => ['manage_reports'],
            ],
            [
                'email' => 'tulay.billing@scholarship.test',
                'username' => 'tulay.billing',
                'first_name' => 'Daniel',
                'middle_initial' => 'E',
                'last_name' => 'Reyes',
                'contact_number' => '09170000015',
                'account_title' => 'billing_staff',
                'permissions' => ['manage_billing'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function adminAccounts(): array
    {
        return [
            [
                'email' => 'admin.accounts@scholarship.test',
                'username' => 'admin.accounts',
                'first_name' => 'Alyssa',
                'middle_initial' => 'A',
                'last_name' => 'Cruz',
                'contact_number' => '09170000021',
                'account_title' => 'Account manager',
                'permissions' => ['manage_accounts'],
            ],
            [
                'email' => 'admin.reviews@scholarship.test',
                'username' => 'admin.reviews',
                'first_name' => 'Ben',
                'middle_initial' => 'B',
                'last_name' => 'Dela Cruz',
                'contact_number' => '09170000022',
                'account_title' => 'Review officer',
                'permissions' => ['manage_reviews'],
            ],
            [
                'email' => 'admin.support@scholarship.test',
                'username' => 'admin.support',
                'first_name' => 'Carla',
                'middle_initial' => 'C',
                'last_name' => 'Ramos',
                'contact_number' => '09170000023',
                'account_title' => 'Support officer',
                'permissions' => ['manage_reports'],
            ],
            [
                'email' => 'admin.billing@scholarship.test',
                'username' => 'admin.billing',
                'first_name' => 'Diego',
                'middle_initial' => 'D',
                'last_name' => 'Santos',
                'contact_number' => '09170000024',
                'account_title' => 'Billing officer',
                'permissions' => ['manage_billing'],
            ],
            [
                'email' => 'admin.finance@scholarship.test',
                'username' => 'admin.finance',
                'first_name' => 'Ella',
                'middle_initial' => 'E',
                'last_name' => 'Navarro',
                'contact_number' => '09170000025',
                'account_title' => 'Finance officer',
                'permissions' => ['view_finance'],
            ],
            [
                'email' => 'admin.records@scholarship.test',
                'username' => 'admin.records',
                'first_name' => 'Felix',
                'middle_initial' => 'F',
                'last_name' => 'Garcia',
                'contact_number' => '09170000026',
                'account_title' => 'Records officer',
                'permissions' => ['view_logs', 'export_data'],
            ],
            [
                'email' => 'admin.manager@scholarship.test',
                'username' => 'admin.manager',
                'first_name' => 'Grace',
                'middle_initial' => 'G',
                'last_name' => 'Mendoza',
                'contact_number' => '09170000027',
                'account_title' => 'Portal manager',
                'permissions' => ['manage_accounts', 'manage_reviews', 'manage_reports', 'manage_billing', 'view_finance', 'view_logs', 'export_data'],
            ],
        ];
    }
}
