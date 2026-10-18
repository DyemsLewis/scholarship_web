<?php

namespace App\Support;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\User;

final class AdminWorkspace
{
    public const ACCOUNT_MANAGER_ROUTE = 'admin.workspaces.accounts';

    public const REVIEW_OFFICER_ROUTE = 'admin.workspaces.reviews';

    public const SUPPORT_OFFICER_ROUTE = 'admin.workspaces.support';

    public const BILLING_OFFICER_ROUTE = 'admin.workspaces.billing';

    public const FINANCE_OFFICER_ROUTE = 'admin.workspaces.finance';

    public const RECORDS_OFFICER_ROUTE = 'admin.workspaces.records';

    public const PORTAL_MANAGER_ROUTE = 'admin.workspaces.portal.index';

    public static function preferredRouteName(User $user): ?string
    {
        $key = AdminRoleCatalog::keyFor($user);

        if (! $key || $key === AdminRoleCatalog::SUPER_ADMIN) {
            return null;
        }

        return AdminRoleCatalog::definitions()[$key]['route'] ?? null;
    }

    public static function key(User $user): ?string
    {
        return AdminRoleCatalog::keyFor($user);
    }

    public static function preferredPath(User $user): ?string
    {
        return AdminRoleCatalog::definitionFor($user)['path'] ?? null;
    }

    public static function usesAccountManagerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::ACCOUNT_MANAGER;
    }

    public static function usesReviewOfficerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::REVIEW_OFFICER;
    }

    public static function usesSupportOfficerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::SUPPORT_OFFICER;
    }

    public static function usesBillingOfficerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::BILLING_OFFICER;
    }

    public static function usesFinanceOfficerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::FINANCE_OFFICER;
    }

    public static function usesRecordsOfficerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::RECORDS_OFFICER;
    }

    public static function usesPortalManagerWorkspace(User $user): bool
    {
        return self::key($user) === AdminRoleCatalog::PORTAL_MANAGER;
    }
}
