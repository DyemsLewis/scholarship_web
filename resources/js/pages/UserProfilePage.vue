<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ScholarshipPreparationReturn from '../components/ScholarshipPreparationReturn.vue';
import ApplicantAddressEditor from '../components/profile/ApplicantAddressEditor.vue';
import ApplicantEvidenceEditor from '../components/profile/ApplicantEvidenceEditor.vue';
import ApplicantHouseholdEditor from '../components/profile/ApplicantHouseholdEditor.vue';
import ApplicantLearningEditor from '../components/profile/ApplicantLearningEditor.vue';
import ApplicantPersonalEditor from '../components/profile/ApplicantPersonalEditor.vue';
import ApplicantProfileEditModal from '../components/profile/ApplicantProfileEditModal.vue';
import ApplicantProfileDetails from '../components/profile/ApplicantProfileDetails.vue';
import ApplicantProfileIdentity from '../components/profile/ApplicantProfileIdentity.vue';
import ApplicantProfileReadiness from '../components/profile/ApplicantProfileReadiness.vue';
import ApplicantProfileRecord from '../components/profile/ApplicantProfileRecord.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import PrivacyNoticeCard from '../components/PrivacyNoticeCard.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
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
    provincesForRegion,
} from '../support/philippineLocations';
import { showPortalToast } from '../support/portalToast';
import { applicantProfileOverview } from '../support/applicantProfileOverview';
import { applicantProfileDetails } from '../support/applicantProfileDetails';
import { hasLocationPin } from '../support/locationPins';
import { profileFormHasChanges } from '../support/profileFormChanges';
import { preparationQuery, withPreparationContext } from '../support/scholarshipPreparationContext';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const locationMessage = ref('');
const showProfileMapModal = ref(false);
const user = ref(null);
const savedProfileReadiness = ref(null);
const form = ref(emptyForm());
const profileSearchParams = new URLSearchParams(window.location.search);
const preparationParams = preparationQuery(window.location.search);
const preparationContext = ref(null);
const contextualHref = (href) => withPreparationContext(href, preparationContext.value?.scholarship_id, preparationContext.value?.return_section);
const requestedProfileSection = profileSearchParams.get('section');
const editableProfileSectionIds = ['personal', 'academic', 'household', 'location', 'verification'];
const showProfileEditorModal = ref(editableProfileSectionIds.includes(requestedProfileSection));
const activeSection = ref(editableProfileSectionIds.includes(requestedProfileSection)
    ? requestedProfileSection
    : 'personal');
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
const profileValidationAlert = ref(null);
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

const profileEditorBusy = computed(() => isSaving.value || isUploadingProfilePhoto.value || isDeletingProfilePhoto.value
    || Boolean(uploadingVerificationDocumentType.value) || deletingVerificationDocumentId.value !== null || rescanningAcademicDocumentId.value !== null);
