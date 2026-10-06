export const providerWorkspaceDefinitions = [
    {
        permission: 'manage_programs',
        href: '/provider/workspaces/programs',
        label: 'Program coordination',
        icon: 'fa-solid fa-compass-drafting',
    },
    {
        permission: 'verify_applications',
        href: '/provider/workspaces/reviews',
        label: 'Application verification',
        icon: 'fa-solid fa-magnifying-glass-chart',
    },
    {
        permission: 'manage_selection_activities',
        href: '/provider/workspaces/selection',
        label: 'Selection activities',
        icon: 'fa-solid fa-calendar-check',
    },
    {
        permission: 'record_final_decisions',
        href: '/provider/workspaces/decisions',
        label: 'Final decisions',
        icon: 'fa-solid fa-gavel',
    },
    {
        permission: 'manage_recipients',
        href: '/provider/workspaces/recipients',
        label: 'Recipient onboarding',
        icon: 'fa-solid fa-user-shield',
    },
    {
        permission: 'manage_monitoring',
        href: '/provider/workspaces/monitoring',
        label: 'Recipient monitoring',
        icon: 'fa-solid fa-heart-pulse',
    },
    {
        permission: 'manage_benefit_releases',
        href: '/provider/workspaces/releases',
        label: 'Benefit distribution',
        icon: 'fa-solid fa-hand-holding-dollar',
    },
    {
        permission: 'manage_profile',
        href: '/provider/workspaces/organization-profile',
        label: 'Organization profile',
        icon: 'fa-solid fa-building-circle-check',
    },
    {
        permission: 'manage_team',
        href: '/provider/workspaces/team',
        label: 'Team access',
        icon: 'fa-solid fa-users-gear',
    },
    {
        permission: 'manage_reports',
        href: '/provider/workspaces/support',
        label: 'Support desk',
        icon: 'fa-solid fa-headset',
        requiresApproval: true,
    },
    {
        permission: 'manage_billing',
        href: '/provider/workspaces/billing',
        label: 'Service requests',
        icon: 'fa-solid fa-receipt',
        requiresApproval: true,
    },
];

export function supplementalProviderWorkspaceLinks({
    primaryWorkspaceUrl,
    permissions = [],
    hasFullAccess = false,
    providerApproved = false,
}) {
    const grantedPermissions = new Set(permissions);

    return providerWorkspaceDefinitions
        .filter((workspace) => workspace.href !== primaryWorkspaceUrl)
        .filter((workspace) => hasFullAccess || grantedPermissions.has(workspace.permission))
        .filter((workspace) => !workspace.requiresApproval || providerApproved)
        .map(({ permission, requiresApproval, ...link }) => ({
            ...link,
            exact: true,
        }));
}
