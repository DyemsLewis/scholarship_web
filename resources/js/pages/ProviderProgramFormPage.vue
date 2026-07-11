<script setup>
import { computed, onMounted, ref } from 'vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';

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
const inputClass = 'w-full min-w-0 rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const sectionCardClass = 'rounded-lg border border-slate-200 bg-slate-50 p-4 sm:p-5';
const fieldCardClass = 'min-w-0 rounded-lg border border-slate-200 bg-white p-4 shadow-sm';
const fieldStackClass = `${fieldCardClass} flex flex-col`;
const basicFieldStackClass = `${fieldStackClass} min-h-32`;
const wideFieldStackClass = `${fieldStackClass} xl:col-span-2`;
const formGridClass = 'grid items-stretch gap-4 md:grid-cols-2';
const formSections = [
    { id: 'basics', label: 'Basics', help: 'Name, logo, amount, and review action.' },
    { id: 'workflow', label: 'Apply', help: 'Submission, contact, renewal, and service contract.' },
    { id: 'target', label: 'Target', help: 'Who can match with this program.' },
    { id: 'location', label: 'Location', help: 'Address and map pin.' },
    { id: 'documents', label: 'Docs', help: 'Required files.' },
    { id: 'rubric', label: 'Review', help: 'Consistent provider scoring criteria.' },
];
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const incomeOptions = ['Any', 'Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const gradeScaleOptions = [
    {
        value: '',
        label: 'No academic minimum',
        inputLabel: 'Academic requirement',
        placeholder: '',
        help: 'Use this when grades are not part of the eligibility rules.',
    },
    {
        value: 'percentage',
        label: 'General average / percentage',
        inputLabel: 'Minimum average',
        placeholder: 'Example: 85',
        help: 'Higher values meet the requirement, such as 85 or above.',
    },
    {
        value: 'grade_point',
        label: 'GWA / GPA grade point',
        inputLabel: 'Maximum GWA / GPA',
        placeholder: 'Example: 2.00',
        help: 'Lower values meet the requirement, such as 2.00 or better.',
    },
    {
        value: 'pass_fail',
        label: 'Pass/fail or competency based',
        inputLabel: 'Document-based review',
        placeholder: '',
        help: 'Use this for competency, pass/fail, or certification-based programs.',
    },
    {
        value: 'other',
        label: 'Other grading scale / manual review',
        inputLabel: 'Manual academic review',
        placeholder: '',
        help: 'Use eligibility details to explain the scale reviewers should check.',
    },
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

const customDocumentRequirements = computed(() => splitRequirementText(scholarshipForm.value.customRequirements)
    .filter((requirement) => !documentRequirementOptions.includes(requirement)));
const allDocumentRequirements = computed(() => [...new Set([
    ...scholarshipForm.value.requirements,
    ...customDocumentRequirements.value,
])]);
const selectedRequirementCount = computed(() => allDocumentRequirements.value.length);
const rubricWeightTotal = computed(() => scholarshipForm.value.reviewRubric
    .reduce((total, criterion) => total + Number(criterion.weight || 0), 0));
const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const scholarshipImagePreview = computed(() => imagePreviewUrl.value || scholarshipForm.value.imageUrl || '/uploads/scholarship-default.jpg');
const scholarshipFormMapAddress = computed(() => {
    const parts = [
        scholarshipForm.value.locationName,
        scholarshipForm.value.locationAddress,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});
const selectedGradeScaleOption = computed(() => gradeScaleOptions.find((option) => option.value === scholarshipForm.value.minimumGradeScale) ?? gradeScaleOptions[0]);
const academicRequirementNeedsValue = computed(() => ['percentage', 'grade_point'].includes(scholarshipForm.value.minimumGradeScale));
const academicRequirementSummary = computed(() => {
    if (scholarshipForm.value.minimumGradeScale === 'pass_fail') {
        return 'Pass/fail or competency';
    }

    if (scholarshipForm.value.minimumGradeScale === 'other') {
        return 'Manual academic review';
    }

    if (!academicRequirementNeedsValue.value || !hasText(scholarshipForm.value.minimumGwa)) {
        return 'No academic minimum';
    }

    return scholarshipForm.value.minimumGradeScale === 'grade_point'
        ? `Max GWA/GPA ${scholarshipForm.value.minimumGwa}`
        : `Min average ${scholarshipForm.value.minimumGwa}%`;
});
const academicRequirementInputMax = computed(() => scholarshipForm.value.minimumGradeScale === 'grade_point' ? 5 : 100);
const academicRequirementInputStep = computed(() => scholarshipForm.value.minimumGradeScale === 'grade_point' ? '0.01' : '0.01');
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
                || scholarshipForm.value.minimumGradeScale === 'pass_fail'
                || scholarshipForm.value.minimumGradeScale === 'other'
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
    {
        label: 'Return service contract',
        complete: hasText(scholarshipForm.value.returnServiceContract),
        help: 'Any required service obligation, teaching placement, or post-award commitment.',
    },
]);
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
        rubric: scholarshipForm.value.reviewRubric.length > 0 && rubricWeightTotal.value === 100,
    };

    return Object.fromEntries(formSections.map((section) => [section.id, Boolean(sectionChecks[section.id])]));
});
const publishWarnings = computed(() => {
    if (!['pending_review', 'rejected'].includes(scholarshipForm.value.status)) {
        return [];
    }

    return missingProgramReadinessItems.value.map((item) => item.label);
});
const finderRuleSummary = computed(() => [
    scholarshipForm.value.eligibleEducationLevels.length ? `${scholarshipForm.value.eligibleEducationLevels.length} education level${scholarshipForm.value.eligibleEducationLevels.length === 1 ? '' : 's'}` : 'All education levels',
    scholarshipForm.value.eligibleSchoolTypes.length ? `${scholarshipForm.value.eligibleSchoolTypes.length} school type${scholarshipForm.value.eligibleSchoolTypes.length === 1 ? '' : 's'}` : 'All school types',
    academicRequirementSummary.value,
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
    hasText(scholarshipForm.value.returnServiceContract) ? 'Return service listed' : 'No return service listed',
    hasText(scholarshipForm.value.otherContractTerms) ? 'Other contract terms listed' : 'No other contract terms',
]);
const statusOptions = computed(() => {
    const options = [
        { value: 'draft', label: 'Save as draft', help: 'Only provider can see it.' },
        { value: 'pending_review', label: 'Submit for admin review', help: 'Admin must approve before students see it.' },
    ];

    if (scholarshipForm.value.status === 'rejected') {
        options.push({ value: 'rejected', label: 'Rejected by admin', help: 'Edit and resubmit when ready.' });
    }

    if (scholarshipForm.value.status === 'published') {
        options.push({ value: 'published', label: 'Published', help: 'Currently visible to students.' });
        options.push({ value: 'closed', label: 'Closed', help: 'Stop accepting new student applications.' });
    }

    if (scholarshipForm.value.status === 'closed') {
        options.push({ value: 'closed', label: 'Closed', help: 'Stop accepting new student applications.' });
    }

    return options;
});
const submitButtonLabel = computed(() => {
    if (isSaving.value) {
        return 'Saving...';
    }

    if (scholarshipForm.value.status === 'draft') {
        return 'Save draft';
    }

    if (scholarshipForm.value.status === 'closed') {
        return 'Close program';
    }

    if (scholarshipForm.value.status === 'published') {
        return 'Save changes';
    }

    return scholarshipForm.value.status === 'rejected'
        ? 'Resubmit for review'
        : 'Submit for review';
});

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

