<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ReviewOfficerSidebar from '../components/ReviewOfficerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const queueTypes = ['providers', 'programs', 'applicants', 'benefits', 'monitoring'];
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const reviewWorkspaceBase = usesPortalManagerWorkspace ? '/admin/workspaces/portal/reviews' : '/admin/workspaces/reviews';
const requestedType = new URLSearchParams(window.location.search).get('type');
const activeType = ref(queueTypes.includes(requestedType) ? requestedType : 'providers');
const search = ref('');
const page = ref(1);
const pageSize = 10;
const isLoading = ref(true);
const isProgramLoading = ref(false);
const errorMessage = ref('');
const providerStatus = ref('pending');
const programStatus = ref('pending_review');
const applicantStatus = ref('pending');
const benefitStatus = ref('attention');
const monitoringStatus = ref('attention');
const providers = ref([]);
const programs = ref([]);
const applicants = ref([]);
const benefits = ref([]);
const monitoring = ref([]);
const stats = ref({
    providers: 0,
    pending_providers: 0,
    approved_providers: 0,
    rejected_providers: 0,
    pending_programs: 0,
    published_programs: 0,
    rejected_programs: 0,
    applicants: 0,
    pending_applicants: 0,
    approved_applicants: 0,
    rejected_applicants: 0,
    unsubmitted_applicants: 0,
    benefit_records: 0,
    benefits_needing_attention: 0,
    benefits_released: 0,
    benefits_with_receipts: 0,
    benefits_note_only: 0,
    monitoring_records: 0,
    monitoring_needing_attention: 0,
    monitoring_reviewed: 0,
    monitoring_stable: 0,
    monitoring_closed: 0,
});

const queueConfig = computed(() => ({
    providers: {
        label: 'Providers',
        icon: 'fa-solid fa-building-shield',
        count: stats.value.pending_providers,
        title: 'Provider verification',
        description: 'Confirm organization identity and submitted proof.',
        placeholder: 'Search provider name or address',
    },
    programs: {
        label: 'Programs',
        icon: 'fa-solid fa-graduation-cap',
        count: stats.value.pending_programs,
        title: 'Program review',
        description: 'Check scholarship details before publication.',
        placeholder: 'Search program or provider',
    },
    applicants: {
        label: 'Applicants',
        icon: 'fa-solid fa-user-check',
        count: stats.value.pending_applicants,
        title: 'Applicant verification',
        description: 'Compare profile claims with submitted records.',
        placeholder: 'Search applicant, email, or school',
    },
    benefits: {
        label: 'Benefits',
        icon: 'fa-solid fa-receipt',
        count: stats.value.benefits_needing_attention,
        title: 'Benefit evidence',
        description: 'Review release records that have evidence concerns.',
        placeholder: 'Search recipient, program, or provider',
    },
    monitoring: {
        label: 'Monitoring',
        icon: 'fa-solid fa-chart-line',
        count: stats.value.monitoring_needing_attention,
        title: 'Monitoring oversight',
        description: 'Review unresolved recipient support records.',
        placeholder: 'Search recipient, program, or provider',
    },
}[activeType.value]));

const queues = computed(() => queueTypes.map((type) => ({
    type,
    ...({
        providers: { label: 'Providers', icon: 'fa-solid fa-building-shield', count: stats.value.pending_providers },
        programs: { label: 'Programs', icon: 'fa-solid fa-graduation-cap', count: stats.value.pending_programs },
        applicants: { label: 'Applicants', icon: 'fa-solid fa-user-check', count: stats.value.pending_applicants },
        benefits: { label: 'Benefits', icon: 'fa-solid fa-receipt', count: stats.value.benefits_needing_attention },
        monitoring: { label: 'Monitoring', icon: 'fa-solid fa-chart-line', count: stats.value.monitoring_needing_attention },
    }[type]),
})));

const attentionTotal = computed(() => stats.value.pending_providers
    + stats.value.pending_programs
    + stats.value.pending_applicants
    + stats.value.benefits_needing_attention
    + stats.value.monitoring_needing_attention);
