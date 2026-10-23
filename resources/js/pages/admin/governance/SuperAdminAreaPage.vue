<script setup>
import { computed, onMounted, ref } from 'vue';
import WorkspaceMetricStrip from '../../../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { labelFromKey } from '../../../support/display';

const area = document.getElementById('app')?.dataset.area ?? 'team';
const config = area === 'platform'
    ? { eyebrow: 'System oversight', title: 'Platform health', description: 'Review platform scale, workload ownership, and recent activity.', icon: 'fa-solid fa-heart-pulse', endpoint: '/admin/governance/platform/data' }
    : { eyebrow: 'Access governance', title: 'Admin team', description: 'Review role coverage and administrative access.', icon: 'fa-solid fa-user-shield', endpoint: '/admin/governance/team/data' };
const isLoading = ref(true);
const errorMessage = ref('');
const payload = ref({});

const metrics = computed(() => {
    if (area === 'team') return [
        metric('members', 'Team members', payload.value.summary?.team_members, 'Managed admins', 'fa-solid fa-users-gear'),
        metric('active', 'Active', payload.value.summary?.active, 'Can access workspace', 'fa-solid fa-user-check'),
        metric('coverage', 'Roles covered', `${coveredRoles.value} of ${payload.value.coverage?.length ?? 0}`, 'Operational roles', 'fa-solid fa-shield-check', uncoveredRoles.value ? 'attention' : ''),
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

async function loadArea() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get(config.endpoint);
        payload.value = data;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this governance page.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadArea);
</script>

<template>
    <AdminWorkspacePage workspace="super_admin">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon">
            <template #actions>
                <a v-if="area === 'team'" href="/admin/accounts/create?role=admin" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus text-xs" aria-hidden="true"></i>Add admin account</a>
                <button v-else type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" @click="loadArea"><i class="fa-solid fa-rotate text-xs" aria-hidden="true"></i>Refresh</button>
            </template>
        </AdminWorkspaceHeader>

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
        <WorkspaceMetricStrip :metrics="metrics" :loading="isLoading" />

        <template v-if="area === 'team'">
            <AdminTableFrame title="Role coverage" description="Every operational role should have at least one active owner." icon="fa-solid fa-sitemap" class="mt-4">
                <div v-if="isLoading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading role coverage...</div>
                <table v-else-if="payload.coverage?.length" class="portal-data-table min-w-[58rem]">
                    <thead><tr><th class="px-5">Role</th><th>Accounts</th><th>Active</th><th class="px-5">Coverage</th></tr></thead>
                    <tbody>
                        <tr v-for="role in payload.coverage" :key="role.key">
                            <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[roleIcon(role.key), 'text-sm']" aria-hidden="true"></i></span><span class="font-bold text-slate-950">{{ role.title }}</span></div></td>
                            <td class="font-bold text-slate-800">{{ role.total }}</td>
                            <td class="font-bold text-slate-800">{{ role.active }}</td>
                            <td class="px-5"><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', role.covered ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']"><i :class="[role.covered ? 'fa-solid fa-check' : 'fa-solid fa-triangle-exclamation', 'mr-1.5']" aria-hidden="true"></i>{{ role.covered ? 'Covered' : 'Needs owner' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else-if="!isLoading" title="No roles found" description="Administrative role definitions are not available." icon="fa-solid fa-sitemap" />
            </AdminTableFrame>

            <AdminTableFrame title="Administrative accounts" description="Managed accounts with platform-level responsibilities." icon="fa-solid fa-users-gear" class="mt-4">
                <div v-if="isLoading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading administrative accounts...</div>
                <table v-else-if="payload.members?.length" class="portal-data-table min-w-[72rem]">
                    <thead><tr><th class="px-5">Admin</th><th>Assigned role</th><th>Permissions</th><th>Status</th><th>Updated</th><th class="px-5 text-right">Open</th></tr></thead>
                    <tbody>
                        <tr v-for="member in payload.members" :key="member.id">
                            <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[memberIcon(member.workspace), 'text-sm']" aria-hidden="true"></i></span><span class="min-w-0"><strong class="block max-w-sm truncate text-sm text-slate-950">{{ member.name }}</strong><span class="block max-w-sm truncate text-xs text-slate-500">{{ member.email }}</span></span></div></td>
                            <td><p class="font-semibold text-slate-800">{{ member.title }}</p><p class="mt-0.5 text-xs text-slate-500">{{ member.workspace }}</p></td>
                            <td class="font-bold text-slate-800">{{ member.permission_count }}</td>
                            <td><span :class="['inline-flex px-2.5 py-1 text-xs font-bold capitalize', member.status === 'suspended' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700']">{{ member.status }}</span></td>
                            <td class="text-sm text-slate-500">{{ member.updated_at }}</td>
                            <td class="px-5 text-right"><a :href="member.edit_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${member.name}`" title="Open account"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else-if="!isLoading" title="No managed admin accounts" description="Create an admin account and assign its operational role." icon="fa-solid fa-user-plus">
                    <template #actions><a href="/admin/accounts/create?role=admin" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Add admin account</a></template>
                </AdminEmptyState>
            </AdminTableFrame>
        </template>

        <template v-else>
            <AdminTableFrame title="Operational ownership" description="Open workload and active ownership across the platform." icon="fa-solid fa-sitemap" class="mt-4">
                <div v-if="isLoading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading platform health...</div>
                <table v-else-if="payload.operational_areas?.length" class="portal-data-table min-w-[66rem]">
                    <thead><tr><th class="px-5">Area</th><th>Owner</th><th>Active owners</th><th>Open items</th><th class="px-5">Coverage</th></tr></thead>
                    <tbody>
                        <tr v-for="item in payload.operational_areas" :key="item.key">
                            <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[areaIcon(item.key), 'text-sm']" aria-hidden="true"></i></span><span class="font-bold text-slate-950">{{ item.label }}</span></div></td>
                            <td class="font-semibold text-slate-700">{{ item.owner }}</td>
                            <td class="font-bold text-slate-800">{{ item.active_owners }}</td>
                            <td><span :class="['inline-flex min-w-10 justify-center px-2.5 py-1 text-xs font-black', item.count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ item.count }}</span></td>
                            <td class="px-5"><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', item.covered ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']">{{ item.covered ? 'Covered' : 'No active owner' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <AdminEmptyState v-else-if="!isLoading" title="No operational areas found" description="Platform workload information is not available." icon="fa-solid fa-sitemap" />
            </AdminTableFrame>

            <AdminTableFrame title="Recent platform activity" description="Latest auditable events from the last seven days." icon="fa-solid fa-clock-rotate-left" class="mt-4">
                <table v-if="payload.activity?.items?.length" class="portal-data-table min-w-[66rem]">
                    <thead><tr><th class="px-5">Event</th><th>Actor</th><th>Category</th><th class="px-5">Recorded</th></tr></thead>
                    <tbody><tr v-for="item in payload.activity.items" :key="item.id"><td class="px-5"><p class="max-w-xl truncate font-bold text-slate-950">{{ item.description }}</p><p class="mt-1 text-xs text-slate-500">{{ item.action_label }}</p></td><td><p class="font-semibold text-slate-800">{{ item.actor_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ labelFromKey(item.actor_role) }}</p></td><td><span class="inline-flex bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ labelFromKey(item.category) }}</span></td><td class="px-5 text-sm text-slate-500">{{ item.created_at }}</td></tr></tbody>
                </table>
                <AdminEmptyState v-else-if="!isLoading" title="No recent activity" description="No auditable platform events were recorded this week." icon="fa-solid fa-clock-rotate-left" />
            </AdminTableFrame>
        </template>
    </AdminWorkspacePage>
</template>
