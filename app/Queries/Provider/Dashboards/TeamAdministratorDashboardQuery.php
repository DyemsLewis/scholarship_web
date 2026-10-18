<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;

final class TeamAdministratorDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $administrator): array
    {
        $context = $this->scope->context($administrator);
        $owner = $administrator->providerOrganizationOwner();
        $adminPermissions = $administrator->effectivePortalPermissions();
        $adminPrograms = $administrator->assignedProviderProgramIds();
        $items = User::query()
            ->where('role', 'provider')
            ->where('parent_account_id', $owner->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (User $account) use ($administrator, $adminPermissions, $adminPrograms): array {
                $state = match (true) {
                    $account->isSuspended() => 'suspended',
                    ! $account->hasVerifiedEmail() || $account->must_reset_password => 'attention',
                    default => 'active',
                };
                $broaderPermissions = $administrator->isManagedAccount()
                    && array_diff($account->effectivePortalPermissions(), $adminPermissions) !== [];
                $broaderPrograms = $administrator->hasLimitedProviderProgramAccess()
                    && (! $account->hasLimitedProviderProgramAccess()
                        || array_diff($account->assignedProviderProgramIds(), $adminPrograms) !== []);

                return [
                    'id' => (int) $account->id,
                    'account' => $account,
                    'state' => $state,
                    'manageable' => ! $broaderPermissions && ! $broaderPrograms,
                ];
            });
        $attention = $items->where('state', 'attention')->where('manageable', true)->take(5)
            ->map(fn (array $item): array => $this->item($item['account'], 'attention'));
        $active = $items->where('state', 'active')->where('manageable', true)->sortByDesc('id')->take(5)
            ->map(fn (array $item): array => $this->item($item['account'], 'active'));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'team-administrator',
                'eyebrow' => 'Organization access',
                'title' => 'Team access dashboard',
                'description' => 'Finish account setup and keep each staff member limited to their assigned role.',
                'icon' => 'fa-solid fa-users-gear',
                'organization_name' => $context['organization_name'],
                'access_scope' => $context['program_access_mode'] === 'selected'
                    ? $context['assigned_program_count'].' assigned programs'
                    : 'Organization-wide access',
            ],
            primaryAction: [
                'label' => $attention->isNotEmpty() ? 'Open setup queue' : 'Add team member',
                'url' => $attention->isNotEmpty() ? '/provider/workspaces/team/setup' : '/provider/team/accounts/create',
            ],
            metrics: [
                ['key' => 'attention', 'label' => 'Setup required', 'value' => $items->where('state', 'attention')->count(), 'detail' => 'Verification or password', 'tone' => 'attention', 'url' => '/provider/workspaces/team/setup'],
                ['key' => 'active', 'label' => 'Active accounts', 'value' => $items->where('state', 'active')->count(), 'detail' => 'Workspace access enabled', 'tone' => 'positive', 'url' => '/provider/workspaces/team/active'],
                ['key' => 'suspended', 'label' => 'Suspended', 'value' => $items->where('state', 'suspended')->count(), 'detail' => 'Sign-in disabled', 'url' => '/provider/workspaces/team/suspended'],
                ['key' => 'total', 'label' => 'Team accounts', 'value' => $items->count(), 'detail' => 'Delegated provider staff'],
            ],
            sections: [
                [
                    'key' => 'setup-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Incomplete account setup',
                    'description' => 'Accounts waiting for email verification or a temporary password change.',
                    'empty_message' => 'No team account needs setup follow-up.',
                    'action' => ['label' => 'Open setup queue', 'url' => '/provider/workspaces/team/setup'],
                    'items' => $attention,
                ],
                [
                    'key' => 'active-team',
                    'eyebrow' => 'Current access',
                    'title' => 'Recently added active staff',
                    'description' => 'A short reference list of staff with working access.',
                    'empty_message' => 'Active team accounts will appear here.',
                    'action' => ['label' => 'View active accounts', 'url' => '/provider/workspaces/team/active'],
                    'items' => $active,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function item(User $account, string $state): array
    {
        $setup = collect([
            ! $account->hasVerifiedEmail() ? 'Email verification pending' : null,
            $account->must_reset_password ? 'Password change pending' : null,
        ])->filter()->values();
        $permissions = $account->effectivePortalPermissions();

        return [
            'id' => (int) $account->id,
            'title' => $account->name,
            'subtitle' => $account->account_title ? str($account->account_title)->headline()->toString() : 'Provider staff',
            'image_url' => null,
            'status' => [
                'label' => $state === 'attention' ? 'Setup incomplete' : 'Access active',
                'tone' => $state === 'attention' ? 'attention' : 'positive',
            ],
            'meta' => [
                $state === 'attention' ? $setup->implode(' and ') : count($permissions).' workspace permission'.(count($permissions) === 1 ? '' : 's'),
                $account->hasLimitedProviderProgramAccess()
                    ? count($account->assignedProviderProgramIds()).' assigned program'.(count($account->assignedProviderProgramIds()) === 1 ? '' : 's')
                    : 'All organization programs',
            ],
            'action' => [
                'label' => 'Manage account',
                'url' => route('provider.team.accounts.edit', $account, false),
            ],
        ];
    }
}
