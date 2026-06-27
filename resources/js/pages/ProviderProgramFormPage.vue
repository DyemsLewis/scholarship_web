<script setup>
import { computed, onMounted, ref } from 'vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const scholarshipId = window.location.pathname.match(/\/provider\/programs\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(scholarshipId));
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const formMessage = ref('');
const formError = ref('');
const user = ref(null);
const scholarshipFormElement = ref(null);
const imageInputElement = ref(null);
const scholarshipForm = ref(emptyScholarshipForm());
const imageFile = ref(null);
const imagePreviewUrl = ref('');
const providerLocationMessage = ref('');
const providerAddressLookupTrigger = ref(0);

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const incomeOptions = ['Any', 'Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const educationLevelOptions = [
    { value: 'elementary', label: 'Elementary' },
    { value: 'junior_high_school', label: 'Junior High School' },
    { value: 'senior_high_school', label: 'Senior High School' },
    { value: 'college', label: 'College / University' },
    { value: 'tvet', label: 'TVET / Vocational' },
    { value: 'als', label: 'ALS / Alternative Learning' },
];
const schoolTypeOptions = [
    { value: 'public', label: 'Public school' },
    { value: 'private', label: 'Private school' },
    { value: 'state_university', label: 'State university / college' },
    { value: 'local_college', label: 'Local college / university' },
    { value: 'tvet_center', label: 'TVET center' },
    { value: 'als_center', label: 'ALS center' },
];
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

