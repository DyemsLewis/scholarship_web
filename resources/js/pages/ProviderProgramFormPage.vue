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
const activeFormSection = ref('basics');

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const formSections = [
    { id: 'basics', label: 'Basics', help: 'Name, logo, amount, and status.' },
    { id: 'workflow', label: 'Apply', help: 'How applicants submit and contact you.' },
    { id: 'target', label: 'Target', help: 'Who can match with this program.' },
    { id: 'location', label: 'Location', help: 'Address and map pin.' },
    { id: 'documents', label: 'Docs', help: 'Required files.' },
];
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const incomeOptions = ['Any', 'Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const educationLevelOptions = [
    { value: 'preschool', label: 'Preschool / Kindergarten' },
    { value: 'elementary', label: 'Elementary' },
    { value: 'junior_high_school', label: 'Junior High School' },
    { value: 'senior_high_school', label: 'Senior High School' },
    { value: 'college', label: 'College / University' },
    { value: 'tvet', label: 'TVET / Vocational' },
    { value: 'als', label: 'ALS / Alternative Learning' },
];
const allEducationLevelValues = educationLevelOptions.map((option) => option.value);
const schoolTypeOptions = [
    { value: 'daycare_learning_center', label: 'Daycare / learning center' },
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
const targetApplicantPresets = [
    {
        key: 'all',
        label: 'All learners',
        icon: 'fa-solid fa-people-group',
        description: 'Use this when the program is open to any Filipino learner regardless of level.',
        educationLevels: allEducationLevelValues,
        schoolTypes: [],
        courses: 'Any',
        years: 'Any grade or year level',
        locations: 'Nationwide',
        eligibility: 'Open to Filipino learners who meet the document, academic, location, and income requirements listed by the provider.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Latest report card or grades', 'School ID'],
    },
    {
        key: 'early_basic',
        label: 'Preschool / Elementary',
        icon: 'fa-solid fa-child-reaching',
        description: 'Best for younger learners where a parent or guardian may manage the account.',
        educationLevels: ['preschool', 'elementary'],
        schoolTypes: ['daycare_learning_center', 'public', 'private'],
        courses: 'N/A',
        years: 'Nursery\nKinder 1\nKinder 2\nGrade 1\nGrade 2\nGrade 3\nGrade 4\nGrade 5\nGrade 6',
        locations: 'Nationwide',
        eligibility: 'Open to preschool or elementary learners. A parent or guardian may manage the applicant profile and provide contact information.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Latest report card or grades', 'Birth certificate', 'Parent or guardian valid ID', 'Proof of income'],
    },
    {
        key: 'junior_high',
        label: 'Junior High School',
        icon: 'fa-solid fa-school',
        description: 'For Grade 7 to Grade 10 learners, including general or special curriculum programs.',
        educationLevels: ['junior_high_school'],
        schoolTypes: ['public', 'private'],
        courses: 'Any',
        years: 'Grade 7\nGrade 8\nGrade 9\nGrade 10',
        locations: 'Nationwide',
        eligibility: 'Open to Junior High School learners who meet the provider requirements and maintain the required general average.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Latest report card or grades', 'School ID', 'Proof of income'],
    },
    {
        key: 'senior_high',
        label: 'Senior High School',
        icon: 'fa-solid fa-book-open-reader',
        description: 'For Grade 11 to Grade 12 applicants where track or strand matters.',
        educationLevels: ['senior_high_school'],
        schoolTypes: ['public', 'private'],
        courses: 'STEM\nABM\nHUMSS\nGAS\nTVL',
        years: 'Grade 11\nGrade 12',
        locations: 'Nationwide',
        eligibility: 'Open to Senior High School learners in eligible tracks or strands who meet the academic and document requirements.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Latest report card or grades', 'School ID', 'Proof of income'],
    },
    {
        key: 'college',
        label: 'College / University',
        icon: 'fa-solid fa-user-graduate',
        description: 'For degree program applicants where course and year level are key matching fields.',
        educationLevels: ['college'],
        schoolTypes: ['state_university', 'local_college', 'private'],
        courses: 'Any course',
        years: '1st year\n2nd year\n3rd year\n4th year\n5th year\nGraduating',
        locations: 'Nationwide',
        eligibility: 'Open to college or university students enrolled in eligible degree programs and year levels.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Transcript of records', 'School ID', 'Proof of income'],
    },
    {
        key: 'tvet',
        label: 'TVET / Vocational',
        icon: 'fa-solid fa-screwdriver-wrench',
        description: 'For skills training, qualification, and certification-focused scholarship programs.',
        educationLevels: ['tvet'],
        schoolTypes: ['tvet_center'],
        courses: 'Cookery NC II\nICT\nAutomotive\nElectrical installation\nCaregiving',
        years: 'NC I\nNC II\nNC III\nNC IV\nFirst term\nSecond term',
        locations: 'Nationwide',
        eligibility: 'Open to TVET or vocational learners enrolled in eligible training programs or qualifications.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'School ID', 'Proof of income', 'Good moral certificate'],
    },
    {
        key: 'als',
        label: 'ALS learners',
        icon: 'fa-solid fa-person-chalkboard',
        description: 'For Alternative Learning System learners and non-traditional pathways.',
        educationLevels: ['als'],
        schoolTypes: ['als_center', 'public'],
        courses: 'Any',
        years: 'Basic literacy\nElementary level\nJunior high school level',
        locations: 'Nationwide',
        eligibility: 'Open to ALS learners who can provide enrollment or learning center verification and meet listed requirements.',
        requirements: ['Completed application form', 'Certificate of enrollment', 'Latest report card or grades', 'Birth certificate', 'Proof of income'],
    },
];
const targetFormProfiles = {
    all: {
        key: 'all',
        title: 'All learners form',
        shortLabel: 'all learners',
        icon: 'fa-solid fa-people-group',
        guidance: 'Use broad rules and only add restrictions that truly matter for matching.',
        showProgramPath: true,
        programPathLabel: 'Eligible tracks, strands, courses, or programs',
        programPathPlaceholder: 'Leave blank or use Any when all paths are accepted',
        programPathHelp: 'For open programs, keeping this blank or set to Any prevents false mismatches.',
        programPathTemplate: 'Any',
        levelLabel: 'Eligible grade, year, or training levels',
        levelPlaceholder: 'Example: Any grade or year level',
        levelTemplate: 'Any grade or year level',
        averageLabel: 'Minimum GWA / general average',
        averagePlaceholder: 'Example: 85',
        averageHelp: 'Use the same scale students will enter in their profile.',
        schoolTypeValues: null,
        notes: ['Best for wide public calls', 'Keep restrictions minimal', 'Good for discovery and matching'],
        emptyPathSummary: 'Any track, strand, course, or program',
        emptyLevelSummary: 'Any grade, year, or training level',
    },
    early_basic: {
        key: 'early_basic',
        title: 'Preschool / Elementary form',
        shortLabel: 'preschool or elementary learners',
        icon: 'fa-solid fa-child-reaching',
        guidance: 'Focus on grade level, guardian documents, location, and school type. Course or strand is not needed here.',
        showProgramPath: false,
        programPathLabel: 'Course or strand',
        programPathPlaceholder: '',
        programPathHelp: 'Hidden for younger learners because they do not have a college course or SHS strand.',
        programPathTemplate: 'N/A',
        levelLabel: 'Eligible grade levels',
        levelPlaceholder: 'Example: Kinder 2, Grade 1, Grade 2',
        levelTemplate: 'Nursery\nKinder 1\nKinder 2\nGrade 1\nGrade 2\nGrade 3\nGrade 4\nGrade 5\nGrade 6',
        averageLabel: 'Minimum general average',
        averagePlaceholder: 'Example: 85',
        averageHelp: 'Use report-card average if the scholarship requires grades.',
        schoolTypeValues: ['daycare_learning_center', 'public', 'private'],
        notes: ['No college course field', 'Guardian documents are common', 'Grade level matters most'],
        emptyPathSummary: 'No course or strand required',
        emptyLevelSummary: 'Any preschool or elementary level',
    },
    junior_high: {
        key: 'junior_high',
        title: 'Junior High School form',
        shortLabel: 'junior high school learners',
        icon: 'fa-solid fa-school',
        guidance: 'Use grade level first. Add curriculum or special program only when the scholarship is limited to one.',
        showProgramPath: true,
        programPathLabel: 'Curriculum or special program',
        programPathPlaceholder: 'Optional: STE, SPA, sports program, general curriculum',
        programPathHelp: 'Use Any when the program accepts all Junior High School curricula.',
        programPathTemplate: 'Any',
        levelLabel: 'Eligible grade levels',
        levelPlaceholder: 'Example: Grade 7, Grade 8, Grade 9, Grade 10',
        levelTemplate: 'Grade 7\nGrade 8\nGrade 9\nGrade 10',
        averageLabel: 'Minimum general average',
        averagePlaceholder: 'Example: 85',
        averageHelp: 'Use the learner report-card average.',
        schoolTypeValues: ['public', 'private'],
        notes: ['Grade 7-10 focused', 'Curriculum is optional', 'Good for report-card based matching'],
        emptyPathSummary: 'Any Junior High School curriculum',
        emptyLevelSummary: 'Any Junior High School grade level',
    },
    senior_high: {
        key: 'senior_high',
        title: 'Senior High School form',
        shortLabel: 'senior high school learners',
        icon: 'fa-solid fa-book-open-reader',
        guidance: 'Track and strand are useful here because many SHS scholarships target STEM, ABM, HUMSS, GAS, or TVL.',
        showProgramPath: true,
        programPathLabel: 'Eligible tracks or strands',
        programPathPlaceholder: 'Example: STEM, ABM, HUMSS, GAS, TVL',
        programPathHelp: 'List one track or strand per line when the program is specific.',
        programPathTemplate: 'STEM\nABM\nHUMSS\nGAS\nTVL',
        levelLabel: 'Eligible SHS grade levels',
        levelPlaceholder: 'Example: Grade 11, Grade 12',
        levelTemplate: 'Grade 11\nGrade 12',
        averageLabel: 'Minimum general average',
        averagePlaceholder: 'Example: 85',
        averageHelp: 'Use the senior high report-card average.',
        schoolTypeValues: ['public', 'private'],
        notes: ['Track or strand can matter', 'Grade 11-12 focused', 'Useful for STEM/TVL targeting'],
        emptyPathSummary: 'Any SHS track or strand',
        emptyLevelSummary: 'Any Senior High School level',
    },
    college: {
        key: 'college',
        title: 'College / University form',
        shortLabel: 'college or university students',
        icon: 'fa-solid fa-user-graduate',
        guidance: 'Course and year level are important matching fields for college scholarships.',
        showProgramPath: true,
        programPathLabel: 'Eligible courses or degree programs',
        programPathPlaceholder: 'Example: BSIT, BSED, Engineering, Accountancy',
        programPathHelp: 'Use Any course when the scholarship is not course-specific.',
        programPathTemplate: 'Any course',
        levelLabel: 'Eligible college year levels',
        levelPlaceholder: 'Example: 1st year, 2nd year, Graduating',
        levelTemplate: '1st year\n2nd year\n3rd year\n4th year\n5th year\nGraduating',
        averageLabel: 'Minimum GWA / general average',
        averagePlaceholder: 'Example: 85 or 2.00',
        averageHelp: 'If using grade-point GWA, explain the scale in eligibility details.',
        schoolTypeValues: ['state_university', 'local_college', 'private'],
        notes: ['Course matching is useful', 'Year level matters', 'Transcript is usually required'],
        emptyPathSummary: 'Any college course',
        emptyLevelSummary: 'Any college year level',
    },
    tvet: {
        key: 'tvet',
        title: 'TVET / Vocational form',
        shortLabel: 'TVET or vocational learners',
        icon: 'fa-solid fa-screwdriver-wrench',
        guidance: 'Target the training qualification, certification level, or term instead of college course/year wording.',
        showProgramPath: true,
        programPathLabel: 'Eligible qualifications or training programs',
        programPathPlaceholder: 'Example: Cookery NC II, ICT, Automotive, Caregiving',
        programPathHelp: 'List qualification names or training programs accepted by the provider.',
        programPathTemplate: 'Cookery NC II\nICT\nAutomotive\nElectrical installation\nCaregiving',
        levelLabel: 'Eligible certification or training level',
        levelPlaceholder: 'Example: NC I, NC II, First term',
        levelTemplate: 'NC I\nNC II\nNC III\nNC IV\nFirst term\nSecond term',
        averageLabel: 'Minimum average or competency rating',
        averagePlaceholder: 'Optional',
        averageHelp: 'Leave blank if the scholarship is based on enrollment or certification readiness instead.',
        schoolTypeValues: ['tvet_center'],
        notes: ['Qualification matters', 'Uses training-level wording', 'Good for skills-based programs'],
        emptyPathSummary: 'Any TVET qualification',
        emptyLevelSummary: 'Any training level',
    },
    als: {
        key: 'als',
        title: 'ALS learner form',
        shortLabel: 'ALS learners',
        icon: 'fa-solid fa-person-chalkboard',
        guidance: 'Focus on ALS level, learning center verification, location, and support needs.',
        showProgramPath: false,
        programPathLabel: 'Course or strand',
        programPathPlaceholder: '',
        programPathHelp: 'Hidden for ALS because matching should use ALS level instead of college course wording.',
        programPathTemplate: 'N/A',
        levelLabel: 'Eligible ALS levels',
        levelPlaceholder: 'Example: Basic literacy, Elementary level, Junior high school level',
        levelTemplate: 'Basic literacy\nElementary level\nJunior high school level',
        averageLabel: 'Minimum assessment score or average',
        averagePlaceholder: 'Optional',
        averageHelp: 'Leave blank when assessment score is not required.',
        schoolTypeValues: ['als_center', 'public'],
        notes: ['No course field needed', 'Learning center proof matters', 'Works for non-traditional pathways'],
        emptyPathSummary: 'No course or strand required',
        emptyLevelSummary: 'Any ALS level',
    },
    mixed: {
        key: 'mixed',
        title: 'Mixed target form',
        shortLabel: 'selected learner groups',
        icon: 'fa-solid fa-layer-group',
        guidance: 'You selected multiple learner groups. Keep labels broad and only restrict fields that apply to every selected group.',
        showProgramPath: true,
        programPathLabel: 'Eligible path, strand, course, or program',
        programPathPlaceholder: 'Example: Any, STEM, BSIT, Cookery NC II',
        programPathHelp: 'Use Any when the selected groups do not share one common path field.',
        programPathTemplate: 'Any',
        levelLabel: 'Eligible grade, year, or training levels',
        levelPlaceholder: 'Example: Grade 12, 1st year, NC II',
        levelTemplate: '',
        averageLabel: 'Minimum GWA / general average',
        averagePlaceholder: 'Example: 85',
        averageHelp: 'Use eligibility notes when different groups use different grade scales.',
        schoolTypeValues: null,
        notes: ['Mixed target', 'Use broad wording', 'Avoid over-restricting matches'],
        emptyPathSummary: 'Any applicable path',
        emptyLevelSummary: 'Any applicable level',
    },
};

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
const activeFormSectionIndex = computed(() => formSections.findIndex((section) => section.id === activeFormSection.value));
const activeFormSectionMeta = computed(() => formSections[activeFormSectionIndex.value] ?? formSections[0]);
const formSectionProgress = computed(() => {
    const sectionChecks = {
        basics: hasText(scholarshipForm.value.title)
            && hasText(scholarshipForm.value.description)
            && hasText(scholarshipForm.value.awardAmount)
            && hasText(scholarshipForm.value.deadline),
        workflow: hasText(scholarshipForm.value.applicationMode)
            && (hasText(scholarshipForm.value.contactEmail) || hasText(scholarshipForm.value.contactNumber)),
        target: hasText(scholarshipForm.value.eligibility)
            && (
                scholarshipForm.value.eligibleEducationLevels.length > 0
                || hasText(scholarshipForm.value.eligibleCourses)
                || scholarshipForm.value.eligibleSchoolTypes.length > 0
                || hasText(scholarshipForm.value.eligibleYearLevels)
                || hasText(scholarshipForm.value.eligibleLocations)
            ),
        location: hasText(scholarshipForm.value.locationName)
            && hasText(scholarshipForm.value.locationAddress)
            && hasText(scholarshipForm.value.latitude)
            && hasText(scholarshipForm.value.longitude),
        documents: selectedRequirementCount.value > 0,
    };

    return Object.fromEntries(formSections.map((section) => [section.id, Boolean(sectionChecks[section.id])]));
});
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
const activeTargetKey = computed(() => inferTargetFormKey(scholarshipForm.value.eligibleEducationLevels));
const activeTargetForm = computed(() => targetFormProfiles[activeTargetKey.value] ?? targetFormProfiles.mixed);
const targetSchoolTypeOptions = computed(() => {
    const values = activeTargetForm.value.schoolTypeValues;

    if (!Array.isArray(values)) {
        return schoolTypeOptions;
    }

    return schoolTypeOptions.filter((option) => values.includes(option.value));
});
const hiddenSelectedSchoolTypeLabels = computed(() => {
    const visibleValues = new Set(targetSchoolTypeOptions.value.map((option) => option.value));
    const hiddenValues = scholarshipForm.value.eligibleSchoolTypes.filter((value) => !visibleValues.has(value));

    return optionLabels(hiddenValues, schoolTypeOptions);
});
const targetApplicantSummary = computed(() => {
    const educationLabels = optionLabels(scholarshipForm.value.eligibleEducationLevels, educationLevelOptions);
    const schoolTypeLabels = optionLabels(scholarshipForm.value.eligibleSchoolTypes, schoolTypeOptions);
    const targetForm = activeTargetForm.value;

    return [
        {
            label: 'Learner levels',
            value: educationLabels.length ? educationLabels.join(', ') : 'Open to all education levels',
        },
        {
            label: 'School types',
            value: schoolTypeLabels.length ? schoolTypeLabels.join(', ') : 'Open to all institution types',
        },
        {
            label: targetForm.showProgramPath ? 'Program path' : 'Course / strand',
            value: hasText(scholarshipForm.value.eligibleCourses) ? scholarshipForm.value.eligibleCourses.replace(/\n/g, ', ') : targetForm.emptyPathSummary,
        },
        {
            label: targetForm.levelLabel,
            value: hasText(scholarshipForm.value.eligibleYearLevels) ? scholarshipForm.value.eligibleYearLevels.replace(/\n/g, ', ') : targetForm.emptyLevelSummary,
        },
    ];
});
const workflowSummary = computed(() => [
    scholarshipForm.value.applicationMode
        ? applicationModeOptions.find((option) => option.value === scholarshipForm.value.applicationMode)?.label ?? scholarshipForm.value.applicationMode
        : 'Application mode not set',
    hasText(scholarshipForm.value.slotsAvailable) ? `${scholarshipForm.value.slotsAvailable} available slot${Number(scholarshipForm.value.slotsAvailable) === 1 ? '' : 's'}` : 'Slots not listed',
    hasText(scholarshipForm.value.contactEmail) || hasText(scholarshipForm.value.contactNumber) ? 'Contact available' : 'No contact channel',
]);

