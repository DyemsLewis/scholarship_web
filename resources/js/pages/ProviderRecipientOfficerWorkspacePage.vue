<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderQueueTabs from '../components/ProviderQueueTabs.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ awaiting: 0, active: 0, declined: 0, closed: 0 });
const nextRecipient = ref(null);
const programs = ref([]);
const recipients = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const allowedQueues = ['awaiting', 'active', 'declined', 'closed'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'awaiting');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const queueTabs = computed(() => [
    { key: 'awaiting', label: 'Awaiting agreement', count: Number(summary.value.awaiting ?? 0) },
    { key: 'active', label: 'Active recipients', count: Number(summary.value.active ?? 0) },
    { key: 'declined', label: 'Declined', count: Number(summary.value.declined ?? 0) },
    { key: 'closed', label: 'Closed records', count: Number(summary.value.closed ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    awaiting: 'Recipients waiting for agreement',
    active: 'Recipients ready for support',
    declined: 'Declined agreement responses',
    closed: 'Completed support records',
}[activeQueue.value]));

function applicantInitials(name) {
    return String(name ?? 'Recipient')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function onboardingClass(state) {
    return {
        awaiting: 'bg-amber-100 text-amber-900',
        active: 'bg-emerald-100 text-emerald-800',
        declined: 'bg-rose-100 text-rose-800',
        closed: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'awaiting') nextUrl.searchParams.delete('queue');
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
        nextRecipient.value = response.data.next_recipient;
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

function selectQueue(queue) {
    activeQueue.value = queue;
    loadWorkspace(1);
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading recipient onboarding" message="Preparing agreement responses and support records." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Recipient onboarding is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="recipients" title="Recipient onboarding" description="Complete agreements and prepare selected recipients for ongoing support." icon="fa-solid fa-user-shield">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextRecipient" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950">
                                <i :class="['fa-solid text-sm', nextRecipient.onboarding.state === 'declined' ? 'fa-message' : 'fa-file-signature']"></i>
                            </span>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <img v-if="nextRecipient.applicant.profile_photo_url" :src="nextRecipient.applicant.profile_photo_url" :alt="nextRecipient.applicant.name" class="h-9 w-9 shrink-0 object-cover">
                                <span v-else class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(nextRecipient.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Needs attention</p>
                                    <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextRecipient.applicant.name }}</h2>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextRecipient.program.title }}</p>
                                </div>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <span :class="['inline-flex px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', onboardingClass(nextRecipient.onboarding.state)]">{{ nextRecipient.onboarding.label }}</span>
                                <p class="mt-1 text-xs font-semibold text-slate-600">{{ nextRecipient.onboarding.next_step }}</p>
                                <p v-if="nextRecipient.onboarding.response_note" class="mt-0.5 max-w-xs truncate text-xs text-rose-700">“{{ nextRecipient.onboarding.response_note }}”</p>
                            </div>
                            <a :href="nextRecipient.action_url" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                {{ nextRecipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                            </a>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">Recipient responses are up to date</h2>
                            <p class="mt-0.5 text-xs text-slate-500">No unanswered or declined agreement needs attention.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div>
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Recipient register</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2>
                            </div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'recipient' : 'recipients' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Recipient onboarding states" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block">
                                <span class="sr-only">Search recipients</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input v-model="searchQuery" type="search" placeholder="Search recipient or program" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                            </label>
                            <label>
                                <span class="sr-only">Filter by program</span>
                                <select v-model="selectedProgram" class="w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <option value="">All assigned programs</option>
                                    <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                </select>
                            </label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="recipients.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(16rem,1.05fr)_minmax(22rem,1.35fr)_minmax(15rem,.8fr)_10rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Recipient</span>
                                <span>Program and support</span>
                                <span>Agreement status</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="recipient in recipients" :key="recipient.id" class="grid gap-4 px-5 py-3.5 transition hover:bg-slate-50 xl:grid-cols-[minmax(16rem,1.05fr)_minmax(22rem,1.35fr)_minmax(15rem,.8fr)_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="recipient.applicant.profile_photo_url" :src="recipient.applicant.profile_photo_url" :alt="recipient.applicant.name" class="h-10 w-10 shrink-0 object-cover">
                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(recipient.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ recipient.applicant.name }}</h3>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ recipient.applicant.education || 'Selected recipient' }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-800">{{ recipient.program.title }}</p>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ recipient.award.amount_label }}<span class="mx-2 text-slate-300">|</span>{{ recipient.program.support_period || 'Support dates not specified' }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', onboardingClass(recipient.onboarding.state)]">{{ recipient.onboarding.label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ recipient.onboarding.responded_at || `${recipient.waiting_days} days waiting` }}<span class="mx-1 text-slate-300">|</span>{{ recipient.onboarding.next_step }}</p>
                                </div>

                                <a :href="recipient.action_url" class="bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ recipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-address-book"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No recipients in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another onboarding state, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="recipients" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
