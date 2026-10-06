<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';
import { supplementalProviderWorkspaceLinks } from '../support/providerWorkspaceNavigation';

const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const isOrganizationOwner = Boolean(window.portalUser?.is_organization_owner);
const providerWorkspaceUrl = window.portalUser?.provider_workspace_url ?? null;
const usesProgramCoordinatorWorkspace = providerWorkspaceUrl === '/provider/workspaces/programs';
const usesApplicationReviewerWorkspace = providerWorkspaceUrl === '/provider/workspaces/reviews';
const usesSelectionOfficerWorkspace = providerWorkspaceUrl === '/provider/workspaces/selection';
const usesDecisionOfficerWorkspace = providerWorkspaceUrl === '/provider/workspaces/decisions';
const usesRecipientOfficerWorkspace = providerWorkspaceUrl === '/provider/workspaces/recipients';
const usesMonitoringOfficerWorkspace = providerWorkspaceUrl === '/provider/workspaces/monitoring';
const usesBenefitReleaseOfficerWorkspace = providerWorkspaceUrl === '/provider/workspaces/releases';
const usesOrganizationProfileManagerWorkspace = providerWorkspaceUrl === '/provider/workspaces/organization-profile';
const usesTeamAdministratorWorkspace = providerWorkspaceUrl === '/provider/workspaces/team';
const usesSupportStaffWorkspace = providerWorkspaceUrl === '/provider/workspaces/support';
const usesBillingStaffWorkspace = providerWorkspaceUrl === '/provider/workspaces/billing';
const usesDedicatedWorkspace = Boolean(providerWorkspaceUrl);
const supplementalWorkspaceLinks = computed(() => supplementalProviderWorkspaceLinks({
    primaryWorkspaceUrl: providerWorkspaceUrl,
    permissions: window.portalUser?.permissions ?? [],
    hasFullAccess: Boolean(window.portalUser?.has_full_access),
    providerApproved: Boolean(window.portalUser?.can_post_scholarships),
}));
const providerHomeHref = providerWorkspaceUrl || '/provider';
const canManagePrograms = hasPermission('manage_programs');
const providerApproved = Boolean(window.portalUser?.can_post_scholarships);
const canVerifyApplications = hasPermission('verify_applications') && providerApproved;
const canManageSelectionActivities = hasPermission('manage_selection_activities') && providerApproved;
const canRecordFinalDecisions = hasPermission('record_final_decisions') && providerApproved;
const canManageRecipients = hasPermission('manage_recipients') && providerApproved;
const canManageMonitoring = hasPermission('manage_monitoring') && providerApproved;
const canManageBenefitReleases = hasPermission('manage_benefit_releases') && providerApproved;
const canAccessApplicationWorkflow = canVerifyApplications || canManageSelectionActivities || canRecordFinalDecisions;
const canAccessRecipientWorkflow = canManageRecipients || canManageMonitoring || canManageBenefitReleases;
const canAccessPrograms = canManagePrograms || canAccessApplicationWorkflow || canAccessRecipientWorkflow;
const canManageProfile = hasPermission('manage_profile');
const canManageTeam = hasPermission('manage_team');
const canManageBilling = hasPermission('manage_billing') && window.portalUser?.can_post_scholarships;
const canManageReports = hasPermission('manage_reports') && window.portalUser?.can_post_scholarships;
const entryLinks = computed(() => {
    if (usesProgramCoordinatorWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Program coordination',
            icon: 'fa-solid fa-compass-drafting',
            activePathPatterns: ['^/provider/(?:workspaces/programs|programs)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Overview', exact: true },
                { href: '/provider/workspaces/programs/drafts', label: 'Drafts and changes', exact: true },
                { href: '/provider/workspaces/programs/review', label: 'Admin review', exact: true },
                { href: '/provider/workspaces/programs/published', label: 'Published programs', exact: true },
                { href: '/provider/workspaces/programs/closed', label: 'Closed programs', exact: true },
                { href: '/provider/programs/create', label: 'Create program', exact: true },
            ],
        }];
    }

    if (usesApplicationReviewerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Application verification',
            icon: 'fa-solid fa-magnifying-glass-chart',
            activePathPatterns: ['^/provider/(?:workspaces/reviews|applications)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Review queue', exact: true },
            ],
        }];
    }

    if (usesSelectionOfficerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Selection activities',
            icon: 'fa-solid fa-calendar-check',
            activePathPatterns: ['^/provider/(?:workspaces/selection|applications)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Activity pipeline', exact: true },
            ],
        }];
    }

    if (usesDecisionOfficerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Final decisions',
            icon: 'fa-solid fa-gavel',
            activePathPatterns: ['^/provider/(?:workspaces/decisions|applications)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Decision docket', exact: true },
            ],
        }];
    }

    if (usesRecipientOfficerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Recipient onboarding',
            icon: 'fa-solid fa-user-shield',
            activePathPatterns: ['^/provider/(?:workspaces/recipients|applications|monitoring)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Recipient register', exact: true },
            ],
        }];
    }

    if (usesMonitoringOfficerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Recipient monitoring',
            icon: 'fa-solid fa-heart-pulse',
            activePathPatterns: ['^/provider/(?:workspaces/monitoring|monitoring)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Monitoring desk', exact: true },
            ],
        }];
    }

    if (usesBenefitReleaseOfficerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Benefit distribution',
            icon: 'fa-solid fa-hand-holding-dollar',
            activePathPatterns: ['^/provider/(?:workspaces/releases|monitoring/\\d+/releases)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Release desk', exact: true },
            ],
        }];
    }

    if (usesOrganizationProfileManagerWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Organization profile',
            icon: 'fa-solid fa-building-circle-check',
            activePathPatterns: ['^/provider/(?:workspaces/organization-profile|profile)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Profile desk', exact: true },
                { href: '/provider/profile/details', label: 'Provider details', exact: true },
                { href: '/provider/profile/verification', label: 'Verification proof', exact: true },
            ],
        }];
    }

    if (usesTeamAdministratorWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Team access',
            icon: 'fa-solid fa-users-gear',
            activePathPatterns: ['^/provider/(?:workspaces/team|team)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Access desk', exact: true },
                { href: '/provider/team/accounts/create', label: 'Add team member', exact: true },
            ],
        }];
    }

    if (usesSupportStaffWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Support desk',
            icon: 'fa-solid fa-headset',
            activePathPatterns: ['^/provider/(?:workspaces/support|reports)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Case desk', exact: true },
            ],
        }];
    }

    if (usesBillingStaffWorkspace) {
        return [{
            href: providerWorkspaceUrl,
            label: 'Service requests',
            icon: 'fa-solid fa-receipt',
            activePathPatterns: ['^/provider/(?:workspaces/billing|billing)(?:/|$)'],
            children: [
                { href: providerWorkspaceUrl, label: 'Request desk', exact: true },
                { href: '/provider/workspaces/billing/services', label: 'Browse services', exact: true },
            ],
        }];
    }

    return [{
        href: '/provider',
        label: isOrganizationOwner ? 'Governance' : 'Dashboard',
        icon: isOrganizationOwner ? 'fa-solid fa-shield-halved' : 'fa-solid fa-gauge-high',
        exact: true,
        activePaths: isOrganizationOwner ? ['/provider/governance'] : [],
    }, ...(canAccessPrograms ? [{
        href: '/provider/programs',
        label: 'Programs',
        icon: 'fa-solid fa-graduation-cap',
        children: [
            { href: '/provider/programs', label: 'All programs', exact: true },
            ...(canManagePrograms ? [{ href: '/provider/programs/create', label: 'Create program', exact: true }] : []),
        ],
    }] : [])];
});
const navLinks = computed(() => usesDedicatedWorkspace
    ? [...entryLinks.value, ...supplementalWorkspaceLinks.value]
    : [
    ...entryLinks.value,
    ...(canAccessApplicationWorkflow && !usesApplicationReviewerWorkspace && !usesSelectionOfficerWorkspace && !usesDecisionOfficerWorkspace ? [{
        href: canVerifyApplications
            ? '/provider/applications/review'
            : (canManageSelectionActivities ? '/provider/applications/activities' : '/provider/applications/decisions'),
        label: 'All applications',
        icon: 'fa-solid fa-user-check',
        children: [
            ...(canVerifyApplications ? [{ href: '/provider/applications/review', label: 'Applicant review', exact: true }] : []),
            ...(canManageSelectionActivities ? [
                { href: '/provider/applications/activities', label: 'Activities and schedules', exact: true },
                { href: '/provider/applications/results', label: 'Record results', exact: true },
            ] : []),
            ...(canRecordFinalDecisions ? [{ href: '/provider/applications/decisions', label: 'Final decisions', exact: true }] : []),
        ],
    }] : []),
    ...((canManageRecipients || canRecordFinalDecisions || canAccessRecipientWorkflow) && !usesDecisionOfficerWorkspace && !usesRecipientOfficerWorkspace && !usesMonitoringOfficerWorkspace && !usesBenefitReleaseOfficerWorkspace ? [{
        href: canAccessRecipientWorkflow ? '/provider/applications/recipients' : '/provider/applications/waitlist',
        label: 'All recipients',
        icon: 'fa-solid fa-award',
        children: [
            ...(canAccessRecipientWorkflow ? [{ href: '/provider/applications/recipients', label: 'Selected recipients', exact: true }] : []),
            ...(canRecordFinalDecisions ? [{ href: '/provider/applications/waitlist', label: 'Waitlist', exact: true }] : []),
        ],
    }] : []),
    ...((canManageMonitoring || canManageBenefitReleases || canManageRecipients) && !usesRecipientOfficerWorkspace && !usesMonitoringOfficerWorkspace && !usesBenefitReleaseOfficerWorkspace ? [{
        href: '/provider/monitoring',
        label: 'Monitoring',
        icon: 'fa-solid fa-heart-pulse',
        activePathPatterns: [
            '^/provider/monitoring(?:/|$)',
        ],
    }] : []),
    {
        href: '/provider/profile/details',
        label: 'Organization',
        icon: 'fa-solid fa-building-user',
        activePaths: ['/provider/profile', '/provider/team'],
        children: [
            { href: '/provider/profile/details', label: canManageProfile ? 'Provider details' : 'View provider details', exact: true },
            { href: '/provider/profile/verification', label: 'Verification', exact: true },
            { href: '/provider/profile/representative', label: 'Representative account', exact: true },
            ...(canManageTeam ? [{ href: '/provider/team', label: 'Team and access', exact: true }] : []),
        ],
    },
    ...((canManageBilling || canManageReports) ? [{
        href: canManageBilling ? '/provider/billing' : '/provider/reports',
        label: 'Support',
        icon: 'fa-solid fa-headset',
        children: [
            ...(canManageBilling ? [
                { href: '/provider/billing', label: 'Service options', exact: true },
                { href: '/provider/billing/requests', label: 'Your requests', exact: true },
            ] : []),
            ...(canManageReports ? [{ href: '/provider/reports', label: 'Reports', exact: true }] : []),
        ],
    }] : []),
]);
</script>

<template>
    <RoleSidebar
        title="Provider"
        subtitle="Scholarship Desk"
        icon="fa-solid fa-building-columns"
        :home-href="providerHomeHref"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the provider portal."
        mobile-collapsible
        neutral-accent
    />
</template>
