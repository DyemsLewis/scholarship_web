<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const appElement = document.getElementById('app');
const initialScholarshipId = appElement?.dataset.scholarshipId ?? new URLSearchParams(window.location.search).get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const isLoading = ref(true);
const errorMessage = ref('');
const applications = ref([]);
const selectedScholarshipContext = ref(initialScholarshipId ? {
    id: Number(initialScholarshipId),
    title: initialScholarshipTitle,
} : null);
const selectedQueueFilter = ref('all');
const selectedQueueSort = ref('priority');
const applicationSearch = ref('');
const applicationPage = ref(1);
const applicationsPerPage = 10;
const programEvents = ref([]);
const scheduleEditorType = ref('');
const scheduleSaving = ref(false);
const scheduleError = ref('');
const scheduleForm = ref(emptyScheduleForm());
const minimumScheduleDateTime = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);

const scheduleTypeCatalog = [
    { value: 'screening', label: 'Screening', icon: 'fa-solid fa-list-check', help: 'Eligibility and document review' },
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question', help: 'Provider-managed exam schedule' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments', help: 'Shared interview instructions' },
    { value: 'distribution', label: 'Distribution', icon: 'fa-solid fa-hand-holding-dollar', help: 'Award release announcement' },
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];

const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const configuredScheduleTypes = computed(() => {
    const configured = selectedScholarshipContext.value?.selection_stages ?? ['screening', 'distribution'];

    return scheduleTypeCatalog.filter((type) => configured.includes(type.value));
});
const exportApplicationsUrl = computed(() => {
    if (!hasProgramContext.value) {
        return '/provider/export/applications';
    }

    return `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedScholarshipId.value)}`;
});
const pageKicker = computed(() => (hasProgramContext.value ? 'Program Workspace' : 'Application Review'));
const pageTitle = computed(() => (hasProgramContext.value
    ? selectedScholarshipContext.value?.title || 'Scholarship program'
    : 'Applicant activity queue'));
const pageDescription = computed(() => (hasProgramContext.value
    ? 'Manage this program schedule and review its submitted applicants in one place.'
    : 'Review submitted applications, document status, and DSS guidance for your programs.'));
const programStageSummary = computed(() => configuredScheduleTypes.value.map((stage) => stage.label).join(', '));
const reviewFilterOptions = computed(() => [
    { value: 'all', label: 'All', count: applications.value.length },
    {
        value: 'pending_review',
        label: 'Pending review',
        count: applications.value.filter((application) => ['submitted', 'under_review'].includes(application.status ?? 'submitted')).length,
    },
    {
        value: 'document_issues',
        label: 'Document issues',
        count: applications.value.filter((application) => documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100).length,
    },
    {
        value: 'strong_candidates',
        label: 'Strong candidates',
        count: applications.value.filter((application) => Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80).length,
    },
]);
const rankedApplications = computed(() => {
    const query = applicationSearch.value.trim().toLowerCase();
    const filteredApplications = applications.value.filter((application) => {
        const matchesSearch = !query || [
            application.applicant?.name,
            application.applicant?.email,
            application.scholarship?.title,
        ].filter(Boolean).join(' ').toLowerCase().includes(query);

        if (!matchesSearch) {
            return false;
        }

        if (selectedQueueFilter.value === 'pending_review') {
            return ['submitted', 'under_review'].includes(application.status ?? 'submitted');
        }

        if (selectedQueueFilter.value === 'document_issues') {
            return documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100;
        }

        if (selectedQueueFilter.value === 'strong_candidates') {
            return Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80;
        }

        return true;
    });

    return [...filteredApplications].sort((first, second) => {
        if (selectedQueueSort.value === 'dss') {
            return Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
        }

        if (selectedQueueSort.value === 'documents') {
            return documentIssueCount(second) - documentIssueCount(first);
        }

        return reviewPriorityScore(second) - reviewPriorityScore(first) || Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
    });
});
const totalApplicationPages = computed(() => Math.max(1, Math.ceil(rankedApplications.value.length / applicationsPerPage)));
const visibleApplications = computed(() => {
    const start = (applicationPage.value - 1) * applicationsPerPage;

    return rankedApplications.value.slice(start, start + applicationsPerPage);
});
const visibleApplicationRange = computed(() => {
    if (rankedApplications.value.length === 0) {
        return '0 applications';
    }

    const start = (applicationPage.value - 1) * applicationsPerPage + 1;
    const end = Math.min(applicationPage.value * applicationsPerPage, rankedApplications.value.length);

    return `${start}-${end} of ${rankedApplications.value.length}`;
});
const customStatusLabels = {
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    distribution_scheduled: 'Distribution scheduled',
    disbursed: 'Distributed',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
};
function statusLabel(status) {
    if (customStatusLabels[status]) {
        return customStatusLabels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded', 'exam_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function programStatusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function documentIssueCount(application) {
    return (application.documents ?? []).filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
}

function reviewPriorityScore(application) {
    const status = application.status ?? 'submitted';
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);
    const issues = documentIssueCount(application);
    let score = 0;

    if (status === 'submitted') {
        score += 24;
    }

    if (status === 'under_review') {
        score += 16;
    }

    if (['exam_qualified', 'exam_scheduled', 'exam_taken'].includes(status)) {
        score += 14;
    }

    if (status === 'exam_passed') {
        score += 10;
    }

    if (issues > 0) {
        score += Math.min(35, issues * 12);
    }

    if (readiness < 100) {
        score += readiness === 0 ? 22 : 14;
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        score += 12;
    }

    if (application.dss_recommendation === 'needs_review') {
        score += 20;
    }

    if (application.dss_recommendation === 'not_recommended') {
        score += 10;
    }

    if (['approved', 'awarded'].includes(status) && !application.distribution_scheduled_for) {
        score += 18;
    }

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['not_awarded', 'disbursed', 'renewed', 'rejected', 'exam_failed'].includes(status)) {
        score -= 25;
    }

    return Math.max(0, score);
}

