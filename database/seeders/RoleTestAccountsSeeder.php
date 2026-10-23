<?php

namespace Database\Seeders;

use App\Models\ScholarshipApplication;
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
        $providerOwners = User::query()
            ->where('role', 'provider')
            ->whereNull('parent_account_id')
            ->whereHas('providerProfile', fn ($query) => $query->where('verification_status', 'approved'))
            ->with('providerProfile')
            ->orderBy('id')
            ->get();

        if (! $adminOwner || $providerOwners->isEmpty()) {
            throw new RuntimeException('Seed the demo admin and approved providers before creating role test accounts.');
        }

        DB::transaction(function () use ($adminOwner, $providerOwners, $password): void {
            foreach ($providerOwners as $providerOwner) {
                foreach ($this->providerAccounts($providerOwner) as $account) {
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

                $reviewer = User::query()
                    ->where('parent_account_id', $providerOwner->id)
                    ->where('username', $providerOwner->username.'.reviewer')
                    ->first();
                $reviewApplicationIds = ScholarshipApplication::query()
                    ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $providerOwner->id))
                    ->where('workflow_stage', 'screening')
                    ->whereNull('assigned_reviewer_id')
                    ->orderBy('id')
                    ->limit(2)
                    ->pluck('id');

                if ($reviewer && $reviewApplicationIds->isNotEmpty()) {
                    ScholarshipApplication::query()
                        ->whereIn('id', $reviewApplicationIds)
                        ->update(['assigned_reviewer_id' => $reviewer->id]);
                }
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
        $assignedProgramIds = $this->assignedProgramIdsForAccount($owner, $role, $account);
        $user = User::query()->updateOrCreate([
            'email' => $account['email'],
        ], [
            'parent_account_id' => $owner->id,
            'username' => $account['username'],
            'role' => $role,
            'account_title' => $account['account_title'],
            'permissions' => $account['permissions'],
            'assigned_program_ids' => $assignedProgramIds,
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

    /**
     * Give the demo team realistic portfolio boundaries instead of granting every
     * operational account access to every scholarship in its organization.
     *
     * @param  array<string, mixed>  $account
     * @return array<int, int>|null
     */
    private function assignedProgramIdsForAccount(User $owner, string $role, array $account): ?array
    {
        if ($role !== 'provider') {
            return null;
        }

        $programScopedProviderRoles = [
            'program_coordinator',
            'application_reviewer',
            'selection_officer',
            'decision_officer',
            'recipient_officer',
            'monitoring_officer',
            'benefit_release_officer',
            'team_administrator',
            'support_staff',
        ];

        if (! in_array($account['account_title'], $programScopedProviderRoles, true)) {
            return null;
        }

        $publishedProgramIds = $owner->providerScholarships()
            ->where('status', 'published')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($publishedProgramIds->isEmpty()) {
            return [];
        }

        $assignedProgramId = $account['account_title'] === 'program_coordinator'
            ? ($publishedProgramIds->get(1) ?? $publishedProgramIds->first())
            : $publishedProgramIds->first();

        return [(int) $assignedProgramId];
    }

    /** @return array<int, array<string, mixed>> */
    private function providerAccounts(User $owner): array
    {
        $accounts = [
            [
                'email' => 'aralbridge.manager@scholarship.test',
                'username' => 'aralbridge.manager',
                'first_name' => 'Marco',
                'middle_initial' => 'A',
                'last_name' => 'Villanueva',
                'contact_number' => '09170000011',
                'account_title' => 'manager',
                'permissions' => User::PROVIDER_PERMISSIONS,
            ],
            [
                'email' => 'aralbridge.programs@scholarship.test',
                'username' => 'aralbridge.programs',
                'first_name' => 'Patricia',
                'middle_initial' => 'B',
                'last_name' => 'Gomez',
                'contact_number' => '09170000012',
                'account_title' => 'program_coordinator',
                'permissions' => ['manage_programs'],
            ],
            [
                'email' => 'aralbridge.reviewer@scholarship.test',
                'username' => 'aralbridge.reviewer',
                'first_name' => 'Rafael',
                'middle_initial' => 'C',
                'last_name' => 'Torres',
                'contact_number' => '09170000013',
                'account_title' => 'application_reviewer',
                'permissions' => ['verify_applications'],
            ],
            [
                'email' => 'aralbridge.selection@scholarship.test',
                'username' => 'aralbridge.selection',
                'first_name' => 'Selena',
                'middle_initial' => 'C',
                'last_name' => 'Mendoza',
                'contact_number' => '09170000016',
                'account_title' => 'selection_officer',
                'permissions' => ['manage_selection_activities'],
            ],
            [
                'email' => 'aralbridge.decisions@scholarship.test',
                'username' => 'aralbridge.decisions',
                'first_name' => 'Diana',
                'middle_initial' => 'C',
                'last_name' => 'Aquino',
                'contact_number' => '09170000017',
                'account_title' => 'decision_officer',
                'permissions' => ['record_final_decisions'],
            ],
            [
                'email' => 'aralbridge.recipients@scholarship.test',
                'username' => 'aralbridge.recipients',
                'first_name' => 'Rina',
                'middle_initial' => 'C',
                'last_name' => 'Flores',
                'contact_number' => '09170000018',
                'account_title' => 'recipient_officer',
                'permissions' => ['manage_recipients'],
            ],
            [
                'email' => 'aralbridge.monitoring@scholarship.test',
                'username' => 'aralbridge.monitoring',
                'first_name' => 'Monica',
                'middle_initial' => 'C',
                'last_name' => 'Santos',
                'contact_number' => '09170000019',
                'account_title' => 'monitoring_officer',
                'permissions' => ['manage_monitoring'],
            ],
            [
                'email' => 'aralbridge.releases@scholarship.test',
                'username' => 'aralbridge.releases',
                'first_name' => 'Beatrice',
                'middle_initial' => 'C',
                'last_name' => 'Cruz',
                'contact_number' => '09170000020',
                'account_title' => 'benefit_release_officer',
                'permissions' => ['manage_benefit_releases'],
            ],
            [
                'email' => 'aralbridge.profile@scholarship.test',
                'username' => 'aralbridge.profile',
                'first_name' => 'Olivia',
                'middle_initial' => 'C',
                'last_name' => 'Domingo',
                'contact_number' => '09170000028',
                'account_title' => 'organization_profile_manager',
                'permissions' => ['manage_profile'],
            ],
            [
                'email' => 'aralbridge.team@scholarship.test',
                'username' => 'aralbridge.team',
                'first_name' => 'Teresa',
                'middle_initial' => 'C',
                'last_name' => 'Bautista',
                'contact_number' => '09170000029',
                'account_title' => 'team_administrator',
                'permissions' => ['manage_team'],
            ],
            [
                'email' => 'aralbridge.support@scholarship.test',
                'username' => 'aralbridge.support',
                'first_name' => 'Sofia',
                'middle_initial' => 'D',
                'last_name' => 'Lim',
                'contact_number' => '09170000014',
                'account_title' => 'support_staff',
                'permissions' => ['manage_reports'],
            ],
            [
                'email' => 'aralbridge.billing@scholarship.test',
                'username' => 'aralbridge.billing',
                'first_name' => 'Daniel',
                'middle_initial' => 'E',
                'last_name' => 'Reyes',
                'contact_number' => '09170000015',
                'account_title' => 'billing_staff',
                'permissions' => ['manage_billing'],
            ],
        ];

        if ($owner->username === 'aralbridge') {
            return $accounts;
        }

        return array_map(static function (array $account) use ($owner): array {
            $prefix = $owner->username ?: 'provider';
            $account['email'] = str_replace('aralbridge.', $prefix.'.', $account['email']);
            $account['username'] = str_replace('aralbridge.', $prefix.'.', $account['username']);

            return $account;
        }, $accounts);
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
