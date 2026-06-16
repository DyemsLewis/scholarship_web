<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const formMessage = ref('');
const formError = ref('');
const editingId = ref(null);
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
});
const scholarships = ref([]);
const scholarshipFormElement = ref(null);
const scholarshipForm = ref(emptyScholarshipForm());

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const incomeOptions = ['Any', 'Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const documentRequirementOptions = [
    'Completed application form',
    'Certificate of enrollment',
    'Latest report card or grades',
    'Transcript of records',
    'School ID',
    'Birth certificate',
    'Good moral certificate',
    'Barangay certificate of residency',
    'Certificate of indigency',
    'Parent or guardian valid ID',
    'Proof of income',
    'Recommendation letter',
];

const programStats = computed(() => [
    {
        label: 'Total Programs',
        value: stats.value.scholarships,
        className: 'text-sky-700',
    },
    {
        label: 'Drafts',
        value: stats.value.drafts,
        className: 'text-amber-600',
    },
    {
        label: 'Published',
        value: scholarships.value.filter((scholarship) => scholarship.status === 'published').length,
        className: 'text-emerald-700',
    },
]);
const selectedRequirementCount = computed(() => scholarshipForm.value.requirements.length);
const canPostScholarships = computed(() => user.value?.can_post_scholarships);

function emptyScholarshipForm() {
    return {
        title: '',
        category: '',
        description: '',
        eligibility: '',
        eligibleCourses: '',
        eligibleYearLevels: '',
        eligibleLocations: '',
        incomeRequirement: 'Any',
        requirements: [],
        awardAmount: '',
        minimumGwa: '',
        deadline: '',
        status: 'draft',
    };
}

function parseRequirements(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter((requirement) => documentRequirementOptions.includes(requirement));
}

function isRequirementSelected(requirement) {
    return scholarshipForm.value.requirements.includes(requirement);
}

function selectCommonRequirements() {
    scholarshipForm.value.requirements = [
        'Completed application form',
        'Certificate of enrollment',
        'Latest report card or grades',
        'School ID',
        'Proof of income',
    ];
}

function clearRequirements() {
    scholarshipForm.value.requirements = [];
}

function statusLabel(status) {
    return String(status ?? 'draft')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship programs.';
    } finally {
        isLoading.value = false;
    }
}

function resetScholarshipForm() {
    editingId.value = null;
    scholarshipForm.value = emptyScholarshipForm();
    formMessage.value = '';
    formError.value = '';
}

