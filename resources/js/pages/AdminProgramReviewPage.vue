<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
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
        help: 'Leave this program in the admin queue while details are clarified.',
        className: 'border-amber-200 bg-amber-50 text-amber-900',
    },
    {
        value: 'published',
        label: 'Publish program',
        help: 'Approve the scholarship and make it visible to eligible applicants.',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    },
    {
        value: 'rejected',
        label: 'Reject program',
        help: 'Return it to the provider with a correction note.',
        className: 'border-rose-200 bg-rose-50 text-rose-900',
    },
];

const documentItems = computed(() => splitItems(scholarship.value?.requirements));
const hasContractDetails = computed(() => Boolean(
    scholarship.value?.renewal_policy
    || scholarship.value?.return_service_contract
    || scholarship.value?.other_contract_terms,
));
const hasLocationDetails = computed(() => Boolean(
    scholarship.value?.location_name
    || scholarship.value?.location_address
    || scholarship.value?.eligible_locations
    || scholarship.value?.map_url,
));
const reviewFacts = computed(() => {
    const current = scholarship.value;

    if (!current) {
        return [];
    }

    return [
        {
            label: 'Award',
            value: formatAmount(current.award_amount),
            detail: current.slots_available ? `${current.slots_available} slot${Number(current.slots_available) === 1 ? '' : 's'} listed` : 'Slots not listed',
        },
        {
            label: 'Deadline',
            value: current.deadline || 'No deadline',
            detail: current.application_mode ? applicationModeLabel(current.application_mode) : 'Application mode not set',
        },
        {
            label: 'Academic',
            value: academicRequirementLabel(current),
            detail: current.minimum_grade_scale ? labelFromKey(current.minimum_grade_scale) : 'Grade rule check',
        },
        {
            label: 'Documents',
            value: documentItems.value.length ? `${documentItems.value.length} listed` : 'No list yet',
            detail: documentItems.value.length ? 'Review against application requirements' : 'Ask provider to add document guidance',
        },
    ];
});
const eligibilityFields = computed(() => [
    { label: 'Education level', value: listLabel(scholarship.value?.eligible_education_levels) },
    { label: 'School type', value: listLabel(scholarship.value?.eligible_school_types) },
    { label: 'Track, strand, course, or program', value: scholarship.value?.eligible_courses || 'Any' },
    { label: 'Grade or year level', value: scholarship.value?.eligible_year_levels || 'Any' },
    { label: 'Income rule', value: scholarship.value?.income_requirement || 'Any' },
    { label: 'Location coverage', value: scholarship.value?.eligible_locations || scholarship.value?.location_name || 'Any' },
]);
const workflowFields = computed(() => [
    { label: 'Application mode', value: applicationModeLabel(scholarship.value?.application_mode) },
    { label: 'Available slots', value: scholarship.value?.slots_available ?? 'Not listed' },
    { label: 'Contact email', value: scholarship.value?.contact_email || 'Not listed' },
    { label: 'Contact number', value: scholarship.value?.contact_number || 'Not listed' },
]);
const providerFields = computed(() => [
    { label: 'Provider', value: scholarship.value?.provider || 'Provider' },
    { label: 'Email', value: scholarship.value?.provider_email || 'Not listed' },
    { label: 'Type', value: labelFromKey(scholarship.value?.provider_type || 'provider') },
    { label: 'Verification', value: statusLabel(scholarship.value?.provider_verification_status || 'pending') },
]);
const contractSections = computed(() => [
    { label: 'Renewal / continuation', value: scholarship.value?.renewal_policy },
    { label: 'Return service contract', value: scholarship.value?.return_service_contract },
    { label: 'Other contract terms', value: scholarship.value?.other_contract_terms },
].filter((section) => hasText(section.value)));

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

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Amount not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function listLabel(value) {
    const items = splitItems(value).map(labelFromKey);

    return items.length ? items.join(', ') : 'Any';
}

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? labelFromKey(value || 'not_listed');
}

function inferGradeScale(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return Number(value) <= 5 ? 'grade_point' : 'percentage';
}