function defaultReviewRubric() {
    return [
        {
            key: 'eligibility_fit',
            label: 'Eligibility fit',
            weight: 35,
            guidance: 'Confirm that the applicant meets the program-specific target and restrictions.',
        },
        {
            key: 'academic_merit',
            label: 'Academic merit',
            weight: 25,
            guidance: 'Review grades using the scale and education level required by this program.',
        },
        {
            key: 'financial_need',
            label: 'Financial need',
            weight: 20,
            guidance: 'Review declared need and supporting income documents where applicable.',
        },
        {
            key: 'document_quality',
            label: 'Document quality',
            weight: 20,
            guidance: 'Check whether required documents are complete, readable, current, and valid.',
        },
    ];
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
        customRequirements: '',
        reviewRubric: defaultReviewRubric(),
        awardAmount: '',
        minimumGwa: '',
        minimumGradeScale: '',
        slotsAvailable: '',
        applicationMode: '',
        renewalPolicy: '',
        returnServiceContract: '',
        otherContractTerms: '',
        contactEmail: '',
        contactNumber: '',
        deadline: '',
        status: 'draft',
        imageUrl: '/uploads/scholarship-default.jpg',
        termsAccepted: false,
    };
}

function splitRequirementText(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function parseRequirements(requirements) {
    return splitRequirementText(requirements)
        .filter((requirement) => documentRequirementOptions.includes(requirement));
}

function parseCustomRequirements(requirements) {
    return splitRequirementText(requirements)
        .filter((requirement) => !documentRequirementOptions.includes(requirement));
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
        customRequirements: parseCustomRequirements(scholarship.requirements).join('\n'),
        reviewRubric: Array.isArray(scholarship.review_rubric) && scholarship.review_rubric.length
            ? scholarship.review_rubric.map((criterion) => ({ ...criterion }))
            : defaultReviewRubric(),
        awardAmount: scholarship.award_amount ?? '',
        minimumGwa: scholarship.minimum_gwa ?? '',
        minimumGradeScale: scholarship.minimum_grade_scale ?? inferGradeScale(scholarship.minimum_gwa),
        slotsAvailable: scholarship.slots_available ?? '',
        applicationMode: scholarship.application_mode ?? '',
        renewalPolicy: scholarship.renewal_policy ?? '',
        returnServiceContract: scholarship.return_service_contract ?? '',
        otherContractTerms: scholarship.other_contract_terms ?? '',
        contactEmail: scholarship.contact_email ?? '',
        contactNumber: scholarship.contact_number ?? '',
        deadline: scholarship.deadline ?? '',
        status: scholarship.status ?? 'draft',
        imageUrl: scholarship.image_url ?? '/uploads/scholarship-default.jpg',
        termsAccepted: false,
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
    scholarshipForm.value.customRequirements = '';
}

function addReviewCriterion() {
    if (scholarshipForm.value.reviewRubric.length >= 6) {
        return;
    }

    scholarshipForm.value.reviewRubric.push({
        key: `criterion_${Date.now().toString(36)}`,
        label: '',
        weight: 10,
        guidance: '',
    });
}

function removeReviewCriterion(index) {
    scholarshipForm.value.reviewRubric.splice(index, 1);
}

function resetReviewRubric() {
    scholarshipForm.value.reviewRubric = defaultReviewRubric();
}

function inferGradeScale(value) {
    if (!hasText(value)) {
        return '';
    }

    return Number(value) <= 5 ? 'grade_point' : 'percentage';
}

function handleGradeScaleChange() {
    if (!academicRequirementNeedsValue.value) {
        scholarshipForm.value.minimumGwa = '';
        return;
    }

    const value = Number(scholarshipForm.value.minimumGwa);

    if (
        (scholarshipForm.value.minimumGradeScale === 'percentage' && value > 0 && value <= 5)
        || (scholarshipForm.value.minimumGradeScale === 'grade_point' && value > 5)
    ) {
        scholarshipForm.value.minimumGwa = '';
    }
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

    if (!scholarshipForm.value.termsAccepted) {
        formError.value = 'Please accept the provider scholarship terms before saving.';
        return;
    }

    if (
        scholarshipForm.value.reviewRubric.some((criterion) => !hasText(criterion.label))
        || rubricWeightTotal.value !== 100
    ) {
        activeFormSection.value = 'rubric';
        formError.value = 'Add a label for every review criterion and make the weights total 100%.';
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
        requirements: allDocumentRequirements.value.join('\n'),
        review_rubric: JSON.stringify(scholarshipForm.value.reviewRubric),
        award_amount: scholarshipForm.value.awardAmount || '',
        minimum_gwa: academicRequirementNeedsValue.value ? scholarshipForm.value.minimumGwa || '' : '',
        minimum_grade_scale: scholarshipForm.value.minimumGradeScale || '',
        slots_available: scholarshipForm.value.slotsAvailable || '',
        application_mode: scholarshipForm.value.applicationMode || '',
        renewal_policy: scholarshipForm.value.renewalPolicy || '',
        return_service_contract: scholarshipForm.value.returnServiceContract || '',
        other_contract_terms: scholarshipForm.value.otherContractTerms || '',
        contact_email: scholarshipForm.value.contactEmail || '',
        contact_number: scholarshipForm.value.contactNumber || '',
        deadline: scholarshipForm.value.deadline || '',
        status: scholarshipForm.value.status,
        terms_accepted: scholarshipForm.value.termsAccepted ? '1' : '',
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Program Form
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit scholarship program' : 'Create scholarship program' }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Add the program details students need for matching, review, and application guidance.
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
                        class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                        novalidate
                        @submit.prevent="saveScholarship"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    {{ isEditMode ? 'Edit Scholarship' : 'Create Scholarship' }}
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    {{ activeFormSectionMeta.label }}
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ activeFormSectionMeta.help }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="grid gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                        Sections
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Move through each page to complete the scholarship setup.
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                                    <button
                                        v-for="section in formSections"
                                        :key="section.id"
                                        type="button"
                                        :class="[
                                            'flex min-h-11 items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition',
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
                        </div>

                        <div
                            v-if="publishWarnings.length"
                            class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                        >
                            <p class="font-bold">
                                Review before submitting
                            </p>
                            <p class="mt-1 leading-6">
                                This program can still be saved as draft, but these sections should be completed before admin review: {{ publishWarnings.join(', ') }}.
                            </p>
                        </div>

                        <div v-show="activeFormSection === 'basics'" :class="['mt-5 grid gap-4', sectionCardClass]">
                            <div :class="fieldStackClass">
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

                            <div :class="[fieldCardClass, 'grid gap-4 sm:grid-cols-[5rem_1fr] sm:items-center']">
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

                            <div :class="formGridClass">
                                <div :class="basicFieldStackClass">
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

                                <div :class="basicFieldStackClass">
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

                                <div :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-grade-scale">
                                        Academic basis
                                    </label>
                                    <select
                                        id="scholarship-grade-scale"
                                        v-model="scholarshipForm.minimumGradeScale"
                                        :class="inputClass"
                                        @change="handleGradeScaleChange"
                                    >
                                        <option
                                            v-for="option in gradeScaleOptions"
                                            :key="option.value || 'none'"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ selectedGradeScaleOption.help }}
                                    </p>
                                </div>

                                <div v-if="academicRequirementNeedsValue" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-minimum-gwa">
                                        {{ selectedGradeScaleOption.inputLabel }}
                                    </label>
                                    <input
                                        id="scholarship-minimum-gwa"
                                        v-model="scholarshipForm.minimumGwa"
                                        type="number"
                                        min="0"
                                        :max="academicRequirementInputMax"
                                        :step="academicRequirementInputStep"
                                        :placeholder="selectedGradeScaleOption.placeholder"
                                        :class="inputClass"
                                    >
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ activeTargetForm.averageHelp }}
                                    </p>
                                </div>

                                <div v-else :class="basicFieldStackClass">
                                    <p class="text-sm font-semibold text-slate-500">
                                        Academic cutoff
                                    </p>
                                    <p class="mt-2 text-sm font-bold text-slate-950">
                                        {{ academicRequirementSummary }}
                                    </p>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        Add extra details in the eligibility section if reviewers need context.
                                    </p>
                                </div>

                                <div :class="basicFieldStackClass">
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

                                <div :class="basicFieldStackClass">
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

                                <div :class="basicFieldStackClass">
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

                                <div :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-status">
                                        Review action
                                    </label>
                                    <select id="scholarship-status" v-model="scholarshipForm.status" required :class="inputClass">
                                        <option
                                            v-for="option in statusOptions"
                                            :key="option.value"
                                            :value="option.value"
                                            :disabled="option.value === 'rejected'"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ statusOptions.find((option) => option.value === scholarshipForm.status)?.help || 'Admin must approve before students can see the program.' }}
                                    </p>
                                </div>
                            </div>

                            <div :class="fieldStackClass">
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

                        <div v-show="activeFormSection === 'target'" :class="['mt-5', sectionCardClass]">
                            <div :class="fieldStackClass">
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

                            <fieldset v-show="activeFormSection === 'workflow'" :class="['mt-5', sectionCardClass]">
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
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>

                                <div class="mt-4 grid items-stretch gap-4 lg:grid-cols-2">
                                    <div :class="fieldStackClass">
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

                                    <div :class="fieldStackClass">
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

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
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

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
                                        <label :class="labelClass" for="scholarship-return-service-contract">
                                            Return service contract
                                        </label>
                                        <textarea
                                            id="scholarship-return-service-contract"
                                            v-model="scholarshipForm.returnServiceContract"
                                            rows="4"
                                            placeholder="Example: Awardees sign a scholarship agreement and render return service after graduation for the period required by the program."
                                            :class="inputClass"
                                        ></textarea>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            State service duration, placement rules, teaching obligations, deferment rules, or where applicants should verify the official contract.
                                        </p>
                                    </div>

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
                                        <label :class="labelClass" for="scholarship-other-contract-terms">
                                            Other contract terms
                                        </label>
                                        <textarea
                                            id="scholarship-other-contract-terms"
                                            v-model="scholarshipForm.otherContractTerms"
                                            rows="4"
                                            placeholder="Example: Scholarship agreement, data privacy consent, approved course rules, refund terms, termination rules, travel clearance, or parent/guardian undertaking."
                                            :class="inputClass"
                                        ></textarea>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            Use this for contract items beyond return service, including data privacy, truthful declarations, approved course or school rules, refund clauses, and termination conditions.
                                        </p>
                                    </div>
                                </div>
                            </fieldset>

                            <fieldset v-show="activeFormSection === 'target'" :class="['mt-4', sectionCardClass]">
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
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200"
                                    >
                                        {{ summary }}
                                    </span>
                                </div>

                                <div :class="['mt-4', fieldCardClass]">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">
                                                Target applicant presets
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                Pick the closest target group first. The form will fill matching rules and recommended documents, then you can edit anything below.
                                            </p>
                                        </div>
                                        <span class="rounded-md bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                            Optional helper
                                        </span>
                                    </div>

                                    <div class="mt-4 grid items-stretch gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        <button
                                            v-for="preset in targetApplicantPresets"
                                            :key="preset.key"
                                            type="button"
                                            :class="[
                                                'group flex h-full flex-col rounded-lg border p-3 text-left transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-white hover:shadow-sm',
                                                activeTargetKey === preset.key
                                                    ? 'border-slate-900 bg-white shadow-sm ring-2 ring-slate-200'
                                                    : 'border-slate-200 bg-slate-50',
                                            ]"
                                            @click="applyTargetApplicantPreset(preset)"
                                        >
                                            <span class="flex items-center gap-3">
                                                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-slate-100 text-slate-700 transition group-hover:bg-slate-900 group-hover:text-white">
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

                                <div :class="['mt-4', fieldCardClass]">
                                    <p class="text-sm font-bold text-slate-950">
                                        Current target applicant summary
                                    </p>
                                    <div class="mt-3 grid items-stretch gap-3 md:grid-cols-2">
                                        <div
                                            v-for="item in targetApplicantSummary"
                                            :key="item.label"
                                            class="flex min-h-full flex-col rounded-md bg-slate-50 p-3 text-sm ring-1 ring-slate-200/80"
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

                                <div :class="['mt-4', fieldCardClass]">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div class="flex gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-700">
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
                                            class="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                            @click="applyActiveTargetDefaults"
                                        >
                                            Use target defaults
                                        </button>
                                    </div>

                                    <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                        <div
                                            v-for="note in activeTargetForm.notes"
                                            :key="note"
                                            class="rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200/80"
                                        >
                                            {{ note }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 grid items-stretch gap-4 lg:grid-cols-2">
                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
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
                                                        ? 'border-slate-900 bg-slate-900 text-white'
                                                        : 'border-slate-300 bg-slate-50 text-slate-700 hover:bg-white',
                                                ]"
                                                @click="toggleSelection('eligibleEducationLevels', option.value)"
                                            >
                                                {{ option.label }}
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="activeTargetForm.showProgramPath" :class="fieldStackClass">
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

                                    <div v-else :class="fieldStackClass">
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

                                    <div :class="fieldStackClass">
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
                                                        ? 'border-slate-900 bg-slate-900 text-white'
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

                                    <div :class="fieldStackClass">
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

                                    <div :class="fieldStackClass">
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

                                    <div :class="wideFieldStackClass">
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

                            <fieldset v-show="activeFormSection === 'location'" :class="['mt-5', sectionCardClass]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Map location
                                        </p>
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

                                <div class="mt-4 grid items-stretch gap-4 lg:grid-cols-2">
                                    <div :class="fieldStackClass">
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

                                    <div :class="fieldStackClass">
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

                            <fieldset v-show="activeFormSection === 'documents'" :class="['mt-5', sectionCardClass]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Document requirements
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Choose the documents applicants must prepare for this scholarship.
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-slate-400 hover:bg-slate-100"
                                            @click="selectCommonRequirements"
                                        >
                                            Select common
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                            @click="clearRequirements"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid items-stretch gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    <label
                                        v-for="requirement in documentRequirementOptions"
                                        :key="requirement"
                                        :class="[
                                            'group flex min-h-full cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition',
                                            isRequirementSelected(requirement)
                                                ? 'border-slate-900 bg-white text-slate-950 shadow-sm ring-2 ring-slate-200'
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
                                                    ? 'border-slate-900 bg-slate-900 text-white'
                                                    : 'border-slate-300 bg-white text-transparent group-hover:border-slate-400',
                                            ]"
                                        >
                                            OK
                                        </span>
                                        <span class="leading-5">
                                            {{ requirement }}
                                        </span>
                                    </label>
                                </div>

                                <div :class="['mt-4', fieldCardClass]">
                                    <label :class="labelClass" for="scholarship-custom-requirements">
                                        Custom document requirements
                                    </label>
                                    <textarea
                                        id="scholarship-custom-requirements"
                                        v-model="scholarshipForm.customRequirements"
                                        rows="4"
                                        maxlength="2000"
                                        placeholder="Example: Signed scholarship agreement&#10;Return service acknowledgment&#10;Data privacy consent"
                                        :class="inputClass"
                                    ></textarea>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        Add one requirement per line for provider-specific files that are not in the common list.
                                    </p>
                                </div>

                                <div :class="['mt-4', fieldCardClass]">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                        {{ selectedRequirementCount }} selected
                                    </p>
                                    <div v-if="selectedRequirementCount" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="requirement in allDocumentRequirements"
                                            :key="requirement"
                                            class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700"
                                        >
                                            {{ requirement }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-xs leading-5 text-slate-500">
                                        No document requirements selected yet.
                                    </p>
                                </div>
                            </fieldset>

                            <fieldset v-show="activeFormSection === 'rubric'" :class="['mt-5', sectionCardClass]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Provider review rubric
                                        </p>
                                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                            Use the same criteria for every applicant. Scores support review but never make the final decision.
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span :class="['rounded-md px-3 py-2 text-xs font-bold', rubricWeightTotal === 100 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
                                            {{ rubricWeightTotal }}% total
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                            @click="resetReviewRubric"
                                        >
                                            Use defaults
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="scholarshipForm.reviewRubric.length >= 6"
                                            class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="addReviewCriterion"
                                        >
                                            Add criterion
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="(criterion, index) in scholarshipForm.reviewRubric"
                                        :key="criterion.key"
                                        class="grid gap-3 rounded-md border border-slate-200 bg-white p-4 lg:grid-cols-[minmax(0,1fr)_7rem_auto]"
                                    >
                                        <div class="min-w-0">
                                            <label :class="labelClass" :for="`rubric-label-${criterion.key}`">
                                                Criterion
                                            </label>
                                            <input
                                                :id="`rubric-label-${criterion.key}`"
                                                v-model="criterion.label"
                                                type="text"
                                                maxlength="100"
                                                placeholder="Example: Community involvement"
                                                :class="inputClass"
                                            >
                                            <textarea
                                                v-model="criterion.guidance"
                                                rows="2"
                                                maxlength="300"
                                                placeholder="Briefly explain what reviewers should check."
                                                :class="['mt-2', inputClass]"
                                            ></textarea>
                                        </div>
                                        <div>
                                            <label :class="labelClass" :for="`rubric-weight-${criterion.key}`">
                                                Weight %
                                            </label>
                                            <input
                                                :id="`rubric-weight-${criterion.key}`"
                                                v-model.number="criterion.weight"
                                                type="number"
                                                min="1"
                                                max="100"
                                                :class="inputClass"
                                            >
                                        </div>
                                        <button
                                            type="button"
                                            :disabled="scholarshipForm.reviewRubric.length === 1"
                                            class="self-start rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-40 lg:mt-7"
                                            @click="removeReviewCriterion(index)"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>

                                <p v-if="rubricWeightTotal !== 100" class="mt-3 text-xs font-semibold text-amber-800">
                                    Adjust the weights until they total exactly 100%.
                                </p>
                            </fieldset>

                        <div class="mt-5 border-t border-slate-200 pt-4">
                            <TermsAgreement
                                v-model="scholarshipForm.termsAccepted"
                                context="scholarship"
                            />
                        </div>

                        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-h-5">
                                <p v-if="formMessage" class="text-sm font-semibold text-emerald-700">
                                    {{ formMessage }}
                                </p>
                                <p v-if="formError" class="text-sm font-semibold text-rose-700">
                                    {{ formError }}
                                </p>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2 lg:flex lg:items-center">
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
                                    {{ submitButtonLabel }}
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
