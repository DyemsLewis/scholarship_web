<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import { createStaffQueueState } from '../../../support/staffQueueState.js';

const queueByPath = {
    '/provider/workspaces/support/needs-response': 'needs_action',
    '/provider/workspaces/support/waiting': 'waiting',
    '/provider/workspaces/support/platform': 'submitted',
    '/provider/workspaces/support/resolved': 'resolved',
};
const queueCopy = {
    needs_action: {
        title: 'Needs response',
        description: 'Applicant concerns waiting for an answer from your organization.',
        empty: 'No applicant concerns need a response.',
    },
    waiting: {
        title: 'Waiting for platform',
        description: 'Cases where your response is complete and platform review is still open.',
        empty: 'No cases are waiting for platform support.',
    },
    submitted: {
        title: 'Platform reports',
        description: 'Issues your organization sent directly to platform support.',
        empty: 'No open platform reports are recorded.',
    },
    resolved: {
        title: 'Resolved cases',
        description: 'Completed concerns kept for reference.',
        empty: 'No resolved support cases match this view.',
    },
};
const currentPath = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/support/needs-response';
const activeQueue = queueByPath[currentPath] ?? 'needs_action';
const pageCopy = queueCopy[activeQueue];

const isLoading = ref(true);
const errorMessage = ref('');
const programs = ref([]);
const reports = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','program_id']);
const search = ref(queueState.get('search') ?? '');
const selectedProgram = ref(queueState.get('program_id') ?? '');
let searchTimer = null;
let requestVersion = 0;
let requestedPage = 1;

const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 cases');

async function loadCases(page = 1) {
    window.clearTimeout(searchTimer);
    requestedPage = page;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const params = {
            queue: activeQueue,
            search: search.value.trim() || undefined,
            program_id: selectedProgram.value || undefined,
            page,
        };
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/support/data', { params: query }), params, () => version === requestVersion);
        if (version !== requestVersion) return;
        programs.value = response.data.programs ?? [];
        reports.value = response.data.reports ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The support case list could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    requestVersion++;
    isLoading.value = true;
    errorMessage.value = '';
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadCases(1), 300);
}, { flush: 'sync' });
watch(selectedProgram, () => loadCases(1), { flush: 'sync' });
onMounted(() => loadCases(queueState.page));
onBeforeUnmount(() => {
    requestVersion++;
    window.clearTimeout(searchTimer);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="support-directory-title">
            <div class="provider-service-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-headset" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Support workspace</p><h1 id="support-directory-title" class="service-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-1 text-sm text-slate-600">{{ pageCopy.description }}</p></div></div>
                        <a href="/provider/workspaces/support/report-platform-issue" class="inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">
                            <i class="fa-solid fa-triangle-exclamation text-amber-300" aria-hidden="true"></i>
                            Report platform issue
                        </a>
                    </div>
                </header>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Support cases">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1">
                            <span class="sr-only">Search support cases</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search concern, applicant, or program">
                        </label>
                        <label class="w-80">
                            <span class="sr-only">Filter by program</span>
                            <select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950">
                                <option value="">All assigned programs</option>
                                <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option>
                            </select>
                        </label>
                        <p class="w-28 shrink-0 text-right text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                    </div>

                    <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadCases(requestedPage)" />
                    <div v-else-if="isLoading" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading support cases...</p></div>
                    <div v-else-if="!reports.length && !errorMessage" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                    </div>

                    <div v-else-if="reports.length" class="staff-table-viewport">
                        <table class="w-full min-w-[56rem] table-fixed border-collapse text-left">
                            <colgroup><col><col class="w-56"><col class="w-48"><col class="w-32"><col class="w-24"></colgroup>
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600">
                                    <th class="px-5 py-3">Concern</th>
                                    <th class="px-5 py-3">Reporter</th>
                                    <th class="px-5 py-3">Related program</th>
                                    <th class="px-5 py-3">Received</th>
                                    <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="report in reports" :key="report.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="service-text text-sm font-bold text-slate-950">{{ report.subject }}</p>
                                        <p class="mt-1 service-text text-xs text-slate-500">{{ report.category_label }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <StaffTableIdentity :src="report.applicant?.profile_photo_url" :kind="report.applicant ? 'applicant' : 'provider'" class="h-10 w-10" />
                                            <p class="min-w-0 service-text text-sm font-bold text-slate-900">{{ report.applicant?.name || 'Provider team' }}</p>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4"><p class="service-text text-sm font-semibold text-slate-800">{{ report.program?.title || 'General platform issue' }}</p></td>
                                    <td class="px-5 py-4 text-xs font-semibold leading-5 text-slate-600">{{ report.created_at }}</td>
                                    <td class="px-5 py-4 text-right"><a :href="queueState.link(report.action_url)" class="staff-row-action staff-row-action--primary h-9 w-9" :aria-label="`Open ${report.subject}`" title="Open case"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer v-if="pagination.last_page > 1" class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)" @click="loadCases(pagination.current_page - 1)">Previous</button>
                        <span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                        <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)" @click="loadCases(pagination.current_page + 1)">Next</button>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
