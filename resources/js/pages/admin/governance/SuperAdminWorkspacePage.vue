<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '../../../components/AdminSidebar.vue';
import TaskPageHeader from '../../../components/TaskPageHeader.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const summary = ref({ managed_admins: 0, active_admins: 0, covered_roles: 0, total_roles: 0, platform_users: 0, operational_attention: 0 });
const roleCoverage = ref([]);
const operationalAreas = ref([]);
const escalations = ref([]);

const workloadByOwner = computed(() => Object.fromEntries(operationalAreas.value.map((area) => [area.owner.toLowerCase(), area.count])));

async function loadGovernance() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get('/admin/dashboard/data');
        summary.value = { ...summary.value, ...data.summary };
        roleCoverage.value = data.role_coverage ?? [];
        operationalAreas.value = data.operational_areas ?? [];
        escalations.value = data.escalations ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load platform governance.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadGovernance);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="dashboard" />
        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader theme="admin" eyebrow="Platform governance" title="Governance overview" description="Monitor admin coverage and platform-level risks." icon="fa-solid fa-shield-halved">
                    <template #actions><a href="/admin/governance/team" class="rounded bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Manage admin team</a></template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading governance overview...</div>
                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid grid-cols-4 divide-x divide-slate-200">
                            <div class="px-6 py-5"><dt class="stat-label">Active admin staff</dt><dd class="stat-value">{{ summary.active_admins }}</dd></div>
                            <div class="px-6 py-5"><dt class="stat-label">Roles covered</dt><dd class="stat-value">{{ summary.covered_roles }} of {{ summary.total_roles }}</dd></div>
                            <div class="px-6 py-5"><dt class="stat-label">Platform users</dt><dd class="stat-value">{{ summary.platform_users }}</dd></div>
                            <div class="px-6 py-5"><dt class="stat-label">Operational attention</dt><dd class="stat-value">{{ summary.operational_attention }}</dd></div>
                        </dl>
                    </section>

                    <section v-if="escalations.length" class="border border-amber-300 bg-amber-50 px-5 py-4">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-800">Coverage risk</p>
                        <p class="mt-1 text-sm font-semibold text-slate-900">{{ escalations.length }} operational area{{ escalations.length === 1 ? '' : 's' }} have open work without an active assigned role.</p>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Responsibility map</p><h2 class="mt-1 text-xl font-black text-slate-950">Admin role coverage</h2></div><a href="/admin/governance/platform" class="text-sm font-bold text-slate-700 hover:text-slate-950">View platform health <i class="fa-solid fa-arrow-right ml-1 text-xs"></i></a></header>
                        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Role</th><th class="px-6 py-3">Active accounts</th><th class="px-6 py-3">Coverage</th><th class="px-6 py-3">Open workload</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="role in roleCoverage" :key="role.key"><td class="px-6 py-4 font-bold text-slate-950">{{ role.title }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ role.active }}</td><td class="px-6 py-4"><span :class="['rounded px-2.5 py-1 text-xs font-bold', role.covered ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800']">{{ role.covered ? 'Covered' : 'No active owner' }}</span></td><td class="px-6 py-4 text-sm font-bold text-slate-700">{{ workloadByOwner[role.title.toLowerCase()] ?? '—' }}</td></tr></tbody></table></div>
                    </section>
                </div>
            </div>
        </section>
    </main>
</template>

<style scoped>
.stat-label { color: #64748b; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; }
.stat-value { margin-top: 0.25rem; color: #020617; font-size: 1.5rem; font-weight: 900; }
.table-head { background: #f8fafc; color: #64748b; font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
</style>
