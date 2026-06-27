<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const locationMessage = ref('');
const user = ref(null);
const form = ref(emptyForm());
const currentStep = ref(0);
const addressLookupTrigger = ref(0);

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm uppercase text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';
const enrollmentOptions = ['Enrolled', 'Incoming student', 'Continuing student', 'Graduating', 'Not currently enrolled'];
const incomeOptions = ['Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const relocationOptions = [
    { value: 'yes', label: 'Yes, if needed' },
    { value: 'no', label: 'No, local only' },
    { value: 'depends', label: 'Depends on the scholarship' },
];
const educationLevelOptions = [
    { value: 'elementary', label: 'Elementary' },
    { value: 'junior_high_school', label: 'Junior High School' },
    { value: 'senior_high_school', label: 'Senior High School' },
    { value: 'college', label: 'College / University' },
    { value: 'tvet', label: 'TVET / Vocational' },
    { value: 'als', label: 'ALS / Alternative Learning' },
    { value: 'other', label: 'Other' },
];
const schoolTypeOptions = [
    { value: 'public', label: 'Public' },
    { value: 'private', label: 'Private' },
    { value: 'state_university', label: 'State university / college' },
    { value: 'local_college', label: 'Local college / university' },
    { value: 'tvet_center', label: 'TVET center' },
    { value: 'als_center', label: 'ALS center' },
    { value: 'other', label: 'Other' },
];
const gradingScaleOptions = [
    { value: 'percentage', label: 'General average / percentage' },
    { value: 'grade_point', label: 'GWA grade point' },
];

