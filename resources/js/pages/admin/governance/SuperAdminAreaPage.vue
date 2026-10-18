<script setup>
import { onMounted, ref } from 'vue';
import AdminSidebar from '../../../components/AdminSidebar.vue';
import TaskPageHeader from '../../../components/TaskPageHeader.vue';

const area = document.getElementById('app')?.dataset.area ?? 'team';
const config = area === 'platform'
    ? { eyebrow: 'System oversight', title: 'Platform health', description: 'Review platform scale, workload ownership, and recent activity.', icon: 'fa-solid fa-heart-pulse', endpoint: '/admin/governance/platform/data' }
    : { eyebrow: 'Access governance', title: 'Admin team', description: 'Review role coverage and administrative access.', icon: 'fa-solid fa-user-shield', endpoint: '/admin/governance/team/data' };
const isLoading = ref(true);
const errorMessage = ref('');
const payload = ref({});

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
    <main class="admin-shell">
        <AdminSidebar />
        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader theme="admin" :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon">
                    <template #actions>
                        <a v-if="area === 'team'" href="/admin/accounts/create?role=admin" class="rounded bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Add admin account</a>
                        <button v-else type="button" class="rounded border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadArea"><i class="fa-solid fa-rotate mr-1.5 text-xs"></i>Refresh</button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading governance data...</div>
                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <template v-if="area === 'team'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-4 divide-x divide-slate-200"><div v-for="item in [['Team members', payload.summary?.team_members], ['Active', payload.summary?.active], ['Suspended', payload.summary?.suspended], ['Unassigned', payload.summary?.unassigned]]" :key="item[0]" class="px-6 py-5"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Role coverage</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Role</th><th class="px-6 py-3">Accounts</th><th class="px-6 py-3">Active</th><th class="px-6 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="role in payload.coverage" :key="role.key"><td class="px-6 py-4 font-bold text-slate-950">{{ role.title }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ role.total }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ role.active }}</td><td class="px-6 py-4"><span :class="['rounded px-2.5 py-1 text-xs font-bold', role.covered ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800']">{{ role.covered ? 'Covered' : 'Needs owner' }}</span></td></tr></tbody></table></div></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Administrative accounts</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Admin</th><th class="px-6 py-3">Assigned role</th><th class="px-6 py-3">Permissions</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Updated</th><th class="px-6 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="member in payload.members" :key="member.id"><td class="px-6 py-4"><strong class="block text-sm text-slate-950">{{ member.name }}</strong><span class="text-xs text-slate-500">{{ member.email }}</span></td><td class="px-6 py-4 text-sm font-semibold text-slate-700">{{ member.title }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ member.permission_count }}</td><td class="px-6 py-4"><span :class="['text-xs font-bold capitalize', member.status === 'suspended' ? 'text-rose-700' : 'text-emerald-700']">{{ member.status }}</span></td><td class="px-6 py-4 text-sm text-slate-500">{{ member.updated_at }}</td><td class="px-6 py-4 text-right"><a :href="member.edit_url" class="text-sm font-bold text-slate-700 hover:text-slate-950">Open account <i class="fa-solid fa-arrow-right ml-1 text-xs"></i></a></td></tr><tr v-if="!payload.members?.length"><td colspan="6" class="empty-row">No managed admin accounts.</td></tr></tbody></table></div></section>
                    </template>

                    <template v-else>
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-6 divide-x divide-slate-200"><div v-for="item in [['Users', payload.summary?.users], ['Providers', payload.summary?.providers], ['Programs', payload.summary?.programs], ['Published', payload.summary?.published_programs], ['Applications', payload.summary?.applications], ['Recipients', payload.summary?.active_recipients]]" :key="item[0]" class="px-5 py-4"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value small">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Operational ownership</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Area</th><th class="px-6 py-3">Owner</th><th class="px-6 py-3">Active owners</th><th class="px-6 py-3">Open items</th><th class="px-6 py-3">Coverage</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in payload.operational_areas" :key="item.key"><td class="px-6 py-4 font-bold text-slate-950">{{ item.label }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.owner }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.active_owners }}</td><td class="px-6 py-4 text-sm font-bold text-slate-800">{{ item.count }}</td><td class="px-6 py-4"><span :class="['rounded px-2.5 py-1 text-xs font-bold', item.covered ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800']">{{ item.covered ? 'Covered' : 'No active owner' }}</span></td></tr></tbody></table></div></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Recent platform activity</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Event</th><th class="px-6 py-3">Actor</th><th class="px-6 py-3">Category</th><th class="px-6 py-3">Recorded</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in payload.activity?.items" :key="item.id"><td class="px-6 py-4"><strong class="block text-sm text-slate-950">{{ item.action_label }}</strong><span class="mt-1 block max-w-2xl text-xs text-slate-500">{{ item.description }}</span></td><td class="px-6 py-4 text-sm text-slate-600">{{ item.actor_name }}</td><td class="px-6 py-4 text-sm capitalize text-slate-600">{{ item.category }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ item.created_at }}</td></tr><tr v-if="!payload.activity?.items?.length"><td colspan="4" class="empty-row">No recent activity.</td></tr></tbody></table></div></section>
                    </template>
                </div>
            </div>
        </section>
    </main>
</template>

<style scoped>
.stat-label { color: #64748b; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; }
.stat-value { margin-top: 0.25rem; color: #020617; font-size: 1.5rem; font-weight: 900; }
.stat-value.small { font-size: 1.25rem; }
.table-head { background: #f8fafc; color: #64748b; font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
.section-head { border-bottom: 1px solid #e2e8f0; padding: 1rem 1.5rem; }
.section-head h2 { color: #020617; font-size: 1.125rem; font-weight: 900; }
.empty-row { padding: 2rem 1.5rem; text-align: center; color: #64748b; font-size: 0.875rem; }
</style>
