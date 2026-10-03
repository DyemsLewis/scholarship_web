<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

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
const queueCopy = computed(() => ({
    awaiting: {
        title: 'Recipients waiting to accept their terms',
        description: 'The applicant must respond before recipient support can be managed.',
    },
    active: {
        title: 'Recipients ready for support',
        description: 'Agreements are accepted and the recipient record is active.',
    },
    declined: {
        title: 'Responses that need provider review',
        description: 'Review the applicant note before deciding the next communication.',
    },
    closed: {
        title: 'Completed support records',
        description: 'Reference completed and ended recipient support without mixing it into active work.',
    },
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading recipient onboarding...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-amber-400 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                                    <i class="fa-solid fa-user-shield"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">Recipient officer</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Recipient onboarding</h1>
                                    <p class="mt-1 text-sm text-slate-600">Track agreement responses and maintain selected-recipient records.</p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3 text-left lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0 lg:text-right">
                                <p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ accessLabel }}</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextRecipient" :class="['mt-4 overflow-hidden rounded-lg border bg-white shadow-sm', nextRecipient.onboarding.state === 'declined' ? 'border-rose-300' : 'border-amber-300']">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(15rem,.7fr)_auto] lg:items-stretch">
                            <div :class="['flex items-center justify-center px-4 py-4 text-white lg:py-5', nextRecipient.onboarding.state === 'declined' ? 'bg-rose-800' : 'bg-slate-950']">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-amber-200">Needs attention</p>
                                    <i :class="['mt-2 text-lg fa-solid', nextRecipient.onboarding.state === 'declined' ? 'fa-message' : 'fa-file-signature']"></i>
                                </div>
                            </div>
                            <div class="flex min-w-0 items-center gap-3 border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <img v-if="nextRecipient.applicant.profile_photo_url" :src="nextRecipient.applicant.profile_photo_url" :alt="nextRecipient.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-sm font-black text-slate-600">{{ applicantInitials(nextRecipient.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-base font-bold text-slate-950">{{ nextRecipient.applicant.name }}</h2>
                                        <span :class="['rounded px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', onboardingClass(nextRecipient.onboarding.state)]">{{ nextRecipient.onboarding.label }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-sm text-slate-500">{{ nextRecipient.program.title }}</p>
                                </div>
                            </div>
                            <div class="flex items-center border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <div class="min-w-0">
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-400">Next step</p>
                                    <p class="mt-1 text-sm font-bold text-slate-900">{{ nextRecipient.onboarding.next_step }}</p>
                                    <p v-if="nextRecipient.onboarding.response_note" class="mt-1 truncate text-xs text-rose-700">“{{ nextRecipient.onboarding.response_note }}”</p>
                                </div>
                            </div>
                            <div class="flex items-center px-5 py-4">
                                <a :href="nextRecipient.action_url" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-black text-white transition hover:bg-slate-800 lg:w-auto">
                                    {{ nextRecipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">Recipient responses are up to date</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">No declined or unanswered agreement currently needs attention.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Recipient register</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueCopy.title }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ queueCopy.description }}</p>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(15rem,1fr)_minmax(12rem,.7fr)] xl:w-[38rem]">
                                    <label class="relative block">
                                        <span class="sr-only">Search recipients</span>
                                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input v-model="searchQuery" type="search" placeholder="Search recipient or program" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    </label>
                                    <label>
                                        <span class="sr-only">Filter by program</span>
                                        <select v-model="selectedProgram" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                            <option value="">All assigned programs</option>
                                            <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-1 overflow-x-auto border-t border-slate-200 pt-2" aria-label="Recipient onboarding states">
                                <button
                                    v-for="tab in queueTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 border-b-2 px-4 py-2 text-xs font-bold transition',
                                        activeQueue === tab.key ? 'border-slate-950 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800',
                                    ]"
                                    @click="selectQueue(tab.key)"
                                >
                                    {{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-amber-200 text-slate-950' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span>
                                </button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="recipients.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(15rem,1.1fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(11rem,.7fr)_10rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Recipient</span>
                                <span>Program</span>
                                <span>Agreement</span>
                                <span>Support</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="recipient in recipients" :key="recipient.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.1fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(11rem,.7fr)_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="recipient.applicant.profile_photo_url" :src="recipient.applicant.profile_photo_url" :alt="recipient.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                    <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(recipient.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ recipient.applicant.name }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ recipient.applicant.education || 'Selected recipient' }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-800">{{ recipient.program.title }}</p>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ recipient.award.amount_label }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', onboardingClass(recipient.onboarding.state)]">{{ recipient.onboarding.label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ recipient.onboarding.responded_at || `${recipient.waiting_days} days waiting` }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ recipient.program.support_period || 'Dates not specified' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ recipient.onboarding.next_step }}</p>
                                </div>

                                <a :href="recipient.action_url" class="rounded-md bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ recipient.onboarding.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-address-book"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No recipients in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another onboarding state, program, or search.</p>
                        </div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                            <p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button>
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button>
                            </div>
                        </div>
                    </section>

                    <p class="mt-4 border-l-2 border-amber-400 px-4 py-2 text-sm text-slate-600">
                        Agreement acceptance activates recipient support. Check-ins and benefit releases remain in their assigned operational workspaces.
                    </p>
                </template>
            </div>
        </section>
    </main>
</template>
