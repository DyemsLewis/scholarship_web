<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantProfileRecord from '../components/profile/ApplicantProfileRecord.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import PrivacyNoticeCard from '../components/PrivacyNoticeCard.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { formatFileSize } from '../support/display';
import { limitPhoneNumber } from '../support/phoneNumber';
import {
    alsPathOptions,
    collegePathOptions,
    juniorHighPathOptions,
    seniorHighPathOptions,
    tvetPathOptions,
} from '../support/learnerProgramPaths';
import {
    citiesForLocation,
    findLocationOption,
    findPhilippineRegion,
    philippineRegionOptions,
    provincesForRegion,
} from '../support/philippineLocations';
import { showPortalToast } from '../support/portalToast';

const isLoading = ref(true);
const showProfileGuidance = ref(false);
const isSaving = ref(false);
const errorMessage = ref('');
const locationMessage = ref('');
const showProfileMapModal = ref(false);
const user = ref(null);
const form = ref(emptyForm());
const profileSearchParams = new URLSearchParams(window.location.search);
const requestedProfileSection = profileSearchParams.get('section');
const editableProfileSectionIds = ['personal', 'academic', 'household', 'location', 'verification'];
const showProfileEditorModal = ref(editableProfileSectionIds.includes(requestedProfileSection));
const activeSection = ref(editableProfileSectionIds.includes(requestedProfileSection)
    ? requestedProfileSection
    : 'personal');
const profileEditorCloseButton = ref(null);
let profileEditorTrigger = null;
let allowUnsavedNavigation = false;
let isHandlingUnsavedNavigation = false;
const isUploadingProfilePhoto = ref(false);
const isDeletingProfilePhoto = ref(false);
const showProfileCamera = ref(false);
const profileCameraVideo = ref(null);
const profileCameraStream = ref(null);
const isStartingProfileCamera = ref(false);
const isCapturingProfilePhoto = ref(false);
const profileCameraError = ref('');
const applicantProvinceOptions = ref([]);
const applicantCityOptions = ref([]);
const isLoadingApplicantProvinces = ref(false);
const isLoadingApplicantCities = ref(false);
const locationOptionsError = ref('');
let locationHierarchyRequestId = 0;
const savedFormSnapshot = ref('');
const fieldErrors = ref({});
const matchSummary = ref({
    available_programs: 0,
    eligible_programs: 0,
    strong_matches: 0,
    needs_review: 0,
    blocked_programs: 0,
    top_gaps: [],
});
const verificationDocuments = ref([]);
const academicOcr = ref({
    enabled: false,
    configured: false,
    provider: 'OCR.space',
    max_file_size_mb: 1,
    supported_extensions: ['pdf', 'jpg', 'jpeg', 'png'],
});
const verificationDocumentTermsAccepted = ref(false);
const uploadingVerificationDocumentType = ref('');
const deletingVerificationDocumentId = ref(null);
const rescanningAcademicDocumentId = ref(null);
const previewDocument = ref(null);
const preparedDocumentsCount = ref(0);
const platformActiveScholarships = ref([]);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const fieldClass = 'min-w-0';
const labelClass = 'mb-2 block text-sm font-semibold leading-5 text-slate-700';
const inputClass = 'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const compactInputClass = 'min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm uppercase text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const sectionCardClass = 'student-card overflow-hidden';
const sectionHeaderClass = 'flex flex-col gap-3 border-b border-slate-200 bg-white p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5';
const sectionBodyClass = 'bg-white p-4 sm:p-5';
const sectionStatusPillClass = 'w-fit rounded-md px-2.5 py-1.5 text-xs font-bold';
const formPanelClass = 'rounded-lg border border-slate-200 bg-slate-50 p-4 sm:p-5';
const formPanelTitleClass = 'text-sm font-bold text-slate-950';
const formPanelDescriptionClass = 'mt-1 text-xs leading-5 text-slate-500';
const optionButtonBaseClass = 'inline-flex min-h-10 items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm font-semibold transition';
const birthdateMaximum = new Date();
birthdateMaximum.setFullYear(birthdateMaximum.getFullYear() - 5);
const birthdateMinimum = new Date();
birthdateMinimum.setFullYear(birthdateMinimum.getFullYear() - 100);
const birthdateMinimumValue = birthdateMinimum.toISOString().slice(0, 10);
const birthdateMaximumValue = birthdateMaximum.toISOString().slice(0, 10);

const enrollmentOptions = ['Enrolled', 'Incoming student', 'Continuing student', 'Graduating', 'Not currently enrolled'];
const incomeOptions = ['Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const citizenshipOptions = [
    { value: 'filipino', label: 'Filipino citizen' },
    { value: 'dual_filipino', label: 'Dual citizen, including Filipino' },
    { value: 'other', label: 'Other citizenship status' },
];
const academicTermOptions = [
    { value: 'full_year', label: 'Full school year' },
    { value: 'first_grading_period', label: 'First grading period' },
    { value: 'second_grading_period', label: 'Second grading period' },
    { value: 'third_grading_period', label: 'Third grading period' },
    { value: 'fourth_grading_period', label: 'Fourth grading period' },
    { value: 'first_semester', label: 'First semester / term' },
    { value: 'second_semester', label: 'Second semester / term' },
    { value: 'third_term', label: 'Third trimester / term' },
    { value: 'summer_term', label: 'Summer term' },
    { value: 'latest_completed_term', label: 'Latest completed term' },
    { value: 'not_applicable', label: 'Not applicable' },
];
const currentScholarshipOptions = [
    { value: 'none', label: 'No scholarship outside this portal' },
    { value: 'receiving', label: 'Receiving scholarship support outside this portal' },
    { value: 'pending', label: 'Outside scholarship application is pending' },
    { value: 'completed', label: 'Outside scholarship already completed' },
];
const schoolYearStart = new Date().getMonth() >= 5 ? new Date().getFullYear() : new Date().getFullYear() - 1;
const academicYearOptions = [
    schoolYearStart,
    schoolYearStart + 1,
    ...Array.from({ length: 4 }, (_, index) => schoolYearStart - index - 1),
].map((start) => `${start}-${start + 1}`);
const accountManagerOptions = [
    { value: 'learner', label: 'Learner / student' },
    { value: 'parent_guardian', label: 'Parent or guardian' },
    { value: 'relative', label: 'Relative' },
    { value: 'school_representative', label: 'School representative' },
    { value: 'other', label: 'Other trusted person' },
];
const guardianRelationshipOptions = ['Parent / guardian', 'Mother', 'Father', 'Grandparent', 'Sibling', 'Relative', 'Teacher / adviser', 'Other'];
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
    { value: 'pass_fail', label: 'Pass/fail or competency based' },
    { value: 'other', label: 'Other institutional grading system' },
];
const genderOptions = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'non_binary', label: 'Non-binary' },
    { value: 'prefer_not_to_say', label: 'Prefer not to say' },
];
const suffixOptions = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
const fieldLabels = {
    first_name: 'First name',
    last_name: 'Last name',
    has_profile_photo: 'Applicant 1x1 or 2x2 photo',
    middle_initial: 'Middle initial',
    suffix: 'Suffix',
    gender: 'Gender',
    contact_number: 'Contact number',
    account_managed_by: 'Account managed by',
    citizenship_status: 'Citizenship declaration',
    birthdate: 'Birthdate',
    education_level: 'Education level',
    school: 'School / learning institution',
    school_type: 'Institution type',
    learner_reference_number: 'Learner / student ID',
    course_or_strand: 'Program path',
    year_level: 'Grade / year / level',
    enrollment_status: 'Enrollment status',
    academic_year: 'Academic year',
    academic_term: 'Academic term or grading period',
    gwa: 'GWA / general average',
    grading_scale: 'Grading scale',
    income_bracket: 'Household income bracket',
    household_size: 'Household size',
    current_scholarship_status: 'Outside scholarship status',
    current_scholarship_details: 'Outside scholarship details',
    address: 'Address',
    barangay: 'Barangay',
    city: 'City / municipality',
    province: 'Province',
    region: 'Region',
    guardian_name: 'Guardian name',
    guardian_relationship: 'Relationship to learner',
    guardian_contact: 'Guardian contact',
    guardian_email: 'Guardian email',
    guardian_is_account_owner: 'Guardian manages this account',
};