const fieldLabels = {
    first_name: 'First name',
    last_name: 'Last name',
    middle_initial: 'Middle initial',
    contact_number: 'Contact number',
    birthdate: 'Birthdate',
    education_level: 'Education level',
    school: 'School / learning institution',
    school_type: 'School type',
    learner_reference_number: 'LRN / student number',
    course_or_strand: 'Track / strand / course',
    year_level: 'Grade / year level',
    enrollment_status: 'Enrollment status',
    gwa: 'GWA / general average',
    grading_scale: 'Grading scale',
    income_bracket: 'Household income bracket',
    household_size: 'Household size',
    preferred_categories: 'Preferred scholarship types',
    preferred_locations: 'Preferred locations',
    willing_to_relocate: 'Willing to relocate',
    support_needs: 'Support needs',
    scholarship_goal: 'Scholarship goal',
    address: 'Address',
    barangay: 'Barangay',
    city: 'City / municipality',
    province: 'Province',
    region: 'Region',
    guardian_name: 'Guardian name',
    guardian_contact: 'Guardian contact',
};
const profileSteps = [
    {
        label: 'Personal',
        detail: 'Identity and contact',
        impact: 'Used to identify your application record.',
        fields: ['first_name', 'middle_initial', 'last_name', 'birthdate', 'contact_number'],
    },
    {
        label: 'Academic',
        detail: 'Education level and school record',
        impact: 'Strongly affects scholarship matching and DSS scoring.',
        fields: ['education_level', 'school', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
    },
    {
        label: 'Address',
        detail: 'Income and home location',
        impact: 'Used for location, income, and distance-based eligibility.',
        fields: ['income_bracket', 'address', 'barangay', 'city', 'province', 'region'],
    },
    {
        label: 'Support',
        detail: 'Guardian and preferences',
        impact: 'Helpful for provider follow-ups and better scholarship recommendations.',
        fields: ['guardian_name', 'guardian_contact'],
    },
];
const requiredProfileFields = profileSteps.flatMap((step) => step.fields);
const matchBoosterFields = ['school_type', 'learner_reference_number', 'household_size', 'preferred_categories', 'preferred_locations', 'willing_to_relocate', 'support_needs', 'scholarship_goal'];
const currentProfileStep = computed(() => profileSteps[currentStep.value]);
const profileFields = computed(() => requiredProfileFields.map((key) => ({
    key,
    label: fieldLabels[key],
    value: form.value[key],
})));
const boosterFields = computed(() => matchBoosterFields.map((key) => ({
    key,
    label: fieldLabels[key],
    value: form.value[key],
})));
const completedProfileFields = computed(() => profileFields.value.filter((field) => hasValue(field.value)).length);
const completedBoosterFields = computed(() => boosterFields.value.filter((field) => hasValue(field.value)).length);
const profileCompletion = computed(() => Math.round((completedProfileFields.value / profileFields.value.length) * 100));
const boosterCompletion = computed(() => Math.round((completedBoosterFields.value / boosterFields.value.length) * 100));
const missingProfileFields = computed(() => profileFields.value.filter((field) => !hasValue(field.value)));
const currentStepMissingFields = computed(() => currentProfileStep.value.fields
    .map((key) => ({ key, label: fieldLabels[key], value: form.value[key] }))
    .filter((field) => !hasValue(field.value)));
const currentStepComplete = computed(() => currentStepMissingFields.value.length === 0);
const isLastStep = computed(() => currentStep.value === profileSteps.length - 1);
const profileMapAddress = computed(() => {
    const parts = [
        form.value.address,
        form.value.barangay,
        form.value.city,
        form.value.province,
        form.value.region,
    ].filter(hasValue);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});

function emptyForm() {
    return {
        first_name: '',
        last_name: '',
        middle_initial: '',
        contact_number: '',
        education_level: '',
        school: '',
        school_type: '',
        learner_reference_number: '',
        course_or_strand: '',
        year_level: '',
        enrollment_status: '',
        gwa: '',
        grading_scale: '',
        income_bracket: '',
        household_size: '',
        preferred_categories: '',
        preferred_locations: '',
        willing_to_relocate: '',
        support_needs: '',
        scholarship_goal: '',
        address: '',
        barangay: '',
        city: '',
        province: '',
        region: '',
        latitude: '',
        longitude: '',
        birthdate: '',
        guardian_name: '',
        guardian_contact: '',
    };
}

function hasValue(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function stepProgress(step) {
    const completed = step.fields.filter((field) => hasValue(form.value[field])).length;

    return {
        completed,
        total: step.fields.length,
        complete: completed === step.fields.length,
    };
}

function canOpenStep(index) {
    return index >= 0 && index < profileSteps.length;
}

function goToStep(index) {
    if (canOpenStep(index)) {
        currentStep.value = index;
        errorMessage.value = '';
    }
}

function nextStep() {
    if (!isLastStep.value) {
        const hadMissingFields = !currentStepComplete.value;

        currentStep.value += 1;
        errorMessage.value = '';
        statusMessage.value = hadMissingFields
            ? 'You can continue now and return to missing details later.'
            : '';
    }
}

function previousStep() {
    if (currentStep.value > 0) {
        currentStep.value -= 1;
        errorMessage.value = '';
    }
}

function gradingScaleLabel(value) {
    return gradingScaleOptions.find((option) => option.value === value)?.label ?? value;
}

function educationLevelLabel(value) {
    return educationLevelOptions.find((option) => option.value === value)?.label ?? value;
}

function schoolTypeLabel(value) {
    return schoolTypeOptions.find((option) => option.value === value)?.label ?? value;
}

const coursePlaceholder = computed(() => {
    if (form.value.education_level === 'senior_high_school') {
        return 'STEM, ABM, HUMSS, TVL...';
    }

    if (form.value.education_level === 'junior_high_school' || form.value.education_level === 'elementary') {
        return 'General / N/A';
    }

    if (form.value.education_level === 'tvet') {
        return 'Cookery, ICT, Automotive...';
    }

    return 'General, STEM, TVL, BSIT...';
});

const yearPlaceholder = computed(() => {
    if (form.value.education_level === 'elementary') {
        return 'Grade 1 to Grade 6';
    }

    if (form.value.education_level === 'junior_high_school') {
        return 'Grade 7 to Grade 10';
    }

    if (form.value.education_level === 'senior_high_school') {
        return 'Grade 11 or Grade 12';
    }

    if (form.value.education_level === 'college') {
        return '1st year, 2nd year, 3rd year...';
    }

    return 'Grade / year level';
});

function fieldDisplayValue(field) {
    if (field.key === 'education_level') {
        return educationLevelLabel(field.value);
    }

    if (field.key === 'school_type') {
        return schoolTypeLabel(field.value);
    }

    if (field.key === 'grading_scale') {
        return gradingScaleLabel(field.value);
    }

    if (field.key === 'willing_to_relocate') {
        return relocationOptions.find((option) => option.value === field.value)?.label ?? field.value;
    }

    return field.value;
}

function fillForm(payload) {
    form.value = {
        first_name: payload?.first_name ?? '',
        last_name: payload?.last_name ?? '',
        middle_initial: payload?.middle_initial ?? '',
        contact_number: payload?.contact_number ?? '',
        education_level: payload?.education_level ?? '',
        school: payload?.school ?? '',
        school_type: payload?.school_type ?? '',
        learner_reference_number: payload?.learner_reference_number ?? '',
        course_or_strand: payload?.course_or_strand ?? '',
        year_level: payload?.year_level ?? '',
        enrollment_status: payload?.enrollment_status ?? '',
        gwa: payload?.gwa ?? '',
        grading_scale: payload?.grading_scale ?? '',
        income_bracket: payload?.income_bracket ?? '',
        household_size: payload?.household_size ?? '',
        preferred_categories: payload?.preferred_categories ?? '',
        preferred_locations: payload?.preferred_locations ?? '',
        willing_to_relocate: payload?.willing_to_relocate ?? '',
        support_needs: payload?.support_needs ?? '',
        scholarship_goal: payload?.scholarship_goal ?? '',
        address: payload?.address ?? '',
        barangay: payload?.barangay ?? '',
        city: payload?.city ?? '',
        province: payload?.province ?? '',
        region: payload?.region ?? '',
        latitude: payload?.latitude ?? '',
        longitude: payload?.longitude ?? '',
        birthdate: payload?.birthdate ?? '',
        guardian_name: payload?.guardian_name ?? '',
        guardian_contact: payload?.guardian_contact ?? '',
    };
}

function handleMiddleInitialInput(event) {
    form.value.middle_initial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handlePhoneInput(key, event) {
    form.value[key] = event.target.value.replace(/[^\d+\s().-]/g, '');
}

function clearProfileMapPoint() {
    form.value.latitude = '';
    form.value.longitude = '';
    locationMessage.value = '';
}

function lookupProfileAddress() {
    if (!profileMapAddress.value) {
        locationMessage.value = 'Enter your address first so the map can search it.';
        return;
    }

    locationMessage.value = 'Searching your address on the map...';
    addressLookupTrigger.value += 1;
}

function handleProfileLocationResolved(location) {
    form.value.latitude = Number(location.latitude).toFixed(7);
    form.value.longitude = Number(location.longitude).toFixed(7);
    locationMessage.value = 'Address found on the map. Save your profile to keep this map point.';
}

function handleProfileLocationPicked(location) {
    const address = location.address ?? {};
    const streetAddress = [
        address.house_number,
        address.road,
    ].filter(Boolean).join(' ');

    form.value.latitude = Number(location.latitude).toFixed(7);
    form.value.longitude = Number(location.longitude).toFixed(7);
    form.value.address = streetAddress || location.displayName || form.value.address;
    form.value.barangay = address.neighbourhood
        || address.suburb
        || address.quarter
        || address.village
        || form.value.barangay;
    form.value.city = address.city
        || address.municipality
        || address.town
        || address.city_district
        || form.value.city;
    form.value.province = address.province
        || address.state
        || address.county
        || form.value.province;
    form.value.region = address.region
        || address.state
        || form.value.region;
    locationMessage.value = location.displayName
        ? 'Pin set. Address fields were filled from the selected map point.'
        : 'Pin set. Save your profile to keep this map point.';
}

function handleProfileLocationError(message) {
    locationMessage.value = message;
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant profile.';
    } finally {
        isLoading.value = false;
    }
}

async function saveProfile(requireComplete = false) {
    if (requireComplete && missingProfileFields.value.length > 0) {
        errorMessage.value = `Complete ${missingProfileFields.value.slice(0, 4).map((field) => field.label).join(', ')}${missingProfileFields.value.length > 4 ? ', and the remaining fields' : ''} before applying.`;
        return;
    }

    isSaving.value = true;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch('/dashboard/profile', form.value);

        user.value = response.data.user;
        fillForm(response.data.user);
        statusMessage.value = requireComplete
            ? 'Profile completed. You can now apply for scholarships.'
            : response.data.message ?? 'Profile progress saved.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update applicant profile.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProfile);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="student-hero">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-2xl">
                            <p class="student-kicker">
                                Profile Setup
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                Set up your student profile
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                You can explore scholarships anytime. Finish these details when you are ready to submit an application.
                            </p>
                        </div>

                        <div class="student-soft-card w-full p-4 lg:max-w-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Profile readiness
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ completedProfileFields }}/{{ profileFields.length }} required details
                                    </p>
                                    <p class="mt-1 text-xs font-semibold text-sky-700">
                                        {{ completedBoosterFields }}/{{ boosterFields.length }} matching boosters added
                                    </p>
                                </div>
                                <p class="font-display text-3xl font-bold text-slate-950">
                                    {{ profileCompletion }}%
                                </p>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${profileCompletion}%` }"></div>
                            </div>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading profile...
                </div>

                <div v-else class="mt-6 space-y-4">
                    <section class="student-card p-5">
                        <div class="grid gap-3 md:grid-cols-4">
                            <button
                                v-for="(step, index) in profileSteps"
                                :key="step.label"
                                type="button"
                                :class="[
                                    'rounded-lg border p-3 text-left transition',
                                    currentStep === index
                                        ? 'border-sky-300 bg-sky-50 shadow-sm'
                                        : stepProgress(step).complete
                                            ? 'border-emerald-200 bg-emerald-50'
                                            : 'border-slate-200 bg-[#f6faf8]',
                                ]"
                                @click="goToStep(index)"
                            >
                                <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                    Step {{ index + 1 }}
                                </span>
                                <span class="mt-1 block font-bold text-slate-950">
                                    {{ step.label }}
                                </span>
                                <span class="mt-1 block text-xs leading-5 text-slate-500">
                                    {{ step.detail }}
                                </span>
                                <span class="mt-1 block text-xs text-slate-500">
                                    {{ stepProgress(step).completed }}/{{ stepProgress(step).total }} complete
                                </span>
                            </button>
                        </div>
                    </section>

                    <section class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                        <div class="student-card p-5">
                            <p class="student-kicker">
                                Matching Guidance
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Complete enough to apply, then improve the match score
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Required fields unlock applications. Optional matching boosters help the finder understand school type and student identifiers when providers use them for review.
                            </p>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                        Required readiness
                                    </p>
                                    <p class="mt-1 text-2xl font-bold text-slate-950">
                                        {{ profileCompletion }}%
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        Needed before final application submission.
                                    </p>
                                </div>
                                <div class="rounded-md border border-sky-100 bg-sky-50 p-3">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-sky-700">
                                        Finder boosters
                                    </p>
                                    <p class="mt-1 text-2xl font-bold text-slate-950">
                                        {{ boosterCompletion }}%
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        Optional details that make matching more accurate.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="student-card p-5">
                            <p class="student-kicker">
                                Current Section
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                {{ currentProfileStep.label }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ currentProfileStep.impact }}
                            </p>
                            <p class="mt-4 rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
                                {{ currentStepMissingFields.length ? `${currentStepMissingFields.length} required detail${currentStepMissingFields.length === 1 ? '' : 's'} missing in this section` : 'This section is ready' }}
                            </p>
                        </div>
                    </section>

                    <section class="student-card p-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="student-kicker">
                                    {{ currentProfileStep.detail }}
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Step {{ currentStep + 1 }}: {{ currentProfileStep.label }}
                                </h3>
                            </div>
                            <p class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                Save anytime
                            </p>
                        </div>

                        <form class="mt-5 grid gap-4" @submit.prevent="saveProfile(true)">
                            <div v-if="currentStep === 0" class="grid gap-4 md:grid-cols-[1fr_5rem_1fr] md:items-end">
                                <div>
                                    <label :class="labelClass" for="profile-first-name">First name</label>
                                    <input id="profile-first-name" v-model="form.first_name" required :class="inputClass">
                                </div>
                                <div>
                                    <label :class="[labelClass, 'md:text-center']" for="profile-middle">M.I.</label>
                                    <input
                                        id="profile-middle"
                                        :value="form.middle_initial"
                                        maxlength="1"
                                        required
                                        :class="compactInputClass"
                                        @input="handleMiddleInitialInput"
                                    >
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-last-name">Last name</label>
                                    <input id="profile-last-name" v-model="form.last_name" required :class="inputClass">
                                </div>
                            </div>

                            <div v-if="currentStep === 0 || currentStep === 1" class="grid gap-4 md:grid-cols-3">
                                <div v-if="currentStep === 0">
                                    <label :class="labelClass" for="profile-contact">Contact number</label>
                                    <input id="profile-contact" :value="form.contact_number" required :class="inputClass" @input="handlePhoneInput('contact_number', $event)">
                                </div>
                                <div v-if="currentStep === 1">
                                    <label :class="labelClass" for="profile-education-level">Education level</label>
                                    <select id="profile-education-level" v-model="form.education_level" :class="inputClass">
                                        <option value="">Select education level</option>
                                        <option
                                            v-for="option in educationLevelOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        This changes the suggested track and grade/year examples below.
                                    </p>
                                </div>
                                <div v-if="currentStep === 1">
                                    <label :class="labelClass" for="profile-grading-scale">Grading scale</label>
                                    <select id="profile-grading-scale" v-model="form.grading_scale" :class="inputClass">
                                        <option value="">Select grading scale</option>
                                        <option
                                            v-for="option in gradingScaleOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="currentStep === 1">
                                    <label :class="labelClass" for="profile-gwa">GWA / general average</label>
                                    <input id="profile-gwa" v-model="form.gwa" type="number" min="0" max="100" step="0.01" placeholder="92.50 or 1.75" :class="inputClass">
                                </div>
                            </div>

                            <div v-if="currentStep === 1" class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="profile-school">School / learning institution</label>
                                    <input id="profile-school" v-model="form.school" placeholder="School or learning center" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-school-type">School type <span class="font-normal text-sky-600">(matching booster)</span></label>
                                    <select id="profile-school-type" v-model="form.school_type" :class="inputClass">
                                        <option value="">Select school type</option>
                                        <option
                                            v-for="option in schoolTypeOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-lrn">LRN / student number <span class="font-normal text-sky-600">(optional)</span></label>
                                    <input id="profile-lrn" v-model="form.learner_reference_number" placeholder="LRN or student ID" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-course">Track / strand / course</label>
                                    <input id="profile-course" v-model="form.course_or_strand" :placeholder="coursePlaceholder" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-year">Grade / year level</label>
                                    <input id="profile-year" v-model="form.year_level" :placeholder="yearPlaceholder" :class="inputClass">
                                </div>
                            </div>

                            <div v-if="currentStep === 1 || currentStep === 2" class="grid gap-4 md:grid-cols-2">
                                <div v-if="currentStep === 1">
                                    <label :class="labelClass" for="profile-enrollment">Enrollment status</label>
                                    <select id="profile-enrollment" v-model="form.enrollment_status" :class="inputClass">
                                        <option value="">Select status</option>
                                        <option
                                            v-for="option in enrollmentOptions"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="currentStep === 2">
                                    <label :class="labelClass" for="profile-income">Household income bracket</label>
                                    <select id="profile-income" v-model="form.income_bracket" :class="inputClass">
                                        <option value="">Select income bracket</option>
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

                            <div v-if="currentStep === 2" class="grid gap-4 md:grid-cols-4">
                                <div class="md:col-span-4">
                                    <label :class="labelClass" for="profile-address">Address</label>
                                    <input id="profile-address" v-model="form.address" placeholder="Home address" :class="inputClass" @input="clearProfileMapPoint">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-barangay">Barangay</label>
                                    <input id="profile-barangay" v-model="form.barangay" placeholder="Barangay" :class="inputClass" @input="clearProfileMapPoint">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-city">City / municipality</label>
                                    <input id="profile-city" v-model="form.city" placeholder="City or municipality" :class="inputClass" @input="clearProfileMapPoint">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-province">Province</label>
                                    <input id="profile-province" v-model="form.province" placeholder="Province" :class="inputClass" @input="clearProfileMapPoint">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-region">Region</label>
                                    <input id="profile-region" v-model="form.region" placeholder="NCR / Region IV-A" :class="inputClass" @input="clearProfileMapPoint">
                                </div>
                            </div>

                            <div v-if="currentStep === 2" class="student-soft-card p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Address map preview
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Search your typed address or click the map to set a pin. The address fields update from the selected pin.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        @click="lookupProfileAddress"
                                    >
                                        Find address on map
                                    </button>
                                </div>

                                <LeafletMapPreview
                                    class="mt-4"
                                    :address="profileMapAddress"
                                    :latitude="form.latitude"
                                    :longitude="form.longitude"
                                    title="Student address map preview"
                                    marker-text="Student address"
                                    :geocode-trigger="addressLookupTrigger"
                                    picker
                                    @resolved="handleProfileLocationResolved"
                                    @picked="handleProfileLocationPicked"
                                    @error="handleProfileLocationError"
                                />

                                <p v-if="locationMessage" class="mt-3 text-xs font-semibold text-slate-700">
                                    {{ locationMessage }}
                                </p>
                            </div>

                            <div v-if="currentStep === 0" class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-birthdate">Birthdate</label>
                                    <input id="profile-birthdate" v-model="form.birthdate" type="date" :class="inputClass">
                                </div>
                            </div>

                            <div v-if="currentStep === 3" class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                    <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                    <input id="profile-guardian-contact" :value="form.guardian_contact" placeholder="Guardian contact number" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                </div>
                            </div>

                            <div v-if="currentStep === 3" class="student-soft-card p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Finder preferences <span class="font-normal text-sky-600">(optional)</span>
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            These details do not block applications. They help the platform suggest better scholarships and help providers understand what support you need.
                                        </p>
                                    </div>
                                    <span class="rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                        Matching boosters
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="profile-household-size">Household size</label>
                                        <input
                                            id="profile-household-size"
                                            v-model="form.household_size"
                                            type="number"
                                            min="1"
                                            max="30"
                                            placeholder="Number of people in household"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="profile-relocate">Willing to relocate</label>
                                        <select id="profile-relocate" v-model="form.willing_to_relocate" :class="inputClass">
                                            <option value="">Select preference</option>
                                            <option
                                                v-for="option in relocationOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="profile-preferred-categories">Preferred scholarship types</label>
                                        <textarea
                                            id="profile-preferred-categories"
                                            v-model="form.preferred_categories"
                                            rows="3"
                                            :placeholder="categoryOptions.join(', ')"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="profile-preferred-locations">Preferred locations</label>
                                        <textarea
                                            id="profile-preferred-locations"
                                            v-model="form.preferred_locations"
                                            rows="3"
                                            placeholder="Example: Manila, Quezon City, online-friendly"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="profile-support-needs">Support needs</label>
                                        <textarea
                                            id="profile-support-needs"
                                            v-model="form.support_needs"
                                            rows="3"
                                            placeholder="Example: tuition, transportation, books, internet, uniform"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="profile-goal">Scholarship goal</label>
                                        <textarea
                                            id="profile-goal"
                                            v-model="form.scholarship_goal"
                                            rows="3"
                                            placeholder="Short goal or reason for applying"
                                            :class="inputClass"
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">{{ statusMessage }}</p>
                                    <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button
                                        type="button"
                                        :disabled="currentStep === 0"
                                        class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        @click="previousStep"
                                    >
                                        Back
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="isSaving"
                                        class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="saveProfile(false)"
                                    >
                                        {{ isSaving ? 'Saving...' : 'Save progress' }}
                                    </button>
                                    <button
                                        v-if="!isLastStep"
                                        type="button"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                        @click="nextStep"
                                    >
                                        Next section
                                    </button>
                                    <button
                                        v-else
                                        type="submit"
                                        :disabled="isSaving || missingProfileFields.length > 0"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                    >
                                        {{ isSaving ? 'Saving...' : 'Complete profile' }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </section>

                    <details class="student-card p-5">
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="student-kicker">
                                        Readiness Checklist
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        View missing profile details
                                    </h3>
                                </div>
                                <p class="text-sm font-semibold text-slate-600">
                                    {{ completedProfileFields }}/{{ profileFields.length }} complete
                                </p>
                            </div>
                        </summary>

                        <div class="mt-4 grid gap-2 md:grid-cols-2">
                            <div
                                v-for="field in profileFields"
                                :key="field.key"
                                class="flex items-center justify-between gap-3 rounded-md bg-[#f6faf8] px-3 py-2.5 text-sm ring-1 ring-slate-200/70"
                            >
                                <span class="font-semibold text-slate-500">
                                    {{ field.label }}
                                </span>
                                <span :class="hasValue(field.value) ? 'font-bold text-slate-950' : 'font-semibold text-rose-600'">
                                    {{ hasValue(field.value) ? fieldDisplayValue(field) : 'Missing' }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-5 rounded-lg border border-sky-100 bg-sky-50 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-bold text-slate-950">
                                        Optional matching boosters
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        These are not required to apply, but they help providers and the finder make better matches.
                                    </p>
                                </div>
                                <p class="text-sm font-bold text-sky-700">
                                    {{ completedBoosterFields }}/{{ boosterFields.length }} added
                                </p>
                            </div>

                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                <div
                                    v-for="field in boosterFields"
                                    :key="field.key"
                                    class="flex items-center justify-between gap-3 rounded-md bg-white px-3 py-2.5 text-sm ring-1 ring-sky-100"
                                >
                                    <span class="font-semibold text-slate-500">
                                        {{ field.label }}
                                    </span>
                                    <span :class="hasValue(field.value) ? 'font-bold text-slate-950' : 'font-semibold text-slate-400'">
                                        {{ hasValue(field.value) ? fieldDisplayValue(field) : 'Optional' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