const decisionTotal = computed(() => stats.value.pending_providers
    + stats.value.pending_programs
    + stats.value.pending_applicants);
const oversightTotal = computed(() => stats.value.benefits_needing_attention
    + stats.value.monitoring_needing_attention);

function applicantReviewState(applicant) {
    if (['approved', 'rejected'].includes(applicant.applicant_verification_status)) {
        return applicant.applicant_verification_status;
    }

    return applicant.verification_documents?.length ? 'pending' : 'unsubmitted';
}

const filters = computed(() => ({
    providers: [
        { value: 'pending', label: 'Pending', count: stats.value.pending_providers },
        { value: 'approved', label: 'Approved', count: stats.value.approved_providers },
        { value: 'rejected', label: 'Rejected', count: stats.value.rejected_providers },
        { value: 'all', label: 'All', count: stats.value.providers },
    ],
    programs: [
        { value: 'pending_review', label: 'Pending', count: stats.value.pending_programs },
        { value: 'published', label: 'Published', count: stats.value.published_programs },
        { value: 'rejected', label: 'Rejected', count: stats.value.rejected_programs },
    ],
    applicants: [
        { value: 'pending', label: 'Pending', count: stats.value.pending_applicants },
        { value: 'approved', label: 'Verified', count: stats.value.approved_applicants },
        { value: 'rejected', label: 'Replacement', count: stats.value.rejected_applicants },
        { value: 'unsubmitted', label: 'No proof', count: stats.value.unsubmitted_applicants },
        { value: 'all', label: 'All', count: stats.value.applicants },
    ],
    benefits: [
        { value: 'attention', label: 'Needs review', count: stats.value.benefits_needing_attention },
        { value: 'released', label: 'Released', count: stats.value.benefits_released },
        { value: 'receipt', label: 'Receipt', count: stats.value.benefits_with_receipts },
        { value: 'note', label: 'Note only', count: stats.value.benefits_note_only },
        { value: 'all', label: 'All', count: stats.value.benefit_records },
    ],
    monitoring: [
        { value: 'attention', label: 'Needs review', count: stats.value.monitoring_needing_attention },
        { value: 'reviewed', label: 'Reviewed', count: stats.value.monitoring_reviewed },
        { value: 'stable', label: 'Stable', count: stats.value.monitoring_stable },
        { value: 'closed', label: 'Closed', count: stats.value.monitoring_closed },
        { value: 'all', label: 'All', count: stats.value.monitoring_records },
    ],
}[activeType.value]));

const activeStatus = computed({
    get() {
        return {
            providers: providerStatus.value,
            programs: programStatus.value,
            applicants: applicantStatus.value,
            benefits: benefitStatus.value,
            monitoring: monitoringStatus.value,
        }[activeType.value];
    },
    set(value) {
        if (activeType.value === 'providers') providerStatus.value = value;
        if (activeType.value === 'programs') programStatus.value = value;
        if (activeType.value === 'applicants') applicantStatus.value = value;
        if (activeType.value === 'benefits') benefitStatus.value = value;
        if (activeType.value === 'monitoring') monitoringStatus.value = value;
    },
});

function includesQuery(values) {
    const query = search.value.trim().toLowerCase();
    return !query || values.filter(Boolean).join(' ').toLowerCase().includes(query);
}