function openFormSection(sectionId) {
    activeFormSection.value = sectionId;
}

function goToPreviousFormSection() {
    const previous = formSections[activeFormSectionIndex.value - 1];

    if (previous) {
        openFormSection(previous.id);
    }
}

function goToNextFormSection() {
    const next = formSections[activeFormSectionIndex.value + 1];

    if (next) {
        openFormSection(next.id);
    }
}

function hasText(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function optionLabels(values, options) {
    return values
        .map((value) => options.find((option) => option.value === value)?.label ?? value)
        .filter(Boolean);
}

function hasSameMembers(values, expectedValues) {
    return values.length === expectedValues.length
        && expectedValues.every((value) => values.includes(value));
}

function onlyContains(values, allowedValues) {
    return values.length > 0 && values.every((value) => allowedValues.includes(value));
}

function inferTargetFormKey(educationLevels) {
    const selectedLevels = [...new Set(educationLevels.filter(Boolean))];

    if (selectedLevels.length === 0 || hasSameMembers(selectedLevels, allEducationLevelValues)) {
        return 'all';
    }

    if (onlyContains(selectedLevels, ['preschool', 'elementary'])) {
        return 'early_basic';
    }

    if (hasSameMembers(selectedLevels, ['junior_high_school'])) {
        return 'junior_high';
    }

    if (hasSameMembers(selectedLevels, ['senior_high_school'])) {
        return 'senior_high';
    }

    if (hasSameMembers(selectedLevels, ['college'])) {
        return 'college';
    }

    if (hasSameMembers(selectedLevels, ['tvet'])) {
        return 'tvet';
    }

    if (hasSameMembers(selectedLevels, ['als'])) {
        return 'als';
    }

    return 'mixed';
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
    scholarshipForm.value.eligibleEducationLevels = [...allEducationLevelValues];
    scholarshipForm.value.eligibleSchoolTypes = [];
    scholarshipForm.value.eligibleCourses = 'Any';
    scholarshipForm.value.eligibleYearLevels = 'Any grade or year level';
    scholarshipForm.value.eligibleLocations = 'Nationwide';
    scholarshipForm.value.incomeRequirement = 'Any';
}

function applyActiveTargetDefaults() {
    const targetForm = activeTargetForm.value;

    if (targetForm.programPathTemplate !== undefined) {
        scholarshipForm.value.eligibleCourses = targetForm.programPathTemplate;
    }

    if (targetForm.levelTemplate) {
        scholarshipForm.value.eligibleYearLevels = targetForm.levelTemplate;
    }

    if (Array.isArray(targetForm.schoolTypeValues)) {
        scholarshipForm.value.eligibleSchoolTypes = [...targetForm.schoolTypeValues];
    }
}

function clearHiddenSchoolTypes() {
    const visibleValues = new Set(targetSchoolTypeOptions.value.map((option) => option.value));

    scholarshipForm.value.eligibleSchoolTypes = scholarshipForm.value.eligibleSchoolTypes
        .filter((value) => visibleValues.has(value));
}

function applyTargetApplicantPreset(preset) {
    scholarshipForm.value.eligibleEducationLevels = [...preset.educationLevels];
    scholarshipForm.value.eligibleSchoolTypes = [...preset.schoolTypes];
    scholarshipForm.value.eligibleCourses = preset.courses;
    scholarshipForm.value.eligibleYearLevels = preset.years;
    scholarshipForm.value.eligibleLocations = preset.locations;
    scholarshipForm.value.eligibility = preset.eligibility;
    scholarshipForm.value.requirements = preset.requirements
        .filter((requirement) => documentRequirementOptions.includes(requirement));

    if (!scholarshipForm.value.description) {
        scholarshipForm.value.description = `A scholarship assistance program for ${preset.label.toLowerCase()}. Review the target applicant rules, prepare documents, and submit before the deadline.`;
    }

    if (!scholarshipForm.value.applicationMode) {
        scholarshipForm.value.applicationMode = 'online';
    }
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

    if (!hasText(scholarshipForm.value.title) || !hasText(scholarshipForm.value.description)) {
        activeFormSection.value = 'basics';
        formError.value = 'Add a scholarship title and description before saving.';
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
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Program Form
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit scholarship program' : 'Create scholarship program' }}
                            </h2>
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
                        novalidate
                        @submit.prevent="saveScholarship"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                    {{ isEditMode ? 'Edit Scholarship' : 'Create Scholarship' }}
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    {{ activeFormSectionMeta.label }}
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ activeFormSectionMeta.help }}
                                </p>
                            </div>

                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 lg:min-w-44">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                    Readiness
                                </p>
                                <p class="mt-1 font-display text-2xl font-bold text-slate-950">
                                    {{ programReadiness }}%
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-[0.85fr_1.15fr]">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                            Sections
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ completedProgramReadinessItems }}/{{ programReadinessItems.length }} sections ready
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${programReadiness}%` }"></div>
                                </div>
                                <div class="mt-4 grid gap-2">
                                    <button
                                        v-for="section in formSections"
                                        :key="section.id"
                                        type="button"
                                        :class="[
                                            'flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm transition',
                                            activeFormSection === section.id
                                                ? 'border-slate-900 bg-white text-slate-950 shadow-sm'
                                                : 'border-slate-200 bg-white/70 text-slate-600 hover:bg-white',
                                        ]"
                                        @click="openFormSection(section.id)"
                                    >
                                        <span class="font-bold">{{ section.label }}</span>
                                        <span :class="['h-2.5 w-2.5 rounded-full', formSectionProgress[section.id] ? 'bg-emerald-500' : 'bg-amber-400']"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="rounded-lg border border-emerald-100 bg-emerald-50/70 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">
                                            Quick setup
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

                        <div v-show="activeFormSection === 'basics'" class="mt-5 grid gap-4">
                            <div>
                                <label :class="labelClass" for="scholarship-title">
                                    Scholarship title
                                </label>
                                <input
                                    id="scholarship-title"
                                    v-model="scholarshipForm.title"
                                    type="text"
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
                                        {{ activeTargetForm.averageLabel }}
                                    </label>
                                    <input
                                        id="scholarship-minimum-gwa"
                                        v-model="scholarshipForm.minimumGwa"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        :placeholder="activeTargetForm.averagePlaceholder"
                                        :class="inputClass"
                                    >
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ activeTargetForm.averageHelp }}
                                    </p>
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
                                    rows="4"
                                    placeholder="Describe the scholarship program"
                                    :class="inputClass"
                                ></textarea>
                            </div>
                        </div>

                        <div v-show="activeFormSection === 'target'" class="mt-5">
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
                        </div>

                            <fieldset v-show="activeFormSection === 'workflow'" class="mt-5 rounded-lg border border-amber-100 bg-amber-50/60 p-4">
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

                                <div class="mt-5 rounded-lg border border-emerald-100 bg-white p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">
                                                Target applicant presets
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                Pick the closest target group first. The form will fill matching rules and recommended documents, then you can edit anything below.
                                            </p>
                                        </div>
                                        <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800 ring-1 ring-emerald-100">
                                            Optional helper
                                        </span>
                                    </div>

                                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        <button
                                            v-for="preset in targetApplicantPresets"
                                            :key="preset.key"
                                            type="button"
                                            :class="[
                                                'group rounded-lg border p-3 text-left transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-white hover:shadow-sm',
                                                activeTargetKey === preset.key
                                                    ? 'border-emerald-500 bg-white shadow-sm ring-2 ring-emerald-100'
                                                    : 'border-slate-200 bg-slate-50',
                                            ]"
                                            @click="applyTargetApplicantPreset(preset)"
                                        >
                                            <span class="flex items-center gap-3">
                                                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-emerald-100 text-emerald-800 transition group-hover:bg-emerald-700 group-hover:text-white">
                                                    <i :class="[preset.icon, 'text-sm']"></i>
                                                </span>
                                                <span class="font-bold text-slate-950">
                                                    {{ preset.label }}
                                                </span>
                                            </span>
                                            <span class="mt-2 block text-xs leading-5 text-slate-500">
                                                {{ preset.description }}
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="text-sm font-bold text-slate-950">
                                        Current target applicant summary
                                    </p>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <div
                                            v-for="item in targetApplicantSummary"
                                            :key="item.label"
                                            class="rounded-md bg-[#f6faf8] p-3 text-sm ring-1 ring-slate-200/80"
                                        >
                                            <p class="font-semibold text-slate-500">
                                                {{ item.label }}
                                            </p>
                                            <p class="mt-1 line-clamp-2 font-bold text-slate-900">
                                                {{ item.value }}
                                            </p>
                                        </div>
                                    </div>
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

                            <fieldset v-show="activeFormSection === 'target'" class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50/60 p-4">
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

                                <div class="mt-4 rounded-lg border border-emerald-200 bg-white p-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div class="flex gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-emerald-100 text-emerald-800">
                                                <i :class="[activeTargetForm.icon, 'text-sm']"></i>
                                            </span>
                                            <div>
                                                <p class="text-sm font-bold text-slate-950">
                                                    {{ activeTargetForm.title }}
                                                </p>
                                                <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                                    {{ activeTargetForm.guidance }}
                                                </p>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 transition hover:bg-emerald-100"
                                            @click="applyActiveTargetDefaults"
                                        >
                                            Use target defaults
                                        </button>
                                    </div>

                                    <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                        <div
                                            v-for="note in activeTargetForm.notes"
                                            :key="note"
                                            class="rounded-md bg-[#f6faf8] px-3 py-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200/80"
                                        >
                                            {{ note }}
                                        </div>
                                    </div>
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

                                    <div v-if="activeTargetForm.showProgramPath">
                                        <label :class="labelClass" for="scholarship-courses">
                                            {{ activeTargetForm.programPathLabel }}
                                        </label>
                                        <textarea
                                            id="scholarship-courses"
                                            v-model="scholarshipForm.eligibleCourses"
                                            rows="3"
                                            :placeholder="activeTargetForm.programPathPlaceholder"
                                            :class="inputClass"
                                        ></textarea>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            {{ activeTargetForm.programPathHelp }}
                                        </p>
                                    </div>

                                    <div v-else class="rounded-md border border-slate-200 bg-white p-3">
                                        <p class="text-sm font-semibold text-slate-700">
                                            {{ activeTargetForm.programPathLabel }}
                                        </p>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            {{ activeTargetForm.programPathHelp }}
                                        </p>
                                        <button
                                            type="button"
                                            class="mt-3 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                            @click="scholarshipForm.eligibleCourses = activeTargetForm.programPathTemplate"
                                        >
                                            Mark as not applicable
                                        </button>
                                    </div>

                                    <div class="rounded-md border border-emerald-100 bg-white p-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <label class="text-sm font-semibold text-slate-700">
                                                Eligible school types
                                            </label>
                                            <div class="flex gap-2">
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="selectAllOptions('eligibleSchoolTypes', targetSchoolTypeOptions)">
                                                    Select all
                                                </button>
                                                <button type="button" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="scholarshipForm.eligibleSchoolTypes = []">
                                                    Open to all
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                v-for="option in targetSchoolTypeOptions"
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
                                        <div
                                            v-if="hiddenSelectedSchoolTypeLabels.length"
                                            class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900"
                                        >
                                            <p class="font-bold">
                                                Hidden selections from another target form
                                            </p>
                                            <p class="mt-1">
                                                {{ hiddenSelectedSchoolTypeLabels.join(', ') }}
                                            </p>
                                            <button
                                                type="button"
                                                class="mt-2 font-bold text-amber-950 underline"
                                                @click="clearHiddenSchoolTypes"
                                            >
                                                Remove hidden school types
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label :class="labelClass" for="scholarship-years">
                                            {{ activeTargetForm.levelLabel }}
                                        </label>
                                        <textarea
                                            id="scholarship-years"
                                            v-model="scholarshipForm.eligibleYearLevels"
                                            rows="3"
                                            :placeholder="activeTargetForm.levelPlaceholder"
                                            :class="inputClass"
                                        ></textarea>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            Use one accepted level per line for cleaner matching.
                                        </p>
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

                            <fieldset v-show="activeFormSection === 'location'" class="mt-5 rounded-lg border border-sky-100 bg-sky-50/60 p-4">
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

                            <fieldset v-show="activeFormSection === 'documents'" class="mt-5 rounded-lg border border-slate-200 bg-white p-4">
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

                        <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-h-5">
                                <p v-if="formMessage" class="text-sm font-semibold text-emerald-700">
                                    {{ formMessage }}
                                </p>
                                <p v-if="formError" class="text-sm font-semibold text-rose-700">
                                    {{ formError }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <button
                                    type="button"
                                    :disabled="activeFormSectionIndex === 0"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="goToPreviousFormSection"
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    :disabled="activeFormSectionIndex === formSections.length - 1"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="goToNextFormSection"
                                >
                                    Next
                                </button>
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
