<script setup>
import { computed, onMounted, ref } from 'vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const summary = ref({ account_attention: 0, pending_reviews: 0, open_reports: 0, active_services: 0, month_revenue: 0, activity_today: 0, total_attention: 0 });
const priorities = ref([]);
const stableAreas = computed(() => priorities.value.filter((item) => Number(item.count) === 0).length);

function money(amount) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 }).format(Number(amount ?? 0) / 100);
}

async function loadOverview() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get('/admin/workspaces/portal/data');
        summary.value = { ...summary.value, ...data.summary };
        priorities.value = data.priorities ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load portal operations.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadOverview);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar />
        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader theme="admin" eyebrow="Portal operations" title="Operations overview" description="See workload and risks across each admin team." icon="fa-solid fa-shield-halved">
                    <template #actions>
                        <button type="button" class="rounded border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadOverview"><i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh</button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading operations...</div>
                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid grid-cols-3 divide-x divide-slate-200">
                            <div class="px-6 py-5"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Items needing attention</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.total_attention }}</dd></div>
                            <div class="px-6 py-5"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Revenue this month</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ money(summary.month_revenue) }}</dd></div>
                            <div class="px-6 py-5"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Stable areas</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ stableAreas }} of {{ priorities.length }}</dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Priority ledger</p><h2 class="mt-1 text-xl font-black text-slate-950">Team workload</h2></div>
                            <p class="text-sm font-semibold text-slate-500">{{ summary.activity_today }} platform events today</p>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-left">
                                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500"><tr><th class="px-6 py-3">Area</th><th class="px-6 py-3">Owner</th><th class="px-6 py-3">Open items</th><th class="px-6 py-3 text-right">Workspace</th></tr></thead>
                                <tbody class="divide-y divide-slate-200 bg-white">
                                    <tr v-for="item in priorities" :key="item.key">
                                        <td class="px-6 py-4 font-bold text-slate-950">{{ item.label }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.owner }}</td>
                                        <td class="px-6 py-4"><span :class="['inline-flex min-w-10 justify-center rounded px-2.5 py-1 text-xs font-black', item.count ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800']">{{ item.count }}</span></td>
                                        <td class="px-6 py-4 text-right"><a :href="item.url" class="text-sm font-bold text-slate-700 hover:text-slate-950">Open overview <i class="fa-solid fa-arrow-right ml-1 text-xs" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </main>
</template>