const filteredItems = computed(() => {
    if (activeType.value === 'providers') {
        return providers.value.filter((provider) => (providerStatus.value === 'all' || provider.verification_status === providerStatus.value)
            && includesQuery([provider.provider_name, provider.name, provider.email, provider.provider_address]));
    }

    if (activeType.value === 'programs') {
        return programs.value.filter((program) => includesQuery([program.title, program.provider, program.category]));
    }

    if (activeType.value === 'applicants') {
        return applicants.value.filter((applicant) => (applicantStatus.value === 'all' || applicantReviewState(applicant) === applicantStatus.value)
            && includesQuery([applicant.name, applicant.username, applicant.email, applicant.school]));
    }

    if (activeType.value === 'benefits') {
        return benefits.value.filter((record) => {
            const matches = benefitStatus.value === 'all'
                || (benefitStatus.value === 'attention' && record.oversight_status === 'attention')
                || (benefitStatus.value === 'released' && record.status === 'released')
                || record.evidence_type === benefitStatus.value;
            return matches && includesQuery([record.applicant_name, record.program_title, record.provider_name, record.release_title]);
        });
    }

    return monitoring.value.filter((record) => (monitoringStatus.value === 'all' || record.oversight_status === monitoringStatus.value)
        && includesQuery([record.applicant_name, record.program_title, record.provider_name]));
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / pageSize)));
const visibleItems = computed(() => filteredItems.value.slice((page.value - 1) * pageSize, page.value * pageSize));
const rangeLabel = computed(() => {
    if (!filteredItems.value.length) return '0 records';
    const start = (page.value - 1) * pageSize + 1;
    return `${start}-${Math.min(page.value * pageSize, filteredItems.value.length)} of ${filteredItems.value.length}`;
});

