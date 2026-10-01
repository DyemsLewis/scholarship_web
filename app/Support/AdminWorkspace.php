<?php

namespace App\Support;

use App\Models\User;

final class AdminWorkspace
{
    public const ACCOUNT_MANAGER_ROUTE = 'admin.workspaces.accounts';

    public const REVIEW_OFFICER_ROUTE = 'admin.workspaces.reviews';

    public const SUPPORT_OFFICER_ROUTE = 'admin.workspaces.support';

    public const BILLING_OFFICER_ROUTE = 'admin.workspaces.billing';

    public const FINANCE_OFFICER_ROUTE = 'admin.workspaces.finance';

    public const RECORDS_OFFICER_ROUTE = 'admin.workspaces.records.activity';

    public const PORTAL_MANAGER_ROUTE = 'admin.workspaces.portal.index';

    public static function preferredRouteName(User $user): ?string
    {
        if (! $user->isAdmin() || ! $user->isManagedAccount()) {
            return null;
        }

        $permissions = collect($user->permissions ?? [])
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $title = strtolower(trim((string) $user->account_title));

        if ($title === 'account manager' || $permissions === ['manage_accounts']) {
            return self::ACCOUNT_MANAGER_ROUTE;
        }

        if ($title === 'review officer' || $permissions === ['manage_reviews']) {
            return self::REVIEW_OFFICER_ROUTE;
        }

        if ($title === 'support officer' || $permissions === ['manage_reports']) {
            return self::SUPPORT_OFFICER_ROUTE;
        }

        if ($title === 'billing officer' || $permissions === ['manage_billing']) {
            return self::BILLING_OFFICER_ROUTE;
        }

        if ($title === 'finance officer' || $permissions === ['view_finance']) {
            return self::FINANCE_OFFICER_ROUTE;
        }

        if ($title === 'records officer' || $permissions === ['export_data', 'view_logs']) {
            return self::RECORDS_OFFICER_ROUTE;
        }

        if ($title === 'portal manager' || $permissions === [
            'export_data',
            'manage_accounts',
            'manage_billing',
            'manage_reports',
            'manage_reviews',
            'view_finance',
            'view_logs',
        ]) {
            return self::PORTAL_MANAGER_ROUTE;
        }

        return null;
    }

    public static function usesAccountManagerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::ACCOUNT_MANAGER_ROUTE;
    }

    public static function usesReviewOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::REVIEW_OFFICER_ROUTE;
    }

    public static function usesSupportOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::SUPPORT_OFFICER_ROUTE;
    }

    public static function usesBillingOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::BILLING_OFFICER_ROUTE;
    }

    public static function usesFinanceOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::FINANCE_OFFICER_ROUTE;
    }

    public static function usesRecordsOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::RECORDS_OFFICER_ROUTE;
    }

    public static function usesPortalManagerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::PORTAL_MANAGER_ROUTE;
    }
}
