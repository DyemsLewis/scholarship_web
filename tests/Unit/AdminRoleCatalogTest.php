<?php

namespace Tests\Unit;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\User;
use App\Support\AdminWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleCatalogTest extends TestCase
{
    #[DataProvider('managedRoleProvider')]
    public function test_managed_admin_roles_resolve_to_their_workspace(
        string $title,
        array $permissions,
        string $expectedKey,
        string $expectedRoute,
        string $expectedPath,
    ): void {
        $user = (new User)->forceFill([
            'role' => 'admin',
            'parent_account_id' => 1,
            'account_title' => $title,
            'permissions' => $permissions,
        ]);

        $this->assertSame($expectedKey, AdminWorkspace::key($user));
        $this->assertSame($expectedRoute, AdminWorkspace::preferredRouteName($user));
        $this->assertSame($expectedPath, AdminWorkspace::preferredPath($user));
    }

    public function test_primary_admin_keeps_the_super_admin_home_without_a_redirect(): void
    {
        $user = (new User)->forceFill([
            'role' => 'admin',
            'parent_account_id' => null,
        ]);

        $this->assertSame(AdminRoleCatalog::SUPER_ADMIN, AdminWorkspace::key($user));
        $this->assertNull(AdminWorkspace::preferredRouteName($user));
        $this->assertSame('/admin', AdminWorkspace::preferredPath($user));
    }

    public function test_non_admin_users_do_not_resolve_to_an_admin_workspace(): void
    {
        $user = (new User)->forceFill(['role' => 'applicant']);

        $this->assertNull(AdminWorkspace::key($user));
        $this->assertNull(AdminWorkspace::preferredPath($user));
    }

    public static function managedRoleProvider(): array
    {
        return [
            'account manager' => ['Account manager', ['manage_accounts'], AdminRoleCatalog::ACCOUNT_MANAGER, 'admin.workspaces.accounts', '/admin/workspaces/accounts'],
            'review officer' => ['Review officer', ['manage_reviews'], AdminRoleCatalog::REVIEW_OFFICER, 'admin.workspaces.reviews', '/admin/workspaces/reviews'],
            'support officer' => ['Support officer', ['manage_reports'], AdminRoleCatalog::SUPPORT_OFFICER, 'admin.workspaces.support', '/admin/workspaces/support'],
            'billing officer' => ['Billing officer', ['manage_billing'], AdminRoleCatalog::BILLING_OFFICER, 'admin.workspaces.billing', '/admin/workspaces/billing'],
            'finance officer' => ['Finance officer', ['view_finance'], AdminRoleCatalog::FINANCE_OFFICER, 'admin.workspaces.finance', '/admin/workspaces/finance'],
            'records officer' => ['Records officer', ['view_logs', 'export_data'], AdminRoleCatalog::RECORDS_OFFICER, 'admin.workspaces.records', '/admin/workspaces/records'],
            'portal manager' => ['Portal manager', User::ADMIN_PERMISSIONS, AdminRoleCatalog::PORTAL_MANAGER, 'admin.workspaces.portal.index', '/admin/workspaces/portal'],
        ];
    }
}
