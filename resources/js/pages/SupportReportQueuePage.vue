<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isAdmin = window.location.pathname.startsWith('/admin');
const Sidebar = isAdmin ? AdminSidebar : ProviderSidebar;
const Footer = isAdmin ? AdminFooter : ProviderFooter;
const basePath = isAdmin ? '/admin/reports' : '/provider/reports';
const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const selectedStatus = ref('open');
const reports = ref([]);
const counts = ref({ open: 0, resolved: 0, all: 0 });
const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});

const pageCopy = computed(() => ({
    eyebrow: isAdmin ? 'Platform Support' : 'Program Support',
    title: isAdmin ? 'Applicant reports' : 'Program concerns',
    description: isAdmin
        ? 'Review program, account, technical, and general concerns submitted by applicants.'
        : 'Review concerns connected only to scholarship programs managed by your organization.',
}));
const statusFilters = computed(() => [
    { value: 'open', label: 'Open', count: counts.value.open },
    { value: 'resolved', label: 'Resolved', count: counts.value.resolved },
    { value: 'all', label: 'All', count: counts.value.all },
]);

function statusClass(status) {
    return status === 'resolved'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';
}

async function loadReports(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`${basePath}/data`, {
            params: {
                page,
                status: selectedStatus.value,
            },
        });

        reports.value = response.data.reports ?? [];
        counts.value = response.data.counts ?? counts.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant reports.';
    } finally {
        isLoading.value = false;
    }
}

function changeFilter(status) {
    selectedStatus.value = status;
    loadReports(1);
}

async function updateStatus(report) {
    const nextStatus = report.status === 'resolved' ? 'open' : 'resolved';

    updatingId.value = report.id;

    try {
        await window.axios.patch(`${basePath}/${report.id}/status`, { status: nextStatus });
        await loadReports(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update this report.';
    } finally {
        updatingId.value = null;
    }
}

onMounted(() => loadReports());
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <component :is="Sidebar" :active="isAdmin ? 'reports' : undefined" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header :class="isAdmin ? 'admin-hero' : 'provider-hero'">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">{{ pageCopy.eyebrow }}</p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">{{ pageCopy.title }}</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                </header>

                <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-slate-950">Report queue</h3>
                            <p class="mt-1 text-sm text-slate-500">Open each report here and mark it resolved when handled.</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="filter in statusFilters"
                                :key="filter.value"
                                type="button"
                                :class="[
                                    'rounded-md border px-3 py-2 text-xs font-bold transition',
                                    selectedStatus === filter.value
                                        ? 'border-slate-950 bg-slate-950 text-white'
                                        : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                ]"
                                @click="changeFilter(filter.value)"
                            >
                                {{ filter.label }} ({{ filter.count }})
                            </button>
                        </div>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading reports...</div>

                    <div v-else-if="errorMessage" class="p-5">
                        <p class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{{ errorMessage }}</p>
                    </div>

                    <div v-else-if="reports.length" class="divide-y divide-slate-100">
                        <article v-for="report in reports" :key="report.id" class="p-5">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                            {{ report.category_label }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', statusClass(report.status)]">
                                            {{ report.status_label }}
                                        </span>
                                    </div>

                                    <h4 class="mt-3 text-lg font-bold text-slate-950">{{ report.subject }}</h4>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ report.description }}</p>

                                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs font-semibold text-slate-500">
                                        <span>{{ report.applicant?.name }} ({{ report.applicant?.email }})</span>
                                        <span v-if="report.program">{{ report.program.title }}</span>
                                        <span>{{ report.created_at }}</span>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    :disabled="updatingId === report.id"
                                    :class="[
                                        'h-fit rounded-md px-4 py-2.5 text-sm font-bold transition disabled:opacity-50',
                                        report.status === 'resolved'
                                            ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                            : 'bg-slate-950 text-white hover:bg-slate-800',
                                    ]"
                                    @click="updateStatus(report)"
                                >
                                    {{ updatingId === report.id ? 'Saving...' : (report.status === 'resolved' ? 'Reopen' : 'Mark resolved') }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="p-10 text-center">
                        <p class="font-bold text-slate-900">No {{ selectedStatus === 'all' ? '' : selectedStatus }} reports</p>
                        <p class="mt-1 text-sm text-slate-500">Applicant concerns routed to this workspace will appear here.</p>
                    </div>

                    <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 p-4">
                        <button
                            type="button"
                            :disabled="pagination.current_page <= 1"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                            @click="loadReports(pagination.current_page - 1)"
                        >
                            Previous
                        </button>
                        <span class="text-xs font-semibold text-slate-500">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        </span>
                        <button
                            type="button"
                            :disabled="pagination.current_page >= pagination.last_page"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                            @click="loadReports(pagination.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </section>

                <component :is="Footer" />
            </div>
        </section>
    </main>
</template>