const profileFormHasPin = computed(() => hasLocationPin(form.value.latitude, form.value.longitude));
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
const profileFieldControlIds = {
    first_name: 'profile-first-name',
    last_name: 'profile-last-name',
    middle_initial: 'profile-middle',
    suffix: 'profile-suffix',
    gender: 'profile-gender',
    contact_number: 'profile-contact',
    account_managed_by: 'profile-account-manager',
    citizenship_status: 'profile-citizenship',
    birthdate: 'profile-birthdate',
    education_level: 'profile-education-level',
    school: 'profile-school',
    school_type: 'profile-school-type',
    learner_reference_number: 'profile-lrn',
    course_or_strand: 'profile-course',
    year_level: 'profile-year',
    enrollment_status: 'profile-enrollment',
    academic_year: 'profile-academic-year',
    academic_term: 'profile-academic-term',
    gwa: 'profile-gwa',
    grading_scale: 'profile-grading-scale',
    income_bracket: 'profile-income',
    household_size: 'profile-household-size',
    current_scholarship_status: 'profile-current-scholarship',
    current_scholarship_details: 'profile-current-scholarship-details',
    guardian_name: 'profile-guardian',
    guardian_relationship: 'profile-guardian-relationship',
    guardian_contact: 'profile-guardian-contact',
    guardian_email: 'profile-guardian-email',
    guardian_is_account_owner: 'profile-guardian-account-owner',
    address: 'profile-address',
    barangay: 'profile-barangay',
    city: 'profile-city',
    province: 'profile-province',
    region: 'profile-region',
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
    const saved = user.value ?? {};
    const educationLevel = saved.education_level;
    const gradingScale = saved.grading_scale;
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
        saved.year_level,
        saved.academic_year ? `AY ${saved.academic_year}` : '',
        academicTermLabel(saved.academic_term),
        gradingLabel,
    ].filter(hasValue);

    if (['percentage', 'grade_point'].includes(gradingScale) && hasValue(saved.gwa)) {
        context.push(gradingScale === 'percentage'
            ? `Saved average: ${saved.gwa}%`
            : `Saved GWA / grade point: ${saved.gwa}`);
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
    const educationLevel = user.value?.education_level;

    return {
        value: 'school_record',
        label: educationLevel === 'tvet' ? 'Training enrollment proof' : 'School enrollment proof',
        description: 'Enrollment certificate, registration form, or admission record.',
        icon: 'fa-solid fa-school',
        context: [user.value?.school, user.value?.enrollment_status].filter(hasValue),
        required: true,
    };
});
const verificationDocumentOptions = computed(() => (user.value?.profile_evidence_requirements ?? [])
    .filter((option) => option.value !== 'guardian_record' || option.required
        || [user.value?.guardian_name, user.value?.guardian_relationship, user.value?.guardian_contact, user.value?.guardian_email].some(hasValue)
        || user.value?.guardian_is_account_owner)
    .map((option) => {
        const saved = user.value ?? {};
        const schoolOption = {
            academic_record: academicProofOption.value,
            recent_school_id: recentSchoolIdOption.value,
            school_record: schoolProofOption.value,
        }[option.value];
        const contexts = {
            identity_record: [saved.name, saved.birthdate],
            recent_school_id: [saved.name, saved.school],
            income_record: [saved.income_bracket, saved.household_size ? `${saved.household_size} household members` : ''],
            residency_record: [saved.barangay, saved.city, saved.province, findPhilippineRegion(saved.region)?.label || saved.region],
            guardian_record: [saved.guardian_name, saved.guardian_relationship],
        };

        return {
            ...option,
            ...schoolOption,
            description: option.examples,
            context: schoolOption?.context?.length ? schoolOption.context : (contexts[option.value] ?? []).filter(hasValue),
            required: option.required,
        };
    }));
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
const missingProfileFields = computed(() => requiredFieldData.value.filter((field) => !hasValue(field.value)));
const profileVerificationStatus = computed(() => user.value?.applicant_verification_status ?? 'unsubmitted');
const academicVerificationDocument = computed(() => verificationDocuments.value
    .find((document) => document.document_type === 'academic_record') ?? null);
const academicOcrActive = computed(() => Boolean(academicOcr.value?.configured));
const academicScanNeedsAttention = computed(() => academicOcrActive.value
    && academicVerificationDocument.value
    && ['failed', 'needs_review', 'unavailable'].includes(academicVerificationDocument.value.ocr_status));
const manualAcademicEntryAllowed = computed(() => academicScanNeedsAttention.value);
const schoolVerificationDocument = computed(() => verificationDocuments.value
    .find((document) => document.document_type === 'school_record') ?? null);
const legacyVerificationDocuments = computed(() => verificationDocuments.value
    .filter((document) => !['academic_record', 'recent_school_id', 'school_record', 'identity_record', 'income_record', 'residency_record', 'guardian_record', 'no_current_scholarship_proof', 'achievement_evidence'].includes(document.document_type)));
const verificationDocumentRows = computed(() => verificationDocumentOptions.value.map((option) => ({
    ...option,
    document: verificationDocuments.value.find((document) => document.document_type === option.value) ?? null,
})));
const requiredVerificationDocumentRows = computed(() => verificationDocumentRows.value.filter((row) => row.required));
const optionalVerificationDocumentRows = computed(() => verificationDocumentRows.value.filter((row) => !row.required));
const verificationDocumentGroups = computed(() => [
    {
        id: 'required',
        label: 'Required files',
        description: 'Each record must match the information saved in your profile.',
        rows: requiredVerificationDocumentRows.value,
    },
    {
        id: 'optional',
        label: 'Optional records',
        description: 'Add these only when a program asks for them or they strengthen the saved profile.',
        rows: optionalVerificationDocumentRows.value,
    },
].filter((group) => group.rows.length > 0));
const missingVerificationDocumentRows = computed(() => verificationDocumentRows.value
    .filter((row) => row.required && !row.document));
