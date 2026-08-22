<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProgramBenefitsEditor from '../components/ProgramBenefitsEditor.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import {
    canonicalProgramPath,
    canonicalizeProgramPathList,
    isOpenProgramPath,
    normalizeProgramPath,
    providerProgramPathOptionsForTarget,
    splitProgramPaths,
} from '../support/learnerProgramPaths';
import { cashGrantAmount, normalizeScholarshipBenefits as normalizeBenefits } from '../support/scholarshipBenefits';

const scholarshipId = window.location.pathname.match(/\/provider\/programs\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(scholarshipId));
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const formError = ref('');
const user = ref(null);
const existingApplicationCount = ref(0);
const awardedSlotsCount = ref(0);
const scholarshipFormElement = ref(null);
const imageInputElement = ref(null);
const imageFile = ref(null);
const imagePreviewUrl = ref('');
const providerLocationMessage = ref('');
const providerAddressLookupTrigger = ref(0);
const eventLocationMapStage = ref('');
const eventLocationMapMessage = ref('');
const eventAddressLookupTrigger = ref(0);
const activeFormSection = ref('overview');
const showAudienceDetails = ref(false);
const showStageSchedules = ref(false);
const showLocationMap = ref(false);
const customizeRubric = ref(false);
const selectedTargetPresetKey = ref('');
const programPathChoice = ref('');
const customProgramPath = ref('');
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const labelClass = 'mb-1.5 block text-sm font-semibold text-slate-700';
const requiredHintClass = 'ml-1 text-xs font-normal text-amber-700';
const optionalHintClass = 'ml-1 text-xs font-normal text-slate-400';
const inputClass = 'w-full min-w-0 rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-100';
const sectionCardClass = 'min-w-0';
const fieldCardClass = 'min-w-0';
const fieldStackClass = 'min-w-0 flex flex-col';
const basicFieldStackClass = fieldStackClass;
const wideFieldStackClass = `${fieldStackClass} xl:col-span-2`;
const formGridClass = 'grid items-start gap-x-5 gap-y-5 md:grid-cols-2';
const currentLocalDateTime = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
    .toISOString();
const todayDate = currentLocalDateTime.slice(0, 10);
const minimumScheduleDateTime = currentLocalDateTime.slice(0, 16);
const formSections = [
    { id: 'overview', label: 'Program', help: 'Basic details, benefits, and application dates.' },
    { id: 'audience', label: 'Eligibility', help: 'Who can apply and how students are matched.' },
    { id: 'documents', label: 'Requirements', help: 'Required documents and the program location.' },
    { id: 'process', label: 'Selection', help: 'Review stages, schedules, contact, and scoring.' },
    { id: 'finish', label: 'Publish', help: 'Choose how to save this program.' },
];
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const customEligibilityOption = '__custom__';
const eligibilityOptions = [
    {
        label: 'Applicants who meet all listed requirements',
        value: 'Open to applicants who meet the education, academic, location, income, and document requirements listed in this program.',
    },
    {
        label: 'Academic performance based',
        value: 'Open to applicants who meet the listed academic, education-level, and document requirements.',
    },
    {
        label: 'Financial need based',
        value: 'Open to applicants who meet the listed income, education-level, and document requirements.',
    },
    {
        label: 'Specific learner group or program',
        value: 'Open to applicants in the listed education levels, grade or year levels, tracks, strands, courses, or training programs.',
    },
    {
        label: 'Local or community applicants',
        value: 'Open to applicants who live or study within the locations covered by this program and meet the listed requirements.',
    },
    {
        label: 'Open to all applicants',
        value: 'Open to all applicants. The provider will review the submitted profile and required documents.',
    },
];
const selectedEligibilityOption = ref('');
const defaultCommitmentText = 'The provider will explain any final recipient commitments after the applicant is accepted.';
const commitmentOptions = [
    {
        value: 'provider_briefing',
        label: 'Provider will explain after acceptance',
        field: 'otherContractTerms',
        text: defaultCommitmentText,
    },
    {
        value: 'none',
        label: 'No recipient commitment',
        field: null,
        text: '',
    },
    {
        value: 'renewal',
        label: 'Renewal requirement may apply',
        field: 'renewalPolicy',
        text: 'Renewal requirements may apply. The provider will explain the final conditions after acceptance.',
    },
    {
        value: 'service',
        label: 'Service or community duty may apply',
        field: 'returnServiceContract',
        text: 'A service or community commitment may apply. The provider will explain the final duties after acceptance.',
    },
    {
        value: 'activities',
        label: 'Progress updates or activities may apply',
        field: 'otherContractTerms',
        text: 'Progress updates or program activities may apply. The provider will explain the final expectations after acceptance.',
    },
    {
        value: 'custom',
        label: 'Custom short preview',
        field: 'otherContractTerms',
        text: null,
    },
];
const selectedCommitmentOption = ref('provider_briefing');
const customCommitmentText = ref('');
const incomeOptions = ['Any', 'Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const selectionStageOptions = [
    {
        value: 'screening',
        label: 'Review',
        description: 'Review eligibility, profile details, and submitted requirements.',
        icon: 'fa-solid fa-list-check',
        required: true,
    },
    {
        value: 'exam',
        label: 'Exam',
        description: 'Provider conducts and grades an exam before the final decision.',
        icon: 'fa-solid fa-clipboard-question',
        required: false,
    },
    {
        value: 'interview',
        label: 'Interview',
        description: 'Meet shortlisted applicants before approval.',
        icon: 'fa-solid fa-comments',
        required: false,
    },
    {
        value: 'distribution',
        label: 'Distribution',
        description: 'Announce award release details to approved applicants.',
        icon: 'fa-solid fa-hand-holding-dollar',
        required: true,
    },
];
const scholarshipForm = ref(emptyScholarshipForm());
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
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
    'Government-issued ID',
    'Recent 2x2 ID photo',
    'Admission or acceptance letter',
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
        courses: 'Cookery NC II\nICT / Computer Systems Servicing\nAutomotive Servicing\nElectrical Installation and Maintenance\nCaregiving',
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
        title: 'All learners',
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
        programPathHelp: 'Choose each accepted track or strand. Use Any strand when there is no restriction.',
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
        programPathPlaceholder: 'Choose one or more eligible courses',
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
        programPathPlaceholder: 'Choose one or more eligible training programs',
        programPathHelp: 'Choose each qualification or training program accepted by the provider.',
        programPathTemplate: 'Cookery NC II\nICT / Computer Systems Servicing\nAutomotive Servicing\nElectrical Installation and Maintenance\nCaregiving',
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
        programPathPlaceholder: 'Choose one or more eligible learner paths',
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
const reviewRubricReady = computed(() => scholarshipForm.value.reviewRubric.length > 0
    && scholarshipForm.value.reviewRubric.every((criterion) => hasText(criterion.label))
    && rubricWeightTotal.value === 100);
const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const selectionPlanLocked = computed(() => isEditMode.value && existingApplicationCount.value > 0);
const minimumAwardSlots = computed(() => Math.max(1, awardedSlotsCount.value));
const scholarshipImagePreview = computed(() => imagePreviewUrl.value || scholarshipForm.value.imageUrl || '/uploads/scholarship-default.jpg');
const officialProgramPreviewUrl = computed(() => {
    const value = String(scholarshipForm.value.officialProgramUrl || '').trim();

    return /^https?:\/\//i.test(value) ? value : '';
});
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
const reviewSubmissionSelected = computed(() => ['pending_review', 'rejected'].includes(scholarshipForm.value.status));
const termsRequiredForSave = computed(() => !['draft', 'closed'].includes(scholarshipForm.value.status));
const deadlineReady = computed(() => hasText(scholarshipForm.value.deadline)
    && scholarshipForm.value.deadline >= todayDate);
const applicationWindowReady = computed(() => !hasText(scholarshipForm.value.applicationOpensAt)
    || !hasText(scholarshipForm.value.deadline)
    || scholarshipForm.value.applicationOpensAt <= scholarshipForm.value.deadline);
const resultsDateReady = computed(() => !hasText(scholarshipForm.value.expectedResultsAt)
    || !hasText(scholarshipForm.value.deadline)
    || scholarshipForm.value.expectedResultsAt >= scholarshipForm.value.deadline);