const selectedRequirementCount = computed(() => scholarshipForm.value.requirements.length);
const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const scholarshipImagePreview = computed(() => imagePreviewUrl.value || scholarshipForm.value.imageUrl || '/uploads/scholarship-default.jpg');
const scholarshipFormMapAddress = computed(() => {
    const parts = [
        scholarshipForm.value.locationName,
        scholarshipForm.value.locationAddress,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});
const programReadinessItems = computed(() => [
    {
        label: 'Basic program details',
        complete: hasText(scholarshipForm.value.title)
            && hasText(scholarshipForm.value.category)
            && hasText(scholarshipForm.value.description)
            && hasText(scholarshipForm.value.awardAmount)
            && hasText(scholarshipForm.value.deadline),
        help: 'Title, category, award amount, deadline, and description.',
    },
    {
        label: 'Eligibility and matching rules',
        complete: hasText(scholarshipForm.value.eligibility)
            && (
                scholarshipForm.value.eligibleEducationLevels.length > 0
                || hasText(scholarshipForm.value.eligibleCourses)
                || scholarshipForm.value.eligibleSchoolTypes.length > 0
                || hasText(scholarshipForm.value.eligibleYearLevels)
                || hasText(scholarshipForm.value.eligibleLocations)
                || scholarshipForm.value.incomeRequirement !== 'Any'
                || hasText(scholarshipForm.value.minimumGwa)
            ),
        help: 'Eligibility text plus at least one finder rule or clear open-to-all intent.',
    },
    {
        label: 'Document checklist',
        complete: selectedRequirementCount.value > 0,
        help: 'Documents applicants must prepare before submission.',
    },
    {
        label: 'Map location',
        complete: hasText(scholarshipForm.value.locationName)
            && hasText(scholarshipForm.value.locationAddress)
            && hasText(scholarshipForm.value.latitude)
            && hasText(scholarshipForm.value.longitude),
        help: 'Address and map pin for distance visualization.',
    },
    {
        label: 'Application workflow',
        complete: hasText(scholarshipForm.value.applicationMode)
            && (
                hasText(scholarshipForm.value.contactEmail)
                || hasText(scholarshipForm.value.contactNumber)
            ),
        help: 'How students apply and who they can contact for questions.',
    },
]);
const completedProgramReadinessItems = computed(() => programReadinessItems.value.filter((item) => item.complete).length);
const programReadiness = computed(() => Math.round((completedProgramReadinessItems.value / programReadinessItems.value.length) * 100));
const missingProgramReadinessItems = computed(() => programReadinessItems.value.filter((item) => !item.complete));
const publishWarnings = computed(() => {
    if (scholarshipForm.value.status !== 'published') {
        return [];
    }

    return missingProgramReadinessItems.value.map((item) => item.label);
});
const finderRuleSummary = computed(() => [
    scholarshipForm.value.eligibleEducationLevels.length ? `${scholarshipForm.value.eligibleEducationLevels.length} education level${scholarshipForm.value.eligibleEducationLevels.length === 1 ? '' : 's'}` : 'All education levels',
    scholarshipForm.value.eligibleSchoolTypes.length ? `${scholarshipForm.value.eligibleSchoolTypes.length} school type${scholarshipForm.value.eligibleSchoolTypes.length === 1 ? '' : 's'}` : 'All school types',
    hasText(scholarshipForm.value.minimumGwa) ? `Min avg ${scholarshipForm.value.minimumGwa}` : 'No minimum average',
    scholarshipForm.value.incomeRequirement && scholarshipForm.value.incomeRequirement !== 'Any' ? scholarshipForm.value.incomeRequirement : 'Any income',
]);
const workflowSummary = computed(() => [
    scholarshipForm.value.applicationMode
        ? applicationModeOptions.find((option) => option.value === scholarshipForm.value.applicationMode)?.label ?? scholarshipForm.value.applicationMode
        : 'Application mode not set',
    hasText(scholarshipForm.value.slotsAvailable) ? `${scholarshipForm.value.slotsAvailable} available slot${Number(scholarshipForm.value.slotsAvailable) === 1 ? '' : 's'}` : 'Slots not listed',
    hasText(scholarshipForm.value.contactEmail) || hasText(scholarshipForm.value.contactNumber) ? 'Contact available' : 'No contact channel',
]);

function hasText(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function emptyScholarshipForm() {
    return {
        title: '',
        category: '',
        description: '',
        eligibility: '',
        eligibleEducationLevels: [],
        eligibleCourses: '',
        eligibleSchoolTypes: [],
        eligibleYearLevels: '',
        eligibleLocations: '',
        incomeRequirement: 'Any',
        locationName: '',
        locationAddress: '',
        latitude: '',
        longitude: '',
        requirements: [],
        awardAmount: '',
        minimumGwa: '',
        slotsAvailable: '',
        applicationMode: '',
        renewalPolicy: '',
        contactEmail: '',
        contactNumber: '',
        deadline: '',
        status: 'draft',
        imageUrl: '/uploads/scholarship-default.jpg',
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

function parseSelections(value, validOptions) {
    const validValues = validOptions.map((option) => option.value);

    if (!value) {
        return [];
    }

    return String(value)
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter((item) => validValues.includes(item));
}

function toggleSelection(field, value) {
    const selected = scholarshipForm.value[field];

    scholarshipForm.value[field] = selected.includes(value)
        ? selected.filter((item) => item !== value)
        : [...selected, value];
}

function selectAllOptions(field, options) {
    scholarshipForm.value[field] = options.map((option) => option.value);
}

function applyBroadEligibility() {
    scholarshipForm.value.eligibleEducationLevels = educationLevelOptions.map((option) => option.value);
    scholarshipForm.value.eligibleSchoolTypes = schoolTypeOptions.map((option) => option.value);
    scholarshipForm.value.eligibleCourses = 'Any track, strand, or course';
    scholarshipForm.value.eligibleYearLevels = 'Any grade or year level';
    scholarshipForm.value.eligibleLocations = 'Philippines';
    scholarshipForm.value.incomeRequirement = 'Any';
}

function applyCommonProgramDetails() {
    if (!scholarshipForm.value.eligibility) {
        scholarshipForm.value.eligibility = 'Open to currently enrolled learners who meet the academic, location, and document requirements listed by the provider.';
    }

    if (!scholarshipForm.value.description) {
        scholarshipForm.value.description = 'A scholarship assistance program for eligible Filipino students. Review the requirements, prepare documents, and submit your application before the deadline.';
    }

    if (!selectedRequirementCount.value) {
        selectCommonRequirements();
    }

    if (!scholarshipForm.value.applicationMode) {
        scholarshipForm.value.applicationMode = 'online';
    }

    if (!scholarshipForm.value.renewalPolicy) {
        scholarshipForm.value.renewalPolicy = 'Renewal depends on continued eligibility, submitted requirements, and available funding.';
    }

    if (!scholarshipForm.value.contactEmail && user.value?.email) {
        scholarshipForm.value.contactEmail = user.value.email;
    }

    if (!scholarshipForm.value.contactNumber && user.value?.contact_number) {
        scholarshipForm.value.contactNumber = user.value.contact_number;
    }
}

function fillScholarshipForm(scholarship) {
    scholarshipForm.value = {
        title: scholarship.title ?? '',
        category: scholarship.category ?? '',
        description: scholarship.description ?? '',
        eligibility: scholarship.eligibility ?? '',
        eligibleEducationLevels: parseSelections(scholarship.eligible_education_levels, educationLevelOptions),
        eligibleCourses: scholarship.eligible_courses ?? '',
        eligibleSchoolTypes: parseSelections(scholarship.eligible_school_types, schoolTypeOptions),
        eligibleYearLevels: scholarship.eligible_year_levels ?? '',
        eligibleLocations: scholarship.eligible_locations ?? '',
        incomeRequirement: scholarship.income_requirement ?? 'Any',
        locationName: scholarship.location_name ?? '',
        locationAddress: scholarship.location_address ?? '',
        latitude: scholarship.latitude ?? '',
        longitude: scholarship.longitude ?? '',
        requirements: parseRequirements(scholarship.requirements),
        awardAmount: scholarship.award_amount ?? '',
        minimumGwa: scholarship.minimum_gwa ?? '',
        slotsAvailable: scholarship.slots_available ?? '',
        applicationMode: scholarship.application_mode ?? '',
        renewalPolicy: scholarship.renewal_policy ?? '',
        contactEmail: scholarship.contact_email ?? '',
        contactNumber: scholarship.contact_number ?? '',
        deadline: scholarship.deadline ?? '',
        status: scholarship.status ?? 'draft',
        imageUrl: scholarship.image_url ?? '/uploads/scholarship-default.jpg',
    };
    imageFile.value = null;
    imagePreviewUrl.value = '';
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

function clearScholarshipMapPoint() {
    scholarshipForm.value.latitude = '';
    scholarshipForm.value.longitude = '';
    providerLocationMessage.value = '';
}

function lookupScholarshipAddress() {
    if (!scholarshipFormMapAddress.value) {
        providerLocationMessage.value = 'Enter the scholarship location address first.';
        return;
    }

    providerLocationMessage.value = 'Searching scholarship address on the map...';
    providerAddressLookupTrigger.value += 1;
}

function handleScholarshipLocationResolved(location) {
    scholarshipForm.value.latitude = Number(location.latitude).toFixed(7);
    scholarshipForm.value.longitude = Number(location.longitude).toFixed(7);
    providerLocationMessage.value = 'Address found on the map. Save the scholarship to keep this map point.';
}

function handleScholarshipLocationPicked(location) {
    const address = location.address ?? {};
    const locationName = address.office
        || address.amenity
        || address.building
        || address.school
        || address.university
        || address.tourism
        || scholarshipForm.value.locationName;

    scholarshipForm.value.latitude = Number(location.latitude).toFixed(7);
    scholarshipForm.value.longitude = Number(location.longitude).toFixed(7);
    scholarshipForm.value.locationName = locationName;
    scholarshipForm.value.locationAddress = location.displayName
        || [
            [address.house_number, address.road].filter(Boolean).join(' '),
            address.neighbourhood || address.suburb || address.quarter,
            address.city || address.municipality || address.town,
            address.province || address.state,
        ].filter(Boolean).join(', ')
        || scholarshipForm.value.locationAddress;
    providerLocationMessage.value = location.displayName
        ? 'Pin set. The scholarship address was filled from the selected map point.'
        : 'Pin set. Save the scholarship to keep this map point.';
}

function handleScholarshipLocationError(message) {
    providerLocationMessage.value = message;
}

function resetScholarshipForm() {
    scholarshipForm.value = emptyScholarshipForm();
    imageFile.value = null;
    imagePreviewUrl.value = '';
    formMessage.value = '';
    formError.value = '';
    providerLocationMessage.value = '';

    if (imageInputElement.value) {
        imageInputElement.value.value = '';
    }
}

function handleImageFile(event) {
    const file = event.target.files?.[0] ?? null;

    imageFile.value = file;

    if (imagePreviewUrl.value) {
        URL.revokeObjectURL(imagePreviewUrl.value);
    }

    imagePreviewUrl.value = file ? URL.createObjectURL(file) : '';
}

async function loadFormData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const profileResponse = await window.axios.get('/provider/profile/data');

        user.value = profileResponse.data.user;

        if (scholarshipId) {
            const scholarshipResponse = await window.axios.get(`/provider/scholarships/${scholarshipId}`);

            fillScholarshipForm(scholarshipResponse.data.scholarship);
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship form.';
    } finally {
        isLoading.value = false;
    }
}

async function saveScholarship() {
    formMessage.value = '';
    formError.value = '';

    if (!scholarshipFormElement.value?.reportValidity()) {
        return;
    }

    isSaving.value = true;

    const payload = new FormData();
    const fields = {
        title: scholarshipForm.value.title,
        category: scholarshipForm.value.category || '',
        description: scholarshipForm.value.description,
        eligibility: scholarshipForm.value.eligibility,
        eligible_education_levels: scholarshipForm.value.eligibleEducationLevels.join('\n'),
        eligible_courses: scholarshipForm.value.eligibleCourses,
        eligible_school_types: scholarshipForm.value.eligibleSchoolTypes.join('\n'),
        eligible_year_levels: scholarshipForm.value.eligibleYearLevels,
        eligible_locations: scholarshipForm.value.eligibleLocations,
        income_requirement: scholarshipForm.value.incomeRequirement || 'Any',
        location_name: scholarshipForm.value.locationName || '',
        location_address: scholarshipForm.value.locationAddress || '',
        latitude: scholarshipForm.value.latitude || '',
        longitude: scholarshipForm.value.longitude || '',
        requirements: scholarshipForm.value.requirements.join('\n'),
        award_amount: scholarshipForm.value.awardAmount || '',
        minimum_gwa: scholarshipForm.value.minimumGwa || '',
        slots_available: scholarshipForm.value.slotsAvailable || '',
        application_mode: scholarshipForm.value.applicationMode || '',
        renewal_policy: scholarshipForm.value.renewalPolicy || '',
        contact_email: scholarshipForm.value.contactEmail || '',
        contact_number: scholarshipForm.value.contactNumber || '',
        deadline: scholarshipForm.value.deadline || '',
        status: scholarshipForm.value.status,
    };

    Object.entries(fields).forEach(([key, value]) => {
        payload.append(key, value);
    });

    if (imageFile.value) {
        payload.append('image_file', imageFile.value);
    }

    if (isEditMode.value) {
        payload.append('_method', 'PUT');
    }

    try {
        const response = isEditMode.value
            ? await window.axios.post(`/provider/scholarships/${scholarshipId}`, payload)
            : await window.axios.post('/provider/scholarships', payload);

        formMessage.value = response.data.message ?? 'Scholarship saved successfully.';

        if (isEditMode.value) {
            fillScholarshipForm(response.data.scholarship);
        } else {
            resetScholarshipForm();
            formMessage.value = response.data.message ?? 'Scholarship created successfully.';
        }
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

onMounted(loadFormData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Program Form
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit scholarship program' : 'Create scholarship program' }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Use this focused page for program details, matching rules, document requirements, and map location.
                            </p>
                        </div>

                        <a
                            href="/provider/programs"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            Back to programs
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship form...
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

                    <form
                        ref="scholarshipFormElement"
                        class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                        @submit.prevent="saveScholarship"
                    >
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            {{ isEditMode ? 'Edit Scholarship' : 'Create Scholarship' }}
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ isEditMode ? 'Update scholarship program' : 'Add scholarship program' }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Save as draft while preparing details, publish when ready, or close when applications should stop.
                        </p>

                        <div class="mt-5 grid gap-4 lg:grid-cols-[0.85fr_1.15fr]">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                            Program readiness
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ completedProgramReadinessItems }}/{{ programReadinessItems.length }} sections ready
                                        </p>
                                    </div>
                                    <p class="font-display text-3xl font-bold text-slate-950">
                                        {{ programReadiness }}%
                                    </p>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${programReadiness}%` }"></div>
                                </div>
                                <div class="mt-4 grid gap-2">
                                    <div
                                        v-for="item in programReadinessItems"
                                        :key="item.label"
                                        class="flex items-start gap-2 rounded-md bg-white px-3 py-2 text-xs ring-1 ring-slate-200"
                                    >
                                        <span :class="['mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full', item.complete ? 'bg-emerald-500' : 'bg-amber-400']"></span>
                                        <span>
                                            <span class="block font-bold text-slate-800">{{ item.label }}</span>
                                            <span class="block leading-5 text-slate-500">{{ item.help }}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-emerald-100 bg-emerald-50/70 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">
                                            Quick setup
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Use these helpers when creating a general scholarship, then adjust any field as needed.
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-emerald-200 bg-white px-3 py-2 text-xs font-bold text-emerald-800 transition hover:bg-emerald-50"
                                            @click="applyCommonProgramDetails"
                                        >
                                            Add common details
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md border border-emerald-200 bg-white px-3 py-2 text-xs font-bold text-emerald-800 transition hover:bg-emerald-50"
                                            @click="applyBroadEligibility"
                                        >
                                            Broad eligibility
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="summary in finderRuleSummary"
                                        :key="summary"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-emerald-100"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span
                                        v-for="summary in workflowSummary"
                                        :key="`workflow-${summary}`"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-emerald-100"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="publishWarnings.length"
                            class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                        >
                            <p class="font-bold">
                                Review before publishing
                            </p>
                            <p class="mt-1 leading-6">
                                This program can still be saved, but these sections are incomplete: {{ publishWarnings.join(', ') }}.
                            </p>
                        </div>

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

                            <div class="grid gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 lg:grid-cols-[5rem_1fr] lg:items-center">
                                <img
                                    :src="scholarshipImagePreview"
                                    alt="Scholarship program preview"
                                    class="h-16 w-16 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                                >
                                <div>
                                    <label :class="labelClass" for="scholarship-image">
                                        Program logo
                                    </label>
                                    <input
                                        id="scholarship-image"
                                        ref="imageInputElement"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-bold file:text-white hover:file:bg-slate-800"
                                        @change="handleImageFile"
                                    >
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        Optional. JPG, PNG, or WebP up to 4MB. If no logo is uploaded, the default scholarship logo will be used.
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                                <div>
                                    <label :class="labelClass" for="scholarship-category">
                                        Category
                                    </label>
                                    <select id="scholarship-category" v-model="scholarshipForm.category" :class="inputClass">
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
                                        Minimum GWA / general average
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
                                    <label :class="labelClass" for="scholarship-slots">
                                        Available slots
                                    </label>
                                    <input
                                        id="scholarship-slots"
                                        v-model="scholarshipForm.slotsAvailable"
                                        type="number"
                                        min="0"
                                        step="1"
                                        placeholder="Optional"
                                        :class="inputClass"
                                    >
                                </div>

                                <div>
                                    <label :class="labelClass" for="scholarship-mode">
                                        Application mode
                                    </label>
                                    <select id="scholarship-mode" v-model="scholarshipForm.applicationMode" :class="inputClass">
                                        <option value="">
                                            Select mode
                                        </option>
                                        <option
                                            v-for="option in applicationModeOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
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
                                    <select id="scholarship-status" v-model="scholarshipForm.status" required :class="inputClass">
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

                            <fieldset class="rounded-lg border border-amber-100 bg-amber-50/60 p-4">
                                <legend class="text-sm font-semibold text-slate-700">
                                    Application workflow
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    Add the practical details students need after they decide a scholarship fits them.
                                </p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="summary in workflowSummary"
                                        :key="`workflow-detail-${summary}`"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-amber-800 ring-1 ring-amber-100"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="scholarship-contact-email">
                                            Contact email
                                        </label>
                                        <input
                                            id="scholarship-contact-email"
                                            v-model="scholarshipForm.contactEmail"
                                            type="email"
                                            placeholder="scholarship.office@example.com"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-contact-number">
                                            Contact number
                                        </label>
                                        <input
                                            id="scholarship-contact-number"
                                            v-model="scholarshipForm.contactNumber"
                                            type="text"
                                            placeholder="0917 123 4567"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div class="lg:col-span-2">
                                        <label :class="labelClass" for="scholarship-renewal">
                                            Renewal or continuation policy
                                        </label>
                                        <textarea
                                            id="scholarship-renewal"
                                            v-model="scholarshipForm.renewalPolicy"
                                            rows="3"
                                            placeholder="Example: Renewable every semester if the learner maintains eligibility and submits updated requirements."
                                            :class="inputClass"
                                        ></textarea>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset class="rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
                                <legend class="text-sm font-semibold text-slate-700">
                                    Matching criteria
                                </legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    These fields power the student match score and finder filters. Leave a section blank when the program is open to everyone.
                                </p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="summary in finderRuleSummary"
                                        :key="`matching-${summary}`"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-emerald-800 ring-1 ring-emerald-100"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div class="rounded-md border border-emerald-100 bg-white p-3 lg:col-span-2">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <label class="text-sm font-semibold text-slate-700">
                                                Eligible education levels
                                            </label>
                                            <div class="flex gap-2">
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="selectAllOptions('eligibleEducationLevels', educationLevelOptions)">
                                                    Select all
                                                </button>
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="scholarshipForm.eligibleEducationLevels = []">
                                                    Open to all
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                v-for="option in educationLevelOptions"
                                                :key="option.value"
                                                type="button"
                                                :class="[
                                                    'rounded-md border px-3 py-2 text-xs font-bold transition',
                                                    scholarshipForm.eligibleEducationLevels.includes(option.value)
                                                        ? 'border-emerald-700 bg-emerald-700 text-white'
                                                        : 'border-slate-300 bg-slate-50 text-slate-700 hover:bg-white',
                                                ]"
                                                @click="toggleSelection('eligibleEducationLevels', option.value)"
                                            >
                                                {{ option.label }}
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-courses">
                                            Eligible tracks, strands, or courses
                                        </label>
                                        <textarea
                                            id="scholarship-courses"
                                            v-model="scholarshipForm.eligibleCourses"
                                            rows="3"
                                            placeholder="Example: General, STEM, TVL, BSIT"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div class="rounded-md border border-emerald-100 bg-white p-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <label class="text-sm font-semibold text-slate-700">
                                                Eligible school types
                                            </label>
                                            <div class="flex gap-2">
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="selectAllOptions('eligibleSchoolTypes', schoolTypeOptions)">
                                                    Select all
                                                </button>
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="scholarshipForm.eligibleSchoolTypes = []">
                                                    Open to all
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                v-for="option in schoolTypeOptions"
                                                :key="option.value"
                                                type="button"
                                                :class="[
                                                    'rounded-md border px-3 py-2 text-xs font-bold transition',
                                                    scholarshipForm.eligibleSchoolTypes.includes(option.value)
                                                        ? 'border-emerald-700 bg-emerald-700 text-white'
                                                        : 'border-slate-300 bg-slate-50 text-slate-700 hover:bg-white',
                                                ]"
                                                @click="toggleSelection('eligibleSchoolTypes', option.value)"
                                            >
                                                {{ option.label }}
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-years">
                                            Eligible grade / year levels
                                        </label>
                                        <textarea
                                            id="scholarship-years"
                                            v-model="scholarshipForm.eligibleYearLevels"
                                            rows="3"
                                            placeholder="Example: Grade 7, Grade 12, 1st year"
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
                                        <select id="scholarship-income" v-model="scholarshipForm.incomeRequirement" :class="inputClass">
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

                            <fieldset class="rounded-lg border border-sky-100 bg-sky-50/60 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <legend class="text-sm font-semibold text-slate-700">
                                            Map location
                                        </legend>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Add the office, campus, or service address. Search an address or click the map to set a pin and fill the address.
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        @click="lookupScholarshipAddress"
                                    >
                                        Find address on map
                                    </button>
                                </div>

                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="scholarship-location-name">
                                            Location name
                                        </label>
                                        <input
                                            id="scholarship-location-name"
                                            v-model="scholarshipForm.locationName"
                                            type="text"
                                            placeholder="Example: City Scholarship Office"
                                            :class="inputClass"
                                            @input="clearScholarshipMapPoint"
                                        >
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-location-address">
                                            Full address
                                        </label>
                                        <input
                                            id="scholarship-location-address"
                                            v-model="scholarshipForm.locationAddress"
                                            type="text"
                                            placeholder="Street, city, province"
                                            :class="inputClass"
                                            @input="clearScholarshipMapPoint"
                                        >
                                    </div>
                                </div>

                                <LeafletMapPreview
                                    class="mt-4"
                                    :address="scholarshipFormMapAddress"
                                    :latitude="scholarshipForm.latitude"
                                    :longitude="scholarshipForm.longitude"
                                    title="Scholarship address map preview"
                                    :marker-text="scholarshipForm.locationName || 'Scholarship location'"
                                    :geocode-trigger="providerAddressLookupTrigger"
                                    picker
                                    @resolved="handleScholarshipLocationResolved"
                                    @picked="handleScholarshipLocationPicked"
                                    @error="handleScholarshipLocationError"
                                />

                                <p v-if="providerLocationMessage" class="mt-3 text-xs font-semibold text-slate-700">
                                    {{ providerLocationMessage }}
                                </p>
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
                                    v-if="!isEditMode"
                                    type="button"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    @click="resetScholarshipForm"
                                >
                                    Clear
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isSaving || !canPostScholarships"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                                >
                                    {{ isSaving ? 'Saving...' : isEditMode ? 'Update scholarship' : 'Create scholarship' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