function reviewPriorityLabel(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'High priority';
    }

    if (score >= 35) {
        return 'Needs review';
    }

    return 'Routine';
}

function reviewPriorityClass(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'bg-rose-100 text-rose-800';
    }

    if (score >= 35) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
}

function emptyScheduleForm(type = '') {
    return {
        type,
        title: '',
        scheduledAt: '',
        mode: 'onsite',
        venue: '',
        locationAddress: '',
        latitude: '',
        longitude: '',
        onlineUrl: '',
        instructions: '',
    };
}

function scheduleTypeLabel(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.label ?? type;
}

function scheduleEvent(type) {
    return programEvents.value.find((event) => event.type === type) ?? null;
}

function defaultScheduleDetails(type) {
    const scholarship = selectedScholarshipContext.value ?? {};

    return {
        title: `${scheduleTypeLabel(type)} schedule`,
        mode: 'onsite',
        venue: scholarship.location_name ?? '',
        locationAddress: scholarship.location_address ?? '',
        latitude: scholarship.latitude ?? '',
        longitude: scholarship.longitude ?? '',
        instructions: {
            screening: 'Keep your profile and submitted requirements complete while the provider reviews your application.',
            exam: 'Review the provider exam instructions and arrive or sign in at least 15 minutes before the scheduled time.',
            interview: 'Bring a valid school ID and be ready to discuss your application and scholarship goals.',
            distribution: 'Bring a valid school ID and any release documents required by the provider.',
        }[type] ?? '',
    };
}

function openScheduleEditor(type) {
    const existing = scheduleEvent(type);
    const defaults = defaultScheduleDetails(type);

    scheduleForm.value = existing
        ? {
            type: existing.type,
            title: existing.title ?? '',
            scheduledAt: existing.scheduled_at ?? '',
            mode: existing.mode ?? 'onsite',
            venue: existing.venue ?? '',
            locationAddress: existing.location_address ?? '',
            latitude: existing.latitude ?? '',
            longitude: existing.longitude ?? '',
            onlineUrl: existing.online_url ?? '',
            instructions: existing.instructions ?? '',
        }
        : { ...emptyScheduleForm(type), ...defaults, type };
    scheduleEditorType.value = type;
    scheduleError.value = '';
}

