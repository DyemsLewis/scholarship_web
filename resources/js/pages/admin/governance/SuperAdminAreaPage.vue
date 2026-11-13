<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import WorkspaceMetricStrip from '../../../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { labelFromKey } from '../../../support/display';

const area = document.getElementById('app')?.dataset.area ?? 'team';
const roleKeys = ['account_manager', 'review_officer', 'support_officer', 'billing_officer', 'finance_officer', 'records_officer', 'portal_manager'];
const requestedRole = new URLSearchParams(window.location.search).get('role');
const selectedRole = ref(roleKeys.includes(requestedRole) ? requestedRole : '');
const config = area === 'platform'
    ? { eyebrow: 'System oversight', title: 'Platform health', description: 'Review platform scale, workload ownership, and recent activity.', icon: 'fa-solid fa-heart-pulse', endpoint: '/admin/governance/platform/data' }
    : { eyebrow: 'Access governance', title: 'Admin team', description: 'Review role coverage and administrative access.', icon: 'fa-solid fa-user-shield', endpoint: '/admin/governance/team/data' };
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(config.endpoint, { signal, ...(area === 'team' && selectedRole.value ? { params: { role: selectedRole.value } } : {}) });
    return response.data;
}, { failureMessage: 'Unable to load this governance page.' });
const { isLoading, errorMessage } = resource;
const payload = computed(() => resource.data.value ?? {});

const metrics = computed(() => {
    if (area === 'team') return [
        metric('members', 'Team members', payload.value.summary?.team_members, 'Managed admins', 'fa-solid fa-users-gear'),
        metric('active', 'Active accounts', payload.value.summary?.active, 'Account status, not access readiness', 'fa-solid fa-user-check'),
        metric('coverage', 'Roles covered', `${coveredRoles.value} of ${payload.value.coverage?.length ?? 0}`, 'Operational roles', 'fa-solid fa-shield-halved', uncoveredRoles.value ? 'attention' : ''),
        metric('unassigned', 'Unassigned', payload.value.summary?.unassigned, 'Need a role', 'fa-solid fa-user-clock', payload.value.summary?.unassigned ? 'attention' : ''),
    ];

    return [
        metric('users', 'Platform users', payload.value.summary?.users, 'All accounts', 'fa-solid fa-users'),
        metric('providers', 'Providers', payload.value.summary?.providers, 'Primary organizations', 'fa-solid fa-building-columns'),
        metric('programs', 'Published programs', payload.value.summary?.published_programs, `${payload.value.summary?.programs ?? 0} total`, 'fa-solid fa-graduation-cap'),
        metric('recipients', 'Active recipients', payload.value.summary?.active_recipients, `${payload.value.summary?.applications ?? 0} applications`, 'fa-solid fa-award'),
    ];
});
const coveredRoles = computed(() => (payload.value.coverage ?? []).filter((role) => role.covered).length);
const uncoveredRoles = computed(() => (payload.value.coverage ?? []).filter((role) => !role.covered).length);

function metric(key, label, value, detail, icon, tone = '') {
    return { key, label, value: value ?? 0, detail, icon, tone };
}

function roleIcon(key) {
    return {
        account_manager: 'fa-solid fa-users-gear',
        review_officer: 'fa-solid fa-clipboard-check',
        support_officer: 'fa-solid fa-headset',
        billing_officer: 'fa-solid fa-file-invoice-dollar',
        finance_officer: 'fa-solid fa-chart-line',
        records_officer: 'fa-solid fa-box-archive',
        portal_manager: 'fa-solid fa-shield-halved',
    }[key] ?? 'fa-solid fa-user-shield';
}

function areaIcon(key) {
    return {
        accounts: 'fa-solid fa-users-gear',
        reviews: 'fa-solid fa-clipboard-check',
        support: 'fa-solid fa-headset',
        services: 'fa-solid fa-file-invoice-dollar',
        finance: 'fa-solid fa-chart-line',
        records: 'fa-solid fa-box-archive',
    }[key] ?? 'fa-solid fa-list-check';
}

