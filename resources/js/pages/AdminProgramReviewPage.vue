<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import { labelFromKey } from '../support/display';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const scholarship = ref(null);
const reviewStatus = ref('pending_review');
const reviewNotes = ref('');

const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const reviewStatusOptions = [
    {
        value: 'pending_review',
        label: 'Keep in review',
        help: 'Wait for clarification or corrections.',
        className: 'border-amber-300 bg-amber-50 text-amber-950',
    },
    {
        value: 'published',
        label: 'Publish program',
        help: 'Make this program visible to applicants.',
        className: 'border-emerald-300 bg-emerald-50 text-emerald-950',
    },
    {
        value: 'rejected',
        label: 'Reject program',
        help: 'Return it with a correction reason.',
        className: 'border-rose-300 bg-rose-50 text-rose-950',
    },
];

const documentItems = computed(() => splitItems(scholarship.value?.requirements));
const selectionStages = computed(() => scholarship.value?.selection_stages?.length
    ? scholarship.value.selection_stages
    : ['screening', 'distribution']);
const programEvents = computed(() => scholarship.value?.program_events ?? []);
const rubricTotal = computed(() => (scholarship.value?.review_rubric ?? [])
    .reduce((total, criterion) => total + Number(criterion.weight || 0), 0));
const targetGroups = computed(() => [
    {
        label: 'Education levels',
        items: optionItems(scholarship.value?.eligible_education_levels),
        empty: 'Any education level',
    },
    {
        label: 'School types',
        items: optionItems(scholarship.value?.eligible_school_types),
        empty: 'Any school type',
    },
    {
        label: 'Track, strand, course, or program',
        items: optionItems(scholarship.value?.eligible_courses),
        empty: 'Any track, strand, course, or program',
    },
    {
        label: 'Grade or year levels',
        items: optionItems(scholarship.value?.eligible_year_levels),
        empty: 'Any grade or year level',
    },
]);
const configuredTargetCount = computed(() => targetGroups.value.filter((group) => group.items.length > 0).length);
const summaryFacts = computed(() => {
    const current = scholarship.value ?? {};

    return [
        { label: 'Award', value: formatAmount(current.award_amount) },
        { label: 'Deadline', value: current.deadline || 'Not specified' },
        { label: 'Available slots', value: current.slots_available ?? 'Not specified' },
        { label: 'Application', value: applicationModeLabel(current.application_mode) },
    ];
});
const eligibilityRules = computed(() => {
    const current = scholarship.value ?? {};

    return [
        { label: 'Academic requirement', value: academicRequirementLabel(current) },
        { label: 'Income requirement', value: current.income_requirement || 'No income restriction' },
        { label: 'Location eligibility', value: current.eligible_locations || 'No location restriction' },
    ];
});
const workflowSteps = computed(() => selectionStages.value.map((stage, index) => ({
    key: stage,
    label: labelFromKey(stage),
    number: index + 1,
    event: programEvents.value.find((event) => event.type === stage) ?? null,
})));
const contractSections = computed(() => [
    { label: 'Renewal / continuation', value: scholarship.value?.renewal_policy },
    { label: 'Return service obligation', value: scholarship.value?.return_service_contract },
    { label: 'Other program terms', value: scholarship.value?.other_contract_terms },
].filter((section) => hasText(section.value)));
const hasLocationDetails = computed(() => Boolean(
    scholarship.value?.location_name
    || scholarship.value?.location_address
    || scholarship.value?.eligible_locations
    || scholarship.value?.map_url,
));
const hasTermsOrLocation = computed(() => contractSections.value.length > 0 || hasLocationDetails.value);
const isProviderVerified = computed(() => scholarship.value?.provider_verification_status === 'approved');
const readinessChecks = computed(() => {
    const current = scholarship.value ?? {};
    const checks = [
        {
            label: 'Provider verification',
            detail: isProviderVerified.value
                ? 'The organization is approved to publish programs.'
                : 'Verify the provider before publishing this program.',
            status: isProviderVerified.value ? 'Verified' : 'Needs review',
            tone: isProviderVerified.value ? 'good' : 'warn',
            icon: 'fa-solid fa-building-shield',
        },
        {
            label: 'Program description',
            detail: hasText(current.description)
                ? 'Applicants have a clear program summary.'
                : 'Ask the provider to add a clear description.',
            status: hasText(current.description) ? 'Provided' : 'Missing',
            tone: hasText(current.description) ? 'good' : 'warn',
            icon: 'fa-solid fa-align-left',
        },
        {
            label: 'Application deadline',
            detail: hasText(current.deadline)
                ? `Applications close ${current.deadline}.`
                : 'Confirm whether this program has an application deadline.',
            status: hasText(current.deadline) ? 'Provided' : 'Review',
            tone: hasText(current.deadline) ? 'good' : 'warn',
            icon: 'fa-regular fa-calendar',
        },
        {
            label: 'Required documents',
            detail: documentItems.value.length
                ? `${documentItems.value.length} applicant requirement${documentItems.value.length === 1 ? '' : 's'} listed.`
                : 'No applicant document requirements are listed.',
            status: documentItems.value.length ? 'Provided' : 'Missing',
            tone: documentItems.value.length ? 'good' : 'warn',
            icon: 'fa-regular fa-file-lines',
        },
        {
            label: 'Applicant targeting',
            detail: configuredTargetCount.value
                ? `${configuredTargetCount.value} target group${configuredTargetCount.value === 1 ? '' : 's'} configured.`
                : 'The program is open across all learner groups.',
            status: configuredTargetCount.value ? 'Defined' : 'Open to all',
            tone: 'neutral',
            icon: 'fa-solid fa-user-group',
        },
        {
            label: 'Applicant contact',
            detail: hasText(current.contact_email) || hasText(current.contact_number)
                ? 'Applicants have a way to contact the provider.'
                : 'Add a contact email or number for applicant questions.',
            status: hasText(current.contact_email) || hasText(current.contact_number) ? 'Provided' : 'Missing',
            tone: hasText(current.contact_email) || hasText(current.contact_number) ? 'good' : 'warn',
            icon: 'fa-regular fa-envelope',
        },
    ];

    if (current.review_rubric?.length) {
        checks.push({
            label: 'Review rubric',
            detail: rubricTotal.value === 100
                ? 'Provider scoring criteria total 100%.'
                : `Provider scoring criteria total ${rubricTotal.value}%.`,
            status: rubricTotal.value === 100 ? 'Balanced' : 'Review',
            tone: rubricTotal.value === 100 ? 'good' : 'warn',
            icon: 'fa-solid fa-list-check',
        });
    }

    if (selectionStages.value.includes('exam')) {
        const examDetailsComplete = hasText(current.exam_duration_minutes)
            && hasText(current.exam_passing_score);

        checks.push({
            label: 'Provider-managed exam',
            detail: examDetailsComplete
                ? `${current.exam_duration_minutes} minutes with a ${Number(current.exam_passing_score)}% passing score.`
                : 'Confirm the exam duration and passing score before publishing.',
            status: examDetailsComplete ? 'Configured' : 'Needs review',
            tone: examDetailsComplete ? 'good' : 'warn',
            icon: 'fa-solid fa-clipboard-question',
        });
    }

    return checks;
});
const attentionCount = computed(() => readinessChecks.value.filter((check) => check.tone === 'warn').length);