function academicRequirementLabel(current) {
    if (current?.minimum_grade_label) {
        return current.minimum_grade_label;
    }

    if (!current?.minimum_gwa) {
        return 'No academic minimum';
    }

    return inferGradeScale(current.minimum_gwa) === 'grade_point'
        ? `Max GWA/GPA ${current.minimum_gwa}`
        : `Min average ${current.minimum_gwa}%`;
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'published'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'pending_review', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function applyScholarship(payload) {
    scholarship.value = payload;
    reviewStatus.value = payload?.status ?? 'pending_review';
    reviewNotes.value = '';
}

async function loadScholarship() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/admin/scholarships/${scholarshipId}/review/data`);

        applyScholarship(response.data.scholarship);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load program review details.';
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
        errorMessage.value = 'Add a rejection reason before rejecting this program.';
        return;
    }

    if (reviewStatus.value === scholarship.value.status && !reviewNote) {
        errorMessage.value = 'Choose a different outcome or add a review note before saving.';
        return;
    }

    isSaving.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/scholarships/${scholarship.value.id}/review`, {
            status: reviewStatus.value,
            review_notes: reviewNote,
        });

        applyScholarship(response.data.scholarship);
        statusMessage.value = response.data.message ?? `Program marked as ${statusLabel(reviewStatus.value)}.`;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update program review.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadScholarship);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <a href="/admin/reviews" class="inline-flex text-sm font-bold text-amber-700 underline">
                                Back to review queue
                            </a>
                            <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                                Program review details
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review the full scholarship setup before publishing, rejecting, or keeping it in the admin queue.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="w-fit rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadScholarship"
                        >
                            Refresh Details
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program review details...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </p>

                    <div v-if="scholarship" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                        <section class="space-y-6">
                            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                                    <img
                                        :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="scholarship.title"
                                        class="h-28 w-28 shrink-0 rounded-lg bg-slate-50 object-contain p-3 ring-1 ring-slate-200"
                                    >

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
                                                    {{ scholarship.category || 'Scholarship program' }}
                                                </p>
                                                <h1 class="mt-2 text-2xl font-bold text-slate-950">
                                                    {{ scholarship.title }}
                                                </h1>
                                                <p class="mt-2 text-sm text-slate-500">
                                                    {{ scholarship.provider || 'Provider' }} - Updated {{ scholarship.updated_at || 'recently' }}
                                                </p>
                                            </div>

                                            <span :class="['w-fit shrink-0 rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(scholarship.status)]">
                                                {{ statusLabel(scholarship.status) }}
                                            </span>
                                        </div>

                                        <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-700">
                                            {{ scholarship.description || 'No description provided.' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <div
                                        v-for="fact in reviewFacts"
                                        :key="fact.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-500">{{ fact.label }}</p>
                                        <p class="mt-1 break-words text-sm font-bold text-slate-950">{{ fact.value }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ fact.detail }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Eligibility
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Targeting and applicant rules
                                </h3>

                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div
                                        v-for="field in eligibilityFields"
                                        :key="field.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-500">{{ field.label }}</p>
                                        <p class="mt-1 whitespace-pre-line break-words text-sm font-bold leading-6 text-slate-950">
                                            {{ field.value }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-sm font-bold text-slate-950">Eligibility notes</p>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">
                                        {{ scholarship.eligibility || 'No eligibility notes provided.' }}
                                    </p>
                                </div>
                            </article>

                            <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Requirements
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Documents and workflow
                                </h3>

                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div
                                        v-for="field in workflowFields"
                                        :key="field.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-500">{{ field.label }}</p>
                                        <p class="mt-1 break-words text-sm font-bold text-slate-950">{{ field.value }}</p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Required documents</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                Check that this list is specific enough for applicants and providers to review consistently.
                                            </p>
                                        </div>
                                        <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                            {{ documentItems.length }} item{{ documentItems.length === 1 ? '' : 's' }}
                                        </span>
                                    </div>

                                    <div v-if="documentItems.length" class="mt-4 flex flex-wrap gap-2">
                                        <span
                                            v-for="item in documentItems"
                                            :key="item"
                                            class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"
                                        >
                                            {{ item }}
                                        </span>
                                    </div>

                                    <p v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-white p-3 text-sm text-slate-500">
                                        No document requirements listed yet.
                                    </p>
                                </div>
                            </article>

                            <article v-if="scholarship.review_rubric?.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Rubric
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Provider review criteria
                                </h3>

                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="criterion in scholarship.review_rubric"
                                        :key="criterion.key || criterion.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <p v-if="criterion.guidance" class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">
                                                    {{ criterion.guidance }}
                                                </p>
                                            </div>
                                            <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                                {{ criterion.weight || 0 }}%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </article>

                            <article v-if="hasContractDetails" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Contract Terms
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Renewal and service obligations
                                </h3>

                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="section in contractSections"
                                        :key="section.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-sm font-bold text-slate-950">{{ section.label }}</p>
                                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ section.value }}</p>
                                    </div>
                                </div>
                            </article>

                            <article v-if="hasLocationDetails" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Location
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Coverage and campus details
                                </h3>

                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-semibold text-slate-500">Location name</p>
                                        <p class="mt-1 break-words text-sm font-bold text-slate-950">
                                            {{ scholarship.location_name || 'Not listed' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-semibold text-slate-500">Coordinates</p>
                                        <p class="mt-1 break-words text-sm font-bold text-slate-950">
                                            {{ scholarship.latitude && scholarship.longitude ? `${scholarship.latitude}, ${scholarship.longitude}` : 'Not listed' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-xs font-semibold text-slate-500">Address</p>
                                    <p class="mt-1 whitespace-pre-line break-words text-sm font-bold leading-6 text-slate-950">
                                        {{ scholarship.location_address || scholarship.eligible_locations || 'Not listed' }}
                                    </p>
                                </div>

                                <a
                                    v-if="scholarship.map_url"
                                    :href="scholarship.map_url"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="mt-3 inline-flex rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Open map
                                </a>
                            </article>
                        </section>

                        <aside class="space-y-4 xl:sticky xl:top-6 xl:self-start">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Decision
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Review outcome
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Pick the outcome after checking the full program details. Rejections require a note for the provider.
                                </p>

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
                                        @click="reviewStatus = option.value"
                                    >
                                        <span class="block text-sm font-bold">{{ option.label }}</span>
                                        <span class="mt-1 block text-xs leading-5">{{ option.help }}</span>
                                    </button>
                                </div>

                                <label class="mt-4 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                    Review note <span v-if="reviewStatus === 'rejected'" class="text-rose-600">*</span>
                                </label>
                                <textarea
                                    v-model="reviewNotes"
                                    rows="5"
                                    maxlength="1500"
                                    placeholder="Summarize missing details, correction requests, or approval context."
                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                ></textarea>

                                <button
                                    type="button"
                                    :disabled="isSaving"
                                    class="mt-4 w-full rounded-md bg-slate-950 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                    @click="updateReview"
                                >
                                    {{ isSaving ? 'Saving review...' : 'Save review decision' }}
                                </button>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Provider
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Organization snapshot
                                </h3>

                                <div class="mt-4 grid gap-2">
                                    <div
                                        v-for="field in providerFields"
                                        :key="field.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <p class="text-xs font-semibold text-slate-500">{{ field.label }}</p>
                                        <p class="mt-1 break-words text-sm font-bold text-slate-950">{{ field.value }}</p>
                                    </div>
                                </div>

                                <a
                                    v-if="scholarship.provider_id"
                                    :href="`/admin/accounts/${scholarship.provider_id}/edit`"
                                    class="mt-3 inline-flex w-full justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Open provider account
                                </a>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Signals
                                </p>
                                <div class="mt-4 grid gap-2 text-sm">
                                    <p class="rounded-md bg-slate-50 p-3 font-semibold text-slate-700 ring-1 ring-slate-200">
                                        {{ scholarship.bookmarks_count || 0 }} saved bookmark{{ Number(scholarship.bookmarks_count || 0) === 1 ? '' : 's' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 p-3 font-semibold text-slate-700 ring-1 ring-slate-200">
                                        {{ scholarship.views_count || 0 }} recorded view{{ Number(scholarship.views_count || 0) === 1 ? '' : 's' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 p-3 font-semibold text-slate-700 ring-1 ring-slate-200">
                                        Created {{ scholarship.created_at || 'recently' }}
                                    </p>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