const programReadinessItems = computed(() => [
    {
        label: 'Program overview',
        section: 'overview',
        complete: hasText(scholarshipForm.value.title)
            && hasText(scholarshipForm.value.category)
            && hasText(scholarshipForm.value.programCycle)
            && hasText(scholarshipForm.value.description),
        help: 'Title, category, program cycle, and a clear description.',
    },
    {
        label: 'Offer details',
        section: 'overview',
        complete: scholarshipForm.value.benefits.length > 0
            && deadlineReady.value
            && applicationWindowReady.value
            && resultsDateReady.value
            && hasText(scholarshipForm.value.applicationMode),
        help: 'At least one benefit, a valid application window, and submission method.',
    },
    {
        label: 'Eligibility and matching rules',
        section: 'audience',
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
        help: 'Eligibility text and at least one finder rule.',
    },
    {
        label: 'Document checklist',
        section: 'documents',
        complete: selectedRequirementCount.value > 0,
        help: 'Documents applicants must prepare before submission.',
    },
    {
        label: 'Program location',
        section: 'documents',
        complete: hasText(scholarshipForm.value.locationName)
            && hasText(scholarshipForm.value.locationAddress)
            && hasText(scholarshipForm.value.latitude)
            && hasText(scholarshipForm.value.longitude),
        help: 'Address and map pin for distance estimates.',
    },
    {
        label: 'Application workflow',
        section: 'process',
        complete: hasText(scholarshipForm.value.applicationMode)
            && (
                hasText(scholarshipForm.value.contactEmail)
                || hasText(scholarshipForm.value.contactNumber)
            ),
        help: 'How students apply and who they can contact for questions.',
    },
    ...(scholarshipForm.value.selectionStages.includes('exam') ? [{
        label: 'Exam details',
        section: 'process',
        complete: hasText(scholarshipForm.value.examDurationMinutes)
            && hasText(scholarshipForm.value.examPassingScore),
        help: 'Provider-managed exam duration and passing score for this program.',
    }] : []),
    {
        label: 'Review scoring',
        section: 'process',
        complete: reviewRubricReady.value,
        help: 'A complete rubric with weights totaling 100%.',
    },
]);
const missingProgramReadinessItems = computed(() => programReadinessItems.value.filter((item) => !item.complete));
const activeFormSectionIndex = computed(() => formSections.findIndex((section) => section.id === activeFormSection.value));
const activeFormSectionMeta = computed(() => formSections[activeFormSectionIndex.value] ?? formSections[0]);
const formSectionProgress = computed(() => {
    const sectionChecks = {
        overview: hasText(scholarshipForm.value.title)
            && hasText(scholarshipForm.value.category)
            && hasText(scholarshipForm.value.programCycle)
            && hasText(scholarshipForm.value.description)
            && scholarshipForm.value.benefits.length > 0
            && deadlineReady.value
            && applicationWindowReady.value
            && resultsDateReady.value
            && hasText(scholarshipForm.value.applicationMode),
        audience: hasText(scholarshipForm.value.eligibility)
            && (
                scholarshipForm.value.eligibleEducationLevels.length > 0
                || hasText(scholarshipForm.value.eligibleCourses)
                || scholarshipForm.value.eligibleSchoolTypes.length > 0
                || hasText(scholarshipForm.value.eligibleYearLevels)
                || hasText(scholarshipForm.value.eligibleLocations)
            ),
        process: scholarshipForm.value.selectionStages.includes('screening')
            && scholarshipForm.value.selectionStages.includes('distribution')
            && (hasText(scholarshipForm.value.contactEmail) || hasText(scholarshipForm.value.contactNumber))
            && (!scholarshipForm.value.selectionStages.includes('exam') || (
                hasText(scholarshipForm.value.examDurationMinutes)
                && hasText(scholarshipForm.value.examPassingScore)
            ))
            && reviewRubricReady.value,
        documents: selectedRequirementCount.value > 0
            && hasText(scholarshipForm.value.locationName)
            && hasText(scholarshipForm.value.locationAddress)
            && hasText(scholarshipForm.value.latitude)
            && hasText(scholarshipForm.value.longitude),
        finish: !termsRequiredForSave.value || scholarshipForm.value.termsAccepted,
    };

    return Object.fromEntries(formSections.map((section) => [section.id, Boolean(sectionChecks[section.id])]));
});
const completedFormSectionCount = computed(() => Object.values(formSectionProgress.value).filter(Boolean).length);
const formProgressPercent = computed(() => Math.round((completedFormSectionCount.value / formSections.length) * 100));
const publishWarnings = computed(() => {
    if (!reviewSubmissionSelected.value) {
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
const programPathSelectOptions = computed(() => providerProgramPathOptionsForTarget(activeTargetKey.value));
const selectedProgramPaths = computed(() => splitProgramPaths(scholarshipForm.value.eligibleCourses));
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
const schedulableSelectionStages = computed(() => selectionStageOptions.filter((stage) => (
    stage.value !== 'screening'
    && scholarshipForm.value.selectionStages.includes(stage.value)
)));
const scheduledProgramEventCount = computed(() => schedulableSelectionStages.value
    .filter((stage) => hasText(scholarshipForm.value.programEvents[stage.value]?.scheduledAt))
    .length);
const activeEventMapStage = computed(() => selectionStageOptions.find((stage) => stage.value === eventLocationMapStage.value) ?? null);
const activeEventMap = computed(() => scholarshipForm.value.programEvents[eventLocationMapStage.value] ?? null);
const activeEventMapAddress = computed(() => {
    if (!activeEventMap.value) {
        return '';
    }

    return [activeEventMap.value.venue, activeEventMap.value.locationAddress]
        .filter((value) => hasText(value))
        .join(', ');
});
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

async function openFormSection(sectionId) {
    activeFormSection.value = sectionId;
    await nextTick();
    scholarshipFormElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function focusFormField(sectionId, fieldId) {
    activeFormSection.value = sectionId;
    await nextTick();

    const field = document.getElementById(fieldId);

    field?.focus({ preventScroll: true });
    field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function readinessFocusTarget(item) {
    if (item.label === 'Program overview') {
        if (!hasText(scholarshipForm.value.title)) return 'scholarship-title';
        if (!hasText(scholarshipForm.value.category)) return 'scholarship-category';
        if (!hasText(scholarshipForm.value.programCycle)) return 'scholarship-program-cycle';

        return 'scholarship-description';
    }

    if (item.label === 'Offer details') {
        if (scholarshipForm.value.benefits.length === 0) return 'program-benefit-type';
        if (!hasText(scholarshipForm.value.applicationMode)) return 'scholarship-mode';
        if (!applicationWindowReady.value) return 'scholarship-application-opens';
        if (!resultsDateReady.value) return 'scholarship-expected-results';

        return 'scholarship-deadline';
    }

    if (item.label === 'Eligibility and matching rules') {
        return !hasText(scholarshipForm.value.eligibility)
            ? 'scholarship-eligibility'
            : 'target-applicant-preset';
    }

    if (item.label === 'Document checklist') return 'scholarship-custom-requirements';

    if (item.label === 'Program location') {
        if (!hasText(scholarshipForm.value.locationName)) return 'scholarship-location-name';
        if (!hasText(scholarshipForm.value.locationAddress)) return 'scholarship-location-address';

        return 'scholarship-map-toggle';
    }

    if (item.label === 'Application workflow') return 'scholarship-contact-email';
    if (item.label === 'Exam details') return 'scholarship-exam-duration';
    if (item.label === 'Review scoring') return `rubric-label-${scholarshipForm.value.reviewRubric[0]?.key}`;

    return '';
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

function formatPreviewDate(value, fallback = 'Not specified') {
    if (!hasText(value)) {
        return fallback;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? 'Not specified';
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

function emptyProgramEvent(type) {
    return {
        id: null,
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

function emptyProgramEvents() {
    return Object.fromEntries(selectionStageOptions.map((stage) => [
        stage.value,
        emptyProgramEvent(stage.value),
    ]));
}

function emptyScholarshipForm() {
    return {
        title: '',
        category: '',
        programCycle: '',
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
        requirements: [
            'Completed application form',
            'Certificate of enrollment',
            'Latest report card or grades',
            'School ID',
            'Proof of income',
        ],
        customRequirements: '',
        reviewRubric: defaultReviewRubric(),
        benefits: [],
        minimumGwa: '',
        minimumGradeScale: '',
        slotsAvailable: '',
        applicationMode: 'online',
        selectionStages: ['screening', 'distribution'],
        examDurationMinutes: '',
        examPassingScore: '',
        programEvents: emptyProgramEvents(),
        renewalPolicy: '',
        returnServiceContract: '',
        otherContractTerms: defaultCommitmentText,
        contactEmail: '',
        contactNumber: '',
        applicationOpensAt: '',
        expectedResultsAt: '',
        officialProgramUrl: '',
        contactPerson: '',
        contactDepartment: '',
        deadline: '',
        status: 'draft',
        imageUrl: '/uploads/scholarship-default.jpg',
        termsAccepted: false,
    };
}

function fillProgramEvents(events) {
    const programEvents = emptyProgramEvents();

    (Array.isArray(events) ? events : []).forEach((event) => {
        if (!programEvents[event.type]) {
            return;
        }

        programEvents[event.type] = {
            id: event.id ?? null,
            type: event.type,
            title: event.title ?? '',
            scheduledAt: event.scheduled_at ?? '',
            mode: event.mode ?? 'onsite',
            venue: event.venue ?? '',
            locationAddress: event.location_address ?? '',
            latitude: event.latitude ?? '',
            longitude: event.longitude ?? '',
            onlineUrl: event.online_url ?? '',
            instructions: event.instructions ?? '',
        };
    });

    return programEvents;
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

function toggleSelectionStage(stage) {
    const option = selectionStageOptions.find((item) => item.value === stage);

    if (option?.required || selectionPlanLocked.value) {
        return;
    }

    const selected = scholarshipForm.value.selectionStages.includes(stage)
        ? scholarshipForm.value.selectionStages.filter((item) => item !== stage)
        : [...scholarshipForm.value.selectionStages, stage];

    scholarshipForm.value.selectionStages = selectionStageOptions
        .map((optionItem) => optionItem.value)
        .filter((optionValue) => selected.includes(optionValue) || ['screening', 'distribution'].includes(optionValue));
}

function scheduleModeNeedsVenue(mode) {
    return ['onsite', 'hybrid'].includes(mode);
}

function scheduleModeShowsOnlineUrl(mode) {
    return ['online', 'hybrid'].includes(mode);
}

function scheduleModeRequiresOnlineUrl(mode, stageType) {
    return scheduleModeShowsOnlineUrl(mode) && stageType !== 'distribution';
}

function programEventsPayload() {
    return schedulableSelectionStages.value
        .map((stage) => scholarshipForm.value.programEvents[stage.value])
        .filter((event) => hasText(event?.scheduledAt))
        .map((event) => ({
            type: event.type,
            title: event.title,
            scheduled_at: event.scheduledAt,
            mode: event.mode,
            venue: event.venue,
            location_address: event.locationAddress,
            latitude: event.latitude || null,
            longitude: event.longitude || null,
            online_url: event.onlineUrl,
            instructions: event.instructions,
        }));
}

function programEventValidationMessage() {
    let previousScheduledStage = null;

    for (const stage of schedulableSelectionStages.value) {
        const event = scholarshipForm.value.programEvents[stage.value];

        if (!hasText(event?.scheduledAt)) {
            continue;
        }

        if (scheduleModeNeedsVenue(event.mode) && !hasText(event.venue)) {
            return `Add a venue for the ${stage.label.toLowerCase()} schedule.`;
        }

        if (scheduleModeRequiresOnlineUrl(event.mode, stage.value) && !hasText(event.onlineUrl)) {
            return `Add the online link for the ${stage.label.toLowerCase()} schedule.`;
        }

        if (!hasText(event.instructions)) {
            return `Add instructions for applicants who reach the ${stage.label.toLowerCase()} stage.`;
        }

        if (
            previousScheduledStage
            && new Date(event.scheduledAt).getTime() < new Date(previousScheduledStage.event.scheduledAt).getTime()
        ) {
            return `${stage.label} must be scheduled after ${previousScheduledStage.stage.label.toLowerCase()}.`;
        }

        previousScheduledStage = { stage, event };
    }

    return '';
}

function minimumDateTimeForEvent(event) {
    if (event?.id && hasText(event.scheduledAt) && event.scheduledAt < minimumScheduleDateTime) {
        return event.scheduledAt;
    }

    return minimumScheduleDateTime;
}

function selectAllOptions(field, options) {
    scholarshipForm.value[field] = options.map((option) => option.value);
}

function setEligibleProgramPaths(paths) {
    scholarshipForm.value.eligibleCourses = canonicalizeProgramPathList(paths.join('\n'));
}

function isProgramPathSelected(path) {
    const normalizedPath = normalizeProgramPath(canonicalProgramPath(path));

    return selectedProgramPaths.value.some((selectedPath) => (
        normalizeProgramPath(selectedPath) === normalizedPath
    ));
}

function addEligibleProgramPath(path) {
    const nextPath = canonicalProgramPath(path);

    if (!nextPath || nextPath === 'Other') {
        return;
    }

    if (isOpenProgramPath(nextPath)) {
        setEligibleProgramPaths([nextPath]);
        return;
    }

    const specificPaths = selectedProgramPaths.value.filter((selectedPath) => (
        !isOpenProgramPath(selectedPath)
        && normalizeProgramPath(selectedPath) !== normalizeProgramPath(nextPath)
    ));

    setEligibleProgramPaths([...specificPaths, nextPath]);
}

function chooseProgramPath() {
    if (!programPathChoice.value || programPathChoice.value === 'Other') {
        return;
    }

    addEligibleProgramPath(programPathChoice.value);
    programPathChoice.value = '';
}

function addCustomProgramPath() {
    if (!customProgramPath.value.trim()) {
        return;
    }

    addEligibleProgramPath(customProgramPath.value);
    customProgramPath.value = '';
    programPathChoice.value = '';
}

function removeEligibleProgramPath(path) {
    setEligibleProgramPaths(selectedProgramPaths.value.filter((selectedPath) => (
        normalizeProgramPath(selectedPath) !== normalizeProgramPath(path)
    )));
}

function applyActiveTargetDefaults() {
    const targetForm = activeTargetForm.value;

    if (targetForm.programPathTemplate !== undefined) {
        scholarshipForm.value.eligibleCourses = canonicalizeProgramPathList(targetForm.programPathTemplate);
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
    scholarshipForm.value.eligibleCourses = canonicalizeProgramPathList(preset.courses);
    scholarshipForm.value.eligibleYearLevels = preset.years;
    scholarshipForm.value.eligibleLocations = preset.locations;
    scholarshipForm.value.eligibility = preset.eligibility;
    syncEligibilityOption();
    scholarshipForm.value.requirements = preset.requirements
        .filter((requirement) => documentRequirementOptions.includes(requirement));

    if (!scholarshipForm.value.description) {
        scholarshipForm.value.description = `A scholarship assistance program for ${preset.label.toLowerCase()}. Review the target applicant rules, prepare documents, and submit before the deadline.`;
    }

    if (!scholarshipForm.value.applicationMode) {
        scholarshipForm.value.applicationMode = 'online';
    }
}

function syncEligibilityOption() {
    const eligibility = scholarshipForm.value.eligibility;
    const preset = eligibilityOptions.find((option) => option.value === eligibility);

    selectedEligibilityOption.value = preset?.value
        ?? (hasText(eligibility) ? customEligibilityOption : '');
}

function applyEligibilityOption() {
    if (selectedEligibilityOption.value === customEligibilityOption) {
        const currentIsPreset = eligibilityOptions.some(
            (option) => option.value === scholarshipForm.value.eligibility,
        );

        if (currentIsPreset) {
            scholarshipForm.value.eligibility = '';
        }

        return;
    }

    scholarshipForm.value.eligibility = selectedEligibilityOption.value;
}

async function applyTargetApplicantPresetByKey(event) {
    const nextPresetKey = event.target.value;
    const previousPresetKey = selectedTargetPresetKey.value;
    const preset = targetApplicantPresets.find((item) => item.key === nextPresetKey);

    if (!preset) {
        event.target.value = previousPresetKey;
        return;
    }

    if (
        previousPresetKey
        && previousPresetKey !== nextPresetKey
        && !await requestConfirmation({
            title: 'Replace the current applicant rules?',
            message: 'This preset will replace the matching rules, eligibility notes, and document checklist currently entered.',
            confirmLabel: 'Apply preset',
        })
    ) {
        event.target.value = previousPresetKey;
        return;
    }

    selectedTargetPresetKey.value = nextPresetKey;
    applyTargetApplicantPreset(preset);
}

function commitmentEntries() {
    return [
        { field: 'renewalPolicy', value: scholarshipForm.value.renewalPolicy },
        { field: 'returnServiceContract', value: scholarshipForm.value.returnServiceContract },
        { field: 'otherContractTerms', value: scholarshipForm.value.otherContractTerms },
    ].filter((entry) => hasText(entry.value));
}

function clearCommitmentFields() {
    scholarshipForm.value.renewalPolicy = '';
    scholarshipForm.value.returnServiceContract = '';
    scholarshipForm.value.otherContractTerms = '';
}

function syncCommitmentEditor() {
    const entries = commitmentEntries();
    const matchingPreset = commitmentOptions.find((option) => (
        option.text !== null
        && entries.length === (option.text ? 1 : 0)
        && (!option.text || (entries[0]?.field === option.field && entries[0]?.value === option.text))
    ));

    if (matchingPreset) {
        selectedCommitmentOption.value = matchingPreset.value;
        customCommitmentText.value = '';
        return;
    }

    selectedCommitmentOption.value = entries.length ? 'custom' : 'none';
    customCommitmentText.value = entries.map((entry) => entry.value).join('\n\n');
}

function applySelectedCommitment() {
    const option = commitmentOptions.find((item) => item.value === selectedCommitmentOption.value);

    if (!option) {
        return;
    }

    if (option.value === 'custom') {
        if (!hasText(customCommitmentText.value)) {
            customCommitmentText.value = commitmentEntries().map((entry) => entry.value).join('\n\n');
        }

        clearCommitmentFields();
        scholarshipForm.value.otherContractTerms = customCommitmentText.value;
        return;
    }

    clearCommitmentFields();

    if (option.field && option.text) {
        scholarshipForm.value[option.field] = option.text;
    }

    customCommitmentText.value = '';
}

function applyCustomCommitment() {
    clearCommitmentFields();
    scholarshipForm.value.otherContractTerms = customCommitmentText.value;
}

function fillScholarshipForm(scholarship) {
    existingApplicationCount.value = Number(scholarship.applications_count ?? 0);
    awardedSlotsCount.value = Number(scholarship.awarded_slots_count ?? 0);
    scholarshipForm.value = {
        title: scholarship.title ?? '',
        category: scholarship.category ?? '',
        programCycle: scholarship.program_cycle ?? '',
        description: scholarship.description ?? '',
        eligibility: scholarship.eligibility ?? '',
        eligibleEducationLevels: parseSelections(scholarship.eligible_education_levels, educationLevelOptions),
        eligibleCourses: canonicalizeProgramPathList(scholarship.eligible_courses),
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
        benefits: normalizeBenefits(scholarship),
        minimumGwa: scholarship.minimum_gwa ?? '',
        minimumGradeScale: scholarship.minimum_grade_scale ?? inferGradeScale(scholarship.minimum_gwa),
        slotsAvailable: scholarship.slots_available ?? '',
        applicationMode: scholarship.application_mode ?? '',
        selectionStages: selectionStageOptions
            .map((option) => option.value)
            .filter((value) => (scholarship.selection_stages ?? ['screening', 'distribution']).includes(value)),
        examDurationMinutes: scholarship.exam_duration_minutes ?? '',
        examPassingScore: scholarship.exam_passing_score ?? '',
        programEvents: fillProgramEvents(scholarship.program_events),
        renewalPolicy: scholarship.renewal_policy ?? '',
        returnServiceContract: scholarship.return_service_contract ?? '',
        otherContractTerms: scholarship.other_contract_terms ?? '',
        contactEmail: scholarship.contact_email ?? '',
        contactNumber: scholarship.contact_number ?? '',
        applicationOpensAt: scholarship.application_opens_at ?? '',
        expectedResultsAt: scholarship.expected_results_at ?? '',
        officialProgramUrl: scholarship.official_program_url ?? '',
        contactPerson: scholarship.contact_person ?? '',
        contactDepartment: scholarship.contact_department ?? '',
        deadline: scholarship.deadline ?? '',
        status: scholarship.status ?? 'draft',
        imageUrl: scholarship.image_url ?? '/uploads/scholarship-default.jpg',
        termsAccepted: false,
    };
    imageFile.value = null;
    imagePreviewUrl.value = '';
    selectedTargetPresetKey.value = inferTargetFormKey(scholarshipForm.value.eligibleEducationLevels);
    syncEligibilityOption();
    syncCommitmentEditor();
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

function openLocationMap() {
    showLocationMap.value = true;

    if (scholarshipFormMapAddress.value && !scholarshipForm.value.latitude) {
        nextTick(lookupScholarshipAddress);
    }
}

function closeLocationMap() {
    showLocationMap.value = false;
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

function clearProgramEventMapPoint(stageValue) {
    const event = scholarshipForm.value.programEvents[stageValue];

    if (!event) {
        return;
    }

    event.latitude = '';
    event.longitude = '';
}

function lookupProgramEventAddress() {
    if (!activeEventMapAddress.value) {
        eventLocationMapMessage.value = 'Enter the event venue or address first.';
        return;
    }

    eventLocationMapMessage.value = 'Searching the event address on the map...';
    eventAddressLookupTrigger.value += 1;
}

function openProgramEventMap(stageValue) {
    showLocationMap.value = false;
    eventLocationMapStage.value = stageValue;
    eventLocationMapMessage.value = '';

    nextTick(() => {
        if (activeEventMapAddress.value && !activeEventMap.value?.latitude) {
            lookupProgramEventAddress();
        }
    });
}

function closeProgramEventMap() {
    eventLocationMapStage.value = '';
    eventLocationMapMessage.value = '';
}

function handleProgramEventLocationResolved(location) {
    if (!activeEventMap.value) {
        return;
    }

    activeEventMap.value.latitude = Number(location.latitude).toFixed(7);
    activeEventMap.value.longitude = Number(location.longitude).toFixed(7);
    eventLocationMapMessage.value = 'Address found. The pin is saved with this stage when you save the program.';
}

function handleProgramEventLocationPicked(location) {
    if (!activeEventMap.value) {
        return;
    }

    const address = location.address ?? {};
    const suggestedVenue = address.office
        || address.amenity
        || address.building
        || address.school
        || address.university
        || address.tourism;

    activeEventMap.value.latitude = Number(location.latitude).toFixed(7);
    activeEventMap.value.longitude = Number(location.longitude).toFixed(7);
    activeEventMap.value.venue = activeEventMap.value.venue || suggestedVenue || '';
    activeEventMap.value.locationAddress = location.displayName
        || [
            [address.house_number, address.road].filter(Boolean).join(' '),
            address.neighbourhood || address.suburb || address.quarter,
            address.city || address.municipality || address.town,
            address.province || address.state,
        ].filter(Boolean).join(', ')
        || activeEventMap.value.locationAddress;
    eventLocationMapMessage.value = location.displayName
        ? 'Pin set. The event address was filled from the selected location.'
        : 'Pin set. Save the program to keep this event location.';
}

function handleProgramEventLocationError(message) {
    eventLocationMapMessage.value = message;
}

function resetScholarshipForm() {
    scholarshipForm.value = emptyScholarshipForm();
    existingApplicationCount.value = 0;
    awardedSlotsCount.value = 0;
    activeFormSection.value = 'overview';
    showAudienceDetails.value = false;
    showStageSchedules.value = false;
    showLocationMap.value = false;
    eventLocationMapStage.value = '';
    eventLocationMapMessage.value = '';
    customizeRubric.value = false;
    selectedTargetPresetKey.value = '';
    selectedEligibilityOption.value = '';
    imageFile.value = null;
    imagePreviewUrl.value = '';
    formError.value = '';
    providerLocationMessage.value = '';
    selectedCommitmentOption.value = 'provider_briefing';
    customCommitmentText.value = '';

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
    formError.value = '';

    if (!hasText(scholarshipForm.value.title)) {
        await focusFormField('overview', 'scholarship-title');
        formError.value = 'Add a scholarship title before saving.';
        return;
    }

    if (termsRequiredForSave.value && !hasText(scholarshipForm.value.description)) {
        await focusFormField('overview', 'scholarship-description');
        formError.value = 'Add a clear scholarship description before continuing.';
        return;
    }

    if (reviewSubmissionSelected.value && missingProgramReadinessItems.value.length > 0) {
        const firstMissingItem = missingProgramReadinessItems.value[0];
        const fieldId = readinessFocusTarget(firstMissingItem);

        if (fieldId) {
            await focusFormField(firstMissingItem.section, fieldId);
        } else {
            await openFormSection(firstMissingItem.section);
        }

        formError.value = `${firstMissingItem.label} is incomplete. ${firstMissingItem.help}`;
        return;
    }

    if (termsRequiredForSave.value && !scholarshipForm.value.termsAccepted) {
        await openFormSection('finish');
        formError.value = 'Accept the provider scholarship terms before submitting or updating this program.';
        return;
    }

    const scheduleError = programEventValidationMessage();

    if (scheduleError) {
        showStageSchedules.value = true;
        await openFormSection('process');
        formError.value = scheduleError;
        return;
    }

    if (
        termsRequiredForSave.value
        && (
            scholarshipForm.value.reviewRubric.some((criterion) => !hasText(criterion.label))
            || rubricWeightTotal.value !== 100
        )
    ) {
        customizeRubric.value = true;
        await focusFormField('process', `rubric-label-${scholarshipForm.value.reviewRubric[0]?.key}`);
        formError.value = 'Add a label for every review criterion and make the weights total 100%.';
        return;
    }

    const importantSave = {
        pending_review: {
            title: 'Submit this program for review?',
            message: 'The program will be sent to the administrator for approval before applicants can see it.',
            confirmLabel: 'Submit for review',
        },
        closed: {
            title: 'Close this program?',
            message: 'Applicants will no longer be able to submit new applications for this program.',
            confirmLabel: 'Close program',
            tone: 'danger',
        },
        published: isEditMode.value ? {
            title: 'Save changes to this published program?',
            message: 'Material changes may return the program to administrator review before it is published again.',
            confirmLabel: 'Save changes',
        } : null,
    }[scholarshipForm.value.status];

    if (importantSave && !await requestConfirmation(importantSave)) {
        return;
    }

    isSaving.value = true;

    const payload = new FormData();
    const fields = {
        title: scholarshipForm.value.title,
        category: scholarshipForm.value.category || '',
        program_cycle: scholarshipForm.value.programCycle || '',
        description: scholarshipForm.value.description,
        eligibility: scholarshipForm.value.eligibility,
        eligible_education_levels: scholarshipForm.value.eligibleEducationLevels.join('\n'),
        eligible_courses: canonicalizeProgramPathList(scholarshipForm.value.eligibleCourses),
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
        benefits: JSON.stringify(scholarshipForm.value.benefits),
        award_amount: cashGrantAmount(scholarshipForm.value.benefits),
        minimum_gwa: academicRequirementNeedsValue.value ? scholarshipForm.value.minimumGwa || '' : '',
        minimum_grade_scale: scholarshipForm.value.minimumGradeScale || '',
        slots_available: scholarshipForm.value.slotsAvailable || '',
        application_mode: scholarshipForm.value.applicationMode || '',
        selection_stages: JSON.stringify(scholarshipForm.value.selectionStages),
        exam_duration_minutes: scholarshipForm.value.selectionStages.includes('exam')
            ? scholarshipForm.value.examDurationMinutes || ''
            : '',
        exam_passing_score: scholarshipForm.value.selectionStages.includes('exam')
            ? scholarshipForm.value.examPassingScore || ''
            : '',
        program_events: JSON.stringify(programEventsPayload()),
        renewal_policy: scholarshipForm.value.renewalPolicy || '',
        return_service_contract: scholarshipForm.value.returnServiceContract || '',
        other_contract_terms: scholarshipForm.value.otherContractTerms || '',
        contact_email: scholarshipForm.value.contactEmail || '',
        contact_number: scholarshipForm.value.contactNumber || '',
        application_opens_at: scholarshipForm.value.applicationOpensAt || '',
        expected_results_at: scholarshipForm.value.expectedResultsAt || '',
        official_program_url: scholarshipForm.value.officialProgramUrl || '',
        contact_person: scholarshipForm.value.contactPerson || '',
        contact_department: scholarshipForm.value.contactDepartment || '',
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

        if (isEditMode.value) {
            fillScholarshipForm(response.data.scholarship);
        } else {
            resetScholarshipForm();
        }
    } catch (error) {
        const validationErrors = error.response?.data?.errors ?? {};
        const firstValidationMessage = Object.values(validationErrors).flat()[0];

        formError.value = firstValidationMessage
            ?? error.response?.data?.message
            ?? 'Unable to save this scholarship right now.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadFormData);
</script>

<template>
    <main class="min-h-screen bg-[#f4f5f7] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-5 sm:px-6 lg:px-8 lg:py-7">
            <div class="mx-auto max-w-6xl">
                <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase text-amber-700">
                            {{ isEditMode ? 'Edit program' : 'New program' }}
                        </p>
                        <h2 class="mt-1 truncate font-display text-2xl font-bold text-slate-950">
                            {{ scholarshipForm.title || (isEditMode ? 'Edit scholarship program' : 'Create scholarship program') }}
                        </h2>
                    </div>

                    <a
                        href="/provider/programs"
                        class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                    >
                        <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                        Back to programs
                    </a>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship form...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-5 space-y-4">
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

                    <div
                        v-if="selectionPlanLocked"
                        class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm"
                    >
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-shield-halved mt-0.5 text-amber-700" aria-hidden="true"></i>
                            <div>
                                <p class="font-bold text-slate-950">Existing applicant process protected</p>
                                <p class="mt-1 leading-6">
                                    {{ existingApplicationCount }} applicant{{ existingApplicationCount === 1 ? '' : 's' }} already use this review path. You can update schedules and other details, but exam and interview stages are locked. Duplicate the program for a different process.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form
                        ref="scholarshipFormElement"
                        class="scroll-mt-4 space-y-4"
                        novalidate
                        @submit.prevent="saveScholarship"
                    >
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <nav class="grid grid-cols-5 overflow-x-auto" aria-label="Program form sections">
                                    <button
                                        v-for="(section, index) in formSections"
                                        :key="section.id"
                                        type="button"
                                        :aria-current="activeFormSection === section.id ? 'step' : undefined"
                                        :class="[
                                            'flex min-w-[7.5rem] items-center justify-center gap-2 border-b-2 px-3 py-3 text-center transition',
                                            activeFormSection === section.id
                                                ? 'border-amber-500 bg-amber-50/60 text-slate-950'
                                                : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-900',
                                        ]"
                                        @click="openFormSection(section.id)"
                                    >
                                        <span
                                            :class="[
                                                'grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] font-bold',
                                                activeFormSection === section.id
                                                    ? 'bg-amber-500 text-slate-950'
                                                    : (formSectionProgress[section.id] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'),
                                            ]"
                                        >
                                            <i v-if="formSectionProgress[section.id]" class="fa-solid fa-check text-[10px]" aria-hidden="true"></i>
                                            <span v-else>{{ index + 1 }}</span>
                                        </span>
                                        <span class="text-sm font-bold">{{ section.label }}</span>
                                    </button>
                            </nav>
                            <div class="h-1 bg-slate-100" aria-hidden="true">
                                <div class="h-full bg-amber-500 transition-all" :style="{ width: `${formProgressPercent}%` }"></div>
                            </div>
                        </div>

                        <div class="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-5 py-4 sm:px-7">
                                <p class="text-xs font-bold text-slate-500">Step {{ activeFormSectionIndex + 1 }} of {{ formSections.length }}</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">{{ activeFormSectionMeta.label }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ activeFormSectionMeta.help }}</p>
                            </div>

                            <div class="p-5 sm:p-7">

                        <div
                            v-if="activeFormSection === 'finish' && publishWarnings.length"
                            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                        >
                            <p class="font-bold">
                                Review before submitting
                            </p>
                            <p class="mt-1 leading-6">
                                This program can still be saved as draft, but these sections should be completed before admin review: {{ publishWarnings.join(', ') }}.
                            </p>
                        </div>

                        <div v-show="['overview', 'audience', 'finish'].includes(activeFormSection)" :class="['grid gap-6', sectionCardClass]">
                            <div v-show="activeFormSection === 'overview'" class="grid gap-5 md:grid-cols-[minmax(0,1fr)_20rem]">
                                <div :class="fieldStackClass">
                                    <label :class="labelClass" for="scholarship-title">
                                        Scholarship title
                                        <span :class="requiredHintClass">Required</span>
                                    </label>
                                    <input
                                        id="scholarship-title"
                                        v-model="scholarshipForm.title"
                                        type="text"
                                        placeholder="Scholarship title"
                                        :class="inputClass"
                                    >
                                </div>

                                <div :class="[fieldCardClass, 'grid gap-3 sm:grid-cols-[4rem_1fr] sm:items-center']">
                                    <img
                                        :src="scholarshipImagePreview"
                                        alt="Scholarship program preview"
                                        class="h-14 w-14 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <label :class="labelClass" for="scholarship-image">
                                            Program logo
                                            <span :class="optionalHintClass">Optional</span>
                                        </label>
                                        <input
                                            id="scholarship-image"
                                            ref="imageInputElement"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="w-full min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 file:mr-2 file:rounded file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-xs file:font-bold file:text-white hover:file:bg-slate-800"
                                            @change="handleImageFile"
                                        >
                                        <p class="mt-1.5 text-xs leading-5 text-slate-500">
                                            Optional JPG, PNG, or WebP up to 4MB.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-show="activeFormSection === 'audience'" :class="fieldStackClass">
                                <label :class="labelClass" for="scholarship-eligibility">
                                    Eligibility summary
                                    <span :class="requiredHintClass">Required</span>
                                </label>
                                <select
                                    id="scholarship-eligibility"
                                    v-model="selectedEligibilityOption"
                                    :class="inputClass"
                                    @change="applyEligibilityOption"
                                >
                                    <option value="">
                                        Select an eligibility summary
                                    </option>
                                    <option
                                        v-for="option in eligibilityOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                    <option :value="customEligibilityOption">
                                        Custom eligibility summary
                                    </option>
                                </select>
                                <p class="mt-1.5 text-xs leading-5 text-slate-500">
                                    This is the short applicant-facing summary. The detailed rules below control matching.
                                </p>
                                <textarea
                                    v-if="selectedEligibilityOption === customEligibilityOption"
                                    id="scholarship-custom-eligibility"
                                    v-model="scholarshipForm.eligibility"
                                    rows="3"
                                    placeholder="Write a short summary of who can apply"
                                    :class="[inputClass, 'mt-3']"
                                ></textarea>
                            </div>

                            <div :class="formGridClass">
                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-category">
                                        Category
                                        <span :class="requiredHintClass">Required</span>
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

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-program-cycle">
                                        Program cycle
                                        <span :class="requiredHintClass">Required</span>
                                    </label>
                                    <input
                                        id="scholarship-program-cycle"
                                        v-model="scholarshipForm.programCycle"
                                        type="text"
                                        maxlength="100"
                                        placeholder="Example: SY 2026-2027 or 2026 intake"
                                        :class="inputClass"
                                    >
                                </div>

                                <div v-show="activeFormSection === 'overview'" :class="[fieldStackClass, 'md:col-span-2']">
                                    <label :class="labelClass" for="scholarship-description">
                                        Description
                                        <span :class="requiredHintClass">Required</span>
                                    </label>
                                    <textarea
                                        id="scholarship-description"
                                        v-model="scholarshipForm.description"
                                        rows="4"
                                        placeholder="Describe the scholarship program"
                                        :class="inputClass"
                                    ></textarea>
                                </div>

                                <div v-show="activeFormSection === 'overview'" class="border-t border-slate-200 pt-6 md:col-span-2">
                                    <p class="text-sm font-bold text-slate-950">Award and applications</p>
                                </div>

                                <ProgramBenefitsEditor
                                    v-show="activeFormSection === 'overview'"
                                    v-model="scholarshipForm.benefits"
                                />

                                <div v-show="activeFormSection === 'audience'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-grade-scale">
                                        Academic basis
                                        <span :class="optionalHintClass">Optional</span>
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

                                <div v-if="activeFormSection === 'audience' && academicRequirementNeedsValue" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-minimum-gwa">
                                        {{ selectedGradeScaleOption.inputLabel }}
                                        <span :class="requiredHintClass">Required</span>
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

                                <div v-else-if="activeFormSection === 'audience'" :class="basicFieldStackClass">
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

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-slots">
                                        Award slots
                                        <span :class="optionalHintClass">Optional</span>
                                    </label>
                                    <input
                                        id="scholarship-slots"
                                        v-model="scholarshipForm.slotsAvailable"
                                        type="number"
                                        :min="minimumAwardSlots"
                                        step="1"
                                        placeholder="No fixed limit"
                                        :class="inputClass"
                                    >
                                    <p v-if="awardedSlotsCount > 0" class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ awardedSlotsCount }} slot{{ awardedSlotsCount === 1 ? ' is' : 's are' }} already occupied.
                                    </p>
                                </div>

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-mode">
                                        Application mode
                                        <span :class="requiredHintClass">Required</span>
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

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-application-opens">
                                        Applications open
                                        <span :class="optionalHintClass">Optional</span>
                                    </label>
                                    <input
                                        id="scholarship-application-opens"
                                        v-model="scholarshipForm.applicationOpensAt"
                                        type="date"
                                        :max="scholarshipForm.deadline || undefined"
                                        :class="inputClass"
                                    >
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        Leave blank when applications should open immediately after publication.
                                    </p>
                                </div>

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-deadline">
                                        Deadline
                                        <span :class="requiredHintClass">Required</span>
                                    </label>
                                    <input
                                        id="scholarship-deadline"
                                        v-model="scholarshipForm.deadline"
                                        type="date"
                                        :min="todayDate"
                                        :class="inputClass"
                                    >
                                </div>

                                <div v-show="activeFormSection === 'overview'" :class="basicFieldStackClass">
                                    <label :class="labelClass" for="scholarship-expected-results">
                                        Expected initial results
                                        <span :class="optionalHintClass">Optional</span>
                                    </label>
                                    <input
                                        id="scholarship-expected-results"
                                        v-model="scholarshipForm.expectedResultsAt"
                                        type="date"
                                        :min="scholarshipForm.deadline || undefined"
                                        :class="inputClass"
                                    >
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        An estimate is enough; schedule details can still be announced later.
                                    </p>
                                </div>

                                <div v-show="activeFormSection === 'finish'" :class="[basicFieldStackClass, 'md:col-span-2']">
                                    <label :class="labelClass" for="scholarship-status">
                                        Save as
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

                        </div>

                            <section v-show="activeFormSection === 'process'" class="flex flex-col gap-6">
                                <div :class="sectionCardClass">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-base font-bold text-slate-950">
                                                Review stages
                                                <span :class="requiredHintClass">Required</span>
                                            </p>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                                Screening and distribution are included. Add an exam or interview only when your program uses them.
                                            </p>
                                        </div>
                                        <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                            {{ scholarshipForm.selectionStages.length }} stages
                                        </span>
                                    </div>

                                    <div class="mt-4 grid auto-rows-fr gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                        <button
                                            v-for="stage in selectionStageOptions"
                                            :key="stage.value"
                                            type="button"
                                            :aria-pressed="scholarshipForm.selectionStages.includes(stage.value)"
                                            :disabled="stage.required || selectionPlanLocked"
                                            :class="[
                                                'flex h-full flex-col rounded-md border p-3 text-left transition',
                                                scholarshipForm.selectionStages.includes(stage.value)
                                                    ? 'border-slate-900 bg-white shadow-sm'
                                                    : 'border-dashed border-slate-300 bg-slate-50 text-slate-600 hover:border-slate-400 hover:bg-white',
                                                stage.required || selectionPlanLocked ? 'cursor-default' : 'cursor-pointer',
                                                selectionPlanLocked ? 'opacity-70' : '',
                                            ]"
                                            @click="toggleSelectionStage(stage.value)"
                                        >
                                            <span class="flex w-full items-center justify-between gap-3">
                                                <span :class="['grid h-9 w-9 place-items-center rounded-md', scholarshipForm.selectionStages.includes(stage.value) ? 'bg-slate-950 text-white' : 'bg-white text-slate-500 ring-1 ring-slate-200']">
                                                    <i :class="stage.icon" aria-hidden="true"></i>
                                                </span>
                                                <span :class="['text-[10px] font-bold uppercase', scholarshipForm.selectionStages.includes(stage.value) ? 'text-emerald-700' : 'text-slate-400']">
                                                    {{ selectionPlanLocked ? 'Protected' : (stage.required ? 'Always included' : (scholarshipForm.selectionStages.includes(stage.value) ? 'Included' : 'Add stage')) }}
                                                </span>
                                            </span>
                                            <span class="mt-3 block font-bold text-slate-950">{{ stage.label }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ stage.description }}</span>
                                        </button>
                                    </div>
                                </div>

                                <div
                                    v-if="scholarshipForm.selectionStages.includes('exam')"
                                    :class="fieldCardClass"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-clipboard-question" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="text-base font-bold text-slate-950">Exam settings</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                Enter the basic rules for the exam your organization will conduct and grade.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <div :class="fieldStackClass">
                                            <label :class="labelClass" for="scholarship-exam-duration">
                                                Duration in minutes
                                                <span :class="requiredHintClass">Required</span>
                                            </label>
                                            <input
                                                id="scholarship-exam-duration"
                                                v-model="scholarshipForm.examDurationMinutes"
                                                type="number"
                                                min="15"
                                                max="480"
                                                step="5"
                                                placeholder="Example: 60"
                                                :class="inputClass"
                                            >
                                        </div>

                                        <div :class="fieldStackClass">
                                            <label :class="labelClass" for="scholarship-exam-passing-score">
                                                Passing score (%)
                                                <span :class="requiredHintClass">Required</span>
                                            </label>
                                            <input
                                                id="scholarship-exam-passing-score"
                                                v-model="scholarshipForm.examPassingScore"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                placeholder="Example: 75"
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <div :class="sectionCardClass">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-base font-bold text-slate-950">Stage schedules</p>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                                Dates are optional. Add one only when it is confirmed; the remaining details will appear after a date is selected.
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex w-fit items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            :aria-expanded="showStageSchedules"
                                            @click="showStageSchedules = !showStageSchedules"
                                        >
                                            <span>{{ showStageSchedules ? 'Hide schedules' : `Manage schedules (${scheduledProgramEventCount})` }}</span>
                                            <i :class="['fa-solid fa-chevron-down text-[10px] transition-transform', showStageSchedules ? 'rotate-180' : '']" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <div v-if="showStageSchedules" class="mt-4 grid gap-3 border-t border-slate-200 pt-4">
                                        <article
                                            v-for="stage in schedulableSelectionStages"
                                            :key="`schedule-${stage.value}`"
                                            class="rounded-md border border-slate-200 bg-white p-4"
                                        >
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="flex min-w-0 items-center gap-3">
                                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-950 text-white">
                                                        <i :class="stage.icon" aria-hidden="true"></i>
                                                    </span>
                                                    <span>
                                                        <span class="block text-sm font-bold text-slate-950">{{ stage.label }}</span>
                                                        <span class="block text-xs text-slate-500">Shared with applicants when confirmed</span>
                                                    </span>
                                                </span>
                                                <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', scholarshipForm.programEvents[stage.value].scheduledAt ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-500']">
                                                    {{ scholarshipForm.programEvents[stage.value].scheduledAt ? 'Scheduled' : 'Optional' }}
                                                </span>
                                            </div>

                                            <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                                                <div :class="fieldStackClass">
                                                    <label :class="labelClass" :for="`program-event-date-${stage.value}`">Date and time</label>
                                                    <input
                                                        :id="`program-event-date-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].scheduledAt"
                                                        type="datetime-local"
                                                        :min="minimumDateTimeForEvent(scholarshipForm.programEvents[stage.value])"
                                                        :class="inputClass"
                                                    >
                                                </div>

                                                <div :class="fieldStackClass">
                                                    <label :class="labelClass" :for="`program-event-mode-${stage.value}`">Mode</label>
                                                    <select
                                                        :id="`program-event-mode-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].mode"
                                                        :class="inputClass"
                                                    >
                                                        <option v-for="option in scheduleModeOptions" :key="option.value" :value="option.value">
                                                            {{ option.label }}
                                                        </option>
                                                    </select>
                                                </div>

                                                <template v-if="scholarshipForm.programEvents[stage.value].scheduledAt">
                                                <div class="md:col-span-2">
                                                    <label :class="labelClass" :for="`program-event-title-${stage.value}`">Schedule title</label>
                                                    <input
                                                        :id="`program-event-title-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].title"
                                                        type="text"
                                                        :placeholder="`${stage.label} schedule`"
                                                        :class="inputClass"
                                                    >
                                                </div>

                                                <div v-if="scheduleModeNeedsVenue(scholarshipForm.programEvents[stage.value].mode)">
                                                    <label :class="labelClass" :for="`program-event-venue-${stage.value}`">
                                                        Event venue
                                                        <span :class="requiredHintClass">Required</span>
                                                    </label>
                                                    <input
                                                        :id="`program-event-venue-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].venue"
                                                        type="text"
                                                        placeholder="Example: Community hall or provider office"
                                                        :class="inputClass"
                                                    >
                                                </div>

                                                <div v-if="scheduleModeNeedsVenue(scholarshipForm.programEvents[stage.value].mode)">
                                                    <label :class="labelClass" :for="`program-event-address-${stage.value}`">
                                                        Event address
                                                        <span :class="optionalHintClass">Optional</span>
                                                    </label>
                                                    <input
                                                        :id="`program-event-address-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].locationAddress"
                                                        type="text"
                                                        placeholder="General venue address"
                                                        :class="inputClass"
                                                        @input="clearProgramEventMapPoint(stage.value)"
                                                    >
                                                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                        <p class="text-xs leading-5 text-slate-500">
                                                            It can differ from the program address.
                                                        </p>
                                                        <button
                                                            type="button"
                                                            class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                                            @click="openProgramEventMap(stage.value)"
                                                        >
                                                            <i class="fa-solid fa-map-location-dot mr-1" aria-hidden="true"></i>
                                                            {{ scholarshipForm.programEvents[stage.value].latitude ? 'Review pin' : 'Set map pin' }}
                                                        </button>
                                                    </div>
                                                </div>

                                                <div
                                                    v-if="scheduleModeShowsOnlineUrl(scholarshipForm.programEvents[stage.value].mode)"
                                                    class="md:col-span-2"
                                                >
                                                    <label :class="labelClass" :for="`program-event-url-${stage.value}`">
                                                        Private online link
                                                        <span v-if="stage.value === 'distribution'" :class="optionalHintClass">Optional</span>
                                                        <span v-else :class="requiredHintClass">Required</span>
                                                    </label>
                                                    <input
                                                        :id="`program-event-url-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].onlineUrl"
                                                        type="url"
                                                        placeholder="https://..."
                                                        :class="inputClass"
                                                    >
                                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                                        {{ stage.value === 'distribution'
                                                            ? 'Add a release portal or briefing link only when recipients need one.'
                                                            : 'Visible only to applicants who reach this stage.' }}
                                                    </p>
                                                </div>

                                                <div class="md:col-span-2">
                                                    <label :class="labelClass" :for="`program-event-instructions-${stage.value}`">Applicant instructions</label>
                                                    <textarea
                                                        :id="`program-event-instructions-${stage.value}`"
                                                        v-model="scholarshipForm.programEvents[stage.value].instructions"
                                                        rows="3"
                                                        placeholder="What should applicants bring, prepare, or do?"
                                                        :class="inputClass"
                                                    ></textarea>
                                                </div>
                                                </template>
                                            </div>
                                        </article>
                                    </div>
                                </div>

                                <div :class="[sectionCardClass, 'grid items-stretch gap-4 lg:grid-cols-2']">
                                    <div class="lg:col-span-2">
                                        <p class="text-base font-bold text-slate-950">
                                            Applicant contact
                                            <span :class="requiredHintClass">Email or number required</span>
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Use the official contact applicants should reach for program questions.
                                        </p>
                                    </div>

                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-contact-person">
                                            Contact person
                                            <span :class="optionalHintClass">Optional</span>
                                        </label>
                                        <input
                                            id="scholarship-contact-person"
                                            v-model="scholarshipForm.contactPerson"
                                            type="text"
                                            maxlength="150"
                                            placeholder="Name of the program contact"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-contact-department">
                                            Office or department
                                            <span :class="optionalHintClass">Optional</span>
                                        </label>
                                        <input
                                            id="scholarship-contact-department"
                                            v-model="scholarshipForm.contactDepartment"
                                            type="text"
                                            maxlength="150"
                                            placeholder="Example: Scholarship Office"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-contact-email">Contact email</label>
                                        <input
                                            id="scholarship-contact-email"
                                            v-model="scholarshipForm.contactEmail"
                                            type="email"
                                            placeholder="scholarship.office@example.com"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-contact-number">Contact number</label>
                                        <input
                                            id="scholarship-contact-number"
                                            v-model="scholarshipForm.contactNumber"
                                            type="text"
                                            placeholder="0917 123 4567"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
                                        <label :class="labelClass" for="scholarship-official-url">
                                            Official program page
                                            <span :class="optionalHintClass">Optional</span>
                                        </label>
                                        <input
                                            id="scholarship-official-url"
                                            v-model="scholarshipForm.officialProgramUrl"
                                            type="url"
                                            maxlength="2048"
                                            placeholder="https://provider.example.org/scholarships/program"
                                            :class="inputClass"
                                        >
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            Applicants can use this public page to confirm the official announcement.
                                        </p>
                                    </div>

                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4 lg:col-span-2">
                                        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)]">
                                            <div>
                                                <p class="text-sm font-bold text-slate-950">Possible commitment after acceptance</p>
                                                <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                                    This is only a short preview, not the final agreement. The provider will explain and confirm any commitment after the applicant is accepted.
                                                </p>
                                            </div>
                                            <div :class="fieldStackClass">
                                                <label :class="labelClass" for="scholarship-commitment-option">Commitment preview</label>
                                                <select
                                                    id="scholarship-commitment-option"
                                                    v-model="selectedCommitmentOption"
                                                    :class="inputClass"
                                                    @change="applySelectedCommitment"
                                                >
                                                    <option v-for="option in commitmentOptions" :key="option.value" :value="option.value">
                                                        {{ option.label }}
                                                    </option>
                                                </select>
                                            </div>
                                        </div>

                                        <div v-if="selectedCommitmentOption === 'custom'" class="mt-4 border-t border-slate-200 pt-4">
                                            <label :class="labelClass" for="scholarship-custom-commitment">
                                                Custom short preview
                                                <span :class="optionalHintClass">Optional</span>
                                            </label>
                                            <textarea
                                                id="scholarship-custom-commitment"
                                                v-model="customCommitmentText"
                                                rows="3"
                                                maxlength="600"
                                                placeholder="Briefly state what may apply. The full agreement can be explained after acceptance."
                                                :class="inputClass"
                                                @input="applyCustomCommitment"
                                            ></textarea>
                                            <div class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                                                <span>Do not paste the complete contract or terms here.</span>
                                                <span>{{ customCommitmentText.length }}/600</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-show="activeFormSection === 'audience'" :class="['mt-6 border-t border-slate-200 pt-6', sectionCardClass]">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-950">
                                        Applicant matching
                                    </h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        Add restrictions only when they are part of the official eligibility rules.
                                    </p>
                                </div>

                                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">Detailed matching rules</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Optional. Limit school type, course, year level, location, or household income only when needed.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                                        :aria-expanded="showAudienceDetails"
                                        @click="showAudienceDetails = !showAudienceDetails"
                                    >
                                        <span>{{ showAudienceDetails ? 'Close detailed rules' : 'Adjust detailed rules' }}</span>
                                        <i
                                            :class="[
                                                'fa-solid fa-chevron-down text-[10px] transition-transform',
                                                showAudienceDetails ? 'rotate-180' : '',
                                            ]"
                                            aria-hidden="true"
                                        ></i>
                                    </button>
                                </div>

                                <div v-if="showAudienceDetails" class="mt-4 grid items-start gap-x-5 gap-y-6 rounded-md border border-slate-200 bg-white p-4 sm:p-5 lg:grid-cols-2">
                                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between lg:col-span-2">
                                        <div class="min-w-0">
                                            <p class="text-[10px] font-bold uppercase text-amber-700">
                                                Target setup
                                            </p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">
                                                {{ activeTargetForm.title }}
                                            </p>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                                {{ activeTargetForm.guidance }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                            @click="applyActiveTargetDefaults"
                                        >
                                            Use suggested defaults
                                        </button>
                                    </div>

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                            <div>
                                                <p class="text-sm font-bold text-slate-900">Education levels</p>
                                                <p class="mt-1 text-xs text-slate-500">Select every learner level that may apply.</p>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-semibold text-slate-500">
                                                    {{ scholarshipForm.eligibleEducationLevels.length ? `${scholarshipForm.eligibleEducationLevels.length} selected` : 'Open to all' }}
                                                </span>
                                                <button type="button" class="text-xs font-bold text-slate-700 hover:text-slate-950" @click="selectAllOptions('eligibleEducationLevels', educationLevelOptions)">
                                                    Select all
                                                </button>
                                                <span class="text-slate-300" aria-hidden="true">|</span>
                                                <button type="button" class="text-xs font-bold text-slate-700 hover:text-slate-950" @click="scholarshipForm.eligibleEducationLevels = []">
                                                    Open to all
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            <label
                                                v-for="option in educationLevelOptions"
                                                :key="option.value"
                                                :class="[
                                                    'flex cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2.5 text-xs font-semibold transition',
                                                    scholarshipForm.eligibleEducationLevels.includes(option.value)
                                                        ? 'border-slate-400 bg-slate-50 text-slate-950'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300',
                                                ]"
                                            >
                                                <input
                                                    v-model="scholarshipForm.eligibleEducationLevels"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    class="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400"
                                                >
                                                {{ option.label }}
                                            </label>
                                        </div>
                                    </div>

                                    <div :class="[fieldStackClass, 'lg:col-span-2']">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                            <div>
                                                <p class="text-sm font-bold text-slate-900">School types</p>
                                                <p class="mt-1 text-xs text-slate-500">Leave this open when any school type is accepted.</p>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-semibold text-slate-500">
                                                    {{ scholarshipForm.eligibleSchoolTypes.length ? `${scholarshipForm.eligibleSchoolTypes.length} selected` : 'Open to all' }}
                                                </span>
                                                <button type="button" class="text-xs font-bold text-slate-700 hover:text-slate-950" @click="selectAllOptions('eligibleSchoolTypes', targetSchoolTypeOptions)">
                                                    Select all
                                                </button>
                                                <span class="text-slate-300" aria-hidden="true">|</span>
                                                <button type="button" class="text-xs font-bold text-slate-700 hover:text-slate-950" @click="scholarshipForm.eligibleSchoolTypes = []">
                                                    Open to all
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            <label
                                                v-for="option in targetSchoolTypeOptions"
                                                :key="option.value"
                                                :class="[
                                                    'flex cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2.5 text-xs font-semibold transition',
                                                    scholarshipForm.eligibleSchoolTypes.includes(option.value)
                                                        ? 'border-slate-400 bg-slate-50 text-slate-950'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300',
                                                ]"
                                            >
                                                <input
                                                    v-model="scholarshipForm.eligibleSchoolTypes"
                                                    type="checkbox"
                                                    :value="option.value"
                                                    class="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400"
                                                >
                                                {{ option.label }}
                                            </label>
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

                                    <div class="border-t border-slate-200 pt-4 lg:col-span-2">
                                        <p class="text-sm font-bold text-slate-900">Additional limits</p>
                                        <p class="mt-1 text-xs text-slate-500">Only fill these fields when the scholarship has a specific restriction.</p>
                                    </div>

                                    <div v-if="activeTargetForm.showProgramPath" :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-courses">
                                            {{ activeTargetForm.programPathLabel }}
                                        </label>
                                        <select
                                            id="scholarship-courses"
                                            v-model="programPathChoice"
                                            :class="inputClass"
                                            @change="chooseProgramPath"
                                        >
                                            <option value="">Select an option to add</option>
                                            <option
                                                v-for="option in programPathSelectOptions"
                                                :key="option"
                                                :value="option"
                                                :disabled="option !== 'Other' && isProgramPathSelected(option)"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                        <div v-if="programPathChoice === 'Other'" class="mt-2 flex flex-col gap-2 sm:flex-row">
                                            <input
                                                v-model="customProgramPath"
                                                type="text"
                                                :placeholder="`Enter another ${activeTargetForm.programPathLabel.toLowerCase()}`"
                                                :class="inputClass"
                                                @keyup.enter.prevent="addCustomProgramPath"
                                            >
                                            <button
                                                type="button"
                                                class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                                @click="addCustomProgramPath"
                                            >
                                                Add
                                            </button>
                                        </div>
                                        <div v-if="selectedProgramPaths.length" class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                v-for="path in selectedProgramPaths"
                                                :key="path"
                                                type="button"
                                                class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-left text-xs font-semibold text-slate-700 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                :title="`Remove ${path}`"
                                                @click="removeEligibleProgramPath(path)"
                                            >
                                                <span>{{ path }}</span>
                                                <i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <p v-else class="mt-2 text-xs font-semibold text-emerald-700">
                                            No restriction selected. All applicable learner paths can match.
                                        </p>
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
                                            class="mt-3 self-start rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                            @click="scholarshipForm.eligibleCourses = canonicalizeProgramPathList(activeTargetForm.programPathTemplate)"
                                        >
                                            Mark as not applicable
                                        </button>
                                    </div>

                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-years">
                                            {{ activeTargetForm.levelLabel }}
                                        </label>
                                        <textarea
                                            id="scholarship-years"
                                            v-model="scholarshipForm.eligibleYearLevels"
                                            rows="2"
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
                                        <input
                                            id="scholarship-locations"
                                            v-model="scholarshipForm.eligibleLocations"
                                            type="text"
                                            placeholder="Example: Manila, Cebu, Quezon City"
                                            :class="inputClass"
                                        >
                                    </div>

                                    <div :class="fieldStackClass">
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
                            </section>

                            <fieldset v-show="activeFormSection === 'documents'" :class="['border-b border-slate-200 pb-7', sectionCardClass]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Map location
                                            <span :class="requiredHintClass">Address and pin required</span>
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Add the office, campus, or service address. Search an address or click the map to set a pin and fill the address.
                                        </p>
                                    </div>

                                    <button
                                        id="scholarship-map-toggle"
                                        type="button"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        @click="openLocationMap"
                                    >
                                        <i class="fa-solid fa-map-location-dot mr-1.5" aria-hidden="true"></i>
                                        {{ scholarshipForm.latitude ? 'Review map pin' : 'Set map pin' }}
                                    </button>
                                </div>

                                <div class="mt-4 grid items-stretch gap-4 lg:grid-cols-2">
                                    <div :class="fieldStackClass">
                                        <label :class="labelClass" for="scholarship-location-name">
                                            Location name
                                            <span :class="requiredHintClass">Required</span>
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
                                            <span :class="requiredHintClass">Required</span>
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

                                <p v-if="providerLocationMessage" class="mt-3 text-xs font-semibold text-slate-700">
                                    {{ providerLocationMessage }}
                                </p>
                                <p v-else-if="scholarshipForm.latitude" class="mt-3 flex items-center gap-2 text-xs font-semibold text-emerald-700">
                                    <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                    A map pin is set for this program.
                                </p>
                            </fieldset>

                            <fieldset v-show="activeFormSection === 'documents'" :class="['pt-7', sectionCardClass]">
                                <div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Document requirements
                                            <span :class="requiredHintClass">At least one</span>
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ selectedRequirementCount }} document{{ selectedRequirementCount === 1 ? '' : 's' }} selected.
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100"
                                        @click="selectCommonRequirements"
                                    >
                                        Use common set
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-50"
                                        @click="clearRequirements"
                                    >
                                        Clear all
                                    </button>
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
                                        <span :class="optionalHintClass">Optional</span>
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
                            </fieldset>

                            <fieldset v-show="activeFormSection === 'process'" :class="['mt-7 border-t border-slate-200 pt-7', sectionCardClass]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Provider review rubric
                                            <span :class="requiredHintClass">Required</span>
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
                                            @click="customizeRubric = !customizeRubric"
                                        >
                                            {{ customizeRubric ? 'Hide editor' : 'Customize scoring' }}
                                        </button>
                                        <button
                                            v-if="customizeRubric"
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                            @click="resetReviewRubric"
                                        >
                                            Use defaults
                                        </button>
                                        <button
                                            v-if="customizeRubric"
                                            type="button"
                                            :disabled="scholarshipForm.reviewRubric.length >= 6"
                                            class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="addReviewCriterion"
                                        >
                                            Add criterion
                                        </button>
                                    </div>
                                </div>

                                <p v-if="!customizeRubric" class="mt-3 text-sm text-slate-600">
                                    {{ scholarshipForm.reviewRubric.length }} standard criteria are ready to use.
                                </p>

                                <div v-if="customizeRubric" class="mt-4 grid gap-3">
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

                                <p v-if="customizeRubric && rubricWeightTotal !== 100" class="mt-3 text-xs font-semibold text-amber-800">
                                    Adjust the weights until they total exactly 100%.
                                </p>
                            </fieldset>

                        <section v-show="activeFormSection === 'finish'" :class="['border-b border-slate-200 pb-6', sectionCardClass]">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Applicant preview</p>
                                    <p class="mt-1 text-sm text-slate-500">This is the main information applicants will use before applying.</p>
                                </div>
                                <span class="w-fit shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-bold text-white">
                                    {{ scholarshipForm.status === 'draft' ? 'Draft' : statusOptions.find((option) => option.value === scholarshipForm.status)?.label }}
                                </span>
                            </div>

                            <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                <div class="border-b border-slate-200 bg-white p-4 sm:p-5">
                                    <div class="flex min-w-0 items-start gap-4">
                                        <img
                                            :src="scholarshipImagePreview"
                                            alt="Program logo"
                                            class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">
                                                {{ scholarshipForm.category || 'Scholarship program' }}
                                            </p>
                                            <h4 class="mt-1 font-display text-xl font-bold leading-tight text-slate-950">
                                                {{ scholarshipForm.title || 'Untitled scholarship' }}
                                            </h4>
                                            <p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">
                                                {{ scholarshipForm.description || 'Add a clear program description.' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <dl class="grid grid-cols-2 border-b border-slate-200 bg-white sm:grid-cols-4">
                                    <div class="border-r border-slate-200 p-3 last:border-r-0 sm:p-4">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Cycle</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-800">{{ scholarshipForm.programCycle || 'Not specified' }}</dd>
                                    </div>
                                    <div class="border-r border-slate-200 p-3 last:border-r-0 sm:p-4">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Opens</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-800">{{ formatPreviewDate(scholarshipForm.applicationOpensAt, 'On publication') }}</dd>
                                    </div>
                                    <div class="border-r border-t border-slate-200 p-3 last:border-r-0 sm:border-t-0 sm:p-4">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Deadline</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-800">{{ formatPreviewDate(scholarshipForm.deadline) }}</dd>
                                    </div>
                                    <div class="border-t border-slate-200 p-3 sm:border-t-0 sm:p-4">
                                        <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Initial results</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-800">{{ formatPreviewDate(scholarshipForm.expectedResultsAt) }}</dd>
                                    </div>
                                </dl>

                                <div class="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Benefits</p>
                                        <div v-if="scholarshipForm.benefits.length" class="mt-2 grid gap-2">
                                            <div
                                                v-for="benefit in scholarshipForm.benefits.slice(0, 3)"
                                                :key="benefit.type"
                                                class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-slate-200"
                                            >
                                                <span class="font-bold text-slate-900">{{ benefit.title }}</span>
                                                <span v-if="benefit.duration" class="ml-1 text-slate-500">| {{ benefit.duration }}</span>
                                            </div>
                                            <p v-if="scholarshipForm.benefits.length > 3" class="text-xs font-semibold text-slate-500">
                                                +{{ scholarshipForm.benefits.length - 3 }} more benefit{{ scholarshipForm.benefits.length - 3 === 1 ? '' : 's' }}
                                            </p>
                                        </div>
                                        <p v-else class="mt-2 text-sm text-slate-500">No benefits added yet.</p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Who can apply</p>
                                        <p class="mt-2 text-sm leading-6 text-slate-700">
                                            {{ scholarshipForm.eligibility || 'Add an eligibility summary.' }}
                                        </p>
                                        <p class="mt-3 text-xs font-semibold text-slate-500">
                                            {{ applicationModeLabel(scholarshipForm.applicationMode) }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    v-if="scholarshipForm.contactPerson || scholarshipForm.contactDepartment || scholarshipForm.contactEmail || scholarshipForm.contactNumber || scholarshipForm.officialProgramUrl"
                                    class="border-t border-slate-200 bg-white px-4 py-3 text-xs leading-5 text-slate-600 sm:px-5"
                                >
                                    <span class="font-bold text-slate-900">
                                        {{ scholarshipForm.contactDepartment || scholarshipForm.contactPerson || 'Program contact' }}
                                    </span>
                                    <span v-if="scholarshipForm.contactDepartment && scholarshipForm.contactPerson"> | {{ scholarshipForm.contactPerson }}</span>
                                    <span v-if="scholarshipForm.contactEmail"> | {{ scholarshipForm.contactEmail }}</span>
                                    <span v-if="scholarshipForm.contactNumber"> | {{ scholarshipForm.contactNumber }}</span>
                                    <a
                                        v-if="officialProgramPreviewUrl"
                                        :href="officialProgramPreviewUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ml-2 font-bold text-slate-900 underline decoration-amber-400 underline-offset-2"
                                    >Official page</a>
                                </div>
                            </div>
                            <p v-if="missingProgramReadinessItems.length" class="mt-4 text-sm font-semibold text-amber-800">
                                {{ missingProgramReadinessItems.length }} required item{{ missingProgramReadinessItems.length === 1 ? '' : 's' }} still need attention before review.
                            </p>
                            <p v-else class="mt-4 flex items-center gap-2 text-sm font-semibold text-emerald-700">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                Program details are ready for review.
                            </p>
                        </section>

                        <div v-show="activeFormSection === 'finish'" class="pt-6">
                            <TermsAgreement
                                v-if="termsRequiredForSave"
                                v-model="scholarshipForm.termsAccepted"
                                context="scholarship"
                            />
                            <p v-else class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                                You can save this draft now. Terms are required only when you submit it for admin review.
                            </p>
                        </div>
                            </div>

                            <div class="sticky bottom-0 z-20 border-t border-slate-200 bg-white/95 p-4 backdrop-blur">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p v-if="formError" class="text-sm font-semibold text-rose-700">
                                            {{ formError }}
                                        </p>
                                        <p v-else class="text-xs font-semibold text-slate-500">
                                            Step {{ activeFormSectionIndex + 1 }} of {{ formSections.length }}: {{ activeFormSectionMeta.label }}
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            :disabled="activeFormSectionIndex === 0"
                                            class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="goToPreviousFormSection"
                                        >
                                            Previous
                                        </button>
                                        <button
                                            v-if="activeFormSectionIndex < formSections.length - 1"
                                            type="button"
                                            class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="goToNextFormSection"
                                        >
                                            Next: {{ formSections[activeFormSectionIndex + 1]?.label }}
                                        </button>
                                        <button
                                            v-if="activeFormSection === 'finish'"
                                            type="submit"
                                            :disabled="isSaving || !canPostScholarships"
                                            class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                        >
                                            {{ submitButtonLabel }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <ProviderFooter />
            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="showLocationMap"
                class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="program-location-map-title"
                tabindex="-1"
                @click.self="closeLocationMap"
                @keydown.esc="closeLocationMap"
            >
                <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                    <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-bold uppercase text-amber-700">Program location</p>
                            <h2 id="program-location-map-title" class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">
                                Set the map pin
                            </h2>
                            <p class="mt-1 truncate text-xs text-slate-500">
                                {{ scholarshipFormMapAddress || 'Add a full address, then search or select the location on the map.' }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close program location map"
                            @click="closeLocationMap"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-3 sm:p-4">
                        <LeafletMapPreview
                            :address="scholarshipFormMapAddress"
                            :latitude="scholarshipForm.latitude"
                            :longitude="scholarshipForm.longitude"
                            title="Scholarship address map preview"
                            :marker-text="scholarshipForm.locationName || 'Scholarship location'"
                            :geocode-trigger="providerAddressLookupTrigger"
                            height="min(58vh, 32rem)"
                            picker
                            @resolved="handleScholarshipLocationResolved"
                            @picked="handleScholarshipLocationPicked"
                            @error="handleScholarshipLocationError"
                        />
                    </div>

                    <footer class="flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p :class="['text-xs font-semibold', scholarshipForm.latitude ? 'text-emerald-700' : 'text-slate-500']">
                            {{ providerLocationMessage || (scholarshipForm.latitude ? 'Pin selected. Use this location to return to the form.' : 'Click the map to place a pin.') }}
                        </p>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                :disabled="!scholarshipFormMapAddress"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="lookupScholarshipAddress"
                            >
                                Search address
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                @click="closeLocationMap"
                            >
                                {{ scholarshipForm.latitude ? 'Use this location' : 'Close map' }}
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="activeEventMap && activeEventMapStage"
                class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="program-event-map-title"
                tabindex="-1"
                @click.self="closeProgramEventMap"
                @keydown.esc="closeProgramEventMap"
            >
                <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                    <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                            <i :class="activeEventMapStage.icon" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-bold uppercase text-amber-700">
                                {{ activeEventMapStage.label }} schedule
                            </p>
                            <h2 id="program-event-map-title" class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">
                                Set the event map pin
                            </h2>
                            <p class="mt-1 truncate text-xs text-slate-500">
                                {{ activeEventMapAddress || 'Add an event venue or address, then select its location on the map.' }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close event location map"
                            @click="closeProgramEventMap"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-3 sm:p-4">
                        <LeafletMapPreview
                            :address="activeEventMapAddress"
                            :latitude="activeEventMap.latitude"
                            :longitude="activeEventMap.longitude"
                            :title="`${activeEventMapStage.label} event map`"
                            :marker-text="activeEventMap.venue || `${activeEventMapStage.label} venue`"
                            :geocode-trigger="eventAddressLookupTrigger"
                            height="min(58vh, 32rem)"
                            picker
                            @resolved="handleProgramEventLocationResolved"
                            @picked="handleProgramEventLocationPicked"
                            @error="handleProgramEventLocationError"
                        />
                    </div>

                    <footer class="flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p :class="['text-xs font-semibold', activeEventMap.latitude ? 'text-emerald-700' : 'text-slate-500']">
                            {{ eventLocationMapMessage || (activeEventMap.latitude ? 'Event pin selected.' : 'Click the map to place the event pin.') }}
                        </p>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                :disabled="!activeEventMapAddress"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="lookupProgramEventAddress"
                            >
                                Search address
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                @click="closeProgramEventMap"
                            >
                                {{ activeEventMap.latitude ? 'Use this location' : 'Close map' }}
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>
    </main>
</template>
