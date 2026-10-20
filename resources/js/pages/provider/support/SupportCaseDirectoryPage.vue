<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderApplicantPhoto from '../../../components/ProviderApplicantPhoto.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

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
const currentPath = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/support/needs-response';
const activeQueue = queueByPath[currentPath] ?? 'needs_action';
const pageCopy = queueCopy[activeQueue];

const isLoading = ref(true);
const errorMessage = ref('');
const programs = ref([]);
const reports = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref('');
let searchTimer = null;
let requestVersion = 0;

const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 cases');

async function loadCases(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/support/data', {
            params: {
                queue: activeQueue,
                search: search.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
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
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadCases(1), 300);
});
watch(selectedProgram, () => loadCases(1));
onMounted(() => loadCases());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="support-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-headset" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Support workspace</p><h1 id="support-directory-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-1 text-sm text-slate-600">{{ pageCopy.description }}</p></div></div>
                        <a href="/provider/workspaces/support/report-platform-issue" class="inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">
                            <i class="fa-solid fa-triangle-exclamation text-amber-300" aria-hidden="true"></i>
                            Report platform issue
                        </a>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

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

                    <div v-if="isLoading" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading support cases...</p></div>
                    <div v-else-if="!reports.length" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                    </div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[72rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                    <th class="w-[34%] px-5 py-3">Concern</th>
                                    <th class="w-[25%] px-5 py-3">Reporter</th>
                                    <th class="w-[24%] px-5 py-3">Related program</th>
                                    <th class="w-[12%] px-5 py-3">Received</th>
                                    <th class="w-[5%] px-5 py-3"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="report in reports" :key="report.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ report.subject }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ report.category_label }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <ProviderApplicantPhoto :src="report.applicant?.profile_photo_url" :name="report.applicant?.name || 'Provider team'" />
                                            <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-900">{{ report.applicant?.name || 'Provider team' }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ report.applicant?.email }}</p></div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4"><p class="truncate text-sm font-semibold text-slate-800">{{ report.program?.title || 'General platform issue' }}</p></td>
                                    <td class="px-5 py-4 text-xs font-semibold leading-5 text-slate-600">{{ report.created_at }}</td>
                                    <td class="px-5 py-4 text-right"><a :href="report.action_url" class="inline-grid h-9 w-9 place-items-center bg-slate-950 text-white hover:bg-slate-800" :aria-label="`Open ${report.subject}`"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer v-if="pagination.last_page > 1" class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadCases(pagination.current_page - 1)">Previous</button>
                        <span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                        <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadCases(pagination.current_page + 1)">Next</button>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