function memberIcon(workspace) {
    return roleIcon(String(workspace ?? '').toLowerCase().replaceAll(' ', '_'));
}

const loadArea = () => resource.load();
watch(selectedRole, () => {
    const url = new URL(window.location.href);
    selectedRole.value ? url.searchParams.set('role', selectedRole.value) : url.searchParams.delete('role');
    window.history.replaceState({}, '', url);
    resource.invalidate();
    loadArea();
});

onMounted(loadArea);
</script>

<template>
    <AdminWorkspacePage workspace="super_admin">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon">
            <template #actions>
                <a v-if="area === 'team'" href="/admin/accounts/create?role=admin" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus text-xs" aria-hidden="true"></i>Add admin account</a>
                <button v-else type="button" :disabled="isLoading" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="loadArea"><i class="fa-solid fa-rotate text-xs" aria-hidden="true"></i>Refresh</button>
            </template>
        </AdminWorkspaceHeader>

        <section v-if="isLoading || errorMessage" class="admin-panel mt-4 overflow-hidden"><AdminLoadState :loading="isLoading" :error="errorMessage" loading-message="Loading governance page..." @retry="loadArea" /></section>
        <template v-else>
        <WorkspaceMetricStrip :metrics="metrics" />

        <template v-if="area === 'team'">
            <AdminTableFrame title="Role coverage" description="Coverage requires completed access setup and the permissions needed by that role." icon="fa-solid fa-sitemap" class="mt-4">
                <table v-if="payload.coverage?.length" class="portal-data-table min-w-[56rem]">
                    <thead><tr><th class="px-5">Role</th><th>Accounts</th><th>Available</th><th class="px-5">Coverage</th></tr></thead>
                    <tbody>
                        <tr v-for="role in payload.coverage" :key="role.key">
                            <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[roleIcon(role.key), 'text-sm']" aria-hidden="true"></i></span><span class="font-bold text-slate-950">{{ role.title }}</span></div></td>
                            <td class="font-bold text-slate-800">{{ role.total }}</td>
                            <td class="font-bold text-slate-800">{{ role.available }}</td>
                            <td class="px-5"><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', role.covered ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']"><i :class="[role.covered ? 'fa-solid fa-check' : 'fa-solid fa-triangle-exclamation', 'mr-1.5']" aria-hidden="true"></i>{{ role.covered ? 'Covered' : 'Needs owner' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else title="No roles found" description="Administrative role definitions are not available." icon="fa-solid fa-sitemap" />
            </AdminTableFrame>

            <AdminTableFrame title="Administrative accounts" description="Managed accounts with platform-level responsibilities." icon="fa-solid fa-users-gear" class="mt-4">
                <template #actions><label><span class="sr-only">Filter admin role</span><select v-model="selectedRole" class="h-10 rounded-sm border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="">All roles</option><option v-for="role in payload.coverage || []" :key="role.key" :value="role.key">{{ role.title }}</option></select></label></template>
                <table v-if="payload.members?.length" class="portal-data-table table-fixed min-w-[56rem]">
                    <colgroup><col><col class="w-44"><col class="w-32"><col class="w-44"><col class="w-36"><col class="w-20"></colgroup>
                    <thead><tr><th class="px-5">Admin</th><th>Assigned role</th><th>Permissions</th><th>Status</th><th>Updated</th><th class="px-5 text-right">Open</th></tr></thead>
                    <tbody>
                        <tr v-for="member in payload.members" :key="member.id" class="align-middle">
                            <td class="px-5"><div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[memberIcon(member.workspace), 'text-sm']" aria-hidden="true"></i></span><span class="min-w-0"><strong class="block break-words text-sm text-slate-950">{{ member.name }}</strong><span class="block text-xs text-slate-500 [overflow-wrap:anywhere]">{{ member.email }}</span></span></div></td>
                            <td><p class="font-semibold text-slate-800">{{ member.workspace }}</p><p v-if="member.title && member.title.toLowerCase() !== member.workspace.toLowerCase() && member.title !== 'Unassigned admin'" class="mt-0.5 text-xs text-slate-500">{{ member.title }}</p></td>
                            <td class="font-bold text-slate-800">{{ member.permission_count }}</td>
                            <td><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', member.access_state !== 'Ready' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700']">{{ member.access_state || labelFromKey(member.status) }}</span></td>
                            <td class="text-sm text-slate-500">{{ member.updated_at }}</td>
                            <td class="px-5 text-right"><a :href="member.edit_url" class="staff-row-action h-10 w-10" :aria-label="`Open ${member.name}`" title="Open account"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else :title="selectedRole ? 'No accounts for this role' : 'No managed admin accounts'" description="Create an admin account or assign this role to an existing staff member." icon="fa-solid fa-user-plus">
                    <template #actions><a href="/admin/accounts/create?role=admin" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Add admin account</a></template>
                </AdminEmptyState>
            </AdminTableFrame>
        </template>

        <template v-else>
            <AdminTableFrame title="Operational ownership" description="Open workload and active ownership across the platform." icon="fa-solid fa-sitemap" class="mt-4">
                <table v-if="payload.operational_areas?.length" class="portal-data-table min-w-[56rem]">
                    <thead><tr><th class="px-5">Area</th><th>Owner</th><th>Available owners</th><th>Team action</th><th>Waiting</th><th class="px-5">Coverage</th></tr></thead>
                    <tbody>
                        <tr v-for="item in payload.operational_areas" :key="item.key">
                            <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[areaIcon(item.key), 'text-sm']" aria-hidden="true"></i></span><span class="font-bold text-slate-950">{{ item.label }}</span></div></td>
                            <td class="font-semibold text-slate-700"><a :href="item.owner_url" class="underline decoration-slate-300 underline-offset-4">{{ item.owner }}</a></td>
                            <td class="font-bold text-slate-800">{{ item.active_owners }}</td>
                            <td><span :class="['inline-flex min-w-10 justify-center px-2.5 py-1 text-xs font-black', item.count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ item.count }}</span></td>
                            <td class="font-bold text-slate-600">{{ item.waiting }}</td>
                            <td class="px-5"><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', item.covered ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']">{{ item.covered ? 'Covered' : 'No active owner' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else title="No operational areas found" description="Platform workload information is not available." icon="fa-solid fa-sitemap" />
            </AdminTableFrame>

            <AdminTableFrame title="Recent platform activity" description="Latest auditable events from the last seven days." icon="fa-solid fa-clock-rotate-left" class="mt-4">
                <table v-if="payload.activity?.items?.length" class="portal-data-table table-fixed min-w-[56rem]">
                    <colgroup><col><col class="w-44"><col class="w-36"><col class="w-44"></colgroup>
                    <thead><tr><th class="px-5">Event</th><th>Actor</th><th>Category</th><th class="px-5">Recorded</th></tr></thead>
                    <tbody><tr v-for="item in payload.activity.items" :key="item.id" class="align-middle"><td class="px-5 font-bold text-slate-950 [overflow-wrap:anywhere]">{{ item.description || item.action_label }}</td><td><p class="break-words font-semibold text-slate-800">{{ item.actor_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ labelFromKey(item.actor_role) }}</p></td><td><span class="inline-flex bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ labelFromKey(item.category) }}</span></td><td class="px-5 text-sm text-slate-500">{{ item.created_at }}</td></tr></tbody>
                </table>
                <AdminEmptyState v-else title="No recent activity" description="No auditable platform events were recorded this week." icon="fa-solid fa-clock-rotate-left" />
            </AdminTableFrame>
        </template>
        </template>
    </AdminWorkspacePage>
</template>