const needsVerificationUpload = computed(() => missingVerificationDocumentRows.value.length > 0);
const verificationUploadCopy = computed(() => {
    if (profileVerificationStatus.value === 'rejected') {
        return {
            title: 'Replace the requested supporting records',
            detail: 'Use the reviewer note and upload a clearer file.',
        };
    }

    if (needsVerificationUpload.value) {
        return {
            title: `Upload ${missingVerificationDocumentRows.value[0].label.toLowerCase()}`,
            detail: 'Open What to upload for examples and matching profile details.',
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
            detail: 'A reviewer compares these files with your saved profile before approving it.',
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
const hasUnsavedChanges = computed(() => savedFormSnapshot.value !== '' && profileFormHasChanges(form.value, JSON.parse(savedFormSnapshot.value)));
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
const profileOverview = computed(() => applicantProfileOverview({
    readiness: savedProfileReadiness.value,
    verificationStatus: profileVerificationStatus.value,
    requirements: user.value?.profile_evidence_requirements ?? [],
    documents: verificationDocuments.value,
    photoReviewStatus: user.value?.profile_photo_review_status,
    scanNeedsAttention: academicScanNeedsAttention.value,
}));

function continueProfileOverview(action) {
    openProfileEditor(action.field ? sectionForField(action.field) : action.section);
}
const profileEditorCopy = computed(() => ({
    personal: {
        title: 'Personal and contact',
        description: 'Update identity and contact details.',
    },
    academic: {
        title: 'Learning record',
        description: 'Update your school, study path, and academic record.',
    },
    household: {
        title: 'Household and guardian',
        description: 'Update household details and the contact who supports you.',
    },
    location: {
        title: 'Home address',
        description: 'Update where you live for residency and distance checks.',
    },
    verification: {
        title: 'Supporting evidence',
        description: 'Upload records that support your saved profile.',
    },
}[activeSection.value] ?? {
    title: 'Applicant profile',
    description: 'Update the information used in scholarship applications.',
}));
const profileEditorIcon = computed(() => profileSections.find((section) => section.id === activeSection.value)?.icon
    ?? 'fa-solid fa-pen');
const profileEditorSize = computed(() => activeSection.value === 'verification'
    ? 'wide'
    : 'standard');
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

async function focusProfileField(field) {
    openSection(sectionForField(field), { clearError: false });
    await nextTick();

    const controlId = activeSection.value === 'verification'
        ? ({ gwa: 'fallback-academic-result', grading_scale: 'fallback-grading-scale' }[field] ?? profileFieldControlIds[field])
        : profileFieldControlIds[field];
    const control = controlId ? document.getElementById(controlId) : null;

    if (control) {
        for (let ancestor = control.parentElement; ancestor; ancestor = ancestor.parentElement) {
            if (ancestor.tagName === 'DETAILS') ancestor.open = true;
        }
        await nextTick();
        control.scrollIntoView({ block: 'center' });
        control.focus({ preventScroll: true });
        return;
    }

    await focusProfileAlert();
}

async function focusProfileAlert() {
    await nextTick();
    profileValidationAlert.value?.scrollIntoView({ block: 'start' });
    profileValidationAlert.value?.focus({ preventScroll: true });
}

function openSection(sectionId, { clearError = true } = {}) {
    activeSection.value = sectionId;

    if (clearError) {
        errorMessage.value = '';
    }

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

    fieldErrors.value = {};
    errorMessage.value = '';
    openSection(sectionId);
    showProfileEditorModal.value = true;
    const url = new URL(window.location.href);
    url.searchParams.set('section', sectionId);
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

async function openVerificationRecords() {
    if (showProfileEditorModal.value && activeSection.value !== 'verification' && hasUnsavedChanges.value) {
        const confirmed = await requestConfirmation({
            title: 'Save before opening supporting evidence?',
            message: 'Supporting records are compared with your saved profile. Save your current changes first so they are not lost.',
            confirmLabel: 'Save and continue',
            cancelLabel: 'Keep editing',
        });
        if (!confirmed || !await saveProfile(false)) return;
    }
    openProfileEditor('verification');
}

async function closeProfileEditor() {
    if (profileEditorBusy.value) return;
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
    fieldErrors.value = {};
    errorMessage.value = '';
    const url = new URL(window.location.href);
    url.searchParams.delete('section');
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
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
const yearLabel = computed(() => learnerPathCopy.value.yearLabel);
const gwaLabel = computed(() => learnerPathCopy.value.gwaLabel);
const learnerIdLabel = computed(() => learnerPathCopy.value.idLabel);
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
const applicantProfileRecord = computed(() => ({
    name: user.value?.name || 'Student applicant',
    initials: profileInitials.value,
    photoUrl: profilePhotoUrl.value,
    educationSummary: [educationLevelLabel(user.value?.education_level), user.value?.course_or_strand, user.value?.year_level]
        .filter(hasValue).join(' - '),
}));
const applicantProfileSections = computed(() => applicantProfileDetails(user.value, {
    requiresGuardian: savedProfileReadiness.value?.requires_guardian,
    labels: {
        gender: genderLabel, citizenship: citizenshipLabel, accountManager: accountManagerLabel,
        educationLevel: educationLevelLabel, schoolType: schoolTypeLabel, gradingScale: gradingScaleLabel,
        academicTerm: academicTermLabel, currentScholarship: currentScholarshipLabel, relationship: relationshipLabel,
        region: (value) => findPhilippineRegion(value)?.label || value,
    },
}));
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
        savedProfileReadiness.value = response.data.profile_readiness ?? null;
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
        savedProfileReadiness.value = response.data.profile_readiness ?? null;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove the applicant photo.';
    } finally {
        isDeletingProfilePhoto.value = false;
    }
}

function handleProfileEscape(event) {
    if (event.key === 'Escape' && !event.defaultPrevented) {
        if (showProfileCamera.value) {
            closeProfileCamera();
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

function academicScanStatusLabel(status) {
    return {
        succeeded: 'Result extracted',
        needs_review: 'Result not found',
        failed: 'Scan failed',
        unavailable: 'Scanner unavailable',
        not_requested: 'Waiting to scan',
    }[status] ?? 'Waiting to scan';
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

function applyAcademicResponse(payload) {
    user.value = payload.user;
    savedProfileReadiness.value = payload.profile_readiness ?? null;
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
        await nextTick();
        const consent = document.querySelector('[data-profile-evidence-terms] input[type="checkbox"]');
        consent?.scrollIntoView({ block: 'center' });
        consent?.focus({ preventScroll: true });
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
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to upload this record. Check the file and try again.';
        await focusProfileAlert();
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
        await focusProfileAlert();
    } finally {
        rescanningAcademicDocumentId.value = null;
    }
}

async function deleteVerificationDocument(document) {
    const isAcademicRecord = document.document_type === 'academic_record';
    const isRequiredEvidence = verificationDocumentRows.value.some((row) => row.value === document.document_type && row.required);
    const confirmed = await requestConfirmation({
        title: `Remove ${document.label || 'supporting record'}?`,
        message: `${document.original_name || 'This file'} will be removed from supporting evidence. Its separate copy in My Documents will stay available.${isAcademicRecord ? ' Your academic record review will return to not submitted.' : isRequiredEvidence ? ' Your profile will need review again after a replacement is uploaded.' : ''}`,
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
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove this record. Please try again.';
        await focusProfileAlert();
    } finally {
        deletingVerificationDocumentId.value = null;
    }
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/profile/data', { params: preparationParams });

        user.value = response.data.user;
        preparationContext.value = response.data.preparation_context ?? null;
        fillForm(response.data.user);
        savedProfileReadiness.value = response.data.profile_readiness ?? null;
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
        savedProfileReadiness.value = response.data.profile_readiness ?? null;
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
        isSaving.value = false;
        fieldErrors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message ?? 'Unable to save this profile section. Check your connection and try again.';
        const firstError = Object.keys(fieldErrors.value)[0];

        if (firstError) {
            await focusProfileField(firstError);
        } else {
            await nextTick();
            profileValidationAlert.value?.scrollIntoView({ block: 'start' });
            profileValidationAlert.value?.focus();
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

});

onBeforeUnmount(() => {
    stopProfileCamera();
    window.removeEventListener('beforeunload', handleBeforeUnload);
    window.removeEventListener('keydown', handleProfileEscape);
    document.removeEventListener('click', handleUnsavedNavigation);
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

                <ScholarshipPreparationReturn v-if="!isLoading" :context="preparationContext" :busy="profileEditorBusy" />

                <div v-if="isLoading" class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm" aria-busy="true" aria-label="Loading applicant record" role="status">
                    <span class="sr-only">Loading profile...</span>
                    <div class="flex items-center gap-5 px-5 py-6 motion-safe:animate-pulse sm:px-6"><div class="h-20 w-20 shrink-0 rounded-sm bg-slate-100"></div><div class="flex-1 space-y-3"><div class="h-3 w-24 rounded-sm bg-slate-100"></div><div class="h-6 w-2/3 rounded-sm bg-slate-100"></div><div class="h-3 w-1/2 rounded-sm bg-slate-100"></div></div></div>
                    <div class="h-20 border-y border-slate-200 bg-slate-50"></div>
                    <div class="h-32 bg-white"></div>
                </div>

                <div v-else-if="!user" class="student-card rounded-md border-slate-300 p-5 sm:p-6" role="alert">
                    <h2 class="font-bold text-slate-950">Your profile could not be loaded</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ errorMessage || 'Please try again to view your saved applicant record.' }}</p>
                    <button type="button" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="loadProfile"><i class="fa-solid fa-rotate-right text-xs" aria-hidden="true"></i>Try again</button>
                </div>

                <div v-else class="space-y-5">
                    <div v-if="errorMessage && !showProfileEditorModal" class="student-card p-4">
                        <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                    </div>

                    <ApplicantProfileRecord
                        :record="applicantProfileRecord"
                        :sections="applicantProfileSections"
                        :verification-status="profileVerificationStatus"
                        :verification-label="verificationStatusLabel(profileVerificationStatus)"
                        :show-trust-notice="false"
                        primary
                        editable
                        @edit="openProfileEditor"
                    >
                        <template #header>
                            <ApplicantProfileIdentity :record="applicantProfileRecord" :status="profileOverview" @edit="openProfileEditor('personal')" />
                            <ApplicantProfileReadiness :state="profileOverview" @continue="continueProfileOverview" />
                        </template>
                        <template #details>
                            <ApplicantProfileDetails :sections="applicantProfileSections" editable @edit="openProfileEditor" />
                        </template>
                    </ApplicantProfileRecord>

                    <section class="student-card overflow-hidden rounded-md border-slate-300">
                        <header class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Profile resources</p>
                                <p class="mt-1 text-xs text-slate-500">Supporting records and reusable application files.</p>
                            </div>
                        </header>
                        <div class="divide-y divide-slate-200">
                            <button type="button" class="flex w-full items-center gap-4 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5" @click="openVerificationRecords">
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', profileVerificationStatus === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800']"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span>
                                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">Supporting evidence</span><span class="mt-1 block text-xs text-slate-500">Records supporting your identity, school, household, and residence</span></span>
                                <span class="shrink-0 text-xs font-bold text-slate-600">{{ verificationStatusLabel(profileVerificationStatus) }}</span>
                                <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                            </button>
                            <a :href="contextualHref('/dashboard/documents')" class="flex items-center gap-4 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5">
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

                <ApplicantProfileEditModal
                    :open="showProfileEditorModal"
                    :title="profileEditorCopy.title"
                    :description="profileEditorCopy.description"
                    :icon="profileEditorIcon"
                    :size="profileEditorSize"
                    :saving="isSaving"
                    :busy="profileEditorBusy"
                    :suspended="showProfileMapModal || Boolean(previewDocument) || showProfileCamera || confirmation.open"
                    :instant-changes="activeSection === 'verification'"
                    :dirty="hasUnsavedChanges"
                    :save-disabled="activeSection !== 'verification' && !hasUnsavedChanges"
                    :save-label="activeSection === 'verification' && !hasUnsavedChanges ? 'Close' : 'Save changes'"
                    @close="closeProfileEditor"
                    @save="finishProfileEditor"
                >
                        <div
                            v-if="errorMessage || validationErrorEntries.length"
                            ref="profileValidationAlert"
                            class="mx-4 mt-4 border-l-4 border-rose-500 bg-rose-50 p-4 outline-none sm:mx-6"
                            role="alert"
                            aria-live="assertive"
                            tabindex="-1"
                        >
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                            <div v-if="validationErrorEntries.length" class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-for="error in validationErrorEntries.slice(0, 5)"
                                    :key="error.key"
                                    type="button"
                                    class="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700"
                                    @click="focusProfileField(error.key)"
                                >
                                    {{ error.label }}: {{ error.message }}
                                </button>
                            </div>
                        </div>

                        <ApplicantPersonalEditor
                            v-if="activeSection === 'personal'"
                            v-model="form"
                            :photo-url="profilePhotoUrl"
                            :applicant-name="profileDisplayName"
                            :photo-review-status="user?.profile_photo_review_status || ''"
                            :photo-review-note="user?.profile_photo_review_note || ''"
                            :uploading-photo="isUploadingProfilePhoto"
                            :deleting-photo="isDeletingProfilePhoto"
                            :age="applicantAge"
                            :is-minor="isMinor"
                            :birthdate-min="birthdateMinimumValue"
                            :birthdate-max="birthdateMaximumValue"
                            :gender-options="genderOptions"
                            :citizenship-options="citizenshipOptions"
                            :account-manager-options="accountManagerOptions"
                            :suffix-options="suffixOptions"
                            @upload-photo="uploadProfilePhoto"
                            @open-camera="openProfileCamera"
                            @remove-photo="deleteProfilePhoto"
                            @middle-initial-input="handleMiddleInitialInput"
                            @phone-input="handlePhoneInput('contact_number', $event)"
                        />

                        <ApplicantHouseholdEditor
                            v-if="activeSection === 'household'"
                            v-model="form"
                            :income-options="incomeOptions"
                            :relationship-options="guardianRelationshipOptions"
                            :scholarship-options="currentScholarshipOptions"
                            :portal-scholarships="platformActiveScholarships"
                            :guardian-required="needsGuardianContext"
                            :has-guardian-details="hasGuardianDetails"
                            :guardian-reason="guardianRequirementLabel"
                            @guardian-phone-input="handlePhoneInput('guardian_contact', $event)"
                        />

                        <ApplicantLearningEditor
                            v-if="activeSection === 'academic'"
                            v-model="form"
                            :education-options="educationLevelOptions"
                            :enrollment-options="enrollmentOptions"
                            :course-options="coursePathOptions"
                            :year-options="yearLevelOptions"
                            :academic-year-options="academicYearOptions"
                            :term-options="academicTermOptions"
                            :grading-scale-options="gradingScaleOptions"
                            :school-type-options="schoolTypeOptions"
                            :path-copy="learnerPathCopy"
                            :field-relevant="isFieldRelevant"
                            :field-required="isFieldRequired"
                            :requires-grades="requiresGrades"
                            :ocr-active="academicOcrActive"
                            :manual-result-allowed="manualAcademicEntryAllowed"
                            :extracted-result="extractedAcademicResult()"
                            @open-evidence="openVerificationRecords"
                        />

                        <ApplicantAddressEditor
                            v-if="activeSection === 'location'"
                            v-model="form"
                            :province-options="applicantProvinceOptions"
                            :city-options="applicantCityOptions"
                            :loading-provinces="isLoadingApplicantProvinces"
                            :loading-cities="isLoadingApplicantCities"
                            :options-error="locationOptionsError"
                            :has-pin="profileFormHasPin"
                            :location-message="locationMessage"
                            @region-change="handleApplicantRegionChange"
                            @province-change="handleApplicantProvinceChange"
                            @city-change="handleApplicantCityChange"
                            @retry-options="loadApplicantLocationHierarchy()"
                            @open-map="showProfileMapModal = true"
                        />

                        <ApplicantEvidenceEditor
                            v-if="activeSection === 'verification'"
                            v-model="form"
                            v-model:consent="verificationDocumentTermsAccepted"
                            :groups="verificationDocumentGroups"
                            :title="verificationUploadCopy.title"
                            :description="verificationUploadCopy.detail"
                            :reviewer-note="user?.applicant_verification_notes || ''"
                            :ocr="academicOcr"
                            :uploading-type="uploadingVerificationDocumentType"
                            :deleting-id="deletingVerificationDocumentId"
                            :scanning-id="rescanningAcademicDocumentId"
                            :disabled="profileEditorBusy"
                            :scan-label="academicScanStatusLabel"
                            :result-label="extractedAcademicResult"
                            :manual-result-allowed="manualAcademicEntryAllowed"
                            :grading-scale-options="gradingScaleOptions"
                            :result-input-label="gwaLabel"
                            :legacy-documents="legacyVerificationDocuments"
                            @upload="uploadVerificationDocument"
                            @preview="previewDocument = $event"
                            @remove="deleteVerificationDocument"
                            @scan="retryAcademicRecordScan"
                        />
                </ApplicantProfileEditModal>

            </div>
        </section>

        <LocationMapModal
            :open="showProfileMapModal"
            eyebrow="Location and distance"
            title="Review approximate location"
            :address="profileMapAddress"
            :latitude="form.latitude"
            :longitude="form.longitude"
            marker-text="Approximate applicant location"
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