function hasText(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function splitItems(value) {
    if (!hasText(value)) {
        return [];
    }

    return String(value)
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function optionItems(value) {
    return splitItems(value).map(labelFromKey);
}

function formatAmount(amount) {
    if (!hasText(amount)) {
        return 'Not specified';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? 'Not specified';
}

function inferGradeScale(value) {
    if (!hasText(value)) {
        return '';
    }

    return Number(value) <= 5 ? 'grade_point' : 'percentage';
}

function academicRequirementLabel(current) {
    if (current?.minimum_grade_label) {
        return current.minimum_grade_label;
    }

    if (!hasText(current?.minimum_gwa)) {
        return 'No academic minimum';
    }

    return inferGradeScale(current.minimum_gwa) === 'grade_point'
        ? `Maximum GWA/GPA ${current.minimum_gwa}`
        : `Minimum average ${current.minimum_gwa}%`;
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'published' || status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected' || status === 'cancelled') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function readinessIconClass(tone) {
    if (tone === 'good') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (tone === 'warn') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-600';
}

function readinessBadgeClass(tone) {
    if (tone === 'good') {
        return 'bg-emerald-50 text-emerald-800 ring-emerald-200';
    }

    if (tone === 'warn') {
        return 'bg-amber-50 text-amber-900 ring-amber-200';
    }

    return 'bg-slate-50 text-slate-700 ring-slate-200';
}

function providerWebsiteUrl(website) {
    const value = String(website ?? '').trim();

    if (!value) {
        return null;
    }

    return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function eventLocation(event) {
    return event?.venue || event?.location_address || (event?.online_url ? 'Online' : 'Location not provided');
}

function applyScholarship(payload) {
    scholarship.value = payload;
    reviewStatus.value = payload?.status ?? 'pending_review';
    reviewNotes.value = '';
    decisionError.value = '';
}

async function loadScholarship() {
    isLoading.value = true;
    loadError.value = '';
    decisionError.value = '';

    try {
        const response = await window.axios.get(`/admin/scholarships/${scholarshipId}/review/data`);
        applyScholarship(response.data.scholarship);
    } catch (error) {
        loadError.value = error.response?.data?.message ?? 'Unable to load program review details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateReview() {
    if (!scholarship.value) {
        return;
    }

    const reviewNote = reviewNotes.value.trim();

    if (reviewStatus.value === 'rejected' && !reviewNote) {
        decisionError.value = 'Add a rejection reason before rejecting this program.';
        return;
    }

    if (reviewStatus.value === scholarship.value.status && !reviewNote) {
        decisionError.value = 'Choose a different outcome or add a review note before saving.';
        return;
    }

    isSaving.value = true;
    decisionError.value = '';

    try {
        const response = await window.axios.patch(`/admin/scholarships/${scholarship.value.id}/review`, {
            status: reviewStatus.value,
            review_notes: reviewNote,
        });

        applyScholarship(response.data.scholarship);
    } catch (error) {
        decisionError.value = error.response?.data?.message ?? 'Unable to save the program review.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadScholarship);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <a href="/admin/reviews" class="inline-flex text-sm font-bold text-amber-700 underline underline-offset-4">
                                Back to review queue
                            </a>
                            <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">Program review details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Confirm that applicants will see complete, accurate, and understandable scholarship information.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="w-fit rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadScholarship"
                        >
                            Refresh details
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program review details...
                </div>

                <div v-else-if="loadError || !scholarship" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Program details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div class="space-y-5">
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                                <img
                                    :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="scholarship.title"
                                    class="h-20 w-20 shrink-0 rounded-md bg-slate-50 object-contain p-2 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
                                                {{ scholarship.category || 'Scholarship program' }}
                                            </p>
                                            <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ scholarship.title }}</h1>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ scholarship.provider || 'Provider' }} - Updated {{ scholarship.updated_at || 'recently' }}
                                            </p>
                                        </div>
                                        <span :class="['w-fit shrink-0 rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(scholarship.status)]">
                                            {{ statusLabel(scholarship.status) }}
                                        </span>
                                    </div>
                                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">
                                        {{ scholarship.description || 'No program description provided.' }}
                                    </p>
                                </div>
                            </div>

                            <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-slate-200">
                                <div v-for="fact in summaryFacts" :key="fact.label" class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">{{ fact.label }}</dt>
                                    <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ fact.value }}</dd>
                                </div>
                            </dl>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Publication check</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">What the admin should confirm</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    Review these items before making the program visible to applicants.
                                </p>
                            </div>

                            <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <div
                                    v-for="check in readinessChecks"
                                    :key="check.label"
                                    class="flex items-start gap-3 p-3 sm:items-center"
                                >
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', readinessIconClass(check.tone)]">
                                        <i :class="check.icon" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-bold text-slate-950">{{ check.label }}</p>
                                        <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ check.detail }}</p>
                                    </div>
                                    <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase ring-1 ring-inset', readinessBadgeClass(check.tone)]">
                                        {{ check.status }}
                                    </span>
                                </div>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Eligibility</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-950">Who can apply</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Confirm that the rules match the provider's intended applicants.
                            </p>

                            <dl class="mt-4 grid overflow-hidden rounded-md border border-slate-200 bg-slate-50 md:grid-cols-3 md:divide-x md:divide-slate-200">
                                <div v-for="rule in eligibilityRules" :key="rule.label" class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">{{ rule.label }}</dt>
                                    <dd class="mt-1 break-words text-sm font-bold leading-6 text-slate-950">{{ rule.value }}</dd>
                                </div>
                            </dl>

                            <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <div
                                    v-for="group in targetGroups"
                                    :key="group.label"
                                    class="grid gap-2 p-3 sm:grid-cols-[15rem_minmax(0,1fr)] sm:items-start"
                                >
                                    <p class="text-sm font-bold text-slate-700">{{ group.label }}</p>
                                    <div v-if="group.items.length" class="flex flex-wrap gap-2">
                                        <span
                                            v-for="item in group.items"
                                            :key="item"
                                            class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700"
                                        >
                                            {{ item }}
                                        </span>
                                    </div>
                                    <p v-else class="text-sm text-slate-500">{{ group.empty }}</p>
                                </div>
                            </div>

                            <div class="mt-4 border-l-4 border-amber-300 bg-amber-50 px-4 py-3">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-amber-800">Provider eligibility notes</p>
                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">
                                    {{ scholarship.eligibility || 'No additional eligibility notes provided.' }}
                                </p>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Application process</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-950">Documents and selection</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                Check what applicants must prepare and what happens after submission.
                            </p>

                            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                <section class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <h4 class="font-bold text-slate-950">Required documents</h4>
                                        <span class="rounded-md bg-white px-2 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                            {{ documentItems.length }} item{{ documentItems.length === 1 ? '' : 's' }}
                                        </span>
                                    </div>
                                    <ul v-if="documentItems.length" class="mt-3 space-y-2">
                                        <li v-for="item in documentItems" :key="item" class="flex items-start gap-2 text-sm leading-6 text-slate-700">
                                            <i class="fa-solid fa-check mt-1.5 text-xs text-emerald-700" aria-hidden="true"></i>
                                            <span>{{ item }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-3 text-sm leading-6 text-slate-500">No document requirements listed.</p>
                                </section>

                                <section class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h4 class="font-bold text-slate-950">Selection process</h4>
                                        <span class="rounded-md bg-white px-2 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                            {{ applicationModeLabel(scholarship.application_mode) }}
                                        </span>
                                    </div>

                                    <div class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200 bg-white">
                                        <div v-for="step in workflowSteps" :key="step.key" class="flex items-start gap-3 p-3">
                                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-bold text-white">
                                                {{ step.number }}
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-bold text-slate-950">{{ step.label }}</p>
                                                <template v-if="step.event">
                                                    <p class="mt-1 text-xs font-bold text-slate-700">{{ step.event.title }}</p>
                                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                                        <p class="text-xs font-semibold text-amber-800">
                                                            {{ step.event.scheduled_label || 'Schedule provided' }} - {{ statusLabel(step.event.mode) }}
                                                        </p>
                                                        <span :class="['rounded-md px-2 py-0.5 text-[10px] font-bold uppercase', statusClass(step.event.status)]">
                                                            {{ statusLabel(step.event.status) }}
                                                        </span>
                                                    </div>
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ eventLocation(step.event) }}</p>
                                                    <p v-if="step.event.instructions" class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">
                                                        {{ step.event.instructions }}
                                                    </p>
                                                    <a
                                                        v-if="step.event.online_url"
                                                        :href="step.event.online_url"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="mt-2 inline-flex text-xs font-bold text-sky-700 underline underline-offset-2"
                                                    >
                                                        Check online link
                                                    </a>
                                                </template>
                                                <p v-else class="mt-1 text-xs leading-5 text-slate-500">
                                                    {{ ['exam', 'interview', 'distribution'].includes(step.key) ? 'No general schedule posted yet.' : 'Handled during provider review.' }}
                                                </p>
                                                <div
                                                    v-if="step.key === 'exam'"
                                                    class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-bold text-slate-600"
                                                >
                                                    <span v-if="scholarship.exam_duration_minutes" class="rounded-md bg-slate-50 px-2 py-1 ring-1 ring-slate-200">
                                                        {{ scholarship.exam_duration_minutes }} minutes
                                                    </span>
                                                    <span v-if="scholarship.exam_passing_score !== null" class="rounded-md bg-slate-50 px-2 py-1 ring-1 ring-slate-200">
                                                        {{ Number(scholarship.exam_passing_score) }}% passing
                                                    </span>
                                                    <span class="rounded-md bg-slate-50 px-2 py-1 ring-1 ring-slate-200">Handled by provider</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <dl class="mt-4 grid overflow-hidden rounded-md border border-slate-200 md:grid-cols-2 md:divide-x md:divide-slate-200">
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Applicant contact email</dt>
                                    <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ scholarship.contact_email || 'Not provided' }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Applicant contact number</dt>
                                    <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ scholarship.contact_number || 'Not provided' }}</dd>
                                </div>
                            </dl>

                            <section v-if="scholarship.review_rubric?.length" class="mt-5 border-t border-slate-200 pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h4 class="font-bold text-slate-950">Provider scoring rubric</h4>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Criteria used after applicants submit.</p>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', rubricTotal === 100 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">
                                        {{ rubricTotal }}% total
                                    </span>
                                </div>
                                <div class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                    <div
                                        v-for="criterion in scholarship.review_rubric"
                                        :key="criterion.key || criterion.label"
                                        class="flex items-start justify-between gap-4 p-3"
                                    >
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">{{ criterion.label }}</p>
                                            <p v-if="criterion.guidance" class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-500">
                                                {{ criterion.guidance }}
                                            </p>
                                        </div>
                                        <span class="shrink-0 text-sm font-bold text-slate-700">{{ criterion.weight || 0 }}%</span>
                                    </div>
                                </div>
                            </section>
                        </article>

                        <article v-if="hasTermsOrLocation" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Conditions and coverage</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-950">What recipients should know</h3>

                            <div class="mt-4 grid gap-5 lg:grid-cols-2">
                                <section v-if="contractSections.length">
                                    <h4 class="text-sm font-bold text-slate-950">Program obligations</h4>
                                    <div class="mt-2 divide-y divide-slate-200 rounded-md border border-slate-200">
                                        <div v-for="term in contractSections" :key="term.label" class="p-3">
                                            <p class="text-xs font-semibold text-slate-500">{{ term.label }}</p>
                                            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ term.value }}</p>
                                        </div>
                                    </div>
                                </section>

                                <section v-if="hasLocationDetails">
                                    <h4 class="text-sm font-bold text-slate-950">Program location</h4>
                                    <div class="mt-2 rounded-md border border-slate-200 p-3">
                                        <p class="font-bold text-slate-950">{{ scholarship.location_name || 'Location coverage' }}</p>
                                        <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">
                                            {{ scholarship.location_address || scholarship.eligible_locations || 'No address provided.' }}
                                        </p>
                                        <a
                                            v-if="scholarship.map_url"
                                            :href="scholarship.map_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="mt-3 inline-flex text-sm font-bold text-sky-700 underline underline-offset-2"
                                        >
                                            Open map
                                        </a>
                                    </div>
                                </section>
                            </div>
                        </article>
                    </div>

                    <aside class="space-y-4 xl:sticky xl:top-8 xl:self-start">
                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Admin decision</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Review outcome</h3>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(scholarship.status)]">
                                    {{ statusLabel(scholarship.status) }}
                                </span>
                            </div>

                            <div v-if="attentionCount" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                <span class="font-bold">{{ attentionCount }} item{{ attentionCount === 1 ? '' : 's' }} need attention.</span>
                                Confirm them before publishing or explain the required correction in your note.
                            </div>

                            <div class="mt-4 grid gap-2">
                                <button
                                    v-for="option in reviewStatusOptions"
                                    :key="option.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border p-3 text-left transition',
                                        reviewStatus === option.value
                                            ? `${option.className} ring-2 ring-slate-900/10`
                                            : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white',
                                    ]"
                                    @click="reviewStatus = option.value; decisionError = ''"
                                >
                                    <span class="block text-sm font-bold">{{ option.label }}</span>
                                    <span class="mt-1 block text-xs leading-5">{{ option.help }}</span>
                                </button>
                            </div>

                            <label class="mt-4 block text-xs font-bold text-slate-700">
                                Review note <span v-if="reviewStatus === 'rejected'" class="text-rose-600">(required)</span>
                            </label>
                            <textarea
                                v-model="reviewNotes"
                                rows="5"
                                maxlength="1500"
                                placeholder="Explain corrections, missing details, or approval context."
                                class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                @input="decisionError = ''"
                            ></textarea>

                            <p v-if="decisionError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-semibold leading-5 text-rose-700">
                                {{ decisionError }}
                            </p>

                            <button
                                type="button"
                                :disabled="isSaving"
                                class="mt-4 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                @click="updateReview"
                            >
                                {{ isSaving ? 'Saving decision...' : 'Save review decision' }}
                            </button>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Provider</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ scholarship.provider || 'Provider' }}</h3>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(scholarship.provider_verification_status)]">
                                    {{ isProviderVerified ? 'Verified' : statusLabel(scholarship.provider_verification_status) }}
                                </span>
                            </div>

                            <dl class="mt-4 space-y-3 text-sm">
                                <div>
                                    <dt class="text-xs font-semibold text-slate-500">Type</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(scholarship.provider_type || 'provider') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-slate-500">Email</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ scholarship.provider_email || 'Not provided' }}</dd>
                                </div>
                                <div v-if="scholarship.provider_website">
                                    <dt class="text-xs font-semibold text-slate-500">Website</dt>
                                    <dd class="mt-1 break-words font-bold">
                                        <a
                                            :href="providerWebsiteUrl(scholarship.provider_website)"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-sky-700 underline underline-offset-2"
                                        >
                                            {{ scholarship.provider_website }}
                                        </a>
                                    </dd>
                                </div>
                                <div v-if="scholarship.provider_address">
                                    <dt class="text-xs font-semibold text-slate-500">Address</dt>
                                    <dd class="mt-1 font-bold leading-6 text-slate-950">{{ scholarship.provider_address }}</dd>
                                </div>
                            </dl>

                            <a
                                v-if="scholarship.provider_id"
                                :href="`/admin/providers/${scholarship.provider_id}/review`"
                                class="mt-4 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                Review provider
                            </a>
                        </section>
                    </aside>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
