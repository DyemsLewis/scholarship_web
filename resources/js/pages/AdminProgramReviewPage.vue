<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import ScholarshipBenefitsPanel from '../components/ScholarshipBenefitsPanel.vue';
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
const requestedSection = new URLSearchParams(window.location.search).get('section');
const reviewSections = [
    { key: 'overview', label: 'Review summary', icon: 'fa-solid fa-list-check' },
    { key: 'offer', label: 'Offer & eligibility', icon: 'fa-solid fa-gift' },
    { key: 'process', label: 'Process & requirements', icon: 'fa-solid fa-route' },
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'overview');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);

function selectReviewSection(section) {
    activeReviewSection.value = section;

    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState(window.history.state, '', url);
}

const applicationModeOptions = [
    { value: 'online', label: 'Portal review' },
    { value: 'onsite', label: 'Portal review with in-person verification' },
    { value: 'provider_review', label: 'Profile review only' },
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
const optionalDocumentItems = computed(() => splitItems(scholarship.value?.optional_requirements));
const postQualificationDocumentItems = computed(() => splitItems(scholarship.value?.post_qualification_requirements));
const selectionStages = computed(() => scholarship.value?.selection_stages?.length
    ? scholarship.value.selection_stages
    : ['screening']);
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
        { label: 'Benefits', value: current.benefit_summary || formatAmount(current.award_amount) },
        { label: 'Program cycle', value: current.program_cycle || 'Not specified' },
        { label: 'Deadline', value: current.deadline || 'Not specified' },
        { label: 'Available slots', value: current.slots_available ?? 'Not specified' },
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
    { label: 'Possible renewal requirement', value: scholarship.value?.renewal_policy },
    { label: 'Possible service commitment', value: scholarship.value?.return_service_contract },
    { label: 'Commitment preview', value: scholarship.value?.other_contract_terms },
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
            label: 'Program cycle',
            detail: hasText(current.program_cycle)
                ? `${current.program_cycle} is shown to applicants.`
                : 'Ask the provider to identify the school year or intake.',
            status: hasText(current.program_cycle) ? 'Provided' : 'Missing',
            tone: hasText(current.program_cycle) ? 'good' : 'warn',
            icon: 'fa-solid fa-rotate',
        },
        {
            label: 'Application deadline',
            detail: hasText(current.deadline)
                ? `${current.application_opens_at ? `Opens ${current.application_opens_at}; ` : ''}closes ${current.deadline}${current.expected_results_at ? `; initial results expected ${current.expected_results_at}` : ''}.`
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
            label: 'Formal application handoff',
            detail: postQualificationDocumentItems.value.length && hasText(current.handoff_instructions)
                ? `${postQualificationDocumentItems.value.length} document${postQualificationDocumentItems.value.length === 1 ? '' : 's'} to bring, with next-step instructions.`
                : 'The provider has not completed the next steps for qualified applicants.',
            status: postQualificationDocumentItems.value.length && hasText(current.handoff_instructions) ? 'Provided' : 'Missing',
            tone: postQualificationDocumentItems.value.length && hasText(current.handoff_instructions) ? 'good' : 'warn',
            icon: 'fa-solid fa-arrow-right-to-bracket',
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
const programReviewFocus = computed(() => {
    const status = scholarship.value?.status ?? 'pending_review';

    if (status === 'published') {
        return {
            eyebrow: 'Publication complete',
            title: 'This program is visible to applicants',
            description: 'The current program record has an approved publication decision.',
            icon: 'fa-solid fa-check',
            section: 'decision',
            action: 'View decision',
        };
    }

    if (status === 'rejected') {
        return {
            eyebrow: 'Provider action needed',
            title: 'Program corrections were requested',
            description: 'Review the decision note and program record while the provider prepares an update.',
            icon: 'fa-solid fa-rotate',
            section: 'decision',
            action: 'View decision',
        };
    }

    if (attentionCount.value) {
        return {
            eyebrow: 'Review needed',
            title: `${attentionCount.value} readiness item${attentionCount.value === 1 ? '' : 's'} need attention`,
            description: 'Open the review summary to identify missing or unclear information before deciding.',
            icon: 'fa-solid fa-triangle-exclamation',
            section: 'overview',
            action: 'Review checklist',
        };
    }

    return {
        eyebrow: 'Ready for decision',
        title: 'The publication checks are complete',
        description: 'Review the offer and process once more, then record the publication decision.',
        icon: 'fa-solid fa-arrow-right',
        section: 'decision',
        action: 'Record decision',
    };
});

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
    const normalizedValue = value === 'hybrid' ? 'onsite' : value;

    return applicationModeOptions.find((option) => option.value === normalizedValue)?.label ?? 'Not specified';
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
    <main class="admin-shell">
        <AdminSidebar active="reviews" />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Program review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Review scholarship program</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Confirm the offer, eligibility, and applicant process before publishing the program.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                href="/admin/reviews?type=programs"
                                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Back to reviews
                            </a>
                            <button
                                type="button"
                                class="w-fit rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                @click="loadScholarship"
                            >
                                Refresh
                            </button>
                            <button
                                v-if="activeReviewSection !== 'decision'"
                                type="button"
                                class="w-fit rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="selectReviewSection('decision')"
                            >
                                Record decision
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program review details...
                </div>

                <div v-else-if="loadError || !scholarship" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Program details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 space-y-4">
                    <section class="admin-panel overflow-hidden">
                        <div class="flex flex-col gap-4 border-l-4 border-l-amber-400 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="scholarship.title"
                                    class="h-12 w-12 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">{{ scholarship.category || 'Scholarship program' }}</p>
                                    <h3 class="mt-1 truncate text-lg font-bold text-slate-950">{{ scholarship.title }}</h3>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                                        <span>{{ scholarship.provider || 'Provider' }}</span>
                                        <span :class="['rounded-md px-2 py-0.5 text-[10px] font-bold uppercase', statusClass(scholarship.provider_verification_status)]">
                                            {{ isProviderVerified ? 'Provider verified' : 'Provider needs review' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <span :class="['w-fit shrink-0 rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(scholarship.status)]">
                                {{ statusLabel(scholarship.status) }}
                            </span>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50/80 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div
                                v-for="(fact, index) in summaryFacts"
                                :key="fact.label"
                                :class="[
                                    'p-3 lg:border-b-0',
                                    index < summaryFacts.length - 1 ? 'border-b border-slate-200' : '',
                                    index >= summaryFacts.length - 2 ? 'sm:border-b-0' : '',
                                    index % 2 === 0 ? 'sm:border-r sm:border-slate-200' : '',
                                    index < summaryFacts.length - 1 ? 'lg:border-r lg:border-slate-200' : '',
                                ]"
                            >
                                <dt class="text-xs font-semibold text-slate-500">{{ fact.label }}</dt>
                                <dd class="mt-1 line-clamp-2 break-words font-bold text-slate-950">{{ fact.value }}</dd>
                            </div>
                        </dl>

                        <div class="flex flex-col gap-4 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                    <i :class="programReviewFocus.icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ programReviewFocus.eyebrow }}</p>
                                    <p class="mt-1 text-sm font-bold text-slate-950">{{ programReviewFocus.title }}</p>
                                    <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">{{ programReviewFocus.description }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="w-fit shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"
                                @click="selectReviewSection(programReviewFocus.section)"
                            >
                                {{ programReviewFocus.action }}
                            </button>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <nav class="grid gap-1 p-1 sm:grid-cols-2 xl:grid-cols-4" aria-label="Program review sections">
                            <button
                                v-for="section in reviewSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeReviewSection === section.key ? 'step' : undefined"
                                :class="[
                                    'flex min-w-0 items-center gap-3 rounded-md px-3 py-2.5 text-left transition',
                                    activeReviewSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="selectReviewSection(section.key)"
                            >
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', activeReviewSection === section.key ? 'bg-white/10 text-amber-300' : 'bg-slate-100 text-slate-600']"><i :class="section.icon" aria-hidden="true"></i></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold">{{ section.label }}</span>
                                    <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                        <template v-if="section.key === 'overview'">{{ attentionCount ? `${attentionCount} checks need attention` : 'Ready to review' }}</template>
                                        <template v-else-if="section.key === 'offer'">{{ scholarship.benefits?.length || 0 }} benefits</template>
                                        <template v-else-if="section.key === 'process'">{{ documentItems.length }} required files</template>
                                        <template v-else>{{ statusLabel(scholarship.status) }}</template>
                                    </span>
                                </span>
                            </button>
                        </nav>
                    </section>

                    <div v-if="activeReviewSection !== 'decision'" class="space-y-4">
                        <article v-if="activeReviewSection === 'overview'" class="admin-panel p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Publication readiness</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Review checklist</h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">Check the applicant-facing record before choosing a review outcome.</p>
                                    </div>
                                </div>
                                <span
                                    :class="[
                                        'w-fit shrink-0 rounded-md px-2.5 py-1 text-xs font-bold',
                                        attentionCount ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800',
                                    ]"
                                >
                                    {{ attentionCount ? `${attentionCount} need attention` : 'Ready to review' }}
                                </span>
                            </div>

                            <section class="mt-4 border-l-4 border-slate-300 bg-slate-50 px-4 py-3">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Program description</p>
                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ scholarship.description || 'No program description provided.' }}</p>
                            </section>

                            <div class="mt-4 grid overflow-hidden rounded-md border border-slate-200 md:grid-cols-2">
                                <div
                                    v-for="(check, index) in readinessChecks"
                                    :key="check.label"
                                    :class="[
                                        'flex items-start gap-3 p-3',
                                        index < readinessChecks.length - 1 ? 'border-b border-slate-200' : '',
                                        index >= readinessChecks.length - (readinessChecks.length % 2 === 0 ? 2 : 1) ? 'md:border-b-0' : '',
                                        index % 2 === 0 ? 'md:border-r md:border-slate-200' : '',
                                        readinessChecks.length % 2 === 1 && index === readinessChecks.length - 1 ? 'md:col-span-2 md:border-r-0' : '',
                                    ]"
                                >
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', readinessIconClass(check.tone)]">
                                        <i :class="check.icon" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold text-slate-950">{{ check.label }}</p>
                                            <span :class="['shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase ring-1 ring-inset', readinessBadgeClass(check.tone)]">
                                                {{ check.status }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ check.detail }}</p>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <article v-if="['offer', 'process'].includes(activeReviewSection)" class="admin-panel overflow-hidden">
                            <div class="p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Program record</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">{{ activeReviewSection === 'offer' ? 'Offer and eligibility' : 'Requirements and selection process' }}</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ activeReviewSection === 'offer' ? 'Review what applicants receive and who can apply.' : 'Review what applicants prepare and what happens after submission.' }}</p>
                            </div>

                            <section v-if="activeReviewSection === 'offer' && scholarship.benefits?.length" class="border-t border-slate-200 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Benefit package</p>
                                <h4 class="mt-1 text-lg font-bold text-slate-950">Benefits for recipients</h4>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Confirm that the support is understandable and complete.</p>
                                <ScholarshipBenefitsPanel class="mt-4" :benefits="scholarship.benefits" />
                            </section>

                            <section v-if="activeReviewSection === 'offer'" class="border-t border-slate-200 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Eligibility</p>
                                <h4 class="mt-1 text-lg font-bold text-slate-950">Who can apply</h4>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Confirm that the rules match the provider's intended applicants.</p>

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
                            </section>

                            <section v-if="activeReviewSection === 'process'" class="border-t border-slate-200 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Application process</p>
                                <h4 class="mt-1 text-lg font-bold text-slate-950">Documents and selection</h4>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Check what applicants must prepare and what happens after submission.</p>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <section class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <h5 class="font-bold text-slate-950">Required documents</h5>
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
                                        <div v-if="optionalDocumentItems.length" class="mt-4 border-t border-slate-200 pt-3">
                                            <p class="text-xs font-bold uppercase tracking-wider text-amber-700">Optional supporting files</p>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span v-for="item in optionalDocumentItems" :key="item" class="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                                    {{ item }}
                                                </span>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h5 class="font-bold text-slate-950">Selection process</h5>
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

                                <section class="mt-4 rounded-md border border-amber-200 bg-amber-50/60 p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wider text-amber-800">After pre-screening</p>
                                            <h5 class="mt-1 font-bold text-slate-950">Formal application handoff</h5>
                                            <p class="mt-1 text-xs leading-5 text-slate-600">
                                                Confirm that qualified applicants receive clear next steps without uploading these final documents to the portal.
                                            </p>
                                        </div>
                                        <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-amber-200">
                                            {{ labelFromKey(scholarship.handoff_mode || 'not specified') }}
                                        </span>
                                    </div>

                                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Documents to bring</p>
                                            <ul v-if="postQualificationDocumentItems.length" class="mt-2 space-y-2">
                                                <li v-for="item in postQualificationDocumentItems" :key="item" class="flex items-start gap-2 text-sm text-slate-700">
                                                    <i class="fa-solid fa-file-circle-check mt-1 text-xs text-amber-700" aria-hidden="true"></i>
                                                    <span>{{ item }}</span>
                                                </li>
                                            </ul>
                                            <p v-else class="mt-2 text-sm text-rose-700">No formal application documents listed.</p>
                                        </div>
                                        <div class="space-y-2 text-sm text-slate-700">
                                            <p v-if="scholarship.handoff_deadline"><span class="font-bold">Deadline:</span> {{ scholarship.handoff_deadline }}</p>
                                            <p v-if="scholarship.handoff_location_name" class="font-bold text-slate-950">{{ scholarship.handoff_location_name }}</p>
                                            <p v-if="scholarship.handoff_location_address">{{ scholarship.handoff_location_address }}</p>
                                            <a v-if="scholarship.handoff_url" :href="scholarship.handoff_url" target="_blank" rel="noopener" class="inline-flex font-bold text-sky-700 underline underline-offset-2">
                                                Check continuation link
                                            </a>
                                            <p class="whitespace-pre-line leading-6">{{ scholarship.handoff_instructions || 'No handoff instructions provided.' }}</p>
                                        </div>
                                    </div>
                                </section>

                                <dl class="mt-4 grid overflow-hidden rounded-md border border-slate-200 md:grid-cols-2 md:divide-x md:divide-slate-200">
                                    <div v-if="scholarship.contact_department || scholarship.contact_person" class="border-b border-slate-200 p-4 md:col-span-2">
                                        <dt class="text-xs font-semibold text-slate-500">Responsible contact</dt>
                                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">
                                            {{ scholarship.contact_department || scholarship.contact_person }}
                                            <span v-if="scholarship.contact_department && scholarship.contact_person" class="font-normal text-slate-500"> | {{ scholarship.contact_person }}</span>
                                        </dd>
                                    </div>
                                    <div class="p-4">
                                        <dt class="text-xs font-semibold text-slate-500">Applicant contact email</dt>
                                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ scholarship.contact_email || 'Not provided' }}</dd>
                                    </div>
                                    <div class="p-4">
                                        <dt class="text-xs font-semibold text-slate-500">Applicant contact number</dt>
                                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ scholarship.contact_number || 'Not provided' }}</dd>
                                    </div>
                                    <div v-if="scholarship.official_program_url" class="border-t border-slate-200 p-4 md:col-span-2">
                                        <dt class="text-xs font-semibold text-slate-500">Official program page</dt>
                                        <dd class="mt-1 break-all text-sm font-bold">
                                            <a :href="scholarship.official_program_url" target="_blank" rel="noopener noreferrer" class="text-sky-700 underline underline-offset-2">
                                                {{ scholarship.official_program_url }}
                                            </a>
                                        </dd>
                                    </div>
                                </dl>

                                <section v-if="scholarship.review_rubric?.length" class="mt-5 border-t border-slate-200 pt-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h5 class="font-bold text-slate-950">Provider scoring rubric</h5>
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
                            </section>

                            <section v-if="activeReviewSection === 'process' && hasTermsOrLocation" class="border-t border-slate-200 p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Conditions and coverage</p>
                                <h4 class="mt-1 text-lg font-bold text-slate-950">What recipients should know</h4>

                                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                                    <section v-if="contractSections.length">
                                        <h5 class="text-sm font-bold text-slate-950">Possible recipient commitments</h5>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">These are previews for applicants. Final terms should be explained after acceptance.</p>
                                        <div class="mt-2 divide-y divide-slate-200 rounded-md border border-slate-200">
                                            <div v-for="term in contractSections" :key="term.label" class="p-3">
                                                <p class="text-xs font-semibold text-slate-500">{{ term.label }}</p>
                                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ term.value }}</p>
                                            </div>
                                        </div>
                                    </section>

                                    <section v-if="hasLocationDetails">
                                        <h5 class="text-sm font-bold text-slate-950">Program location</h5>
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
                            </section>
                        </article>
                    </div>

                    <aside v-if="['overview', 'decision'].includes(activeReviewSection)" class="space-y-4">
                        <section v-if="activeReviewSection === 'decision'" class="admin-panel w-full p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Admin decision</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Publication decision</h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">Publish the program, return it for correction, or keep it in review.</p>
                                    </div>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(scholarship.status)]">
                                    {{ statusLabel(scholarship.status) }}
                                </span>
                            </div>

                            <div v-if="attentionCount" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                <span class="font-bold">{{ attentionCount }} item{{ attentionCount === 1 ? '' : 's' }} need attention.</span>
                                Confirm them before publishing or explain the required correction in your note.
                            </div>

                            <div class="mt-4 grid gap-2 md:grid-cols-3">
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

                        <section v-if="activeReviewSection === 'overview'" class="admin-panel w-full overflow-hidden">
                            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-building-shield" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Submitting provider</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ scholarship.provider || 'Provider' }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">Confirm the organization behind this program.</p>
                                    </div>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(scholarship.provider_verification_status)]">
                                    {{ isProviderVerified ? 'Verified' : statusLabel(scholarship.provider_verification_status) }}
                                </span>
                            </div>

                            <dl class="grid border-t border-slate-200 bg-slate-50/80 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div class="border-b border-slate-200 p-4 sm:border-r lg:border-b-0">
                                    <dt class="text-xs font-semibold text-slate-500">Type</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(scholarship.provider_type || 'provider') }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 lg:border-b-0 lg:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Email</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ scholarship.provider_email || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Website</dt>
                                    <dd class="mt-1 break-words font-bold">
                                        <a
                                            v-if="scholarship.provider_website"
                                            :href="providerWebsiteUrl(scholarship.provider_website)"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-sky-700 underline underline-offset-2"
                                        >
                                            {{ scholarship.provider_website }}
                                        </a>
                                        <span v-else class="text-slate-950">Not provided</span>
                                    </dd>
                                </div>
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Address</dt>
                                    <dd class="mt-1 font-bold leading-6 text-slate-950">{{ scholarship.provider_address || 'Not provided' }}</dd>
                                </div>
                            </dl>

                            <div v-if="scholarship.provider_id" class="border-t border-slate-200 p-4">
                                <a
                                    :href="`/admin/providers/${scholarship.provider_id}/review`"
                                    class="inline-flex w-fit items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                >
                                    Open provider record
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </section>
                    </aside>

                    <nav class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm" aria-label="Program review navigation">
                        <button
                            type="button"
                            :disabled="!previousReviewSection"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:invisible"
                            @click="previousReviewSection && selectReviewSection(previousReviewSection.key)"
                        >
                            Previous
                        </button>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Step {{ activeReviewSectionIndex + 1 }} of {{ reviewSections.length }}</p>
                        <button
                            v-if="nextReviewSection"
                            type="button"
                            class="rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                            @click="selectReviewSection(nextReviewSection.key)"
                        >
                            Next: {{ nextReviewSection.label }}
                        </button>
                        <a v-else href="/admin/reviews?type=programs" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Back to queue</a>
                    </nav>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
