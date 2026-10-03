<?php

namespace App\Support;

use App\Models\User;

final class ProviderWorkspace
{
    public const PROGRAM_COORDINATOR_ROUTE = 'provider.workspaces.programs';

    public const PROGRAM_COORDINATOR_PATH = '/provider/workspaces/programs';

    public const APPLICATION_REVIEWER_ROUTE = 'provider.workspaces.reviews';

    public const APPLICATION_REVIEWER_PATH = '/provider/workspaces/reviews';

    public const SELECTION_OFFICER_ROUTE = 'provider.workspaces.selection';

    public const SELECTION_OFFICER_PATH = '/provider/workspaces/selection';

    public const DECISION_OFFICER_ROUTE = 'provider.workspaces.decisions';

    public const DECISION_OFFICER_PATH = '/provider/workspaces/decisions';

    public const RECIPIENT_OFFICER_ROUTE = 'provider.workspaces.recipients';

    public const RECIPIENT_OFFICER_PATH = '/provider/workspaces/recipients';

    public const MONITORING_OFFICER_ROUTE = 'provider.workspaces.monitoring';

    public const MONITORING_OFFICER_PATH = '/provider/workspaces/monitoring';

    public const BENEFIT_RELEASE_OFFICER_ROUTE = 'provider.workspaces.releases';

    public const BENEFIT_RELEASE_OFFICER_PATH = '/provider/workspaces/releases';

    public const ORGANIZATION_PROFILE_MANAGER_ROUTE = 'provider.workspaces.organization-profile';

    public const ORGANIZATION_PROFILE_MANAGER_PATH = '/provider/workspaces/organization-profile';

    public const TEAM_ADMINISTRATOR_ROUTE = 'provider.workspaces.team';

    public const TEAM_ADMINISTRATOR_PATH = '/provider/workspaces/team';

    public const SUPPORT_STAFF_ROUTE = 'provider.workspaces.support';

    public const SUPPORT_STAFF_PATH = '/provider/workspaces/support';

    public const BILLING_STAFF_ROUTE = 'provider.workspaces.billing';

    public const BILLING_STAFF_PATH = '/provider/workspaces/billing';

    public static function preferredRouteName(User $user): ?string
    {
        if (! $user->isProvider() || ! $user->isManagedAccount()) {
            return null;
        }

        $permissions = collect($user->effectivePortalPermissions())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $title = str_replace(['-', ' '], '_', strtolower(trim((string) $user->account_title)));

        if ($title === 'program_coordinator' || $permissions === ['manage_programs']) {
            return self::PROGRAM_COORDINATOR_ROUTE;
        }

        if ($title === 'application_reviewer' || $permissions === ['verify_applications']) {
            return self::APPLICATION_REVIEWER_ROUTE;
        }

        if ($title === 'selection_officer' || $permissions === ['manage_selection_activities']) {
            return self::SELECTION_OFFICER_ROUTE;
        }

        if ($title === 'decision_officer' || $permissions === ['record_final_decisions']) {
            return self::DECISION_OFFICER_ROUTE;
        }

        if ($title === 'recipient_officer' || $permissions === ['manage_recipients']) {
            return self::RECIPIENT_OFFICER_ROUTE;
        }

        if ($title === 'monitoring_officer' || $permissions === ['manage_monitoring']) {
            return self::MONITORING_OFFICER_ROUTE;
        }

        if ($title === 'benefit_release_officer' || $permissions === ['manage_benefit_releases']) {
            return self::BENEFIT_RELEASE_OFFICER_ROUTE;
        }

        if ($title === 'organization_profile_manager' || $permissions === ['manage_profile']) {
            return self::ORGANIZATION_PROFILE_MANAGER_ROUTE;
        }

        if ($title === 'team_administrator' || $permissions === ['manage_team']) {
            return self::TEAM_ADMINISTRATOR_ROUTE;
        }

        if ($title === 'support_staff' || $permissions === ['manage_reports']) {
            return self::SUPPORT_STAFF_ROUTE;
        }

        if ($title === 'billing_staff' || $permissions === ['manage_billing']) {
            return self::BILLING_STAFF_ROUTE;
        }

        return null;
    }

    public static function preferredPath(User $user): ?string
    {
        return match (self::preferredRouteName($user)) {
            self::PROGRAM_COORDINATOR_ROUTE => self::PROGRAM_COORDINATOR_PATH,
            self::APPLICATION_REVIEWER_ROUTE => self::APPLICATION_REVIEWER_PATH,
            self::SELECTION_OFFICER_ROUTE => self::SELECTION_OFFICER_PATH,
            self::DECISION_OFFICER_ROUTE => self::DECISION_OFFICER_PATH,
            self::RECIPIENT_OFFICER_ROUTE => self::RECIPIENT_OFFICER_PATH,
            self::MONITORING_OFFICER_ROUTE => self::MONITORING_OFFICER_PATH,
            self::BENEFIT_RELEASE_OFFICER_ROUTE => self::BENEFIT_RELEASE_OFFICER_PATH,
            self::ORGANIZATION_PROFILE_MANAGER_ROUTE => self::ORGANIZATION_PROFILE_MANAGER_PATH,
            self::TEAM_ADMINISTRATOR_ROUTE => self::TEAM_ADMINISTRATOR_PATH,
            self::SUPPORT_STAFF_ROUTE => self::SUPPORT_STAFF_PATH,
            self::BILLING_STAFF_ROUTE => self::BILLING_STAFF_PATH,
            default => null,
        };
    }

    public static function usesProgramCoordinatorWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::PROGRAM_COORDINATOR_ROUTE;
    }

    public static function usesApplicationReviewerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::APPLICATION_REVIEWER_ROUTE;
    }

    public static function usesSelectionOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::SELECTION_OFFICER_ROUTE;
    }

    public static function usesDecisionOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::DECISION_OFFICER_ROUTE;
    }

    public static function usesRecipientOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::RECIPIENT_OFFICER_ROUTE;
    }

    public static function usesMonitoringOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::MONITORING_OFFICER_ROUTE;
    }

    public static function usesBenefitReleaseOfficerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::BENEFIT_RELEASE_OFFICER_ROUTE;
    }

    public static function usesOrganizationProfileManagerWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::ORGANIZATION_PROFILE_MANAGER_ROUTE;
    }

    public static function usesTeamAdministratorWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::TEAM_ADMINISTRATOR_ROUTE;
    }

    public static function usesSupportStaffWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::SUPPORT_STAFF_ROUTE;
    }

    public static function usesBillingStaffWorkspace(User $user): bool
    {
        return self::preferredRouteName($user) === self::BILLING_STAFF_ROUTE;
    }
}
