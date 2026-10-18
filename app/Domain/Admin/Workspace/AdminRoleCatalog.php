<?php

namespace App\Domain\Admin\Workspace;

use App\Models\User;

final class AdminRoleCatalog
{
    public const SUPER_ADMIN = 'super_admin';

    public const ACCOUNT_MANAGER = 'account_manager';

    public const REVIEW_OFFICER = 'review_officer';

    public const SUPPORT_OFFICER = 'support_officer';

    public const BILLING_OFFICER = 'billing_officer';

    public const FINANCE_OFFICER = 'finance_officer';

    public const RECORDS_OFFICER = 'records_officer';

    public const PORTAL_MANAGER = 'portal_manager';

    /**
     * @return array<string, array{title: string, route: string, path: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            self::SUPER_ADMIN => [
                'title' => 'Super admin',
                'route' => 'admin.index',
                'path' => '/admin',
                'permissions' => User::ADMIN_PERMISSIONS,
            ],
            self::ACCOUNT_MANAGER => [
                'title' => 'Account manager',
                'route' => 'admin.workspaces.accounts',
                'path' => '/admin/workspaces/accounts',
                'permissions' => ['manage_accounts'],
            ],
            self::REVIEW_OFFICER => [
                'title' => 'Review officer',
                'route' => 'admin.workspaces.reviews',
                'path' => '/admin/workspaces/reviews',
                'permissions' => ['manage_reviews'],
            ],
            self::SUPPORT_OFFICER => [
                'title' => 'Support officer',
                'route' => 'admin.workspaces.support',
                'path' => '/admin/workspaces/support',
                'permissions' => ['manage_reports'],
            ],
            self::BILLING_OFFICER => [
                'title' => 'Billing officer',
                'route' => 'admin.workspaces.billing',
                'path' => '/admin/workspaces/billing',
                'permissions' => ['manage_billing'],
            ],
            self::FINANCE_OFFICER => [
                'title' => 'Finance officer',
                'route' => 'admin.workspaces.finance',
                'path' => '/admin/workspaces/finance',
                'permissions' => ['view_finance'],
            ],
            self::RECORDS_OFFICER => [
                'title' => 'Records officer',
                'route' => 'admin.workspaces.records',
                'path' => '/admin/workspaces/records',
                'permissions' => ['view_logs', 'export_data'],
            ],
            self::PORTAL_MANAGER => [
                'title' => 'Portal manager',
                'route' => 'admin.workspaces.portal.index',
                'path' => '/admin/workspaces/portal',
                'permissions' => User::ADMIN_PERMISSIONS,
            ],
        ];
    }

    public static function keyFor(User $user): ?string
    {
        if (! $user->isAdmin()) {
            return null;
        }

        if (! $user->isManagedAccount()) {
            return self::SUPER_ADMIN;
        }

        $title = self::normalizeTitle($user->account_title);

        foreach (self::definitions() as $key => $definition) {
            if ($key !== self::SUPER_ADMIN && self::normalizeTitle($definition['title']) === $title) {
                return $key;
            }
        }

        $permissions = self::normalizePermissions($user->effectivePortalPermissions());

        foreach (self::definitions() as $key => $definition) {
            if ($key !== self::SUPER_ADMIN && self::normalizePermissions($definition['permissions']) === $permissions) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{title: string, route: string, path: string, permissions: list<string>}|null
     */
    public static function definitionFor(User $user): ?array
    {
        $key = self::keyFor($user);

        return $key ? (self::definitions()[$key] ?? null) : null;
    }

    private static function normalizeTitle(?string $title): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim((string) $title)));
    }

    /** @param  iterable<string>  $permissions */
    private static function normalizePermissions(iterable $permissions): array
    {
        return collect($permissions)
            ->filter(fn ($permission): bool => is_string($permission) && $permission !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
