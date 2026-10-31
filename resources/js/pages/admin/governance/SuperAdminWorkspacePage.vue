<script setup>
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import WorkspaceMetricStrip from '../../../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get('/admin/dashboard/data', { signal });
    return response.data;
}, { failureMessage: 'Unable to load platform governance.' });
const { isLoading, errorMessage } = resource;
const summary = computed(() => resource.data.value?.summary ?? {});
const roleCoverage = computed(() => resource.data.value?.role_coverage ?? []);
const operationalAreas = computed(() => resource.data.value?.operational_areas ?? []);
const escalations = computed(() => resource.data.value?.escalations ?? []);

const workloadByOwner = computed(() => Object.fromEntries(operationalAreas.value.map((area) => [area.owner.toLowerCase(), area.count])));
const metrics = computed(() => [
    { key: 'admins', label: 'Active admin staff', value: summary.value.active_admins, detail: `${summary.value.managed_admins} managed accounts`, icon: 'fa-solid fa-user-shield' },
    { key: 'coverage', label: 'Roles covered', value: `${summary.value.covered_roles} of ${summary.value.total_roles}`, detail: escalations.value.length ? 'Coverage gap detected' : 'Operational ownership', icon: 'fa-solid fa-shield-halved', tone: escalations.value.length ? 'attention' : '' },
    { key: 'users', label: 'Platform users', value: summary.value.platform_users, detail: 'All registered accounts', icon: 'fa-solid fa-users' },
    { key: 'attention', label: 'Open workload', value: summary.value.operational_attention, detail: 'Across admin teams', icon: 'fa-solid fa-list-check', tone: summary.value.operational_attention ? 'attention' : '' },
]);

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

const loadGovernance = () => resource.load();

onMounted(loadGovernance);
</script>

<template>
    <AdminWorkspacePage workspace="super_admin">
        <AdminWorkspaceHeader eyebrow="Platform governance" title="Governance overview" description="Review admin coverage and platform-level workload." icon="fa-solid fa-shield-halved">
            <template #actions><a href="/admin/governance/team" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-users-gear text-xs" aria-hidden="true"></i>Manage admin team</a></template>
        </AdminWorkspaceHeader>

        <section v-if="isLoading || errorMessage" class="admin-panel mt-4 overflow-hidden"><AdminLoadState :loading="isLoading" :error="errorMessage" loading-message="Loading governance overview..." @retry="loadGovernance" /></section>
        <template v-else>
        <WorkspaceMetricStrip :metrics="metrics" />

        <section v-if="!isLoading && escalations.length" class="mt-4 flex items-center gap-4 border border-amber-300 bg-amber-50 px-5 py-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-800"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
            <div class="min-w-0 flex-1"><p class="font-black text-slate-950">Admin coverage needs attention</p><p class="mt-1 text-sm text-slate-600">{{ escalations.length }} area{{ escalations.length === 1 ? '' : 's' }} have open work without an active owner.</p></div>
            <a href="/admin/governance/team" class="shrink-0 text-sm font-bold text-slate-800 hover:text-slate-950">Review team <i class="fa-solid fa-arrow-right ml-1 text-xs" aria-hidden="true"></i></a>
        </section>

        <AdminTableFrame title="Role coverage" description="Ownership and open workload for each administrative role." icon="fa-solid fa-sitemap" class="mt-4">
            <template #actions><a href="/admin/governance/platform" class="text-sm font-bold text-slate-700 hover:text-slate-950">Platform health <i class="fa-solid fa-arrow-right ml-1 text-xs" aria-hidden="true"></i></a></template>
            <table v-if="roleCoverage.length" class="portal-data-table min-w-[56rem]">
                <thead><tr><th class="px-5">Role</th><th>Active accounts</th><th>Coverage</th><th class="px-5">Open workload</th></tr></thead>
                <tbody>
                    <tr v-for="role in roleCoverage" :key="role.key">
                        <td class="px-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[roleIcon(role.key), 'text-sm']" aria-hidden="true"></i></span><span class="font-bold text-slate-950">{{ role.title }}</span></div></td>
                        <td class="font-bold text-slate-800">{{ role.active }}</td>
                        <td><span :class="['inline-flex px-2.5 py-1 text-xs font-bold', role.covered ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']"><i :class="[role.covered ? 'fa-solid fa-check' : 'fa-solid fa-triangle-exclamation', 'mr-1.5']" aria-hidden="true"></i>{{ role.covered ? 'Covered' : 'Needs owner' }}</span></td>
                        <td class="px-5 font-black text-slate-950">{{ workloadByOwner[role.title.toLowerCase()] ?? 0 }}</td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No governance roles found" description="Administrative role definitions are not available." icon="fa-solid fa-sitemap" />
        </AdminTableFrame>
        </template>
    </AdminWorkspacePage>
</template>