function editScholarship(scholarship) {
    editingId.value = scholarship.id;
    formMessage.value = '';
    formError.value = '';
    scholarshipForm.value = {
        title: scholarship.title ?? '',
        category: scholarship.category ?? '',
        description: scholarship.description ?? '',
        eligibility: scholarship.eligibility ?? '',
        eligibleCourses: scholarship.eligible_courses ?? '',
        eligibleYearLevels: scholarship.eligible_year_levels ?? '',
        eligibleLocations: scholarship.eligible_locations ?? '',
        incomeRequirement: scholarship.income_requirement ?? 'Any',
        requirements: parseRequirements(scholarship.requirements),
        awardAmount: scholarship.award_amount ?? '',
        minimumGwa: scholarship.minimum_gwa ?? '',
        deadline: scholarship.deadline ?? '',
        status: scholarship.status ?? 'draft',
    };

    scholarshipFormElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function saveScholarship() {
    formMessage.value = '';
    formError.value = '';

    if (!scholarshipFormElement.value?.reportValidity()) {
        return;
    }

    isSaving.value = true;

    const payload = {
        title: scholarshipForm.value.title,
        category: scholarshipForm.value.category || null,
        description: scholarshipForm.value.description,
        eligibility: scholarshipForm.value.eligibility,
        eligible_courses: scholarshipForm.value.eligibleCourses,
        eligible_year_levels: scholarshipForm.value.eligibleYearLevels,
        eligible_locations: scholarshipForm.value.eligibleLocations,
        income_requirement: scholarshipForm.value.incomeRequirement || 'Any',
        requirements: scholarshipForm.value.requirements.join('\n'),
        award_amount: scholarshipForm.value.awardAmount || null,
        minimum_gwa: scholarshipForm.value.minimumGwa || null,
        deadline: scholarshipForm.value.deadline || null,
        status: scholarshipForm.value.status,
    };

    try {
        const response = editingId.value
            ? await window.axios.put(`/provider/scholarships/${editingId.value}`, payload)
            : await window.axios.post('/provider/scholarships', payload);

        const message = response.data.message ?? 'Scholarship saved successfully.';
        resetScholarshipForm();
        formMessage.value = message;
        await loadProviderData();
    } catch (error) {
        formError.value = error.response?.data?.message ?? 'Unable to save scholarship.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Scholarship Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Create and edit programs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Manage scholarship details, award amounts, deadlines, and publishing status from this page.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Provider
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship programs...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div
                        v-if="!canPostScholarships"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm"
                    >
                        <p class="font-bold">
                            Provider verification required
                        </p>
                        <p class="mt-1 leading-6">
                            Your provider account is currently {{ user?.verification_status || 'pending' }}. An admin must approve the provider account before scholarships can be created or updated.
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <article
                            v-for="item in programStats"
                            :key="item.label"
                            class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <p class="text-sm font-semibold text-slate-500">
                                {{ item.label }}
                            </p>
                            <p :class="['mt-3 font-display text-3xl font-bold', item.className]">
                                {{ item.value }}
                            </p>
                        </article>
                    </div>

                    <form
                        ref="scholarshipFormElement"
                        class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                        @submit.prevent="saveScholarship"
                    >
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            {{ editingId ? 'Edit Scholarship' : 'Create Scholarship' }}
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ editingId ? 'Update scholarship program' : 'Add scholarship program' }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Save as draft while preparing details, publish when ready, or close when applications should stop.
                        </p>

                        <div class="mt-5 grid gap-4">
                            <div>
                                <label :class="labelClass" for="scholarship-title">
                                    Scholarship title
                                </label>
                                <input
                                    id="scholarship-title"
                                    v-model="scholarshipForm.title"
                                    type="text"
                                    required
                                    placeholder="Scholarship title"
                                    :class="inputClass"
                                >
                            </div>

                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                                    <div>
                                        <label :class="labelClass" for="scholarship-category">
                                            Category
                                        </label>
                                        <select
                                            id="scholarship-category"
                                            v-model="scholarshipForm.category"
                                            :class="inputClass"
                                        >
                                            <option value="">
                                                Select category
                                            </option>
                                            <option
                                                v-for="option in categoryOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-amount">
                                            Award amount
                                    </label>
                                    <input
                                        id="scholarship-amount"
                                        v-model="scholarshipForm.awardAmount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0.00"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-minimum-gwa">
                                            Minimum GWA / avg
                                        </label>
                                        <input
                                            id="scholarship-minimum-gwa"
                                            v-model="scholarshipForm.minimumGwa"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            placeholder="85 or 2.00"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-deadline">
                                            Deadline
                                    </label>
                                    <input
                                        id="scholarship-deadline"
                                        v-model="scholarshipForm.deadline"
                                        type="date"
                                        :class="inputClass"
                                    >
                                </div>

                                <div>
                                    <label :class="labelClass" for="scholarship-status">
                                        Status
                                    </label>
                                    <select
                                        id="scholarship-status"
                                        v-model="scholarshipForm.status"
                                        required
                                        :class="inputClass"
                                    >
                                        <option value="draft">
                                            Draft
                                        </option>
                                        <option value="published">
                                            Published
                                        </option>
                                        <option value="closed">
                                            Closed
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label :class="labelClass" for="scholarship-description">
                                    Description
                                </label>
                                <textarea
                                    id="scholarship-description"
                                    v-model="scholarshipForm.description"
                                    required
                                    rows="4"
                                    placeholder="Describe the scholarship program"
                                    :class="inputClass"
                                ></textarea>
                            </div>

                            <div>
                                <label :class="labelClass" for="scholarship-eligibility">
                                    Eligibility
                                </label>
                                <textarea
                                    id="scholarship-eligibility"
                                    v-model="scholarshipForm.eligibility"
                                    rows="4"
                                    placeholder="Who can apply?"
                                    :class="inputClass"
                                ></textarea>
                            </div>

                            <fieldset class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
                                <legend class="text-sm font-semibold text-slate-700">
                                    Matching criteria
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    These fields power the student match score and admin analytics. Use commas or new lines for multiple entries.
                                </p>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="scholarship-courses">
                                            Eligible courses / strands
                                        </label>
                                        <textarea
                                            id="scholarship-courses"
                                            v-model="scholarshipForm.eligibleCourses"
                                            rows="3"
                                            placeholder="Example: BSIT, STEM, ABM"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-years">
                                            Eligible year levels
                                        </label>
                                        <textarea
                                            id="scholarship-years"
                                            v-model="scholarshipForm.eligibleYearLevels"
                                            rows="3"
                                            placeholder="Example: 1st year, Grade 12"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-locations">
                                            Eligible locations
                                        </label>
                                        <textarea
                                            id="scholarship-locations"
                                            v-model="scholarshipForm.eligibleLocations"
                                            rows="3"
                                            placeholder="Example: Manila, Cebu, Quezon City"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-income">
                                            Income requirement
                                        </label>
                                        <select
                                            id="scholarship-income"
                                            v-model="scholarshipForm.incomeRequirement"
                                            :class="inputClass"
                                        >
                                            <option
                                                v-for="option in incomeOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="rounded-lg border border-slate-200 bg-white p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <legend class="text-sm font-semibold text-slate-700">
                                            Document requirements
                                        </legend>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Choose the documents applicants must prepare for this scholarship.
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-sky-300 hover:bg-sky-50 hover:text-sky-700"
                                            @click="selectCommonRequirements"
                                        >
                                            Select common
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                            @click="clearRequirements"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    <label
                                        v-for="requirement in documentRequirementOptions"
                                        :key="requirement"
                                        :class="[
                                            'group flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition',
                                            isRequirementSelected(requirement)
                                                ? 'border-sky-300 bg-sky-50 text-slate-950 shadow-sm'
                                                : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-slate-300 hover:bg-white',
                                        ]"
                                    >
                                        <input
                                            v-model="scholarshipForm.requirements"
                                            type="checkbox"
                                            :value="requirement"
                                            class="sr-only"
                                        >
                                        <span
                                            :class="[
                                                'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border text-[10px] font-bold transition',
                                                isRequirementSelected(requirement)
                                                    ? 'border-sky-600 bg-sky-600 text-white'
                                                    : 'border-slate-300 bg-white text-transparent group-hover:border-sky-300',
                                            ]"
                                        >
                                            OK
                                        </span>
                                        <span class="leading-5">
                                            {{ requirement }}
                                        </span>
                                    </label>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                        {{ selectedRequirementCount }} selected
                                    </p>
                                    <div v-if="selectedRequirementCount" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="requirement in scholarshipForm.requirements"
                                            :key="requirement"
                                            class="rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800"
                                        >
                                            {{ requirement }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-xs leading-5 text-slate-500">
                                        No document requirements selected yet.
                                    </p>
                                </div>
                            </fieldset>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-h-5">
                                <p v-if="formMessage" class="text-sm font-semibold text-emerald-700">
                                    {{ formMessage }}
                                </p>
                                <p v-if="formError" class="text-sm font-semibold text-rose-700">
                                    {{ formError }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    @click="resetScholarshipForm"
                                >
                                    {{ editingId ? 'Cancel edit' : 'Clear' }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isSaving || !canPostScholarships"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                                >
                                    {{ isSaving ? 'Saving...' : editingId ? 'Update scholarship' : 'Create scholarship' }}
                                </button>
                            </div>
                        </div>
                    </form>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-4">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Saved Programs
                            </p>
                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                Created scholarships
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ scholarships.length }} program{{ scholarships.length === 1 ? '' : 's' }} saved by this provider.
                            </p>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-6 text-sm text-slate-500">
                            No scholarships yet. Create your first scholarship program above.
                        </div>

                        <div v-else class="grid gap-4 p-4 lg:grid-cols-2">
                            <article
                                v-for="scholarship in scholarships"
                                :key="scholarship.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <span :class="['inline-flex rounded-md px-2 py-1 text-xs font-bold uppercase', statusClass(scholarship.status)]">
                                            {{ statusLabel(scholarship.status) }}
                                        </span>
                                        <h4 class="mt-3 text-lg font-bold text-slate-950">
                                            {{ scholarship.title }}
                                        </h4>
                                        <p class="mt-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                            {{ scholarship.category || 'Uncategorized' }}
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-white"
                                        @click="editScholarship(scholarship)"
                                    >
                                        Edit
                                    </button>
                                </div>

                                <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.description }}
                                </p>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Award
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ formatAmount(scholarship.award_amount) }}
                                        </p>
                                    </div>

                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Deadline
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ scholarship.deadline || 'Not set' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Minimum GWA / avg
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ scholarship.minimum_gwa || 'Not set' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Saved by students
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ scholarship.bookmarks_count || 0 }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md border border-emerald-100 bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        Matching criteria
                                    </p>
                                    <p class="mt-1 leading-6 text-slate-700">
                                        Courses: {{ scholarship.eligible_courses || 'Any' }}
                                    </p>
                                    <p class="mt-1 leading-6 text-slate-700">
                                        Year levels: {{ scholarship.eligible_year_levels || 'Any' }}
                                    </p>
                                    <p class="mt-1 leading-6 text-slate-700">
                                        Locations: {{ scholarship.eligible_locations || 'Any' }}
                                    </p>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
