<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderApplicantPhoto from '../components/ProviderApplicantPhoto.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ awaiting: 0, active: 0, declined: 0, closed: 0 });
const programs = ref([]);
const recipients = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { agreements: 'awaiting', active: 'active', declined: 'declined', closed: 'closed' };
const queueOptions = ['awaiting', 'active', 'declined', 'closed'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'awaiting');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const queueSections = computed(() => [
    { key: 'awaiting', label: 'Agreement responses', shortLabel: 'Agreements', description: 'Follow up on selected applicants who have not responded.', count: Number(summary.value.awaiting ?? 0), href: '/provider/workspaces/recipients/agreements', icon: 'fa-file-signature' },
    { key: 'active', label: 'Active recipients', shortLabel: 'Active', description: 'Open recipients who accepted their scholarship terms.', count: Number(summary.value.active ?? 0), href: '/provider/workspaces/recipients/active', icon: 'fa-user-check' },
    { key: 'declined', label: 'Declined responses', shortLabel: 'Declined', description: 'Review applicants who declined the recipient agreement.', count: Number(summary.value.declined ?? 0), href: '/provider/workspaces/recipients/declined', icon: 'fa-user-xmark' },
    { key: 'closed', label: 'Closed recipient records', shortLabel: 'Closed', description: 'View completed or ended scholarship support records.', count: Number(summary.value.closed ?? 0), href: '/provider/workspaces/recipients/closed', icon: 'fa-box-archive' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const leadRecipient = computed(() => ['awaiting', 'declined'].includes(activeQueue.value) ? recipients.value[0] ?? null : null);
const isClosed = computed(() => activeQueue.value === 'closed');

function onboardingClass(state) {
    return {
        awaiting: 'bg-amber-100 text-amber-900',
        active: 'bg-emerald-100 text-emerald-800',
        declined: 'bg-rose-100 text-rose-800',
        closed: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function onboardingIcon(state) {
    return {
        awaiting: 'fa-solid fa-clock',
        active: 'fa-solid fa-circle-check',
        declined: 'fa-solid fa-circle-xmark',
        closed: 'fa-solid fa-box-archive',
    }[state] ?? 'fa-solid fa-file-signature';
}

function recipientActionUrl(path) {
    const actionUrl = new URL(path, window.location.origin);

    if (actionUrl.pathname.startsWith('/provider/applications/')) {
        actionUrl.searchParams.set('section', 'decision');
        actionUrl.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);
    }

    return `${actionUrl.pathname}${actionUrl.search}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'awaiting') nextUrl.searchParams.delete('queue');
    else nextUrl.searchParams.set('queue', activeQueue.value);

    if (selectedProgram.value) nextUrl.searchParams.set('program_id', selectedProgram.value);
    else nextUrl.searchParams.delete('program_id');

    window.history.replaceState({}, '', nextUrl);
}

async function loadWorkspace(page = 1, initial = false) {
    if (initial) isLoading.value = true;
    else isRefreshing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/recipients/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        recipients.value = response.data.recipients ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load recipient onboarding.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

watch(selectedProgram, () => loadWorkspace(1));
watch(searchQuery, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadWorkspace(1), 300);
});

onMounted(() => loadWorkspace(1, true));
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <ProviderWorkspaceState v-if="isLoading" title="Loading recipient onboarding" message="Preparing agreement and support records." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Recipient onboarding is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="recipients" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-user-shield" :show-role-guide="false" />

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Recipient onboarding pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} recipient{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="leadRecipient" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <ProviderApplicantPhoto :src="leadRecipient.applicant.profile_photo_url" :name="leadRecipient.applicant.name" />
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Needs attention</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ leadRecipient.applicant.name }}</h2>
                                <p class="mt-0.5 truncate text-sm text-slate-500">{{ leadRecipient.program.title }} / {{ leadRecipient.onboarding.next_step }}</p>
                            </div>
                            <a :href="recipientActionUrl(leadRecipient.action_url)" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ leadRecipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-950">{{ pagination.total }} recipient{{ pagination.total === 1 ? '' : 's' }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ isClosed ? 'Most recently closed records appear first.' : 'Only records from this onboarding page are shown.' }}</p>
                            </div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="grid grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)] gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search recipients</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search recipient or program" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="recipients.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[70rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[32%]"><col class="w-[25%]"><col class="w-[27%]"><col class="w-[16%]"></colgroup>
                                <thead><tr><th scope="col">Recipient and program</th><th scope="col">Scholarship support</th><th scope="col">Recipient status</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="recipient in recipients" :key="recipient.id">
                                        <td><div class="flex min-w-0 items-start gap-3"><ProviderApplicantPhoto :src="recipient.applicant.profile_photo_url" :name="recipient.applicant.name" /><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ recipient.applicant.name }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ recipient.program.title }}</p><p v-if="recipient.applicant.education" class="mt-1 truncate text-xs text-slate-500">{{ recipient.applicant.education }}</p></div></div></td>
                                        <td><p class="font-semibold text-slate-800"><i class="fa-solid fa-award mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ recipient.award.amount_label }}</p><p class="mt-1 text-xs text-slate-500"><i class="fa-regular fa-calendar mr-1.5 text-slate-400" aria-hidden="true"></i>{{ recipient.program.support_period || 'Support period not specified' }}</p></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', onboardingClass(recipient.onboarding.state)]"><i :class="onboardingIcon(recipient.onboarding.state)" aria-hidden="true"></i>{{ recipient.onboarding.label }}</span><p class="mt-1.5 text-xs text-slate-500">{{ recipient.onboarding.responded_at || `${recipient.waiting_days} day${recipient.waiting_days === 1 ? '' : 's'} waiting` }}</p><p v-if="recipient.onboarding.response_note" class="mt-1 line-clamp-1 text-xs text-rose-700">{{ recipient.onboarding.response_note }}</p></td>
                                        <td><a :href="recipientActionUrl(recipient.action_url)" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ recipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <i class="fa-solid fa-address-book text-2xl text-slate-300" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No recipients on this page</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedProgram ? 'Clear the filters to check the full list.' : 'Recipient records will appear here when they reach this state.' }}</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="recipients" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