function closeScheduleEditor() {
    scheduleEditorType.value = '';
    scheduleForm.value = emptyScheduleForm();
}

function handleSchedulePinPicked(location) {
    scheduleForm.value.latitude = location.latitude;
    scheduleForm.value.longitude = location.longitude;

    if (location.displayName) {
        scheduleForm.value.locationAddress = location.displayName;
    }
}

async function saveProgramSchedule() {
    if (!scheduleForm.value.scheduledAt || !scheduleForm.value.instructions.trim()) {
        scheduleError.value = 'Add the date, time, and applicant instructions.';
        return;
    }

    scheduleSaving.value = true;
    scheduleError.value = '';

    try {
        const response = await window.axios.post(`/provider/scholarships/${selectedScholarshipId.value}/events`, {
            type: scheduleForm.value.type,
            title: scheduleForm.value.title || null,
            scheduled_at: scheduleForm.value.scheduledAt,
            mode: scheduleForm.value.mode,
            venue: scheduleForm.value.venue || null,
            location_address: scheduleForm.value.locationAddress || null,
            latitude: scheduleForm.value.latitude || null,
            longitude: scheduleForm.value.longitude || null,
            online_url: scheduleForm.value.onlineUrl || null,
            instructions: scheduleForm.value.instructions,
        });
        const eventIndex = programEvents.value.findIndex((event) => event.type === response.data.event.type);

        if (eventIndex >= 0) {
            programEvents.value.splice(eventIndex, 1, response.data.event);
        } else {
            programEvents.value.push(response.data.event);
        }

        closeScheduleEditor();
        await loadProviderData(false);
    } catch (handledError) {
        void handledError;
    } finally {
        scheduleSaving.value = false;
    }
}

async function loadProviderData(showLoading = true) {
    isLoading.value = showLoading;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data', {
            params: hasProgramContext.value ? { scholarship_id: selectedScholarshipId.value } : {},
        });

        applications.value = response.data.applications;
        selectedScholarshipContext.value = response.data.selected_scholarship ?? selectedScholarshipContext.value;
        programEvents.value = response.data.program_events ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
}

watch([selectedQueueFilter, selectedQueueSort, applicationSearch], () => {
    applicationPage.value = 1;
});