function label(value) {
    return String(value ?? 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function badgeClass(status) {
    if (['approved', 'published', 'documented', 'reviewed', 'stable', 'released'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (['rejected', 'attention', 'missed', 'withheld'].includes(status)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}

function initials(name, fallback) {
    return String(name || fallback).split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('').toUpperCase();
}

function selectQueue(type) {
    activeType.value = type;
    search.value = '';
    page.value = 1;
    window.history.replaceState({}, '', `${reviewWorkspaceBase}?type=${type}`);
}

async function changeStatus(value) {
    activeStatus.value = value;
    page.value = 1;
    if (activeType.value === 'programs') await loadData(true);
}

async function loadData(programOnly = false) {
    if (programOnly) isProgramLoading.value = true;
    else isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/reviews/data', {
            params: { program_status: programStatus.value },
        });
        stats.value = { ...stats.value, ...response.data.stats };
        providers.value = response.data.providers ?? [];
        programs.value = response.data.scholarships ?? [];
        applicants.value = response.data.applicants ?? [];
        benefits.value = response.data.benefit_records ?? [];
        monitoring.value = response.data.monitoring_records ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the review queue.';
    } finally {
        isLoading.value = false;
        isProgramLoading.value = false;
    }
}

watch(search, () => { page.value = 1; });
onMounted(loadData);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <ReviewOfficerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Verification desk"
                    title="Review workspace"
                    description="Open one queue and resolve the records that need attention."
                    icon="fa-solid fa-clipboard-check"
                >
                    <template #actions>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadData()">
                            <i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading review queues...</div>

                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs attention</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ attentionTotal }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Verification decisions</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ decisionTotal }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Oversight flags</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ oversightTotal }}</dd></div>
                        </dl>
                    </section>

                    <nav class="admin-panel grid overflow-hidden sm:grid-cols-5" aria-label="Review queues">
                        <button
                            v-for="queue in queues"
                            :key="queue.type"
                            type="button"
                            :class="['flex items-center gap-3 border-b border-slate-200 px-4 py-3 text-left transition last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0', activeType === queue.type ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50']"
                            @click="selectQueue(queue.type)"
                        >
                            <i :class="[queue.icon, 'w-4 text-center text-sm', activeType === queue.type ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1 truncate text-sm font-bold">{{ queue.label }}</span>
                            <span :class="['rounded px-1.5 py-0.5 text-[10px] font-black', activeType === queue.type ? 'bg-white/10 text-white' : 'bg-amber-100 text-amber-900']">{{ queue.count }}</span>
                        </button>
                    </nav>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ queueConfig.label }} queue</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">{{ queueConfig.title }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ queueConfig.description }}</p>
                        </header>

                        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:flex-row lg:items-center">
                            <label class="relative w-full lg:max-w-xl">
                                <span class="sr-only">Search active queue</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input v-model="search" type="search" :placeholder="queueConfig.placeholder" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-500">
                            </label>
                            <select :value="activeStatus" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none lg:ml-auto lg:min-w-48" @change="changeStatus($event.target.value)">
                                <option v-for="filter in filters" :key="filter.value" :value="filter.value">{{ filter.label }} ({{ filter.count }})</option>
                            </select>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ rangeLabel }}</p>
                        </div>

                        <div v-if="isProgramLoading" class="p-6 text-sm text-slate-500">Loading program records...</div>
                        <div v-else-if="visibleItems.length === 0" class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                            <p class="mt-3 font-bold text-slate-950">No records in this view</p>
                            <p class="mt-1 text-sm text-slate-500">Try another status or search term.</p>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article v-for="item in visibleItems" :key="item.id ?? item.application_id" class="flex flex-col gap-3 px-5 py-4 sm:px-6 lg:flex-row lg:items-center">
                                <template v-if="activeType === 'providers'">
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-black text-white">{{ initials(item.provider_name || item.name, 'PR') }}</div>
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.provider_name || item.name }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', badgeClass(item.verification_status)]">{{ label(item.verification_status) }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ item.provider_type ? label(item.provider_type) : 'Provider organization' }}<template v-if="item.provider_address"> &middot; {{ item.provider_address }}</template></p></div>
                                    <a :href="`${reviewWorkspaceBase}/providers/${item.id}`" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Review</a>
                                </template>

                                <template v-else-if="activeType === 'programs'">
                                    <img :src="item.image_url || '/uploads/scholarship-default.jpg'" :alt="item.title" class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1 ring-1 ring-slate-200">
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.title }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', badgeClass(item.status)]">{{ label(item.status) }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ item.provider || 'Provider' }} &middot; {{ item.category || 'Uncategorized' }}</p></div>
                                    <a :href="`${reviewWorkspaceBase}/programs/${item.id}`" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Review</a>
                                </template>

                                <template v-else-if="activeType === 'applicants'">
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-black text-white">{{ initials(item.name || item.username, 'AP') }}</div>
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.name || item.username }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', badgeClass(applicantReviewState(item))]">{{ label(applicantReviewState(item)) }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ item.email }} &middot; {{ item.school || 'School not provided' }}</p></div>
                                    <a :href="`${reviewWorkspaceBase}/applicants/${item.id}`" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Open record</a>
                                </template>

                                <template v-else-if="activeType === 'benefits'">
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-receipt" aria-hidden="true"></i></div>
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.applicant_name || 'Recipient' }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', badgeClass(item.oversight_status)]">{{ item.oversight_label }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ item.release_title || 'Benefit release' }} &middot; {{ item.program_title || 'Program' }}</p><p v-if="item.oversight_flags?.length" class="mt-1 line-clamp-1 text-xs font-semibold text-amber-800">{{ item.oversight_flags[0] }}</p></div>
                                    <div class="flex shrink-0 gap-2"><a v-if="item.receipt" :href="item.receipt.view_url" target="_blank" rel="noopener noreferrer" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Evidence</a><a v-if="item.applicant_id" :href="`${reviewWorkspaceBase}/applicants/${item.applicant_id}`" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800">Recipient</a></div>
                                </template>

                                <template v-else>
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div>
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.applicant_name || 'Recipient' }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', badgeClass(item.oversight_status)]">{{ item.oversight_label }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ item.program_title }} &middot; {{ item.provider_name }}</p><p v-if="item.attention_items?.length" class="mt-1 line-clamp-1 text-xs font-semibold text-amber-800">{{ item.attention_items[0].title }}<span v-if="item.attention_items.length > 1"> +{{ item.attention_items.length - 1 }} more</span></p></div>
                                    <a :href="`${reviewWorkspaceBase}/monitoring/${item.application_id}`" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Review</a>
                                </template>
                            </article>
                        </div>
                    </section>

                    <nav v-if="totalPages > 1" class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3" aria-label="Queue pagination">
                        <p class="text-xs font-semibold text-slate-500">Page {{ page }} of {{ totalPages }}</p>
                        <div class="flex gap-2"><button type="button" :disabled="page === 1" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" @click="page -= 1">Previous</button><button type="button" :disabled="page === totalPages" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" @click="page += 1">Next</button></div>
                    </nav>
                </div>
            </div>
        </section>
    </main>
</template>