const profileSections = [
    {
        id: 'personal',
        label: 'Personal',
        detail: 'Identity and contact',
        icon: 'fa-solid fa-address-card',
        impact: 'Identity and contact information.',
        required: true,
        fields: ['first_name', 'middle_initial', 'last_name', 'suffix', 'has_profile_photo', 'gender', 'birthdate', 'contact_number', 'account_managed_by', 'citizenship_status'],
        requiredFields: ['first_name', 'last_name', 'has_profile_photo', 'birthdate', 'contact_number', 'account_managed_by', 'citizenship_status'],
    },
    {
        id: 'academic',
        label: 'Learning',
        detail: 'School record',
        icon: 'fa-solid fa-book-open-reader',
        impact: 'Matching details.',
        required: true,
        fields: ['education_level', 'school', 'school_type', 'learner_reference_number', 'course_or_strand', 'year_level', 'enrollment_status', 'academic_year', 'academic_term', 'grading_scale', 'gwa'],
        requiredFields: ['education_level', 'school', 'course_or_strand', 'year_level', 'enrollment_status', 'academic_year', 'academic_term', 'grading_scale', 'gwa'],
    },
    {
        id: 'household',
        label: 'Household',
        detail: 'Eligibility and guardian',
        icon: 'fa-solid fa-house-chimney-user',
        impact: 'Household, guardian, and scholarship status.',
        required: true,
        fields: ['income_bracket', 'household_size', 'current_scholarship_status', 'current_scholarship_details', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email', 'guardian_is_account_owner'],
        requiredFields: ['income_bracket', 'household_size', 'current_scholarship_status', 'current_scholarship_details', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email'],
    },
    {
        id: 'location',
        label: 'Location',
        detail: 'Address and travel',
        icon: 'fa-solid fa-location-dot',
        impact: 'Distance and coverage.',
        required: true,
        fields: ['address', 'barangay', 'city', 'province', 'region'],
        requiredFields: ['city', 'province', 'region'],
    },
    {
        id: 'review',
        label: 'Review',
        detail: 'Final check',
        icon: 'fa-solid fa-clipboard-check',
        impact: 'Check before applying.',
        required: false,
        fields: [],
    },
    {
        id: 'verification',
        label: 'Supporting evidence',
        detail: 'Required records',
        icon: 'fa-solid fa-file-circle-check',
        impact: 'Upload the records providers need to check.',
        required: false,
        fields: [],
    },
];

const courseRequiredLevels = ['senior_high_school', 'college', 'tvet'];
const gradesRequiredLevels = ['elementary', 'junior_high_school', 'senior_high_school', 'college', 'tvet', 'als', 'other'];
const guardianRequiredLevels = ['preschool', 'elementary', 'junior_high_school', 'senior_high_school'];
const requiresProgramPath = computed(() => courseRequiredLevels.includes(form.value.education_level));
const requiresGrades = computed(() => gradesRequiredLevels.includes(form.value.education_level));
const requiresNumericGrade = computed(() => ['percentage', 'grade_point'].includes(form.value.grading_scale));
const academicProofOption = computed(() => {
    const educationLevel = form.value.education_level;
    const gradingScale = form.value.grading_scale;
    const educationLabel = educationLevelOptions.find((option) => option.value === educationLevel)?.label;
    const gradingLabel = gradingScaleOptions.find((option) => option.value === gradingScale)?.label;
    const recordCopy = {
        preschool: {
            label: 'Progress report or assessment',
            detail: 'Latest progress report or school assessment.',
        },
        elementary: {
            label: 'Latest report card',
            detail: 'Latest report card for the selected grade level.',
        },
        junior_high_school: {
            label: 'Latest report card',
            detail: 'Latest report card for the selected grade level.',
        },
        senior_high_school: {
            label: 'Latest report card',
            detail: 'Latest report card for the selected grade level.',
        },
        college: {
            label: 'Latest grades or transcript',
            detail: 'Recent grade report or transcript for the selected year level.',
        },
        tvet: {
            label: 'Training assessment record',
            detail: 'Latest training, competency, or assessment record.',
        },
        als: {
            label: 'ALS assessment or progress record',
            detail: 'Latest assessment or progress record from the ALS learning center.',
        },
        other: {
            label: 'Latest academic record',
            detail: 'Official record showing the learner\'s latest academic result.',
        },
    }[educationLevel] ?? {
        label: 'Academic or progress record',
        detail: 'Select an education level in Learning to see the best academic record to upload.',
    };
    const context = [
        educationLabel,
        form.value.year_level,
        form.value.academic_year ? `AY ${form.value.academic_year}` : '',
        academicTermLabel(form.value.academic_term),
        gradingLabel,
    ].filter(hasValue);

    if (requiresNumericGrade.value && hasValue(form.value.gwa)) {
        context.push(gradingScale === 'percentage'
            ? `Saved average: ${form.value.gwa}%`
            : `Saved GWA / grade point: ${form.value.gwa}`);
    }

    return {
        value: 'academic_record',
        label: recordCopy.label,
        description: recordCopy.detail,
        icon: 'fa-solid fa-file-lines',
        context,
        required: true,
    };
});
const recentSchoolIdOption = computed(() => ({
    value: 'recent_school_id',
    label: 'Recent school ID',
    description: 'Clear front of your current school ID.',
    icon: 'fa-solid fa-id-card',
    context: [],
    required: true,
}));
const schoolProofOption = computed(() => {
    const educationLevel = form.value.education_level;

    return {
        value: 'school_record',
        label: educationLevel === 'tvet' ? 'Training enrollment proof' : 'School enrollment proof',
        description: 'Enrollment certificate, registration form, or admission record.',
        icon: 'fa-solid fa-school',
        context: [form.value.school, form.value.enrollment_status].filter(hasValue),
        required: false,
    };
});
const verificationDocumentOptions = computed(() => [
    academicProofOption.value,
    recentSchoolIdOption.value,
    schoolProofOption.value,
]);
const applicantAge = computed(() => calculateAge(form.value.birthdate));
const isMinor = computed(() => applicantAge.value !== null && applicantAge.value < 18);
const needsGuardianContext = computed(() => isMinor.value
    || guardianRequiredLevels.includes(form.value.education_level)
    || ['parent_guardian', 'relative', 'school_representative', 'other'].includes(form.value.account_managed_by));
const hasGuardianDetails = computed(() => [
    form.value.guardian_name,
    form.value.guardian_relationship,
    form.value.guardian_contact,
    form.value.guardian_email,
].some(hasValue) || form.value.guardian_is_account_owner);
const requiredProfileFields = computed(() => profileSections.flatMap((section) => sectionRequiredFields(section)));
const requiredFieldData = computed(() => requiredProfileFields.value.map((key) => ({
    key,
    label: fieldLabel(key),
    value: profileFieldValue(key),
})));
const completedRequiredFields = computed(() => requiredFieldData.value.filter((field) => hasValue(field.value)).length);
const profileCompletion = computed(() => requiredFieldData.value.length === 0 ? 100 : Math.round((completedRequiredFields.value / requiredFieldData.value.length) * 100));
const missingProfileFields = computed(() => requiredFieldData.value.filter((field) => !hasValue(field.value)));
const profileComplete = computed(() => missingProfileFields.value.length === 0);
const profileVerificationStatus = computed(() => user.value?.applicant_verification_status ?? 'unsubmitted');
const academicVerificationDocument = computed(() => verificationDocuments.value
    .find((document) => document.document_type === 'academic_record') ?? null);
const academicOcrActive = computed(() => Boolean(academicOcr.value?.configured));
const academicScanNeedsAttention = computed(() => academicOcrActive.value
    && academicVerificationDocument.value
    && ['failed', 'needs_review', 'unavailable'].includes(academicVerificationDocument.value.ocr_status));
const manualAcademicEntryAllowed = computed(() => academicScanNeedsAttention.value);
const manualAcademicResultComplete = computed(() => Boolean(form.value.grading_scale)
    && (!requiresNumericGrade.value || hasValue(form.value.gwa)));
const schoolVerificationDocument = computed(() => verificationDocuments.value
    .find((document) => document.document_type === 'school_record') ?? null);
const legacyVerificationDocuments = computed(() => verificationDocuments.value
    .filter((document) => !['academic_record', 'recent_school_id', 'school_record', 'achievement_evidence'].includes(document.document_type)));
const verificationDocumentRows = computed(() => verificationDocumentOptions.value.map((option) => ({
    ...option,
    document: verificationDocuments.value.find((document) => document.document_type === option.value) ?? null,
})));
const missingVerificationDocumentRows = computed(() => verificationDocumentRows.value
    .filter((row) => row.required && !row.document));
const needsVerificationUpload = computed(() => missingVerificationDocumentRows.value.length > 0);
const verificationUploadCopy = computed(() => {
    if (profileVerificationStatus.value === 'rejected') {
        return {
            title: 'Replace the academic record',
            detail: 'Use the reviewer note and upload a clearer file.',
        };
    }

    if (needsVerificationUpload.value) {
        return {
            title: `Upload ${missingVerificationDocumentRows.value[0].label.toLowerCase()}`,
            detail: 'This file is required.',
        };
    }

    if (profileVerificationStatus.value === 'pending') {
        if (academicScanNeedsAttention.value) {
            return {
                title: 'Academic result needs attention',
                detail: 'Retry the scan, replace the file, or enter the result below.',
            };
        }

        return {
            title: 'Records under review',
            detail: 'You can replace a file if it changes.',
        };
    }

    if (profileVerificationStatus.value === 'approved') {
        return {
            title: 'Records reviewed',
            detail: 'Replace a file only when it changes.',
        };
    }

    return {
        title: 'Add required files',
        detail: 'Use clear, recent documents.',
    };
});
const hasUnsavedChanges = computed(() => savedFormSnapshot.value !== '' && savedFormSnapshot.value !== formSnapshot());
const profileDisplayName = computed(() => [
    form.value.first_name,
    form.value.middle_initial ? `${form.value.middle_initial}.` : '',
    form.value.last_name,
    form.value.suffix,
].filter(Boolean).join(' ') || user.value?.name || 'Student applicant');
const profileInitials = computed(() => [form.value.first_name, form.value.last_name]
    .filter(Boolean)
    .map((name) => name.trim().charAt(0).toUpperCase())
    .join('') || 'ST');
const profilePhotoUrl = computed(() => user.value?.has_profile_photo
    ? `/dashboard/profile/photo?v=${encodeURIComponent(user.value.profile_photo_updated_at ?? '')}`
    : '');
const profileEducationSummary = computed(() => [
    educationLevelLabel(form.value.education_level),
    form.value.course_or_strand,
    form.value.year_level,
].filter(hasValue).join(' - ') || 'Learning details not completed');
const profileRecommendedAction = computed(() => {
    const nextMissing = missingProfileFields.value[0];

    if (nextMissing) {
        const gradeIsManaged = academicOcrActive.value && ['gwa', 'grading_scale'].includes(nextMissing.key);

        return {
            label: gradeIsManaged ? 'Upload academic record' : `Add ${nextMissing.label}`,
            section: gradeIsManaged
                ? 'verification'
                : profileSections.find((section) => sectionAllFields(section).includes(nextMissing.key))?.id || 'personal',
            detail: gradeIsManaged
                ? 'The portal reads your academic result from the file, then an authorized reviewer confirms it.'
                : 'This helps providers and matching rules read your profile correctly.',
        };
    }

    if (needsVerificationUpload.value) {
        const nextProof = missingVerificationDocumentRows.value[0];

        return {
            label: verificationDocuments.value.length === 0 ? 'Upload supporting records' : `Upload ${nextProof.label.toLowerCase()}`,
            section: 'verification',
            detail: verificationDocuments.value.length === 0
                ? 'Add records supporting the academic and enrollment details in your profile.'
                : nextProof.description,
        };
    }

    if (profileVerificationStatus.value === 'unsubmitted') {
        return {
            label: 'Add supporting evidence',
            section: 'verification',
            detail: 'Support the academic and enrollment information saved in your profile.',
        };
    }

    if (profileVerificationStatus.value === 'rejected') {
        return {
            label: 'Replace academic record',
            section: 'verification',
            detail: 'Review the reviewer note and upload a clearer or updated academic record.',
        };
    }

    return {
        label: 'Review saved profile',
        section: 'review',
        detail: 'Your required information is complete. Check the saved details before applying.',
    };
});
const overviewProfileAction = computed(() => {
    if (profileRecommendedAction.value.section !== 'review') {
        return {
            ...profileRecommendedAction.value,
            eyebrow: 'Next step',
            actionable: true,
        };
    }

    if (profileVerificationStatus.value === 'pending') {
        return {
            eyebrow: 'Profile status',
            label: 'Supporting evidence is being reviewed',
            detail: 'Your required profile details are complete. You can still update information while the records are reviewed.',
            actionable: false,
        };
    }

    return {
        eyebrow: 'Profile status',
        label: 'Your profile is ready for applications',
        detail: 'Required details are complete and can be reused when you apply.',
        actionable: false,
    };
});
const activeProfileSection = computed(() => profileSections.find((section) => section.id === activeSection.value) ?? profileSections[0]);
const recommendedSection = computed(() => {
    if (academicOcrActive.value && missingProfileFields.value.some((field) => ['gwa', 'grading_scale'].includes(field.key))) {
        return 'verification';
    }

    const section = profileSections.find((item) => item.required && sectionProgress(item).complete === false);

    return section?.id ?? 'review';
});
const validationErrorEntries = computed(() => Object.entries(fieldErrors.value).map(([key, messages]) => ({
    key,
    label: fieldLabel(key),
    message: Array.isArray(messages) ? messages[0] : String(messages),
})));
const profileMapAddress = computed(() => {
    const parts = [
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
        suffix: '',
        gender: '',
        contact_number: '',
        account_managed_by: '',
        citizenship_status: '',
        education_level: '',
        school: '',
        school_type: '',
        learner_reference_number: '',
        course_or_strand: '',
        year_level: '',
        enrollment_status: '',
        academic_year: '',
        academic_term: '',
        gwa: '',
        grading_scale: '',
        income_bracket: '',
        household_size: '',
        support_needs: '',
        current_scholarship_status: '',
        current_scholarship_details: '',
        scholarship_goal: '',
        achievements: '',
        activities_and_responsibilities: '',
        address: '',
        barangay: '',
        city: '',
        province: '',
        region: '',
        latitude: '',
        longitude: '',
        birthdate: '',
        guardian_name: '',
        guardian_relationship: '',
        guardian_contact: '',
        guardian_email: '',
        guardian_is_account_owner: false,
    };
}

function calculateAge(value) {
    if (!value) {
        return null;
    }

    const birthdate = new Date(`${value}T00:00:00`);

    if (Number.isNaN(birthdate.getTime())) {
        return null;
    }

    const today = new Date();
    let age = today.getFullYear() - birthdate.getFullYear();
    const birthdayHasPassed = today.getMonth() > birthdate.getMonth()
        || (today.getMonth() === birthdate.getMonth() && today.getDate() >= birthdate.getDate());

    if (!birthdayHasPassed) {
        age -= 1;
    }

    return age;
}

function formSnapshot() {
    return JSON.stringify(form.value);
}

function markFormSaved() {
    savedFormSnapshot.value = formSnapshot();
}

function hasValue(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function profileFieldValue(field) {
    if (field === 'has_profile_photo') {
        return user.value?.has_profile_photo ? 'uploaded' : '';
    }

    return form.value[field];
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
    if (field === 'course_or_strand') {
        return requiresProgramPath.value || hasValue(form.value.course_or_strand);
    }

    if (field === 'grading_scale') {
        return requiresGrades.value || hasValue(form.value.grading_scale);
    }

    if (field === 'gwa') {
        return (requiresGrades.value && (!form.value.grading_scale || requiresNumericGrade.value))
            || (!requiresGrades.value && hasValue(form.value.gwa));
    }

    if (['academic_year', 'academic_term'].includes(field)) {
        return requiresGrades.value || hasValue(form.value[field]);
    }

    if (field === 'current_scholarship_details') {
        return ['receiving', 'pending'].includes(form.value.current_scholarship_status)
            || hasValue(form.value.current_scholarship_details);
    }

    if (['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email', 'guardian_is_account_owner'].includes(field)) {
        return needsGuardianContext.value
            || hasValue(form.value.guardian_name)
            || hasValue(form.value.guardian_relationship)
            || hasValue(form.value.guardian_contact)
            || hasValue(form.value.guardian_email)
            || form.value.guardian_is_account_owner;
    }

    return true;
}

function isFieldRequired(field) {
    if (field === 'account_managed_by') {
        return true;
    }

    if (field === 'course_or_strand') {
        return requiresProgramPath.value;
    }

    if (field === 'grading_scale') {
        return requiresGrades.value;
    }

    if (field === 'gwa') {
        return requiresGrades.value && (!form.value.grading_scale || requiresNumericGrade.value);
    }

    if (['academic_year', 'academic_term'].includes(field)) {
        return requiresGrades.value;
    }

    if (field === 'current_scholarship_details') {
        return ['receiving', 'pending'].includes(form.value.current_scholarship_status);
    }

    if (['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email'].includes(field)) {
        return needsGuardianContext.value;
    }

    if (field === 'guardian_is_account_owner') {
        return false;
    }

    return true;
}

function sectionProgress(section) {
    const fields = section.required ? sectionRequiredFields(section) : sectionAllFields(section);
    const completed = fields.filter((field) => hasValue(profileFieldValue(field))).length;

    return {
        completed,
        total: fields.length,
        complete: fields.length === 0 || completed === fields.length,
        percent: fields.length === 0 ? 100 : Math.round((completed / fields.length) * 100),
    };
}

function optionButtonClass(isSelected) {
    return [
        optionButtonBaseClass,
        isSelected
            ? 'border-slate-900 bg-slate-900 text-white'
            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-400',
    ];
}

function sectionForField(field) {
    if (academicOcrActive.value && ['gwa', 'grading_scale'].includes(field)) {
        return 'verification';
    }

    return profileSections.find((section) => section.fields.includes(field))?.id ?? 'personal';
}

function openSection(sectionId) {
    activeSection.value = sectionId;
    errorMessage.value = '';

    if (showProfileEditorModal.value && editableProfileSectionIds.includes(sectionId)) {
        const url = new URL(window.location.href);
        url.searchParams.set('section', sectionId);
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    }
}

function openProfileEditor(sectionId = profileRecommendedAction.value.section) {
    if (sectionId === 'review') {
        return;
    }

    profileEditorTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    openSection(sectionId);
    showProfileEditorModal.value = true;
    const url = new URL(window.location.href);
    url.searchParams.set('section', sectionId);
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    void nextTick(() => profileEditorCloseButton.value?.focus());
}

function openVerificationRecords() {
    openProfileEditor('verification');
}

async function closeProfileEditor() {
    if (hasUnsavedChanges.value) {
        const confirmed = await requestConfirmation({
            eyebrow: 'Unsaved profile changes',
            title: 'Discard unsaved changes?',
            message: 'Your updates have not been saved. Keep editing to preserve them, or discard them and close the profile editor.',
            confirmLabel: 'Discard changes',
            cancelLabel: 'Keep editing',
            tone: 'danger',
        });

        if (!confirmed) {
            return;
        }

        form.value = JSON.parse(savedFormSnapshot.value);
        fieldErrors.value = {};
        errorMessage.value = '';
        await loadApplicantLocationHierarchy();
    }

    showProfileEditorModal.value = false;
    const url = new URL(window.location.href);
    url.searchParams.delete('section');
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    await nextTick();
    profileEditorTrigger?.focus?.();
    profileEditorTrigger = null;
}

async function saveAndCloseProfileEditor() {
    if (!hasUnsavedChanges.value) {
        await closeProfileEditor();
        return;
    }

    const saved = await saveProfile(false);

    if (saved) {
        showProfileEditorModal.value = false;
        const url = new URL(window.location.href);
        url.searchParams.delete('section');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        showPortalToast({ message: 'Profile section saved.' });
        await nextTick();
        profileEditorTrigger?.focus?.();
        profileEditorTrigger = null;
    }
}

async function finishProfileEditor() {
    if (activeSection.value === 'verification' && !hasUnsavedChanges.value) {
        await closeProfileEditor();
        return;
    }

    await saveAndCloseProfileEditor();
}

function gradingScaleLabel(value) {
    return gradingScaleOptions.find((option) => option.value === value)?.label ?? value;
}

function genderLabel(value) {
    return genderOptions.find((option) => option.value === value)?.label ?? value;
}

function citizenshipLabel(value) {
    return citizenshipOptions.find((option) => option.value === value)?.label ?? value;
}

function academicTermLabel(value) {
    return academicTermOptions.find((option) => option.value === value)?.label ?? value;
}

function currentScholarshipLabel(value) {
    return currentScholarshipOptions.find((option) => option.value === value)?.label ?? value;
}

function educationLevelLabel(value) {
    return educationLevelOptions.find((option) => option.value === value)?.label ?? value;
}

function schoolTypeLabel(value) {
    return schoolTypeOptions.find((option) => option.value === value)?.label ?? value;
}

function accountManagerLabel(value) {
    return accountManagerOptions.find((option) => option.value === value)?.label ?? value;
}

function relationshipLabel(value) {
    return guardianRelationshipOptions.find((option) => option === value) ?? value;
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
                summary: 'Use the learner record from the school or center.',
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
                summary: 'Grade level, school, location, and need matter most.',
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
                summary: 'Grade level, school, location, and academic record matter most.',
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
                summary: 'Track, grade level, average, and school record matter most.',
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
                summary: 'Course, year level, GWA, school type, and location matter most.',
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
                summary: 'Qualification, center, level, and training record matter most.',
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
                summary: 'Current level, learning center, location, and learning record matter most.',
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
                summary: 'Choose a learner level first.',
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
const coursePathOptions = computed(() => {
    switch (form.value.education_level) {
        case 'junior_high_school':
            return juniorHighPathOptions;
        case 'senior_high_school':
            return seniorHighPathOptions;
        case 'college':
            return collegePathOptions;
        case 'tvet':
            return tvetPathOptions;
        case 'als':
            return alsPathOptions;
        default:
            return [];
    }
});
const guardianRequirementLabel = computed(() => {
    if (isMinor.value) {
        return 'Required for a learner under 18';
    }

    return needsGuardianContext.value ? 'Required for this profile' : 'Optional contact';
});
const guardianRequirementText = computed(() => {
    if (isMinor.value) {
        return `The learner is ${applicantAge.value}. Add the adult who can receive scholarship follow-ups and help manage consent.`;
    }

    return needsGuardianContext.value
        ? 'Younger education levels or adult-managed accounts need a guardian contact for scholarship follow-ups.'
        : 'Add a trusted contact if someone else helps with applications.';
});
const applicantProfileRecord = computed(() => ({
    name: profileDisplayName.value,
    initials: profileInitials.value,
    photoUrl: profilePhotoUrl.value,
    educationSummary: profileEducationSummary.value,
}));
const applicantProfileSections = computed(() => [
    {
        id: 'personal',
        title: 'Personal and contact',
        icon: 'fa-solid fa-address-card',
        description: 'Identity and contact details available during application review.',
        items: [
            { label: 'Age', value: applicantAge.value !== null ? `${applicantAge.value} years old` : '' },
            { label: 'Gender', value: genderLabel(form.value.gender) },
            { label: 'Contact number', value: form.value.contact_number },
            { label: 'Email address', value: user.value?.email },
            { label: 'Account managed by', value: accountManagerLabel(form.value.account_managed_by) },
            { label: 'Citizenship', value: citizenshipLabel(form.value.citizenship_status) },
        ],
    },
    {
        id: 'academic',
        title: 'Learning record',
        icon: 'fa-solid fa-graduation-cap',
        description: 'Current education and academic information used for eligibility review.',
        items: [
            { label: 'School', value: form.value.school },
            { label: 'Education', value: profileEducationSummary.value },
            { label: 'Academic result', value: form.value.gwa ? `${form.value.gwa} - ${gradingScaleLabel(form.value.grading_scale)}` : '' },
            { label: 'Enrollment', value: form.value.enrollment_status },
            { label: 'Academic year', value: form.value.academic_year },
            { label: 'Record period', value: academicTermLabel(form.value.academic_term) },
        ],
    },
    {
        id: 'household',
        title: 'Household and guardian',
        icon: 'fa-solid fa-house-chimney-user',
        description: 'Household context considered by programs with financial or guardian requirements.',
        items: [
            { label: 'Income bracket', value: form.value.income_bracket },
            { label: 'Household size', value: form.value.household_size ? `${form.value.household_size} members` : '' },
            { label: 'Current scholarship', value: currentScholarshipLabel(form.value.current_scholarship_status) },
            { label: 'Parent or guardian', value: (needsGuardianContext.value || hasGuardianDetails.value) ? form.value.guardian_name : 'Not required' },
        ],
    },
    {
        id: 'location',
        title: 'Location',
        icon: 'fa-solid fa-location-dot',
        description: 'General residence used for location and distance eligibility rules.',
        items: [
            { label: 'Region', value: form.value.region },
            { label: 'Province', value: form.value.province },
            { label: 'City / municipality', value: form.value.city },
            { label: 'Barangay', value: form.value.barangay },
            { label: 'Street / home address', value: form.value.address, wide: true },
        ],
    },
]);
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

    if (field.key === 'gender') {
        return genderLabel(field.value);
    }

    if (field.key === 'account_managed_by') {
        return accountManagerLabel(field.value);
    }

    if (field.key === 'citizenship_status') {
        return citizenshipLabel(field.value);
    }

    if (field.key === 'academic_term') {
        return academicTermLabel(field.value);
    }

    if (field.key === 'current_scholarship_status') {
        return currentScholarshipLabel(field.value);
    }

    if (field.key === 'guardian_relationship') {
        return relationshipLabel(field.value);
    }

    if (field.key === 'guardian_is_account_owner') {
        return field.value ? 'Yes' : 'No';
    }

    return field.value;
}

function listFromText(value) {
    return String(value ?? '')
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function isOptionSelected(key, option) {
    return listFromText(form.value[key]).includes(option);
}

function toggleListOption(key, option) {
    const selected = listFromText(form.value[key]);
    const next = selected.includes(option)
        ? selected.filter((item) => item !== option)
        : [...selected, option];

    form.value[key] = next.join('\n');
}

function fillForm(payload) {
    form.value = {
        first_name: payload?.first_name ?? '',
        last_name: payload?.last_name ?? '',
        middle_initial: payload?.middle_initial ?? '',
        suffix: payload?.suffix ?? '',
        gender: payload?.gender ?? '',
        contact_number: payload?.contact_number ?? '',
        account_managed_by: payload?.account_managed_by ?? '',
        citizenship_status: payload?.citizenship_status ?? '',
        education_level: payload?.education_level ?? '',
        school: payload?.school ?? '',
        school_type: payload?.school_type ?? '',
        learner_reference_number: payload?.learner_reference_number ?? '',
        course_or_strand: payload?.course_or_strand ?? '',
        year_level: payload?.year_level ?? '',
        enrollment_status: payload?.enrollment_status ?? '',
        academic_year: payload?.academic_year ?? '',
        academic_term: payload?.academic_term ?? '',
        gwa: payload?.gwa ?? '',
        grading_scale: payload?.grading_scale ?? '',
        income_bracket: payload?.income_bracket ?? '',
        household_size: payload?.household_size ?? '',
        support_needs: payload?.support_needs ?? '',
        current_scholarship_status: payload?.current_scholarship_status ?? '',
        current_scholarship_details: payload?.current_scholarship_details ?? '',
        scholarship_goal: payload?.scholarship_goal ?? '',
        achievements: payload?.achievements ?? '',
        activities_and_responsibilities: payload?.activities_and_responsibilities ?? '',
        address: payload?.address ?? '',
        barangay: payload?.barangay ?? '',
        city: payload?.city ?? '',
        province: payload?.province ?? '',
        region: payload?.region ?? '',
        latitude: payload?.latitude ?? '',
        longitude: payload?.longitude ?? '',
        birthdate: payload?.birthdate ?? '',
        guardian_name: payload?.guardian_name ?? '',
        guardian_relationship: payload?.guardian_relationship ?? '',
        guardian_contact: payload?.guardian_contact ?? '',
        guardian_email: payload?.guardian_email ?? '',
        guardian_is_account_owner: Boolean(payload?.guardian_is_account_owner),
    };
}

function handleMiddleInitialInput(event) {
    form.value.middle_initial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handlePhoneInput(key, event) {
    form.value[key] = limitPhoneNumber(event.target.value);
}

async function saveProfilePhoto(file, input = null) {
    if (!file) {
        return false;
    }

    isUploadingProfilePhoto.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('profile_photo', file);

    try {
        const response = await window.axios.post('/dashboard/profile/photo', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        user.value = response.data.user;
        return true;
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.profile_photo?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to upload the applicant photo.';
        return false;
    } finally {
        isUploadingProfilePhoto.value = false;
        if (input) {
            input.value = '';
        }
    }
}

async function uploadProfilePhoto(event) {
    const input = event.target;
    const file = input.files?.[0] ?? null;

    await saveProfilePhoto(file, input);
}

function stopProfileCamera() {
    profileCameraStream.value?.getTracks().forEach((track) => track.stop());
    profileCameraStream.value = null;

    if (profileCameraVideo.value) {
        profileCameraVideo.value.srcObject = null;
    }
}

function closeProfileCamera() {
    stopProfileCamera();
    showProfileCamera.value = false;
    profileCameraError.value = '';
}

async function openProfileCamera() {
    showProfileCamera.value = true;
    profileCameraError.value = '';
    isStartingProfileCamera.value = true;
    await nextTick();

    if (!navigator.mediaDevices?.getUserMedia) {
        profileCameraError.value = 'Camera access is unavailable in this browser. Use Choose photo instead.';
        isStartingProfileCamera.value = false;
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: 'user',
                width: { ideal: 1280 },
                height: { ideal: 1280 },
            },
        });

        if (!showProfileCamera.value || !profileCameraVideo.value) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        profileCameraStream.value = stream;
        profileCameraVideo.value.srcObject = stream;
        await profileCameraVideo.value.play();
    } catch (error) {
        if (error?.name === 'NotAllowedError') {
            profileCameraError.value = 'Camera permission was not allowed. Enable it in your browser or choose an existing photo.';
        } else if (error?.name === 'NotFoundError') {
            profileCameraError.value = 'No camera was found on this device. Use Choose photo instead.';
        } else {
            profileCameraError.value = 'The camera could not be opened. Camera access requires a secure HTTPS connection.';
        }
    } finally {
        isStartingProfileCamera.value = false;
    }
}

async function captureProfilePhoto() {
    const video = profileCameraVideo.value;

    if (!video?.videoWidth || !video?.videoHeight) {
        profileCameraError.value = 'The camera is still loading. Wait a moment and try again.';
        return;
    }

    isCapturingProfilePhoto.value = true;
    profileCameraError.value = '';

    try {
        const sourceSize = Math.min(video.videoWidth, video.videoHeight);
        const sourceX = Math.round((video.videoWidth - sourceSize) / 2);
        const sourceY = Math.round((video.videoHeight - sourceSize) / 2);
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 800;
        const context = canvas.getContext('2d');

        if (!context) {
            throw new Error('Canvas is unavailable.');
        }

        context.drawImage(video, sourceX, sourceY, sourceSize, sourceSize, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

        if (!blob) {
            throw new Error('The photo could not be captured.');
        }

        const photo = new File([blob], `applicant-photo-${Date.now()}.jpg`, { type: 'image/jpeg' });
        const uploaded = await saveProfilePhoto(photo);

        if (uploaded) {
            closeProfileCamera();
        } else {
            profileCameraError.value = errorMessage.value || 'The captured photo could not be uploaded.';
        }
    } catch (error) {
        profileCameraError.value = error?.message || 'The photo could not be captured. Please try again.';
    } finally {
        isCapturingProfilePhoto.value = false;
    }
}

async function deleteProfilePhoto() {
    const confirmed = await requestConfirmation({
        title: 'Remove applicant photo?',
        message: 'Removing this required photo will make your profile incomplete and prevent new application submissions until you upload another one.',
        confirmLabel: 'Remove photo',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    isDeletingProfilePhoto.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.delete('/dashboard/profile/photo');
        user.value = response.data.user;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove the applicant photo.';
    } finally {
        isDeletingProfilePhoto.value = false;
    }
}

function handleProfileEscape(event) {
    if (event.key === 'Escape') {
        if (showProfileCamera.value) {
            closeProfileCamera();
            return;
        }

        if (showProfileEditorModal.value && !showProfileMapModal.value) {
            void closeProfileEditor();
        }
    }
}

function clearProfileMapPoint() {
    form.value.latitude = '';
    form.value.longitude = '';
    locationMessage.value = '';
}

async function loadApplicantCities(requestId = locationHierarchyRequestId) {
    const region = findPhilippineRegion(form.value.region);
    const province = findLocationOption(applicantProvinceOptions.value, form.value.province);

    applicantCityOptions.value = [];

    if (!region || !province) {
        return;
    }

    isLoadingApplicantCities.value = true;

    try {
        const cities = await citiesForLocation(region.code, province.code);

        if (requestId === locationHierarchyRequestId) {
            applicantCityOptions.value = cities;
        }
    } catch (error) {
        if (requestId === locationHierarchyRequestId) {
            locationOptionsError.value = 'City and municipality options could not be loaded. Check your internet connection and try the region again.';
        }
    } finally {
        if (requestId === locationHierarchyRequestId) {
            isLoadingApplicantCities.value = false;
        }
    }
}

async function loadApplicantLocationHierarchy({ resetProvince = false, resetCity = false } = {}) {
    const requestId = ++locationHierarchyRequestId;
    const region = findPhilippineRegion(form.value.region);

    locationOptionsError.value = '';
    applicantProvinceOptions.value = [];
    applicantCityOptions.value = [];

    if (resetProvince) {
        form.value.province = '';
    }

    if (resetProvince || resetCity) {
        form.value.city = '';
    }

    if (!region) {
        return;
    }

    form.value.region = region.value;

    isLoadingApplicantProvinces.value = true;

    try {
        const provinces = await provincesForRegion(region.code);

        if (requestId !== locationHierarchyRequestId) {
            return;
        }

        applicantProvinceOptions.value = provinces;

        if (region.value === 'NCR') {
            form.value.province = 'Metro Manila';
        }

        await loadApplicantCities(requestId);
    } catch (error) {
        if (requestId === locationHierarchyRequestId) {
            locationOptionsError.value = 'Province options could not be loaded. Check your internet connection and select the region again.';
        }
    } finally {
        if (requestId === locationHierarchyRequestId) {
            isLoadingApplicantProvinces.value = false;
        }
    }
}

function handleApplicantRegionChange() {
    clearProfileMapPoint();
    loadApplicantLocationHierarchy({ resetProvince: true });
}

function handleApplicantProvinceChange() {
    const requestId = ++locationHierarchyRequestId;

    form.value.city = '';
    locationOptionsError.value = '';
    clearProfileMapPoint();
    loadApplicantCities(requestId);
}

function handleApplicantCityChange() {
    clearProfileMapPoint();
}

function handleProfileLocationResolved(location) {
    form.value.latitude = Number(location.latitude).toFixed(7);
    form.value.longitude = Number(location.longitude).toFixed(7);
    locationMessage.value = 'Map updated from your city, province, and region. Save your profile to keep this map point.';
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
    const resolvedRegion = findPhilippineRegion(address.region
        || address.state
        || form.value.region);
    form.value.region = resolvedRegion?.value || form.value.region;
    loadApplicantLocationHierarchy();
    locationMessage.value = location.displayName
        ? 'Pin set. Address fields were filled from the selected map point.'
        : 'Pin set. Save your profile to keep this map point.';
}

function handleProfileLocationError(message) {
    locationMessage.value = message;
}

function verificationStatusLabel(status) {
    return {
        unsubmitted: 'Not submitted',
        pending: 'Under review',
        approved: 'Evidence reviewed',
        rejected: 'Needs replacement',
    }[status] ?? 'Not submitted';
}

function verificationStatusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'pending') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-600';
}

function verificationDocumentStatusLabel(status) {
    return {
        submitted: 'Awaiting review',
        approved: 'Accepted',
        rejected: 'Needs replacement',
    }[status] ?? 'Submitted';
}

function verificationDocumentStatusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function academicScanStatusLabel(status) {
    return {
        succeeded: 'Result extracted',
        needs_review: 'Result not found',
        failed: 'Scan failed',
        unavailable: 'Scanner unavailable',
        not_requested: 'Waiting to scan',
    }[status] ?? 'Waiting to scan';
}

function academicScanStatusClass(status) {
    if (status === 'succeeded') {
        return 'bg-sky-100 text-sky-800';
    }

    if (['failed', 'needs_review'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function extractedAcademicResult(document = academicVerificationDocument.value) {
    if (!document) {
        return 'Upload an academic record';
    }

    if (['failed', 'needs_review', 'unavailable'].includes(document.ocr_status) && form.value.grading_scale) {
        if (!requiresNumericGrade.value) {
            return `${gradingScaleLabel(form.value.grading_scale)} - entered manually`;
        }

        if (hasValue(form.value.gwa)) {
            return form.value.grading_scale === 'percentage'
                ? `${form.value.gwa}% - entered manually`
                : `${form.value.gwa} GWA / GPA - entered manually`;
        }
    }

    if (document.ocr_grading_scale === 'pass_fail') {
        return 'Pass / competency result';
    }

    if (document.ocr_grade !== null && document.ocr_grade !== undefined) {
        return document.ocr_grading_scale === 'percentage'
            ? `${document.ocr_grade}%`
            : `${document.ocr_grade} GWA / GPA`;
    }

    return 'No result extracted yet';
}

function verificationFileAccept(documentType) {
    if ((academicOcrActive.value && documentType === 'academic_record') || documentType === 'recent_school_id') {
        return '.pdf,.jpg,.jpeg,.png';
    }

    return '.pdf,.jpg,.jpeg,.png,.doc,.docx';
}

function applyAcademicResponse(payload) {
    user.value = payload.user;
    verificationDocuments.value = payload.verification_documents ?? [];
    academicOcr.value = payload.academic_ocr ?? academicOcr.value;
    const savedForm = savedFormSnapshot.value ? JSON.parse(savedFormSnapshot.value) : null;
    const savedGwa = payload.user?.gwa ?? '';
    const savedGradingScale = payload.user?.grading_scale ?? '';

    form.value.gwa = savedGwa;
    form.value.grading_scale = savedGradingScale;

    if (savedForm) {
        savedForm.gwa = savedGwa;
        savedForm.grading_scale = savedGradingScale;
        savedFormSnapshot.value = JSON.stringify(savedForm);
    } else {
        markFormSaved();
    }
}

async function uploadVerificationDocument(documentType, event) {
    const input = event.target;
    const file = input.files?.[0] ?? null;

    if (!file) {
        return;
    }

    if (!verificationDocumentTermsAccepted.value) {
        const message = 'Agree to the upload terms first.';
        errorMessage.value = message;
        showPortalToast({ type: 'error', message });
        input.value = '';
        return;
    }

    uploadingVerificationDocumentType.value = documentType;
    errorMessage.value = '';

    const payload = new FormData();
    payload.append('document_type', documentType);
    payload.append('document_file', file);
    payload.append('terms_accepted', '1');

    try {
        const response = await window.axios.post('/dashboard/profile/verification-documents', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        applyAcademicResponse(response.data);
        preparedDocumentsCount.value = response.data.prepared_documents_count ?? preparedDocumentsCount.value;
    } catch (handledError) {
        void handledError;
    } finally {
        uploadingVerificationDocumentType.value = '';
        input.value = '';
    }
}

async function retryAcademicRecordScan(document) {
    rescanningAcademicDocumentId.value = document.id;
    errorMessage.value = '';

    try {
        const response = await window.axios.post(`/dashboard/profile/verification-documents/${document.id}/scan`);

        applyAcademicResponse(response.data);
    } catch (error) {
        const message = error.response?.data?.message ?? 'Unable to scan the academic record right now.';
        errorMessage.value = message;
        showPortalToast({ type: 'error', message });
    } finally {
        rescanningAcademicDocumentId.value = null;
    }
}

async function deleteVerificationDocument(document) {
    const isAcademicRecord = document.document_type === 'academic_record';
    const isRecentSchoolId = document.document_type === 'recent_school_id';
    const isSchoolRecord = document.document_type === 'school_record';
    const isAchievementEvidence = document.document_type === 'achievement_evidence';
    const confirmed = await requestConfirmation({
        title: isAcademicRecord
            ? 'Remove academic record?'
            : (isRecentSchoolId
                ? 'Remove recent school ID?'
                : (isSchoolRecord
                    ? 'Remove school proof?'
                    : (isAchievementEvidence ? 'Remove achievement evidence?' : 'Remove older proof file?'))),
        message: `${document.original_name || 'This file'} will be removed from supporting evidence. Its separate copy in Documents will stay available.${isAcademicRecord ? ' Your academic record review will return to not submitted.' : ''}`,
        confirmLabel: 'Remove file',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    deletingVerificationDocumentId.value = document.id;
    errorMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/profile/verification-documents/${document.id}`);

        applyAcademicResponse(response.data);
        preparedDocumentsCount.value = response.data.prepared_documents_count ?? preparedDocumentsCount.value;
    } catch (handledError) {
        void handledError;
    } finally {
        deletingVerificationDocumentId.value = null;
    }
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/profile/data');

        user.value = response.data.user;
        fillForm(response.data.user);
        await loadApplicantLocationHierarchy();
        matchSummary.value = response.data.match_summary ?? matchSummary.value;
        platformActiveScholarships.value = response.data.platform_active_scholarships ?? [];
        verificationDocuments.value = response.data.verification_documents ?? [];
        preparedDocumentsCount.value = response.data.prepared_documents_count ?? 0;
        academicOcr.value = response.data.academic_ocr ?? academicOcr.value;
        markFormSaved();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant profile.';
    } finally {
        isLoading.value = false;
    }
}

async function saveProfile(requireComplete = false, nextSectionId = null) {
    if (requireComplete && missingProfileFields.value.length > 0) {
        errorMessage.value = `Add ${missingProfileFields.value.slice(0, 4).map((field) => field.label).join(', ')}${missingProfileFields.value.length > 4 ? ', and the remaining required details' : ''} before completing the profile.`;
        openSection(recommendedSection.value);
        return false;
    }

    if (!requireComplete && !hasUnsavedChanges.value) {
        if (nextSectionId) {
            openSection(nextSectionId);
        } else {
            showPortalToast({ message: 'Your profile is already up to date.' });
        }

        return true;
    }

    isSaving.value = true;
    errorMessage.value = '';
    fieldErrors.value = {};

    try {
        const response = await window.axios.patch('/dashboard/profile', {
            ...form.value,
            profile_section: activeSection.value,
        });

        user.value = response.data.user;
        fillForm(response.data.user);
        await loadApplicantLocationHierarchy();
        matchSummary.value = response.data.match_summary ?? matchSummary.value;
        platformActiveScholarships.value = response.data.platform_active_scholarships ?? platformActiveScholarships.value;
        markFormSaved();
        if (requireComplete) {
            showProfileEditorModal.value = false;
        }
        if (nextSectionId) {
            openSection(nextSectionId);
        }

        return true;
    } catch (error) {
        fieldErrors.value = error.response?.data?.errors ?? {};
        const firstError = Object.keys(fieldErrors.value)[0];

        if (firstError) {
            openSection(sectionForField(firstError));
        }

        return false;
    } finally {
        isSaving.value = false;
    }
}

function handleBeforeUnload(event) {
    if (!hasUnsavedChanges.value || allowUnsavedNavigation) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

async function handleUnsavedNavigation(event) {
    if (
        !hasUnsavedChanges.value
        || allowUnsavedNavigation
        || isHandlingUnsavedNavigation
        || event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
    ) {
        return;
    }

    const eventTarget = event.target instanceof Element ? event.target : event.target?.parentElement;
    const link = eventTarget?.closest?.('a[href]');

    if (!link || link.target === '_blank' || link.hasAttribute('download')) {
        return;
    }

    const destination = new URL(link.href, window.location.href);

    if (destination.href === window.location.href) {
        return;
    }

    event.preventDefault();
    isHandlingUnsavedNavigation = true;

    const confirmed = await requestConfirmation({
        eyebrow: 'Unsaved profile changes',
        title: 'Leave without saving?',
        message: 'You changed information in this profile section. If you leave now, those updates will be lost.',
        confirmLabel: 'Discard and leave',
        cancelLabel: 'Keep editing',
        tone: 'danger',
    });

    isHandlingUnsavedNavigation = false;

    if (!confirmed) {
        return;
    }

    allowUnsavedNavigation = true;
    window.location.assign(destination.href);
}

onMounted(async () => {
    window.addEventListener('beforeunload', handleBeforeUnload);
    window.addEventListener('keydown', handleProfileEscape);
    document.addEventListener('click', handleUnsavedNavigation);
    await loadProfile();

    if (requestedProfileSection === 'verification') {
        openVerificationRecords();
    } else if (['personal', 'academic', 'household', 'location'].includes(requestedProfileSection)) {
        openProfileEditor(requestedProfileSection);
    }

    document.body.style.overflow = showProfileEditorModal.value ? 'hidden' : '';
});

onBeforeUnmount(() => {
    stopProfileCamera();
    window.removeEventListener('beforeunload', handleBeforeUnload);
    window.removeEventListener('keydown', handleProfileEscape);
    document.removeEventListener('click', handleUnsavedNavigation);
    document.body.style.overflow = '';
});

watch(showProfileEditorModal, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});

watch(() => form.value.grading_scale, (scale) => {
    if (['pass_fail', 'other'].includes(scale)) {
        form.value.gwa = '';
    }
});
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <FilePreviewModal
            :file="previewDocument"
            :title="previewDocument?.document_name || previewDocument?.original_name || 'Supporting evidence'"
            context="Applicant supporting evidence"
            @close="previewDocument = null"
        />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <Teleport to="body">
            <div
                v-if="showProfileCamera"
                class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/75 p-3 sm:p-6"
                role="presentation"
                @click.self="closeProfileCamera"
            >
                <section class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="profile-camera-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <p class="student-kicker">Applicant photo</p>
                            <h2 id="profile-camera-title" class="mt-1 text-xl font-bold text-slate-950">Take a profile photo</h2>
                            <p class="mt-1 text-sm text-slate-500">Center your face and use a clear, well-lit background.</p>
                        </div>
                        <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 transition hover:bg-slate-100" aria-label="Close camera" @click="closeProfileCamera">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="bg-slate-950 p-4 sm:p-5">
                        <div class="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-md bg-slate-900 ring-1 ring-white/20">
                            <video
                                ref="profileCameraVideo"
                                class="h-full w-full -scale-x-100 object-cover"
                                autoplay
                                muted
                                playsinline
                            ></video>
                            <div v-if="isStartingProfileCamera" class="absolute inset-0 grid place-items-center bg-slate-950 text-sm font-bold text-white">
                                <span><i class="fa-solid fa-spinner fa-spin mr-2" aria-hidden="true"></i>Opening camera...</span>
                            </div>
                            <div v-else-if="profileCameraError && !profileCameraStream" class="absolute inset-0 grid place-items-center p-6 text-center text-sm leading-6 text-white">
                                <span><i class="fa-solid fa-camera-slash mb-3 block text-2xl text-amber-300" aria-hidden="true"></i>{{ profileCameraError }}</span>
                            </div>
                            <div v-if="profileCameraStream" class="pointer-events-none absolute inset-5 rounded-full border border-dashed border-white/60"></div>
                        </div>
                    </div>

                    <div class="space-y-3 border-t border-slate-200 px-5 py-4">
                        <p v-if="profileCameraError && profileCameraStream" class="rounded-md bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ profileCameraError }}</p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100" @click="closeProfileCamera">Cancel</button>
                            <button
                                type="button"
                                class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!profileCameraStream || isStartingProfileCamera || isCapturingProfilePhoto || isUploadingProfilePhoto"
                                @click="captureProfilePhoto"
                            >
                                <i :class="isCapturingProfilePhoto || isUploadingProfilePhoto ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-camera'" class="mr-2" aria-hidden="true"></i>
                                {{ isCapturingProfilePhoto || isUploadingProfilePhoto ? 'Saving photo...' : 'Take photo' }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </Teleport>


        <section class="student-page">
            <div class="student-container space-y-4">
                <ApplicantPageHeader
                    eyebrow="Applicant account"
                    title="My profile"
                    description="Review and update the record shared with scholarship providers."
                    icon="fa-solid fa-id-card"
                    compact
                />

                <div v-if="isLoading" class="student-card p-6 text-sm text-slate-500">
                    Loading profile...
                </div>

                <div v-else class="space-y-5">
                    <div v-if="errorMessage" class="student-card p-4">
                        <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                    </div>

                    <ApplicantProfileRecord
                        :record="applicantProfileRecord"
                        :sections="applicantProfileSections"
                        :verification-status="profileVerificationStatus"
                        :verification-label="verificationStatusLabel(profileVerificationStatus)"
                        primary
                        editable
                        @edit="openProfileEditor"
                    >
                        <template #actions>
                            <button type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800" @click="openProfileEditor(profileComplete ? 'personal' : profileRecommendedAction.section)">
                                <i class="fa-solid fa-pen text-xs" aria-hidden="true"></i>
                                {{ profileComplete ? 'Edit profile' : 'Continue setup' }}
                            </button>
                        </template>
                    </ApplicantProfileRecord>

                    <section :class="['border border-l-4 p-4 shadow-sm sm:p-5', overviewProfileAction.actionable ? 'border-amber-200 border-l-amber-400 bg-amber-50' : 'border-emerald-200 border-l-emerald-500 bg-emerald-50/60']">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center', overviewProfileAction.actionable ? 'bg-amber-200 text-amber-900' : 'bg-emerald-100 text-emerald-800']"><i :class="overviewProfileAction.actionable ? 'fa-solid fa-arrow-right' : 'fa-solid fa-check'" aria-hidden="true"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ overviewProfileAction.eyebrow }}</p><h2 class="mt-1 font-bold text-slate-950">{{ overviewProfileAction.label }}</h2><p class="mt-1 text-sm text-slate-600">{{ overviewProfileAction.detail }}</p></div>
                            </div>
                            <button v-if="overviewProfileAction.actionable" type="button" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openProfileEditor(overviewProfileAction.section)">Continue</button>
                        </div>
                    </section>

                    <section class="student-card overflow-hidden rounded-md border-slate-300">
                        <header class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Records and readiness</p>
                                <p class="mt-1 text-xs text-slate-500">Required profile details are {{ profileCompletion }}% complete.</p>
                            </div>
                            <span class="w-fit rounded-sm bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">{{ missingProfileFields.length ? `${missingProfileFields.length} details remaining` : 'Profile complete' }}</span>
                        </header>
                        <div class="divide-y divide-slate-200">
                            <button type="button" class="flex w-full items-center gap-4 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5" @click="openVerificationRecords">
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', profileVerificationStatus === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800']"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">Supporting evidence</span><span class="mt-1 block text-xs text-slate-500">Academic, enrollment, and recent school identification records</span></span>
                                <span class="shrink-0 text-xs font-bold text-slate-600">{{ verificationStatusLabel(profileVerificationStatus) }}</span>
                                <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                            </button>
                            <a href="/dashboard/documents" class="flex items-center gap-4 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">Prepared documents</span><span class="mt-1 block text-xs text-slate-500">Reusable files for future applications</span></span>
                                <span class="shrink-0 text-xs font-bold text-slate-600">{{ preparedDocumentsCount }} saved</span>
                                <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                            </a>
                            <a href="/dashboard/scholarships" class="flex items-center gap-4 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">Eligible scholarships</span><span class="mt-1 block text-xs text-slate-500">Programs currently matching your saved details</span></span>
                                <span class="shrink-0 text-xs font-bold text-slate-600">{{ matchSummary.eligible_programs }} match{{ matchSummary.eligible_programs === 1 ? '' : 'es' }}</span>
                                <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                            </a>
                        </div>
                    </section>

                    <PrivacyNoticeCard context="profile" compact />
                </div>

                <Teleport to="body">
                    <div
                        v-if="showProfileEditorModal"
                        class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-2 sm:p-5"
                        role="presentation"
                        @click.self="closeProfileEditor"
                    >
                    <section :class="['applicant-profile-editor flex max-h-[96vh] w-full max-w-6xl flex-col overflow-hidden bg-slate-50 shadow-2xl sm:max-h-[92vh]', showProfileGuidance ? 'show-guidance' : '']" role="dialog" aria-modal="true" aria-labelledby="profile-editor-title" aria-describedby="profile-editor-description">
                    <section class="shrink-0 overflow-hidden border-b border-slate-200 bg-white">
                        <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <button
                                    type="button"
                                    ref="profileEditorCloseButton"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 transition hover:bg-slate-100"
                                    title="Close profile editor"
                                    aria-label="Close profile editor"
                                    @click="closeProfileEditor"
                                >
                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                </button>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">
                                        {{ activeSection === 'verification' ? 'Supporting records' : 'Edit applicant profile' }}
                                    </p>
                                    <h2 id="profile-editor-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ activeProfileSection.label }}</h2>
                                    <p id="profile-editor-description" class="mt-1 text-sm text-slate-500">{{ activeProfileSection.impact }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-600 transition hover:bg-slate-50 hover:text-slate-950" @click="showProfileGuidance = !showProfileGuidance">
                                    <i :class="['fa-solid mr-1.5 text-[10px]', showProfileGuidance ? 'fa-eye-slash' : 'fa-circle-question']" aria-hidden="true"></i>
                                    {{ showProfileGuidance ? 'Hide guidance' : 'Show guidance' }}
                                </button>
                                <span :class="['text-xs font-bold', hasUnsavedChanges ? 'text-amber-700' : 'text-slate-400']">
                                    {{ hasUnsavedChanges ? 'Unsaved changes' : 'Saved' }}
                                </span>
                                <button
                                    type="button"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="isSaving || (activeSection !== 'verification' && !hasUnsavedChanges)"
                                    @click="finishProfileEditor"
                                >
                                    {{ isSaving ? 'Saving...' : activeSection === 'verification' && !hasUnsavedChanges ? 'Close' : 'Save changes' }}
                                </button>
                            </div>
                        </div>

                    </section>

                    <section class="min-h-0 flex-1 space-y-5 overflow-y-auto p-3 sm:p-5">
                        <div v-if="errorMessage || validationErrorEntries.length" class="student-card p-4">
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                            <div v-if="validationErrorEntries.length" class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-for="error in validationErrorEntries.slice(0, 5)"
                                    :key="error.key"
                                    type="button"
                                    class="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700"
                                    @click="openSection(sectionForField(error.key))"
                                >
                                    Review {{ error.label }}
                                </button>
                            </div>
                        </div>

                        <section v-if="['personal', 'household'].includes(activeSection)" :id="`profile-${activeSection}`" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">{{ activeSection === 'personal' ? 'Personal and contact' : 'Household and guardian' }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ activeSection === 'personal' ? 'Add the learner identity and current contact details.' : 'Add eligibility, guardian, and outside-scholarship details.' }}</p>
                                </div>
                            </div>

                            <div :class="[sectionBodyClass, 'space-y-4']">
                                <div v-if="activeSection === 'personal'" :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Official name</h4>
                                        <p :class="formPanelDescriptionClass">Enter the name used in school and identification records.</p>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-12">
                                        <div :class="[fieldClass, 'md:col-span-4']">
                                            <label :class="labelClass" for="profile-first-name">First name</label>
                                            <input id="profile-first-name" v-model="form.first_name" :class="inputClass">
                                        </div>
                                        <div :class="[fieldClass, 'md:col-span-2']">
                                            <label :class="[labelClass, 'text-center']" for="profile-middle">M.I.</label>
                                            <input
                                                id="profile-middle"
                                                :value="form.middle_initial"
                                                maxlength="1"
                                                :class="compactInputClass"
                                                @input="handleMiddleInitialInput"
                                            >
                                        </div>
                                        <div :class="[fieldClass, 'md:col-span-4']">
                                            <label :class="labelClass" for="profile-last-name">Last name</label>
                                            <input id="profile-last-name" v-model="form.last_name" :class="inputClass">
                                        </div>
                                        <div :class="[fieldClass, 'md:col-span-2']">
                                            <label :class="labelClass" for="profile-suffix">Suffix <span class="font-normal text-slate-400">(optional)</span></label>
                                            <select id="profile-suffix" v-model="form.suffix" :class="inputClass">
                                                <option value="">None</option>
                                                <option v-if="form.suffix && !suffixOptions.includes(form.suffix)" :value="form.suffix">{{ form.suffix }}</option>
                                                <option v-for="option in suffixOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="activeSection === 'personal'" :class="formPanelClass">
                                    <div
                                        v-if="['needs_replacement', 'resubmitted'].includes(user?.profile_photo_review_status)"
                                        :class="[
                                            'mb-4 border-l-4 px-3 py-3 text-sm',
                                            user.profile_photo_review_status === 'resubmitted'
                                                ? 'border-amber-400 bg-amber-50 text-amber-950'
                                                : 'border-rose-500 bg-rose-50 text-rose-950',
                                        ]"
                                    >
                                        <p class="font-bold">
                                            {{ user.profile_photo_review_status === 'resubmitted' ? 'Your new 2x2 photo was sent for review.' : 'A reviewer requested a new 2x2 photo.' }}
                                        </p>
                                        <p v-if="user.profile_photo_review_note" class="mt-1 leading-5">{{ user.profile_photo_review_note }}</p>
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-[6rem_minmax(0,1fr)] sm:items-center lg:grid-cols-[6rem_minmax(0,1fr)_auto]">
                                        <div class="grid aspect-square w-24 place-items-center overflow-hidden rounded-md border border-slate-200 bg-white text-lg font-bold text-slate-500">
                                            <img
                                                v-if="profilePhotoUrl"
                                                :src="profilePhotoUrl"
                                                :alt="`${profileDisplayName} applicant photo`"
                                                class="h-full w-full object-cover"
                                            >
                                            <span v-else>{{ profileInitials }}</span>
                                        </div>
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 :class="formPanelTitleClass">Applicant photo</h4>
                                                <span class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-amber-800 ring-1 ring-amber-200">Required</span>
                                            </div>
                                            <p :class="formPanelDescriptionClass">
                                                Upload a recent square 1x1 or 2x2-style photo so a provider can identify the applicant during application review.
                                            </p>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">JPG or PNG, 300 to 4000 pixels square, up to 5 MB.</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 sm:col-start-2 lg:col-start-auto">
                                            <label
                                                :class="[
                                                    'inline-flex h-10 cursor-pointer items-center gap-2 rounded-md bg-slate-900 px-3.5 text-xs font-bold text-white transition hover:bg-slate-800',
                                                    isUploadingProfilePhoto ? 'pointer-events-none opacity-60' : '',
                                                ]"
                                            >
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png"
                                                    class="sr-only"
                                                    :disabled="isUploadingProfilePhoto"
                                                    @change="uploadProfilePhoto"
                                                >
                                                <i :class="isUploadingProfilePhoto ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-image'" aria-hidden="true"></i>
                                                {{ isUploadingProfilePhoto ? 'Uploading...' : profilePhotoUrl ? 'Choose replacement' : 'Choose photo' }}
                                            </label>
                                            <button
                                                type="button"
                                                class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-300 bg-white px-3.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="isUploadingProfilePhoto || isDeletingProfilePhoto"
                                                @click="openProfileCamera"
                                            >
                                                <i class="fa-solid fa-camera" aria-hidden="true"></i>
                                                Open camera
                                            </button>
                                            <button
                                                v-if="profilePhotoUrl"
                                                type="button"
                                                class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-300 bg-white px-3.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="isDeletingProfilePhoto"
                                                @click="deleteProfilePhoto"
                                            >
                                                <i :class="isDeletingProfilePhoto ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can'" aria-hidden="true"></i>
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                    <p class="mt-3 border-l-2 border-slate-300 pl-3 text-xs leading-5 text-slate-500">
                                        The photo is private and appears only to authorized staff and a provider reviewing an application you submitted.
                                    </p>
                                </div>

                                <div v-if="activeSection === 'personal'" :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Basic information</h4>
                                        <p :class="formPanelDescriptionClass">Used for age-based eligibility and account communication.</p>
                                    </div>
                                    <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                        <div>
                                            <label :class="labelClass" for="profile-birthdate">Birthdate</label>
                                            <input id="profile-birthdate" v-model="form.birthdate" type="date" :min="birthdateMinimumValue" :max="birthdateMaximumValue" :class="inputClass">
                                            <p class="mt-1 text-xs text-slate-500">Applicants must be 5 to 100 years old.</p>
                                            <p v-if="applicantAge !== null" class="mt-1 text-xs font-semibold text-slate-500">
                                                Age {{ applicantAge }}<span v-if="isMinor"> - guardian details required</span>
                                            </p>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-gender">Gender <span class="font-normal text-slate-400">(optional)</span></label>
                                            <select id="profile-gender" v-model="form.gender" :class="inputClass">
                                                <option value="">Select gender</option>
                                                <option v-for="option in genderOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-contact">Contact number</label>
                                            <input id="profile-contact" :value="form.contact_number" maxlength="20" placeholder="09XX XXX XXXX" :class="inputClass" @input="handlePhoneInput('contact_number', $event)">
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-citizenship">Citizenship declaration</label>
                                            <select id="profile-citizenship" v-model="form.citizenship_status" :class="inputClass">
                                                <option value="">Select citizenship status</option>
                                                <option v-for="option in citizenshipOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">Used only when a program has a citizenship rule.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="contents">
                                    <div v-if="activeSection === 'personal'" :class="formPanelClass">
                                        <div class="mb-4">
                                            <h4 :class="formPanelTitleClass">Account responsibility</h4>
                                            <p :class="formPanelDescriptionClass">Choose who signs in, updates this profile, and manages applications.</p>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-account-manager">
                                                Who manages this account?
                                                <span class="font-normal text-slate-400">(required)</span>
                                            </label>
                                            <select id="profile-account-manager" v-model="form.account_managed_by" required :class="inputClass">
                                                <option value="">Select account manager</option>
                                                <option v-for="option in accountManagerOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div v-if="activeSection === 'household'" :class="formPanelClass">
                                        <div class="mb-4">
                                            <h4 :class="formPanelTitleClass">Household context</h4>
                                            <p :class="formPanelDescriptionClass">Use the household's approximate current situation.</p>
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label :class="labelClass" for="profile-income">Household income bracket <span class="font-normal text-slate-400">(required)</span></label>
                                                <select id="profile-income" v-model="form.income_bracket" required :class="inputClass">
                                                    <option value="">Select income bracket</option>
                                                    <option v-for="option in incomeOptions" :key="option" :value="option">{{ option }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label :class="labelClass" for="profile-household-size">Household size <span class="font-normal text-slate-400">(required)</span></label>
                                                <input
                                                    id="profile-household-size"
                                                    v-model="form.household_size"
                                                    type="number"
                                                    min="1"
                                                    max="30"
                                                    required
                                                    placeholder="Number of people"
                                                    :class="inputClass"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="activeSection === 'household'" :class="formPanelClass">
                                    <div class="mb-4 flex items-start gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 ring-1 ring-slate-200">
                                            <i class="fa-solid fa-user-shield text-sm" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <h4 :class="formPanelTitleClass">Guardian information</h4>
                                            <p :class="formPanelDescriptionClass">{{ guardianRequirementLabel }}. {{ guardianRequirementText }}</p>
                                        </div>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-2">
                                        <div>
                                            <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                            <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-guardian-relationship">Relationship to learner</label>
                                            <select id="profile-guardian-relationship" v-model="form.guardian_relationship" :class="inputClass">
                                                <option value="">Select relationship</option>
                                                <option v-for="option in guardianRelationshipOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                            <input id="profile-guardian-contact" :value="form.guardian_contact" maxlength="20" placeholder="09XX XXX XXXX" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-guardian-email">Guardian email <span v-if="needsGuardianContext" class="font-normal text-slate-400">(required)</span></label>
                                            <input id="profile-guardian-email" v-model="form.guardian_email" type="email" :required="needsGuardianContext" placeholder="guardian@example.com" :class="inputClass">
                                        </div>
                                    </div>
                                    <label class="mt-4 flex cursor-pointer items-start gap-3 border-t border-slate-200 pt-4 text-sm text-slate-600">
                                        <input
                                            v-model="form.guardian_is_account_owner"
                                            type="checkbox"
                                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                        >
                                        <span>
                                            <span class="block font-bold text-slate-950">Guardian manages this account</span>
                                            <span class="mt-1 block text-xs leading-5">Select this when the guardian signs in, updates the learner profile, and manages applications.</span>
                                        </span>
                                    </label>
                                </div>

                                <div v-if="activeSection === 'household'" :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Other scholarship status</h4>
                                        <p :class="formPanelDescriptionClass">Portal awards are detected automatically. Only declare scholarships managed outside this platform.</p>
                                    </div>
                                    <div class="mb-5 rounded-md border border-slate-200 bg-white p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Detected by the portal</p>
                                        <div v-if="platformActiveScholarships.length" class="mt-2 flex flex-wrap gap-2">
                                            <span v-for="record in platformActiveScholarships" :key="record.application_id" class="rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-900 ring-1 ring-amber-200">{{ record.title }}</span>
                                        </div>
                                        <p v-else class="mt-1 text-sm font-semibold text-slate-700">No active scholarship award is recorded in this portal.</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Scholarship outside this portal</p>
                                        <div class="mt-3 space-y-4">
                                            <div>
                                                <label :class="labelClass" for="profile-current-scholarship">Outside scholarship status <span class="font-normal text-slate-400">(required)</span></label>
                                                <select id="profile-current-scholarship" v-model="form.current_scholarship_status" :class="inputClass">
                                                    <option value="">Select an option</option>
                                                    <option v-for="option in currentScholarshipOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                                </select>
                                            </div>
                                            <div v-if="['receiving', 'pending'].includes(form.current_scholarship_status)">
                                                <label :class="labelClass" for="profile-current-scholarship-details">Outside provider and program details <span class="font-normal text-slate-400">(required)</span></label>
                                                <textarea
                                                    id="profile-current-scholarship-details"
                                                    v-model="form.current_scholarship_details"
                                                    rows="3"
                                                    maxlength="1000"
                                                    placeholder="Provider and program name"
                                                    :class="inputClass"
                                                ></textarea>
                                            </div>
                                            <p class="text-xs leading-5 text-slate-500">You do not need to list scholarships awarded through this portal because those are checked automatically.</p>
                                        </div>
                                    </div>
                                </div>

                                <p v-if="activeSection === 'household'" class="border-l-2 border-slate-300 pl-3 text-xs leading-5 text-slate-500">
                                    <i class="fa-solid fa-lock mr-1.5" aria-hidden="true"></i>
                                    Household details are used for eligibility and authorized provider review. They are not shown publicly.
                                </p>
                            </div>
                        </section>

                        <section v-if="activeSection === 'academic'" id="profile-academic" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Learning background</h3>
                                    <p class="mt-1 text-sm text-slate-500">Add the learner's current school stage, program path, and grade format.</p>
                                </div>
                            </div>

                            <div :class="[sectionBodyClass, 'space-y-4']">
                                <div :class="formPanelClass">
                                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 :class="formPanelTitleClass">Current learning stage</h4>
                                            <p :class="formPanelDescriptionClass">Start with where the learner is studying now.</p>
                                        </div>
                                        <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                            {{ form.education_level ? educationLevelLabel(form.education_level) : 'Level not selected' }}
                                        </span>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-3">
                                        <div>
                                            <label :class="labelClass" for="profile-education-level">Education level</label>
                                            <select id="profile-education-level" v-model="form.education_level" :class="inputClass">
                                                <option value="">Select education level</option>
                                                <option v-for="option in educationLevelOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-school">School / learning institution</label>
                                            <input id="profile-school" v-model="form.school" placeholder="School or learning center" :class="inputClass">
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-enrollment">Enrollment status <span class="font-normal text-slate-400">(required)</span></label>
                                            <select id="profile-enrollment" v-model="form.enrollment_status" required :class="inputClass">
                                                <option value="">Select status</option>
                                                <option v-for="option in enrollmentOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="requiresGrades || form.academic_year || form.academic_term" :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Academic record period</h4>
                                        <p :class="formPanelDescriptionClass">Identify the school year and grading period represented by the saved result and proof.</p>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-2">
                                        <div>
                                            <label :class="labelClass" for="profile-academic-year">Academic year</label>
                                            <select id="profile-academic-year" v-model="form.academic_year" :class="inputClass">
                                                <option value="">Select academic year</option>
                                                <option v-if="form.academic_year && !academicYearOptions.includes(form.academic_year)" :value="form.academic_year">{{ form.academic_year }}</option>
                                                <option v-for="option in academicYearOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-academic-term">Term or grading period</label>
                                            <select id="profile-academic-term" v-model="form.academic_term" :class="inputClass">
                                                <option value="">Select record period</option>
                                                <option v-for="option in academicTermOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Program and grades</h4>
                                        <p :class="formPanelDescriptionClass">{{ academicSummary }}</p>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                                        <div v-if="isFieldRelevant('course_or_strand')" class="xl:col-span-2">
                                            <label :class="labelClass" for="profile-course">
                                                {{ courseLabel }}
                                                <span class="font-normal text-slate-400">{{ requiresProgramPath ? '(required)' : '(optional)' }}</span>
                                            </label>
                                            <select v-if="coursePathOptions.length" id="profile-course" v-model="form.course_or_strand" :class="inputClass">
                                                <option value="">Select {{ courseLabel.toLowerCase() }}</option>
                                                <option v-if="form.course_or_strand && !coursePathOptions.includes(form.course_or_strand)" :value="form.course_or_strand">{{ form.course_or_strand }}</option>
                                                <option v-for="option in coursePathOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                            <input v-else id="profile-course" v-model="form.course_or_strand" :placeholder="coursePlaceholder" :class="inputClass">
                                            <input
                                                v-if="coursePathOptions.length && (form.course_or_strand === 'Other' || (hasValue(form.course_or_strand) && !coursePathOptions.includes(form.course_or_strand)))"
                                                v-model="form.course_or_strand"
                                                class="mt-2"
                                                :placeholder="`Type exact ${courseLabel.toLowerCase()}`"
                                                :class="inputClass"
                                            >
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ courseHelpText }}</p>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-year">{{ yearLabel }}</label>
                                            <select v-if="yearLevelOptions.length" id="profile-year" v-model="form.year_level" :class="inputClass">
                                                <option value="">Select {{ yearLabel.toLowerCase() }}</option>
                                                <option v-if="form.year_level && !yearLevelOptions.includes(form.year_level)" :value="form.year_level">{{ form.year_level }}</option>
                                                <option v-for="option in yearLevelOptions" :key="option" :value="option">{{ option }}</option>
                                            </select>
                                            <input v-else id="profile-year" v-model="form.year_level" :placeholder="yearPlaceholder" :class="inputClass">
                                        </div>
                                        <div v-if="academicOcrActive && isFieldRelevant('grading_scale')">
                                            <label :class="labelClass">Academic result</label>
                                            <button
                                                type="button"
                                                class="flex min-h-11 w-full items-center justify-between gap-3 rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-left text-sm font-semibold text-slate-900 transition hover:border-amber-400 hover:bg-amber-50/40"
                                                @click="openVerificationRecords"
                                            >
                                                <span class="truncate">{{ extractedAcademicResult() }}</span>
                                                <i class="fa-solid fa-file-shield shrink-0 text-amber-700" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div v-else-if="isFieldRelevant('grading_scale')">
                                            <label :class="labelClass" for="profile-grading-scale">Grading scale</label>
                                            <select id="profile-grading-scale" v-model="form.grading_scale" :class="inputClass">
                                                <option value="">Select grading scale</option>
                                                <option v-for="option in gradingScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                        <div v-if="!academicOcrActive && isFieldRelevant('gwa')">
                                            <label :class="labelClass" for="profile-gwa">{{ gwaLabel }}</label>
                                            <input
                                                id="profile-gwa"
                                                v-model="form.gwa"
                                                type="number"
                                                min="0"
                                                :max="form.grading_scale === 'grade_point' ? 5 : 100"
                                                step="0.01"
                                                :placeholder="form.grading_scale === 'grade_point' ? 'Example: 1.75' : 'Example: 92.50'"
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>
                                    <p v-if="isFieldRelevant('grading_scale')" class="mt-3 border-l-2 border-slate-300 pl-3 text-xs leading-5 text-slate-500">
                                        {{ academicOcrActive
                                            ? manualAcademicEntryAllowed
                                                ? 'The automatic scan could not read the result. Enter the value in Supporting evidence, where it will remain pending for reviewer confirmation.'
                                                : 'Your academic result is read from Supporting evidence and cannot be edited here. A reviewer still checks the record before accepting it.'
                                            : 'Numeric scales are kept as entered. Pass/fail and other systems are checked using supporting records.' }}
                                    </p>
                                </div>

                                <div :class="formPanelClass">
                                    <div class="mb-4">
                                        <h4 :class="formPanelTitleClass">Additional school details <span class="font-normal text-slate-400">(optional)</span></h4>
                                        <p :class="formPanelDescriptionClass">These details can improve matching and provider review.</p>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-2">
                                        <div>
                                            <label :class="labelClass" for="profile-school-type">Institution type</label>
                                            <select id="profile-school-type" v-model="form.school_type" :class="inputClass">
                                                <option value="">Select institution type</option>
                                                <option v-for="option in schoolTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label :class="labelClass" for="profile-lrn">{{ learnerIdLabel }}</label>
                                            <input id="profile-lrn" v-model="form.learner_reference_number" :placeholder="learnerIdPlaceholder" :class="inputClass">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'location'" id="profile-location" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Location</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                        Programs use your city, province, and region for residency rules and distance estimates.
                                    </p>
                                </div>
                            </div>

                            <div :class="[sectionBodyClass, 'space-y-4']">
                                <div :class="formPanelClass">
                                    <div class="mb-5 flex items-start gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-950 text-amber-300">
                                            <i class="fa-solid fa-house" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <h4 :class="formPanelTitleClass">Home address</h4>
                                            <p class="mt-1 max-w-2xl text-sm font-medium leading-5 text-slate-600">Your general location is enough here. Some providers may request your complete address later for verification.</p>
                                        </div>
                                    </div>
                                    <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                                            <div>
                                                <label :class="labelClass" for="profile-region">Region</label>
                                                <select id="profile-region" v-model="form.region" :class="inputClass" @change="handleApplicantRegionChange">
                                                    <option value="">Select region</option>
                                                    <option v-if="form.region && !findPhilippineRegion(form.region)" :value="form.region">{{ form.region }}</option>
                                                    <option v-for="region in philippineRegionOptions" :key="region.code" :value="region.value">{{ region.label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label :class="labelClass" for="profile-province">Province</label>
                                                <select
                                                    id="profile-province"
                                                    v-model="form.province"
                                                    :class="inputClass"
                                                    :disabled="!form.region || isLoadingApplicantProvinces"
                                                    @change="handleApplicantProvinceChange"
                                                >
                                                    <option value="">{{ isLoadingApplicantProvinces ? 'Loading provinces...' : 'Select province' }}</option>
                                                    <option v-if="form.province && !findLocationOption(applicantProvinceOptions, form.province)" :value="form.province">{{ form.province }}</option>
                                                    <option v-for="province in applicantProvinceOptions" :key="province.code" :value="province.value">{{ province.label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label :class="labelClass" for="profile-city">City / municipality</label>
                                                <select
                                                    id="profile-city"
                                                    v-model="form.city"
                                                    :class="inputClass"
                                                    :disabled="!form.province || isLoadingApplicantCities"
                                                    @change="handleApplicantCityChange"
                                                >
                                                    <option value="">{{ isLoadingApplicantCities ? 'Loading cities...' : 'Select city or municipality' }}</option>
                                                    <option v-if="form.city && !findLocationOption(applicantCityOptions, form.city)" :value="form.city">{{ form.city }}</option>
                                                    <option v-for="city in applicantCityOptions" :key="city.code" :value="city.value">{{ city.label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label :class="labelClass" for="profile-barangay">Barangay <span class="font-normal text-slate-400">(optional)</span></label>
                                                <input id="profile-barangay" v-model="form.barangay" placeholder="Barangay" :class="inputClass">
                                            </div>
                                            <div class="md:col-span-2 xl:col-span-4">
                                                <label :class="labelClass" for="profile-address">Street / home address <span class="font-normal text-slate-400">(optional)</span></label>
                                                <input id="profile-address" v-model="form.address" placeholder="Street and house number" :class="inputClass">
                                            </div>
                                        </div>
                                        <p v-if="locationOptionsError" class="mt-3 text-xs font-semibold text-rose-600">{{ locationOptionsError }}</p>
                                        <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                                                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="text-sm font-bold text-slate-950">{{ form.latitude && form.longitude ? 'Map pin saved' : 'Map pin not set' }}</p>
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ locationMessage || profileMapAddress || 'The map uses your city, province, and region.' }}</p>
                                                </div>
                                            </div>
                                            <button type="button" class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="showProfileMapModal = true"><i class="fa-solid fa-map-location-dot mr-2" aria-hidden="true"></i>{{ form.latitude ? 'Review map pin' : 'Set map pin' }}</button>
                                        </div>
                                    </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'verification'" id="profile-verification" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Profile records</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Supporting evidence</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                        Upload the records providers need to check.
                                    </p>
                                </div>
                                <span :class="[sectionStatusPillClass, needsVerificationUpload ? 'bg-amber-100 text-amber-800' : verificationStatusClass(profileVerificationStatus)]">
                                    {{ needsVerificationUpload ? 'Files needed' : verificationStatusLabel(profileVerificationStatus) }}
                                </span>
                            </div>

                            <div :class="[sectionBodyClass, 'space-y-4']">
                                <div
                                    v-if="user?.applicant_verification_notes"
                                    :class="[
                                        'flex items-start gap-3 rounded-lg border p-4 text-sm',
                                        profileVerificationStatus === 'rejected'
                                            ? 'border-rose-200 bg-rose-50 text-rose-800'
                                            : 'border-slate-200 bg-slate-50 text-slate-700',
                                    ]"
                                >
                                    <i class="fa-solid fa-message mt-1 shrink-0" aria-hidden="true"></i>
                                    <div>
                                        <p class="font-bold">Reviewer note</p>
                                        <p class="mt-1 leading-6">{{ user.applicant_verification_notes }}</p>
                                    </div>
                                </div>

                                <div :class="formPanelClass">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="text-lg font-bold text-slate-950">{{ verificationUploadCopy.title }}</h4>
                                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">{{ verificationUploadCopy.detail }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-600">
                                            {{ verificationDocumentRows.filter((row) => row.document).length }} of {{ verificationDocumentRows.length }} saved
                                        </span>
                                    </div>

                                    <div class="mt-4">
                                        <TermsAgreement v-model="verificationDocumentTermsAccepted" context="document" />
                                    </div>

                                    <div class="mt-5 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                        <article
                                            v-for="row in verificationDocumentRows"
                                            :key="row.value"
                                            class="flex flex-col gap-4 border-b border-slate-200 bg-white p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md text-sm', row.document ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600']">
                                                    <i :class="row.icon" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <h5 class="text-sm font-bold text-slate-950">{{ row.label }}</h5>
                                                        <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase', row.required ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500']">
                                                            {{ row.required ? 'Required' : 'Optional' }}
                                                        </span>
                                                        <span v-if="row.document" :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase', verificationDocumentStatusClass(row.document.status)]">
                                                            {{ verificationDocumentStatusLabel(row.document.status) }}
                                                        </span>
                                                    </div>
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ row.description }}</p>
                                                    <p v-if="row.document" class="mt-1 max-w-xl truncate text-xs font-semibold text-slate-700">
                                                        {{ row.document.original_name }} <span class="font-normal text-slate-400">- {{ formatFileSize(row.document.size) }}</span>
                                                    </p>
                                                    <p v-else class="mt-1 text-xs font-semibold text-slate-400">No file uploaded</p>
                                                    <div
                                                        v-if="academicOcrActive && row.value === 'academic_record' && row.document"
                                                        class="mt-2 flex flex-wrap items-center gap-2 text-xs"
                                                    >
                                                        <span :class="['rounded px-2 py-1 font-bold', academicScanStatusClass(row.document.ocr_status)]">
                                                            {{ academicScanStatusLabel(row.document.ocr_status) }}
                                                        </span>
                                                        <strong v-if="row.document.ocr_status === 'succeeded'" class="text-slate-900">
                                                            {{ extractedAcademicResult(row.document) }}
                                                        </strong>
                                                        <span class="basis-full leading-5 text-slate-500">{{ row.document.ocr_message }}</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                                <button
                                                    v-if="academicOcrActive && row.value === 'academic_record' && row.document && ['failed', 'needs_review', 'unavailable', 'not_requested'].includes(row.document.ocr_status)"
                                                    type="button"
                                                    :disabled="rescanningAcademicDocumentId === row.document.id"
                                                    class="inline-flex h-9 items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 text-xs font-bold text-amber-900 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                    @click="retryAcademicRecordScan(row.document)"
                                                >
                                                    <i :class="rescanningAcademicDocumentId === row.document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-arrows-rotate'" aria-hidden="true"></i>
                                                    {{ rescanningAcademicDocumentId === row.document.id ? 'Scanning...' : 'Retry scan' }}
                                                </button>
                                                <button
                                                    v-if="row.document"
                                                    type="button"
                                                    class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                                    @click="previewDocument = row.document"
                                                >
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                    View
                                                </button>
                                                <label
                                                    :class="[
                                                        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-xs font-bold transition',
                                                        row.document ? 'cursor-pointer border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'cursor-pointer bg-slate-900 text-white hover:bg-slate-800',
                                                        uploadingVerificationDocumentType ? 'pointer-events-none opacity-50' : '',
                                                    ]"
                                                >
                                                    <input
                                                    type="file"
                                                    :accept="verificationFileAccept(row.value)"
                                                    class="sr-only"
                                                    :disabled="uploadingVerificationDocumentType !== ''"
                                                    @change="uploadVerificationDocument(row.value, $event)"
                                                >
                                                    <i :class="uploadingVerificationDocumentType === row.value ? 'fa-solid fa-spinner fa-spin' : row.document ? 'fa-solid fa-rotate' : 'fa-solid fa-upload'" aria-hidden="true"></i>
                                                    {{ uploadingVerificationDocumentType === row.value
                                                        ? 'Uploading...'
                                                        : row.document
                                                            ? 'Replace'
                                                            : 'Upload' }}
                                                </label>
                                                <button
                                                    v-if="row.document"
                                                    type="button"
                                                    :disabled="deletingVerificationDocumentId === row.document.id"
                                                    class="grid h-9 w-9 place-items-center rounded-md border border-rose-200 bg-white text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                    :aria-label="`Remove ${row.label}`"
                                                    @click="deleteVerificationDocument(row.document)"
                                                >
                                                    <i :class="deletingVerificationDocumentId === row.document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can'" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </article>
                                    </div>

                                </div>

                                <div v-if="manualAcademicEntryAllowed" :class="formPanelClass">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <h4 :class="formPanelTitleClass">Enter the result shown on your record</h4>
                                            <p :class="formPanelDescriptionClass">Use the exact grading scale and result from the uploaded file. This entry stays pending until a reviewer confirms it.</p>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid items-end gap-4 sm:grid-cols-2">
                                        <div>
                                            <label :class="labelClass" for="fallback-grading-scale">Grading scale</label>
                                            <select id="fallback-grading-scale" v-model="form.grading_scale" :class="inputClass">
                                                <option value="">Select grading scale</option>
                                                <option v-for="option in gradingScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </div>
                                        <div v-if="requiresNumericGrade">
                                            <label :class="labelClass" for="fallback-academic-result">{{ gwaLabel }}</label>
                                            <input
                                                id="fallback-academic-result"
                                                v-model="form.gwa"
                                                type="number"
                                                min="0"
                                                :max="form.grading_scale === 'grade_point' ? 5 : 100"
                                                step="0.01"
                                                :placeholder="form.grading_scale === 'grade_point' ? 'Example: 1.75' : 'Example: 92.50'"
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>

                                    <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-xs leading-5 text-slate-500">Manual entry helps matching but does not replace review of the original record.</p>
                                        <button
                                            type="button"
                                            :disabled="isSaving || !manualAcademicResultComplete"
                                            class="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                                            @click="saveProfile(false)"
                                        >
                                            <i :class="isSaving ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-floppy-disk'" aria-hidden="true"></i>
                                            {{ isSaving ? 'Saving...' : 'Save entered result' }}
                                        </button>
                                    </div>
                                </div>

                            <div v-if="legacyVerificationDocuments.length" class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 sm:p-5">
                                <div class="flex items-start gap-3">
                                    <i class="fa-solid fa-shield-heart mt-1 text-amber-700" aria-hidden="true"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-bold text-slate-950">Older proof files are no longer required</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-600">These files were submitted under the previous profile-proof process. You may review or remove them; the portal will not ask you to replace them.</p>
                                        <div class="mt-3 grid gap-2">
                                            <div v-for="document in legacyVerificationDocuments" :key="document.id" class="flex flex-col gap-2 rounded-md border border-amber-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="min-w-0">
                                                    <p class="truncate text-xs font-bold text-slate-900">{{ document.original_name }}</p>
                                                    <p class="mt-0.5 text-[11px] text-slate-500">Previously submitted supporting file</p>
                                                </div>
                                                <div class="flex shrink-0 gap-2">
                                                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewDocument = document">View</button>
                                                    <button type="button" :disabled="deletingVerificationDocumentId === document.id" class="rounded-md border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="deleteVerificationDocument(document)">Remove</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                                <div class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-600">
                                    <i class="fa-solid fa-lock mt-1 shrink-0 text-slate-500" aria-hidden="true"></i>
                                    <p>Only authorized admins and providers reviewing your submitted application can access these files.</p>
                                </div>
                            </div>
                        </section>


                        <div class="sticky bottom-0 flex flex-col gap-3 border border-slate-200 bg-white p-3 shadow-[0_-8px_20px_rgba(15,23,42,0.06)] sm:flex-row sm:items-center sm:justify-end">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isSaving"
                                @click="closeProfileEditor"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isSaving || (activeSection !== 'verification' && !hasUnsavedChanges)"
                                @click="finishProfileEditor"
                            >
                                {{ isSaving ? 'Saving...' : activeSection === 'verification' && !hasUnsavedChanges ? 'Close' : 'Save changes' }}
                            </button>
                        </div>
                    </section>

                    </section>
                    </div>
                </Teleport>

            </div>
        </section>

        <LocationMapModal
            :open="showProfileMapModal"
            eyebrow="Applicant location"
            title="Set your home location pin"
            :address="profileMapAddress"
            :latitude="form.latitude"
            :longitude="form.longitude"
            marker-text="Applicant home location"
            :location-message="locationMessage"
            :auto-geocode-delay="900"
            :geocode-zoom="11"
            picker
            @resolved="handleProfileLocationResolved"
            @picked="handleProfileLocationPicked"
            @error="handleProfileLocationError"
            @close="showProfileMapModal = false"
        />
    </main>
</template>
