<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import ProviderProgramQueueContext from '../../../components/ProviderProgramQueueContext.vue';
import { createStaffQueueState, providerProgramQueueLink } from '../../../support/staffQueueState.js';

const queueByPath = {
    '/provider/workspaces/recipients/agreements': 'awaiting',
    '/provider/workspaces/recipients/active': 'active',
    '/provider/workspaces/recipients/declined': 'declined',
    '/provider/workspaces/recipients/closed': 'closed',
};
const queueCopy = {
    awaiting: { title: 'Agreement responses', description: 'Track selected applicants who still need to respond to their terms.', empty: 'No agreement responses are pending.' },
    active: { title: 'Active recipients', description: 'Manage accepted recipients and their support status.', empty: 'No recipients have active support.' },
    declined: { title: 'Declined responses', description: 'Review applicants who declined the recipient agreement.', empty: 'No selected applicants declined their terms.' },
    closed: { title: 'Closed recipient records', description: 'Reference completed or ended scholarship support.', empty: 'No recipient support records are closed.' },
};

const path = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/recipients/agreements';
const activeQueue = queueByPath[path] ?? 'awaiting';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ awaiting: 0, active: 0, declined: 0, closed: 0 });
const programs = ref([]);
const recipients = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','program_id']);
const search = ref(queueState.get('search') ?? '');
const selectedProgram = ref(queueState.get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let requestVersion = 0;
let requestedPage = 1;
let searchTimer = null;

const queues = computed(() => [
    { key: 'awaiting', label: 'Awaiting response', count: summary.value.awaiting, href: '/provider/workspaces/recipients/agreements' },
    { key: 'active', label: 'Active', count: summary.value.active, href: '/provider/workspaces/recipients/active' },
    { key: 'declined', label: 'Declined', count: summary.value.declined, href: '/provider/workspaces/recipients/declined' },
    { key: 'closed', label: 'Closed', count: summary.value.closed, href: '/provider/workspaces/recipients/closed' },
]);
const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 recipients');

function recordUrl(recipient) {
    return queueState.link(`/provider/applications/${recipient.id}`);
}

function statusClass(state) {
    return {
        active: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        awaiting: 'border-amber-200 bg-amber-50 text-amber-800',
        declined: 'border-slate-300 bg-slate-100 text-slate-700',
        closed: 'border-slate-300 bg-slate-100 text-slate-700',
    }[state] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

async function loadRecipients(page = 1) {
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
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/recipients/data', { params: query }), params, () => version === requestVersion);
        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        recipients.value = response.data.recipients ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The recipient register could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    requestVersion++;
    isLoading.value = true;
    errorMessage.value = '';
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadRecipients(1), 300);
}, { flush: 'sync' });
watch(selectedProgram, () => loadRecipients(1), { flush: 'sync' });
onMounted(() => loadRecipients(queueState.page));
onBeforeUnmount(() => {
    requestVersion++;
    window.clearTimeout(searchTimer);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="recipient-directory-title">
            <div class="provider-application-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div class="flex min-w-0 items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-award" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Recipient onboarding</p><h1 id="recipient-directory-title" class="application-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p></div></div>
                        <a href="/provider/workspaces/recipients/withdrawals" class="inline-flex min-h-10 shrink-0 items-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700"><i class="fa-solid fa-arrow-right-from-bracket text-xs" aria-hidden="true"></i>Withdrawal requests</a>
                    </div>
                    <ProviderProgramQueueContext :program-id="selectedProgram" :programs="programs" class="mt-4" />
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Recipient queues">
                    <a v-for="queue in queues" :key="queue.key" :href="providerProgramQueueLink(queue.href, selectedProgram)" :aria-current="activeQueue === queue.key ? 'page' : undefined" :class="['flex min-h-16 items-center justify-between border-r border-slate-200 px-5 last:border-r-0', activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']"><span class="text-sm font-bold">{{ queue.label }}</span><span :class="['min-w-7 px-2 py-1 text-center text-xs font-bold', activeQueue === queue.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-700']">{{ queue.count }}</span></a>
                </nav>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Recipient list">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1"><span class="sr-only">Search recipients</span><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search recipient or program"></label>
                        <label class="w-72"><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="">All programs</option><option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option></select></label>
                    </div>


                    <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadRecipients(requestedPage)" />
                    <div v-else-if="isLoading" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading recipient records...</p></div>
                    <div v-else-if="!recipients.length && !errorMessage" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-user-shield text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-xs text-slate-500">Recipient records move here after a scholarship is awarded.</p></div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[56rem] table-fixed border-collapse text-left"><colgroup><col><col class="w-44"><col class="w-40"><col class="w-32"><col class="w-36"></colgroup>
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600"><th class="px-5 py-3">Recipient</th><th class="px-5 py-3">Onboarding</th><th class="px-5 py-3">Support</th><th class="px-5 py-3">Award</th><th class="px-5 py-3 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="recipient in recipients" :key="recipient.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex items-center gap-3"><StaffTableIdentity :src="recipient.applicant.profile_photo_url" class="h-11 w-11" /><div class="min-w-0"><p class="application-text text-sm font-bold text-slate-950">{{ recipient.applicant.name }}</p><p class="mt-1 application-text text-xs text-slate-500">{{ recipient.program.title }}</p></div></div></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(recipient.onboarding.state)]">{{ recipient.onboarding.label }}</span></td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold text-slate-900">{{ recipient.onboarding.state === 'active' ? 'Active' : recipient.onboarding.state === 'closed' ? 'Closed' : 'Not started' }}</p>
                                        <p v-if="recipient.onboarding.state === 'active' && recipient.program.support_period" class="mt-1 application-text text-xs text-slate-500">{{ recipient.program.support_period }}</p>
                                        <p v-else-if="recipient.onboarding.state === 'closed' && recipient.support.latest_decision_at" class="mt-1 text-xs text-slate-500">{{ recipient.support.latest_decision_at }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-bold text-slate-900">{{ recipient.award.amount_label }}</td>
                                    <td class="px-5 py-4 text-right"><a :href="recordUrl(recipient)" class="staff-row-action staff-row-action--primary h-9 px-3 text-xs" :aria-label="`${recipient.onboarding.action_label} for ${recipient.applicant.name}`">{{ recipient.onboarding.action_label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3"><p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p><div class="flex gap-2"><button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)" @click="loadRecipients(pagination.current_page - 1)"><i class="fa-solid fa-arrow-left text-[9px]" aria-hidden="true"></i>Previous</button><button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)" @click="loadRecipients(pagination.current_page + 1)">Next<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></button></div></footer>
                </section>
            </div>
        </section>
    </main>
</template>