watch(totalApplicationPages, (totalPages) => {
    if (applicationPage.value > totalPages) {
        applicationPage.value = totalPages;
    }
});

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                {{ pageKicker }}
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ pageTitle }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ pageDescription }}
                            </p>
                        </div>
                        <div v-if="hasProgramContext" class="flex flex-wrap gap-2">
                            <a
                                :href="`/provider/programs/${selectedScholarshipId}/edit`"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Edit program
                            </a>
                            <a
                                href="/provider/applications"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                All applications
                            </a>
                            <a
                                href="/provider/programs"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Programs
                            </a>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application review page...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section v-if="hasProgramContext" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    :src="selectedScholarshipContext?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="selectedScholarshipContext?.title || 'Scholarship program'"
                                    class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">{{ selectedScholarshipContext?.title }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ selectedScholarshipContext?.category || 'Scholarship program' }}</p>
                                </div>
                            </div>
                            <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', programStatusClass(selectedScholarshipContext?.status)]">
                                {{ statusLabel(selectedScholarshipContext?.status) }}
                            </span>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-3 sm:divide-x sm:divide-slate-200">
                            <div class="p-3">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Benefits</dt>
                                <dd class="mt-1 line-clamp-2 text-sm font-bold leading-5 text-slate-900">{{ selectedScholarshipContext?.benefit_summary || 'Not specified' }}</dd>
                            </div>
                            <div class="border-t border-slate-200 p-3 sm:border-t-0">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Deadline</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedScholarshipContext?.deadline || 'Not set' }}</dd>
                            </div>
                            <div class="border-t border-slate-200 p-3 sm:border-t-0">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Selection stages</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-900">{{ programStageSummary || 'Screening' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="hasProgramContext" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Program Schedule</p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">One announcement for each stage</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                    Set a date once. Applicants receive it automatically when your approval moves them into that stage.
                                </p>
                            </div>
                            <a :href="`/provider/programs/${selectedScholarshipId}/edit`" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                Edit selection plan
                            </a>
                        </div>

                        <p v-if="scheduleError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ scheduleError }}</p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <button
                                v-for="type in configuredScheduleTypes"
                                :key="type.value"
                                type="button"
                                :class="[
                                    'flex min-h-32 flex-col rounded-md border p-3 text-left transition',
                                    scheduleEditorType === type.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
                                ]"
                                @click="openScheduleEditor(type.value)"
                            >
                                <span class="flex items-start justify-between gap-3">
                                    <span :class="['grid h-9 w-9 place-items-center rounded-md', scheduleEditorType === type.value ? 'bg-white/10' : 'bg-white text-slate-700 ring-1 ring-slate-200']">
                                        <i :class="type.icon" aria-hidden="true"></i>
                                    </span>
                                    <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', scheduleEvent(type.value) ? 'bg-emerald-100 text-emerald-800' : (scheduleEditorType === type.value ? 'bg-white/10 text-white' : 'bg-slate-200 text-slate-600')]">
                                        {{ scheduleEvent(type.value) ? 'Scheduled' : 'Not set' }}
                                    </span>
                                </span>
                                <span class="mt-3 font-bold">{{ type.label }}</span>
                                <span :class="['mt-1 text-xs leading-5', scheduleEditorType === type.value ? 'text-slate-300' : 'text-slate-500']">
                                    {{ scheduleEvent(type.value)?.scheduled_label || type.help }}
                                </span>
                            </button>
                        </div>

                        <form v-if="scheduleEditorType" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4" @submit.prevent="saveProgramSchedule">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ scheduleTypeLabel(scheduleForm.type) }}</p>
                                    <h4 class="mt-1 font-bold text-slate-950">Publish shared instructions</h4>
                                </div>
                                <button type="button" class="text-sm font-bold text-slate-500 hover:text-slate-900" @click="closeScheduleEditor">Close</button>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Title</label>
                                    <input v-model="scheduleForm.title" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Date and time</label>
                                    <input v-model="scheduleForm.scheduledAt" type="datetime-local" :min="minimumScheduleDateTime" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Mode</label>
                                    <select v-model="scheduleForm.mode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                        <option v-for="mode in scheduleModeOptions" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <div v-if="['onsite', 'hybrid'].includes(scheduleForm.mode)" class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Venue</label>
                                    <input v-model="scheduleForm.venue" type="text" maxlength="500" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Full address</label>
                                    <input v-model="scheduleForm.locationAddress" type="text" maxlength="1000" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div class="overflow-hidden rounded-md md:col-span-2">
                                    <LeafletMapPreview
                                        :address="scheduleForm.locationAddress"
                                        :latitude="scheduleForm.latitude"
                                        :longitude="scheduleForm.longitude"
                                        :title="scheduleForm.venue || 'Program activity location'"
                                        :marker-text="scheduleForm.venue || scheduleForm.title"
                                        height="14rem"
                                        picker
                                        auto-geocode
                                        @picked="handleSchedulePinPicked"
                                    />
                                </div>
                            </div>

                            <div v-if="['online', 'hybrid'].includes(scheduleForm.mode)" class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Online link</label>
                                <input v-model="scheduleForm.onlineUrl" type="url" maxlength="2000" placeholder="https://..." required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                            </div>

                            <div class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Applicant instructions</label>
                                <textarea v-model="scheduleForm.instructions" rows="3" maxlength="3000" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600"></textarea>
                            </div>

                            <div class="mt-4 flex justify-end">
                                <button type="submit" :disabled="scheduleSaving" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                    {{ scheduleSaving ? 'Publishing...' : 'Publish to eligible applicants' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <details class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm shadow-sm">
                        <summary class="cursor-pointer font-bold text-slate-950">
                            DSS queue guide
                        </summary>
                        <p class="mt-2 leading-5 text-slate-600">
                            DSS helps rank applications, but the provider still makes the final decision.
                        </p>
                    </details>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ hasProgramContext ? 'Submitted applicants' : 'Submitted applications' }}
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <label class="relative w-full sm:max-w-sm">
                                <span class="sr-only">Search applicants</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="applicationSearch"
                                    type="search"
                                    placeholder="Search applicant or program"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <a
                                :href="exportApplicationsUrl"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export CSV
                            </a>
                        </div>

                        <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="filter in reviewFilterOptions"
                                        :key="filter.value"
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueFilter === filter.value
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueFilter = filter.value"
                                    >
                                        {{ filter.label }} ({{ filter.count }})
                                    </button>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'priority'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'priority'"
                                    >
                                        Priority
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'dss'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'dss'"
                                    >
                                        DSS
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'documents'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'documents'"
                                    >
                                        Documents
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applications to review yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Applicants for this program will appear here after eligible students submit the application wizard.'
                                    : 'Applications will appear after an approved scholarship is published and an eligible applicant submits the application wizard.' }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="/provider/programs" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">Check programs</a>
                                <a href="/provider/programs/create" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">Create scholarship</a>
                            </div>
                        </div>

                        <div v-else-if="rankedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications match this review filter.
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <div class="hidden grid-cols-[minmax(0,1.35fr)_minmax(0,1.1fr)_7rem_6rem_8rem_auto] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500 xl:grid">
                                <span>Applicant</span>
                                <span>Program</span>
                                <span>Scores</span>
                                <span>Files</span>
                                <span>Status</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article
                                v-for="application in visibleApplications"
                                :key="application.id"
                                class="grid gap-3 border-b border-slate-200 p-3 last:border-b-0 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1.1fr)_7rem_6rem_8rem_auto] xl:items-center xl:px-4"
                            >
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <h4 class="min-w-0 truncate text-sm font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <i
                                            v-if="application.applicant?.profile_verification_status === 'approved'"
                                            class="fa-solid fa-circle-check text-xs text-emerald-600"
                                            title="Verified applicant"
                                            aria-label="Verified applicant"
                                        ></i>
                                        <span :class="['rounded px-1.5 py-0.5 text-[9px] font-bold uppercase', reviewPriorityClass(application)]">
                                            {{ reviewPriorityLabel(application) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">Submitted {{ application.submitted_at || 'recently' }}</p>
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-800">{{ application.scholarship?.title || 'Scholarship' }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-slate-500">
                                        {{ application.status_progress?.label || statusLabel(application.status) }}
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 xl:contents">
                                    <div class="rounded-md bg-slate-50 px-2.5 py-2 text-xs ring-1 ring-slate-200 xl:bg-transparent xl:p-0 xl:ring-0">
                                        <p class="font-semibold text-slate-500 xl:hidden">DSS / match</p>
                                        <p class="mt-0.5 font-bold text-slate-950 xl:mt-0">{{ application.dss_score ?? 0 }}% / {{ application.eligibility_score ?? 0 }}%</p>
                                    </div>
                                    <div class="rounded-md bg-slate-50 px-2.5 py-2 text-xs ring-1 ring-slate-200 xl:bg-transparent xl:p-0 xl:ring-0">
                                        <p class="font-semibold text-slate-500 xl:hidden">Files</p>
                                        <p class="mt-0.5 font-bold text-slate-950 xl:mt-0">{{ application.document_readiness?.percent ?? 0 }}%</p>
                                        <p v-if="documentIssueCount(application)" class="mt-0.5 text-[10px] font-semibold text-amber-700">{{ documentIssueCount(application) }} issue{{ documentIssueCount(application) === 1 ? '' : 's' }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between gap-3 xl:contents">
                                    <span :class="['w-fit rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(application.status)]">
                                        {{ statusLabel(application.status) }}
                                    </span>
                                    <a
                                        :href="application.detail_url || `/provider/applications/${application.id}`"
                                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                    >
                                        View
                                        <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </article>

                            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs font-semibold text-slate-500">Showing {{ visibleApplicationRange }}</p>
                                <div v-if="totalApplicationPages > 1" class="flex gap-2">
                                    <button
                                        type="button"
                                        :disabled="applicationPage === 1"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                        @click="applicationPage -= 1"
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="applicationPage === totalApplicationPages"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                        @click="applicationPage += 1"
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
