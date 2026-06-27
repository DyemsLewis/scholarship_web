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
const activeSection = ref('personal');
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
    { value: 'preschool', label: 'Preschool / Kindergarten' },
    { value: 'elementary', label: 'Elementary' },
    { value: 'junior_high_school', label: 'Junior High School' },
    { value: 'senior_high_school', label: 'Senior High School' },
    { value: 'college', label: 'College / University' },
    { value: 'tvet', label: 'TVET / Vocational' },
    { value: 'als', label: 'ALS / Alternative Learning' },
    { value: 'other', label: 'Other' },
];
const schoolTypeOptions = [
    { value: 'daycare_learning_center', label: 'Daycare / learning center' },
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
    school_type: 'Institution type',
    learner_reference_number: 'Learner / student ID',
    course_or_strand: 'Program path',
    year_level: 'Grade / year / level',
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

const profileSections = [
    {
        id: 'personal',
        label: 'Personal',
        detail: 'Identity and contact',
        impact: 'Used to identify your application record and contact you about updates.',
        required: true,
        fields: ['first_name', 'middle_initial', 'last_name', 'birthdate', 'contact_number'],
        requiredFields: ['first_name', 'middle_initial', 'last_name', 'birthdate', 'contact_number'],
    },
    {
        id: 'academic',
        label: 'Learning',
        detail: 'Education level and school record',
        impact: 'Affects eligibility matching, GWA checks, and DSS scoring.',
        required: true,
        fields: ['education_level', 'school', 'school_type', 'learner_reference_number', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
        requiredFields: ['education_level', 'school', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
    },
    {
        id: 'location',
        label: 'Location and need',
        detail: 'Income, address, and distance matching',
        impact: 'Used for location restrictions, distance visualization, and financial-need scoring.',
        required: true,
        fields: ['income_bracket', 'household_size', 'address', 'barangay', 'city', 'province', 'region'],
        requiredFields: ['income_bracket', 'address', 'barangay', 'city', 'province', 'region'],
    },
    {
        id: 'guardian',
        label: 'Guardian',
        detail: 'Parent or guardian contact',
        impact: 'Useful for programs that need guardian confirmation or follow-up.',
        required: true,
        fields: ['guardian_name', 'guardian_contact'],
        requiredFields: ['guardian_name', 'guardian_contact'],
    },
    {
        id: 'preferences',
        label: 'Finder preferences',
        detail: 'Optional matching boosters',
        impact: 'Helps the finder and providers understand what kind of support fits you best.',
        required: false,
        fields: ['preferred_categories', 'preferred_locations', 'willing_to_relocate', 'support_needs', 'scholarship_goal'],
    },
];

const courseRequiredLevels = ['senior_high_school', 'college', 'tvet'];
const requiresProgramPath = computed(() => courseRequiredLevels.includes(form.value.education_level));
const requiredProfileFields = computed(() => profileSections.flatMap((section) => sectionRequiredFields(section)));
const boosterFields = computed(() => profileSections.flatMap((section) => sectionAllFields(section).filter((field) => !sectionRequiredFields(section).includes(field))));
const requiredFieldData = computed(() => requiredProfileFields.value.map((key) => ({
    key,
    label: fieldLabel(key),
    value: form.value[key],
})));
const boosterFieldData = computed(() => boosterFields.value.map((key) => ({
    key,
    label: fieldLabel(key),
    value: form.value[key],
})));
const completedRequiredFields = computed(() => requiredFieldData.value.filter((field) => hasValue(field.value)).length);
const completedBoosterFields = computed(() => boosterFieldData.value.filter((field) => hasValue(field.value)).length);
const profileCompletion = computed(() => requiredFieldData.value.length === 0 ? 100 : Math.round((completedRequiredFields.value / requiredFieldData.value.length) * 100));
const boosterCompletion = computed(() => boosterFieldData.value.length === 0 ? 100 : Math.round((completedBoosterFields.value / boosterFieldData.value.length) * 100));
const missingProfileFields = computed(() => requiredFieldData.value.filter((field) => !hasValue(field.value)));
const profileComplete = computed(() => missingProfileFields.value.length === 0);
const activeProfileSection = computed(() => profileSections.find((section) => section.id === activeSection.value) ?? profileSections[0]);
const activeSectionIndex = computed(() => profileSections.findIndex((section) => section.id === activeProfileSection.value.id));
const recommendedSection = computed(() => {
    const section = profileSections.find((item) => item.required && sectionProgress(item).complete === false);

    return section?.id ?? 'preferences';
});
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

function sectionAllFields(section) {
    return section.fields.filter((field) => isFieldRelevant(field));
}

function sectionRequiredFields(section) {
    if (!section.required) {
        return [];
    }

    return (section.requiredFields ?? section.fields).filter((field) => isFieldRequired(field));
}

function isFieldRelevant(field) {
    return field !== 'course_or_strand' || requiresProgramPath.value || hasValue(form.value.course_or_strand);
}

function isFieldRequired(field) {
    if (field === 'course_or_strand') {
        return requiresProgramPath.value;
    }

    return true;
}

function sectionProgress(section) {
    const fields = section.required ? sectionRequiredFields(section) : sectionAllFields(section);
    const completed = fields.filter((field) => hasValue(form.value[field])).length;

    return {
        completed,
        total: fields.length,
        complete: fields.length === 0 || completed === fields.length,
        percent: fields.length === 0 ? 100 : Math.round((completed / fields.length) * 100),
    };
}

function sectionMissingFields(section) {
    return sectionRequiredFields(section)
        .filter((field) => !hasValue(form.value[field]))
        .map((field) => fieldLabel(field));
}

function openSection(sectionId) {
    activeSection.value = sectionId;
    errorMessage.value = '';
}

function goToPreviousSection() {
    const previous = profileSections[activeSectionIndex.value - 1];

    if (previous) {
        openSection(previous.id);
    }
}

function goToNextSection() {
    const next = profileSections[activeSectionIndex.value + 1];

    if (next) {
        openSection(next.id);
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

const learnerPathCopy = computed(() => {
    switch (form.value.education_level) {
        case 'preschool':
            return {
                courseLabel: 'Learning focus',
                coursePlaceholder: 'General readiness, early literacy...',
                courseHelp: 'Optional for preschool learners. Use this only if a scholarship asks for a focus area.',
                yearLabel: 'Level',
                yearPlaceholder: 'Kinder 1, Kinder 2...',
                gwaLabel: 'Latest progress rating / average',
                idLabel: 'Learner ID',
                idPlaceholder: 'School learner ID if available',
                summary: 'Use grade or level details that match the learner record from the school or center.',
            };
        case 'elementary':
            return {
                courseLabel: 'Learning focus',
                coursePlaceholder: 'General education / N/A',
                courseHelp: 'Optional for elementary learners because most programs match by grade level and location.',
                yearLabel: 'Grade level',
                yearPlaceholder: 'Grade 1 to Grade 6',
                gwaLabel: 'General average',
                idLabel: 'Learner Reference Number',
                idPlaceholder: 'LRN if available',
                summary: 'Elementary matching usually relies on grade level, school, location, average, and household need.',
            };
        case 'junior_high_school':
            return {
                courseLabel: 'Curriculum / focus',
                coursePlaceholder: 'General curriculum, STE, SPA, sports...',
                courseHelp: 'Optional for JHS unless a provider targets a special curriculum or program.',
                yearLabel: 'Grade level',
                yearPlaceholder: 'Grade 7 to Grade 10',
                gwaLabel: 'General average',
                idLabel: 'Learner Reference Number',
                idPlaceholder: 'LRN if available',
                summary: 'JHS matching should emphasize grade level, curriculum if any, average, location, and support needs.',
            };
        case 'senior_high_school':
            return {
                courseLabel: 'Track / strand',
                coursePlaceholder: 'STEM, ABM, HUMSS, GAS, TVL...',
                courseHelp: 'Required for SHS because many scholarships target specific tracks or strands.',
                yearLabel: 'Grade level',
                yearPlaceholder: 'Grade 11 or Grade 12',
                gwaLabel: 'General average',
                idLabel: 'Learner Reference Number',
                idPlaceholder: 'LRN if available',
                summary: 'SHS matching uses track/strand, grade level, average, location, and financial need.',
            };
        case 'college':
            return {
                courseLabel: 'Course / degree program',
                coursePlaceholder: 'BSIT, BSA, BSED, BS Nursing...',
                courseHelp: 'Required for college because providers often target specific courses or degree groups.',
                yearLabel: 'Year level',
                yearPlaceholder: '1st year, 2nd year, 3rd year...',
                gwaLabel: 'GWA / general average',
                idLabel: 'Student number',
                idPlaceholder: 'College student number',
                summary: 'College matching uses degree program, year level, GWA, school type, location, and need.',
            };
        case 'tvet':
            return {
                courseLabel: 'Training program / qualification',
                coursePlaceholder: 'Cookery NC II, ICT, Automotive...',
                courseHelp: 'Required for TVET because scholarships may be tied to a qualification or training area.',
                yearLabel: 'Training level / batch',
                yearPlaceholder: 'NC II, NC III, first term, batch...',
                gwaLabel: 'Latest average / assessment rating',
                idLabel: 'Trainee number',
                idPlaceholder: 'TESDA or center trainee ID',
                summary: 'TVET matching uses qualification, center, training level, location, and support needs.',
            };
        case 'als':
            return {
                courseLabel: 'ALS program / learning strand',
                coursePlaceholder: 'Basic literacy, A&E elementary, A&E JHS...',
                courseHelp: 'Optional unless a scholarship specifically asks for ALS program type.',
                yearLabel: 'ALS level',
                yearPlaceholder: 'Basic literacy, elementary level, JHS level...',
                gwaLabel: 'Latest assessment rating / average',
                idLabel: 'Learner ID',
                idPlaceholder: 'ALS learner ID if available',
                summary: 'ALS matching should describe the current level, learning center, location, and support needs.',
            };
        default:
            return {
                courseLabel: 'Program path',
                coursePlaceholder: 'Track, strand, course, program, or N/A',
                courseHelp: 'Use the learner path that best matches your school or training record.',
                yearLabel: 'Grade / year / level',
                yearPlaceholder: 'Grade, year level, training level...',
                gwaLabel: 'GWA / general average',
                idLabel: 'Learner / student ID',
                idPlaceholder: 'LRN, student number, or trainee ID',
                summary: 'Choose the learner level first so the profile can show the right fields and matching guidance.',
            };
    }
});

const courseLabel = computed(() => learnerPathCopy.value.courseLabel);
const coursePlaceholder = computed(() => learnerPathCopy.value.coursePlaceholder);
const courseHelpText = computed(() => learnerPathCopy.value.courseHelp);
const yearLabel = computed(() => learnerPathCopy.value.yearLabel);
const yearPlaceholder = computed(() => learnerPathCopy.value.yearPlaceholder);
const gwaLabel = computed(() => learnerPathCopy.value.gwaLabel);
const learnerIdLabel = computed(() => learnerPathCopy.value.idLabel);
const learnerIdPlaceholder = computed(() => learnerPathCopy.value.idPlaceholder);
const academicSummary = computed(() => learnerPathCopy.value.summary);
const yearLevelOptions = computed(() => {
    switch (form.value.education_level) {
        case 'preschool':
            return ['Nursery', 'Kinder 1', 'Kinder 2'];
        case 'elementary':
            return ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
        case 'junior_high_school':
            return ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];
        case 'senior_high_school':
            return ['Grade 11', 'Grade 12'];
        case 'college':
            return ['1st year', '2nd year', '3rd year', '4th year', '5th year', 'Graduating'];
        case 'tvet':
            return ['NC I', 'NC II', 'NC III', 'NC IV', 'First term', 'Second term'];
        case 'als':
            return ['Basic literacy', 'Elementary level', 'Junior high school level'];
        default:
            return [];
    }
});

function fieldLabel(key) {
    if (key === 'course_or_strand') {
        return courseLabel.value;
    }

    if (key === 'year_level') {
        return yearLabel.value;
    }

    if (key === 'gwa') {
        return gwaLabel.value;
    }

    if (key === 'learner_reference_number') {
        return learnerIdLabel.value;
    }

    return fieldLabels[key] ?? key;
}

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
        errorMessage.value = `Add ${missingProfileFields.value.slice(0, 4).map((field) => field.label).join(', ')}${missingProfileFields.value.length > 4 ? ', and the remaining required details' : ''} before completing the profile.`;
        statusMessage.value = '';
        openSection(recommendedSection.value);
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
                                Student Profile
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                Build a learner profile that fits your level
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Works for preschool, elementary, high school, college, TVET, ALS, and other learner paths. Save anytime and add optional finder details when you are ready.
                            </p>
                        </div>

                        <div class="student-soft-card w-full p-4 lg:max-w-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Application readiness
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ completedRequiredFields }}/{{ requiredFieldData.length }} required details
                                    </p>
                                    <p class="mt-1 text-xs font-semibold text-sky-700">
                                        {{ completedBoosterFields }}/{{ boosterFieldData.length }} finder boosters added
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

                <div v-else class="mt-6 grid gap-5 xl:grid-cols-[17rem_minmax(0,1fr)]">
                    <aside class="student-card h-fit p-4 xl:sticky xl:top-5">
                        <p class="student-kicker">
                            Profile Pages
                        </p>
                        <div class="mt-4 grid gap-2">
                            <button
                                v-for="section in profileSections"
                                :key="section.id"
                                type="button"
                                :class="[
                                    'rounded-lg border p-3 text-left transition hover:border-slate-400 hover:bg-white',
                                    activeSection === section.id ? 'border-slate-900 bg-white shadow-sm' : 'border-slate-200 bg-[#f6faf8]',
                                ]"
                                @click="openSection(section.id)"
                            >
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-slate-950">{{ section.label }}</span>
                                    <span
                                        :class="[
                                            'rounded-md px-2 py-0.5 text-xs font-bold',
                                            sectionProgress(section).complete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                                        ]"
                                    >
                                        {{ sectionProgress(section).completed }}/{{ sectionProgress(section).total }}
                                    </span>
                                </span>
                                <span class="mt-1 block text-xs leading-5 text-slate-500">
                                    {{ section.detail }}
                                </span>
                            </button>
                        </div>

                        <button
                            type="button"
                            class="mt-4 w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                            :disabled="isSaving"
                            @click="saveProfile(false)"
                        >
                            {{ isSaving ? 'Saving...' : 'Save profile' }}
                        </button>
                    </aside>

                    <section class="space-y-5">
                        <div v-if="statusMessage || errorMessage" class="student-card p-4">
                            <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">
                                {{ statusMessage }}
                            </p>
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                        </div>

                        <div class="student-card p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <p class="student-kicker">
                                        Page {{ activeSectionIndex + 1 }} of {{ profileSections.length }}
                                    </p>
                                    <h3 class="mt-2 text-2xl font-bold text-slate-950">
                                        {{ activeProfileSection.label }}
                                    </h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ activeProfileSection.impact }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="activeSectionIndex === 0"
                                        @click="goToPreviousSection"
                                    >
                                        Previous page
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="activeSectionIndex === profileSections.length - 1"
                                        @click="goToNextSection"
                                    >
                                        Next page
                                    </button>
                                </div>
                            </div>
                        </div>

                        <section v-if="activeSection === 'personal'" id="profile-personal" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Required Section</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Personal details</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        Basic identity and contact information used in scholarship records.
                                    </p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    {{ sectionProgress(profileSections[0]).percent }}% ready
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-[1fr_5rem_1fr] md:items-end">
                                <div>
                                    <label :class="labelClass" for="profile-first-name">First name</label>
                                    <input id="profile-first-name" v-model="form.first_name" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="[labelClass, 'md:text-center']" for="profile-middle">M.I.</label>
                                    <input
                                        id="profile-middle"
                                        :value="form.middle_initial"
                                        maxlength="1"
                                        :class="compactInputClass"
                                        @input="handleMiddleInitialInput"
                                    >
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-last-name">Last name</label>
                                    <input id="profile-last-name" v-model="form.last_name" :class="inputClass">
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-birthdate">Birthdate</label>
                                    <input id="profile-birthdate" v-model="form.birthdate" type="date" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-contact">Contact number</label>
                                    <input id="profile-contact" :value="form.contact_number" :class="inputClass" @input="handlePhoneInput('contact_number', $event)">
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'academic'" id="profile-academic" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Required Section</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Learning background</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        Select the learner level first so the labels and required fields fit the student record.
                                    </p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    {{ sectionProgress(profileSections[1]).percent }}% ready
                                </span>
                            </div>

                            <div class="student-soft-card mt-5 grid gap-3 p-4 md:grid-cols-[1fr_2fr] md:items-center">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                        Current learner path
                                    </p>
                                    <p class="mt-1 text-lg font-bold text-slate-950">
                                        {{ form.education_level ? educationLevelLabel(form.education_level) : 'Choose education level' }}
                                    </p>
                                </div>
                                <p class="text-sm leading-6 text-slate-600">
                                    {{ academicSummary }}
                                </p>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div>
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
                                </div>
                                <div>
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
                                <div>
                                    <label :class="labelClass" for="profile-school">School / learning institution</label>
                                    <input id="profile-school" v-model="form.school" placeholder="School or learning center" :class="inputClass">
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="profile-course">
                                        {{ courseLabel }}
                                        <span class="font-normal text-sky-600">
                                            {{ requiresProgramPath ? '(required)' : '(optional)' }}
                                        </span>
                                    </label>
                                    <input id="profile-course" v-model="form.course_or_strand" :placeholder="coursePlaceholder" :class="inputClass">
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        {{ courseHelpText }}
                                    </p>
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-year">{{ yearLabel }}</label>
                                    <input id="profile-year" v-model="form.year_level" list="profile-year-options" :placeholder="yearPlaceholder" :class="inputClass">
                                    <datalist id="profile-year-options">
                                        <option
                                            v-for="option in yearLevelOptions"
                                            :key="option"
                                            :value="option"
                                        />
                                    </datalist>
                                </div>
                                <div>
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
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="profile-gwa">{{ gwaLabel }}</label>
                                    <input id="profile-gwa" v-model="form.gwa" type="number" min="0" max="100" step="0.01" placeholder="92.50 or 1.75" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-school-type">Institution type <span class="font-normal text-sky-600">(booster)</span></label>
                                    <select id="profile-school-type" v-model="form.school_type" :class="inputClass">
                                        <option value="">Select institution type</option>
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
                                    <label :class="labelClass" for="profile-lrn">{{ learnerIdLabel }} <span class="font-normal text-sky-600">(optional)</span></label>
                                    <input id="profile-lrn" v-model="form.learner_reference_number" :placeholder="learnerIdPlaceholder" :class="inputClass">
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'location'" id="profile-location" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Required Section</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Location and financial need</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        Used for distance, regional eligibility, and financial assistance matching.
                                    </p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    {{ sectionProgress(profileSections[2]).percent }}% ready
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
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
                                <div>
                                    <label :class="labelClass" for="profile-household-size">Household size <span class="font-normal text-sky-600">(booster)</span></label>
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
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-4">
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

                            <div class="student-soft-card mt-5 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Address map preview
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Search the typed address or click the map to set a pin. This helps visualize distance from scholarships.
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
                        </section>

                        <section v-if="activeSection === 'guardian'" id="profile-guardian" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Required Section</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Guardian information</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        Some scholarships may need guardian contact for verification or follow-up.
                                    </p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    {{ sectionProgress(profileSections[3]).percent }}% ready
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                    <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                    <input id="profile-guardian-contact" :value="form.guardian_contact" placeholder="Guardian contact number" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'preferences'" id="profile-preferences" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Optional Section</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Finder preferences</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        These do not block applications. They help the finder recommend programs that fit your goals and support needs.
                                    </p>
                                </div>
                                <span class="rounded-md bg-sky-50 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-sky-700">
                                    {{ boosterCompletion }}% boosted
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
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
                                    <label :class="labelClass" for="profile-support-needs">Support needs</label>
                                    <textarea
                                        id="profile-support-needs"
                                        v-model="form.support_needs"
                                        rows="3"
                                        placeholder="Example: tuition, transportation, books, internet, uniform"
                                        :class="inputClass"
                                    ></textarea>
                                </div>
                                <div class="md:col-span-2">
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
                        </section>
                    </section>

                    <aside class="space-y-5 xl:col-start-2 xl:grid xl:grid-cols-3 xl:gap-5 xl:space-y-0">
                        <section class="student-card p-5">
                            <p class="student-kicker">
                                Readiness
                            </p>
                            <div class="mt-3 flex items-end justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold text-slate-950">
                                        {{ profileComplete ? 'Ready to apply' : 'Needs a few details' }}
                                    </h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        Required fields unlock application submission. Optional fields improve recommendations.
                                    </p>
                                </div>
                                <p class="font-display text-3xl font-bold text-slate-950">
                                    {{ profileCompletion }}%
                                </p>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${profileCompletion}%` }"></div>
                            </div>

                            <div v-if="missingProfileFields.length" class="mt-4 rounded-lg border border-amber-100 bg-amber-50 p-3">
                                <p class="text-sm font-bold text-amber-900">
                                    Missing required details
                                </p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button
                                        v-for="field in missingProfileFields.slice(0, 8)"
                                        :key="field.key"
                                        type="button"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-amber-800 ring-1 ring-amber-100"
                                        @click="openSection(profileSections.find((section) => sectionAllFields(section).includes(field.key))?.id || 'personal')"
                                    >
                                        {{ field.label }}
                                    </button>
                                </div>
                            </div>

                            <div v-else class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">
                                Required details are complete. You can submit applications.
                            </div>

                            <div class="mt-4 grid gap-2">
                                <button
                                    type="button"
                                    :disabled="isSaving"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="saveProfile(false)"
                                >
                                    {{ isSaving ? 'Saving...' : 'Save changes' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="isSaving || missingProfileFields.length > 0"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                    @click="saveProfile(true)"
                                >
                                    Complete profile
                                </button>
                            </div>
                        </section>

                        <section class="student-card p-5">
                            <p class="student-kicker">
                                Section Health
                            </p>
                            <div class="mt-4 grid gap-3">
                                <div
                                    v-for="section in profileSections"
                                    :key="section.id"
                                    class="rounded-lg border border-slate-200 bg-[#f6faf8] p-3"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-bold text-slate-950">
                                            {{ section.label }}
                                        </p>
                                        <p class="text-xs font-bold text-slate-500">
                                            {{ sectionProgress(section).completed }}/{{ sectionProgress(section).total }}
                                        </p>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="section.required ? 'bg-slate-900' : 'bg-sky-600'"
                                            :style="{ width: `${sectionProgress(section).percent}%` }"
                                        ></div>
                                    </div>
                                    <p v-if="sectionMissingFields(section).length" class="mt-2 text-xs leading-5 text-slate-500">
                                        Missing: {{ sectionMissingFields(section).slice(0, 3).join(', ') }}
                                    </p>
                                    <p v-else class="mt-2 text-xs font-semibold text-emerald-700">
                                        {{ section.required ? 'Ready' : 'Good optional context' }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <details class="student-card p-5">
                            <summary class="cursor-pointer list-none">
                                <p class="student-kicker">Checklist</p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">Required application fields</h3>
                            </summary>

                            <div class="mt-4 grid gap-2">
                                <div
                                    v-for="field in requiredFieldData"
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
                        </details>
                    </aside>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
