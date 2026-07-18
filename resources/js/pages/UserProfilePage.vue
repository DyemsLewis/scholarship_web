<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { formatFileSize } from '../support/display';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const locationMessage = ref('');
const user = ref(null);
const form = ref(emptyForm());
const profileView = ref('overview');
const activeSection = ref('personal');
const addressLookupTrigger = ref(0);
const savedFormSnapshot = ref('');
const fieldErrors = ref({});
const matchSummary = ref({
    available_programs: 0,
    eligible_programs: 0,
    strong_matches: 0,
    needs_review: 0,
    blocked_programs: 0,
    preference_matches: 0,
    top_gaps: [],
});
const verificationDocuments = ref([]);
const verificationDocumentType = ref('school_id');
const verificationDocumentFile = ref(null);
const verificationDocumentTermsAccepted = ref(false);
const verificationFileInput = ref(null);
const isUploadingVerificationDocument = ref(false);
const deletingVerificationDocumentId = ref(null);
const preparedDocumentsCount = ref(0);
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
const sectionHeaderClass = 'flex flex-col gap-2 border-b border-slate-200 bg-white p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6';
const sectionBodyClass = 'p-5 sm:p-6';
const sectionStatusPillClass = 'w-fit rounded-md px-3 py-2 text-xs font-bold uppercase tracking-[0.14em]';
const optionButtonBaseClass = 'rounded-md border px-3 py-2 text-sm font-semibold transition';

const enrollmentOptions = ['Enrolled', 'Incoming student', 'Continuing student', 'Graduating', 'Not currently enrolled'];
const incomeOptions = ['Below PHP 10,000', 'PHP 10,000 - 20,000', 'PHP 20,001 - 40,000', 'PHP 40,001 - 60,000', 'Above PHP 60,000'];
const categoryOptions = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const supportNeedOptions = ['Tuition', 'Books and supplies', 'Transportation', 'Uniform', 'Internet / device', 'Boarding / housing', 'Exam or certification fees'];
const accountManagerOptions = [
    { value: 'learner', label: 'Learner / student' },
    { value: 'parent_guardian', label: 'Parent or guardian' },
    { value: 'relative', label: 'Relative' },
    { value: 'school_representative', label: 'School representative' },
    { value: 'other', label: 'Other trusted person' },
];
const guardianRelationshipOptions = ['Parent / guardian', 'Mother', 'Father', 'Grandparent', 'Sibling', 'Relative', 'Teacher / adviser', 'Other'];
const regionOptions = ['NCR', 'CAR', 'Region I', 'Region II', 'Region III', 'Region IV-A', 'MIMAROPA', 'Region V', 'Region VI', 'Region VII', 'Region VIII', 'Region IX', 'Region X', 'Region XI', 'Region XII', 'Region XIII', 'BARMM'];
const provinceOptions = ['Metro Manila', 'Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay', 'Antique', 'Apayao', 'Aurora', 'Bataan', 'Batangas', 'Benguet', 'Bohol', 'Bukidnon', 'Bulacan', 'Cagayan', 'Camarines Norte', 'Camarines Sur', 'Capiz', 'Cavite', 'Cebu', 'Davao del Norte', 'Davao del Sur', 'Davao Oriental', 'Iloilo', 'Isabela', 'Laguna', 'La Union', 'Leyte', 'Misamis Oriental', 'Negros Occidental', 'Negros Oriental', 'Nueva Ecija', 'Nueva Vizcaya', 'Pampanga', 'Pangasinan', 'Quezon', 'Rizal', 'South Cotabato', 'Tarlac', 'Zambales'];
const preferredLocationOptions = ['Anywhere in the Philippines', 'Near my home address', 'Online-friendly', ...regionOptions, 'Cebu', 'Davao'];
const juniorHighPathOptions = ['General curriculum', 'STE', 'SPA', 'Sports program', 'Special science class', 'Other'];
const seniorHighPathOptions = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Arts and Design', 'Sports Track', 'Other'];
const collegePathOptions = ['Any course', 'BS Information Technology', 'BS Education', 'BS Nursing', 'BS Accountancy', 'BS Business Administration', 'Engineering', 'Criminology', 'Agriculture', 'Other'];
const tvetPathOptions = ['Cookery NC II', 'ICT / Computer Systems Servicing', 'Automotive Servicing', 'Electrical Installation and Maintenance', 'Caregiving', 'Shielded Metal Arc Welding', 'Other'];
const alsPathOptions = ['Basic Literacy', 'A&E Elementary', 'A&E Junior High School', 'Other'];
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
const verificationDocumentOptions = [
    { value: 'school_id', label: 'School or student ID' },
    { value: 'government_id', label: 'Government-issued ID' },
    { value: 'enrollment_certificate', label: 'Enrollment certificate' },
    { value: 'birth_certificate', label: 'Birth certificate' },
    { value: 'other', label: 'Other identity or school proof' },
];
const fieldLabels = {
    first_name: 'First name',
    last_name: 'Last name',
    middle_initial: 'Middle initial',
    suffix: 'Suffix',
    gender: 'Gender',
    contact_number: 'Contact number',
    account_managed_by: 'Account managed by',
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
    guardian_relationship: 'Relationship to learner',
    guardian_contact: 'Guardian contact',
    guardian_email: 'Guardian email',
    guardian_is_account_owner: 'Guardian manages this account',
};

const profileSections = [
    {
        id: 'personal',
        label: 'Personal',
        detail: 'Identity',
        icon: 'fa-solid fa-address-card',
        impact: 'Applicant identity.',
        required: true,
        fields: ['first_name', 'middle_initial', 'last_name', 'suffix', 'gender', 'birthdate', 'contact_number', 'account_managed_by'],
        requiredFields: ['first_name', 'last_name', 'birthdate', 'contact_number', 'account_managed_by'],
    },
    {
        id: 'academic',
        label: 'Learning',
        detail: 'School record',
        icon: 'fa-solid fa-book-open-reader',
        impact: 'Matching details.',
        required: true,
        fields: ['education_level', 'school', 'school_type', 'learner_reference_number', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
        requiredFields: ['education_level', 'school', 'course_or_strand', 'year_level', 'grading_scale', 'gwa'],
    },
    {
        id: 'household',
        label: 'Household',
        detail: 'Need and support',
        icon: 'fa-solid fa-house',
        impact: 'Financial context.',
        required: true,
        fields: ['income_bracket', 'household_size', 'support_needs'],
        requiredFields: ['income_bracket'],
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
        id: 'preferences',
        label: 'Preferences',
        detail: 'Finder priorities',
        icon: 'fa-solid fa-sliders',
        impact: 'Personalizes result order.',
        required: false,
        fields: ['preferred_categories', 'preferred_locations', 'willing_to_relocate', 'scholarship_goal'],
    },
    {
        id: 'guardian',
        label: 'Guardian',
        detail: 'Contact person',
        icon: 'fa-solid fa-user-shield',
        impact: 'Trusted contact.',
        required: true,
        fields: ['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email', 'guardian_is_account_owner'],
        requiredFields: ['guardian_name', 'guardian_relationship', 'guardian_contact'],
    },
    {
        id: 'verification',
        label: 'Verification',
        detail: 'Proof and status',
        icon: 'fa-solid fa-shield-check',
        impact: 'Confirms your account.',
        required: false,
        fields: [],
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
];

const courseRequiredLevels = ['senior_high_school', 'college', 'tvet'];
const gradesRequiredLevels = ['elementary', 'junior_high_school', 'senior_high_school', 'college', 'tvet', 'als', 'other'];
const guardianRequiredLevels = ['preschool', 'elementary', 'junior_high_school', 'senior_high_school'];
const requiresProgramPath = computed(() => courseRequiredLevels.includes(form.value.education_level));
const requiresGrades = computed(() => gradesRequiredLevels.includes(form.value.education_level));
const requiresNumericGrade = computed(() => ['percentage', 'grade_point'].includes(form.value.grading_scale));
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
const visibleProfileSections = computed(() => profileSections.filter((section) => section.id !== 'guardian' || needsGuardianContext.value || hasGuardianDetails.value));
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
const profileCompletion = computed(() => requiredFieldData.value.length === 0 ? 100 : Math.round((completedRequiredFields.value / requiredFieldData.value.length) * 100));
const missingProfileFields = computed(() => requiredFieldData.value.filter((field) => !hasValue(field.value)));
const profileComplete = computed(() => missingProfileFields.value.length === 0);
const profileVerificationStatus = computed(() => user.value?.applicant_verification_status ?? 'unsubmitted');
const selectedVerificationDocument = computed(() => verificationDocuments.value
    .find((document) => document.document_type === verificationDocumentType.value) ?? null);
const verificationSteps = computed(() => {
    const status = profileVerificationStatus.value;
    const proofCount = verificationDocuments.value.length;

    return [
        {
            number: 1,
            label: status === 'rejected' ? 'Replace proof' : 'Submit proof',
            detail: status === 'rejected'
                ? 'Upload a clearer or updated file.'
                : proofCount
                    ? `${proofCount} proof file${proofCount === 1 ? '' : 's'} submitted.`
                    : 'Choose an identity or enrollment file.',
            state: ['pending', 'approved'].includes(status) ? 'complete' : 'current',
        },
        {
            number: 2,
            label: 'Admin review',
            detail: status === 'approved'
                ? 'Review completed.'
                : status === 'pending'
                    ? 'Your account is being checked.'
                    : status === 'rejected'
                        ? 'Restarts after resubmission.'
                        : 'Begins after you submit proof.',
            state: status === 'approved' ? 'complete' : status === 'pending' ? 'current' : 'upcoming',
        },
        {
            number: 3,
            label: 'Verified badge',
            detail: status === 'approved' ? 'Visible to scholarship providers.' : 'Appears after admin approval.',
            state: status === 'approved' ? 'complete' : 'upcoming',
        },
    ];
});
const verificationUploadCopy = computed(() => {
    if (profileVerificationStatus.value === 'rejected') {
        return {
            title: 'Replace the requested proof',
            detail: 'Review the admin note, then submit a clearer or updated file.',
        };
    }

    if (profileVerificationStatus.value === 'pending') {
        return {
            title: 'Add or update proof',
            detail: 'Changing a file restarts the admin review, so update only when needed.',
        };
    }

    if (profileVerificationStatus.value === 'approved') {
        return {
            title: 'Keep your proof current',
            detail: 'Only replace a file when it is outdated. Any change returns the account to review.',
        };
    }

    return {
        title: 'Submit verification proof',
        detail: 'One clear school ID or enrollment certificate is usually enough.',
    };
});
const verificationSubmitLabel = computed(() => {
    if (isUploadingVerificationDocument.value) {
        return 'Uploading...';
    }

    if (selectedVerificationDocument.value) {
        return profileVerificationStatus.value === 'rejected' ? 'Replace and resubmit' : 'Update proof';
    }

    return verificationDocuments.value.length ? 'Add proof' : 'Submit for review';
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
const profileEducationSummary = computed(() => [
    educationLevelLabel(form.value.education_level),
    form.value.course_or_strand,
    form.value.year_level,
].filter(hasValue).join(' - ') || 'Learning details not completed');
const profileLocationSummary = computed(() => [form.value.city, form.value.province, form.value.region]
    .filter(hasValue)
    .join(', ') || 'Location not completed');
const profileOverviewSections = computed(() => visibleProfileSections.value
    .filter((section) => !['verification', 'review'].includes(section.id))
    .map((section) => ({
        ...section,
        status: sectionStatusLabel(section),
        statusClass: sectionStatusClass(section),
        summary: overviewSectionSummary(section.id),
    })));
const applicationSetupItems = computed(() => [
    {
        id: 'profile',
        label: 'Required profile details',
        detail: profileComplete.value
            ? 'Ready for scholarship applications.'
            : `${missingProfileFields.value.length} required detail${missingProfileFields.value.length === 1 ? '' : 's'} remaining.`,
        state: profileComplete.value ? 'complete' : 'action',
        section: recommendedSection.value,
    },
    {
        id: 'email',
        label: 'Email address',
        detail: user.value?.email_verified ? 'Verified for account notifications.' : 'Verify through the message sent to your inbox.',
        state: user.value?.email_verified ? 'complete' : 'action',
    },
    {
        id: 'verification',
        label: 'Applicant account',
        detail: verificationStatusLabel(profileVerificationStatus.value),
        state: profileVerificationStatus.value === 'approved'
            ? 'complete'
            : profileVerificationStatus.value === 'pending'
                ? 'pending'
                : 'action',
        section: 'verification',
    },
    {
        id: 'documents',
        label: 'Prepared documents',
        detail: preparedDocumentsCount.value
            ? `${preparedDocumentsCount.value} reusable file${preparedDocumentsCount.value === 1 ? '' : 's'} saved.`
            : 'Add reusable files as scholarship requirements become clear.',
        state: preparedDocumentsCount.value ? 'complete' : 'optional',
        href: '/dashboard/documents',
    },
]);
const profileQuality = computed(() => {
    if (profileComplete.value) {
        return {
            label: 'Application-ready',
            detail: 'Required details are complete.',
        };
    }

    if (profileCompletion.value >= 70) {
        return {
            label: 'Ready to match',
            detail: 'Add the remaining details before applying.',
        };
    }

    if (profileCompletion.value >= 40) {
        return {
            label: 'Basic profile',
            detail: 'Enough to explore scholarships.',
        };
    }

    return {
        label: 'Starting profile',
        detail: 'Add learner details to improve matching.',
    };
});
const profileRecommendedAction = computed(() => {
    const nextMissing = missingProfileFields.value[0];

    if (nextMissing) {
        return {
            label: `Add ${nextMissing.label}`,
            section: profileSections.find((section) => sectionAllFields(section).includes(nextMissing.key))?.id || 'personal',
            detail: 'This helps providers and matching rules read your profile correctly.',
        };
    }

    if (profileVerificationStatus.value === 'unsubmitted') {
        return {
            label: 'Submit verification proof',
            section: 'verification',
            detail: 'A verified badge helps providers know that an admin checked your account.',
        };
    }

    if (profileVerificationStatus.value === 'rejected') {
        return {
            label: 'Replace verification proof',
            section: 'verification',
            detail: 'Review the admin note and upload a clearer or updated document.',
        };
    }

    return {
        label: 'Review provider preview',
        section: 'review',
        detail: 'Your required information is complete. Check the final view before applying.',
    };
});
const activeProfileSection = computed(() => profileSections.find((section) => section.id === activeSection.value) ?? profileSections[0]);
const visibleActiveSectionIndex = computed(() => visibleProfileSections.value.findIndex((section) => section.id === activeProfileSection.value.id));
const recommendedSection = computed(() => {
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
        suffix: '',
        gender: '',
        contact_number: '',
        account_managed_by: '',
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
        return needsGuardianContext.value;
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

    if (['guardian_name', 'guardian_relationship', 'guardian_contact'].includes(field)) {
        return needsGuardianContext.value;
    }

    if (['guardian_email', 'guardian_is_account_owner'].includes(field)) {
        return false;
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

function sectionStatusLabel(section) {
    if (!section.fields.length) {
        return profileComplete.value ? 'Ready' : 'Review';
    }

    const progress = sectionProgress(section);

    if (!section.required) {
        return progress.completed > 0 ? 'Added' : 'Optional';
    }

    if (progress.complete) {
        return 'Complete';
    }

    return progress.completed > 0 ? 'In progress' : 'Needs details';
}

function sectionStatusClass(section) {
    if (!section.fields.length) {
        return profileComplete.value
            ? 'bg-emerald-100 text-emerald-800'
            : 'bg-slate-100 text-slate-600';
    }

    const progress = sectionProgress(section);

    if (!section.required) {
        return progress.completed > 0
            ? 'bg-slate-100 text-slate-700'
            : 'bg-slate-100 text-slate-600';
    }

    if (progress.complete) {
        return 'bg-emerald-100 text-emerald-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function optionButtonClass(isSelected) {
    return [
        optionButtonBaseClass,
        isSelected
            ? 'border-slate-900 bg-slate-900 text-white'
            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-400',
    ];
}

function profileSection(sectionId) {
    return profileSections.find((section) => section.id === sectionId) ?? profileSections[0];
}

function sectionForField(field) {
    return profileSections.find((section) => section.fields.includes(field))?.id ?? 'personal';
}

function sectionForMatchGap(gap) {
    if (['academic', 'education_level', 'course', 'school_type', 'year_level'].includes(gap?.key)) {
        return 'academic';
    }

    if (gap?.key === 'income') {
        return 'household';
    }

    if (gap?.key === 'location') {
        return 'location';
    }

    return 'personal';
}

function sectionHasErrors(section) {
    return Object.keys(fieldErrors.value).some((field) => section.fields.includes(field));
}

function openSection(sectionId) {
    activeSection.value = sectionId;
    errorMessage.value = '';
}

function openProfileEditor(sectionId = profileRecommendedAction.value.section) {
    profileView.value = 'edit';
    openSection(sectionId);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function openProfileOverview() {
    if (hasUnsavedChanges.value) {
        const saved = await saveProfile(false);

        if (!saved) {
            return;
        }
    }

    profileView.value = 'overview';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goToPreviousSection() {
    const previous = visibleProfileSections.value[visibleActiveSectionIndex.value - 1];

    if (previous) {
        openSection(previous.id);
    }
}

async function goToNextSection() {
    const next = visibleProfileSections.value[visibleActiveSectionIndex.value + 1];

    if (!next) {
        await openProfileOverview();
        return;
    }

    if (hasUnsavedChanges.value) {
        await saveProfile(false, next.id);
        return;
    }

    openSection(next.id);
}

function overviewSectionSummary(sectionId) {
    const summaries = {
        personal: [
            applicantAge.value !== null ? `${applicantAge.value} years old` : '',
            genderLabel(form.value.gender),
            form.value.contact_number,
        ],
        academic: [
            educationLevelLabel(form.value.education_level),
            form.value.school,
            [form.value.course_or_strand, form.value.year_level].filter(hasValue).join(' - '),
        ],
        household: [
            form.value.income_bracket,
            form.value.household_size ? `${form.value.household_size} household members` : '',
            listFromText(form.value.support_needs).slice(0, 2).join(', '),
        ],
        location: [form.value.city, form.value.province, form.value.region],
        preferences: [
            listFromText(form.value.preferred_categories).slice(0, 2).join(', '),
            form.value.willing_to_relocate ? relocationOptions.find((option) => option.value === form.value.willing_to_relocate)?.label : '',
        ],
        guardian: [form.value.guardian_name, relationshipLabel(form.value.guardian_relationship), form.value.guardian_contact],
    };

    return (summaries[sectionId] ?? []).filter(hasValue).join(' - ') || 'No details added yet.';
}

function gradingScaleLabel(value) {
    return gradingScaleOptions.find((option) => option.value === value)?.label ?? value;
}

function genderLabel(value) {
    return genderOptions.find((option) => option.value === value)?.label ?? value;
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
                summary: 'Grade level, average, location, and support needs matter most.',
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
                summary: 'Track, grade level, average, location, and need matter most.',
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
                summary: 'Course, year level, GWA, school type, location, and need matter most.',
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
                summary: 'Qualification, center, level, location, and support needs matter most.',
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
                summary: 'Current level, learning center, location, and support needs matter most.',
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
const reviewGroups = computed(() => [
    {
        title: 'Learner',
        items: [
            ['Name', [form.value.first_name, form.value.middle_initial ? `${form.value.middle_initial}.` : '', form.value.last_name, form.value.suffix].filter(Boolean).join(' ')],
            ['Gender', genderLabel(form.value.gender)],
            ['Birthdate', form.value.birthdate],
            ['Contact', form.value.contact_number],
            ['Account managed by', accountManagerLabel(form.value.account_managed_by)],
        ],
    },
    {
        title: 'Learning',
        items: [
            ['Level', educationLevelLabel(form.value.education_level)],
            ['School', form.value.school],
            [yearLabel.value, form.value.year_level],
            ...(isFieldRelevant('course_or_strand') ? [[courseLabel.value, form.value.course_or_strand]] : []),
            ...(isFieldRelevant('gwa') ? [[gwaLabel.value, form.value.gwa], ['Grading scale', gradingScaleLabel(form.value.grading_scale)]] : []),
        ],
    },
    {
        title: 'Household',
        items: [
            ['Income bracket', form.value.income_bracket],
            ['Household size', form.value.household_size],
            ['Support needed', listFromText(form.value.support_needs).join(', ')],
        ],
    },
    {
        title: 'Location',
        items: [
            ['Address', [form.value.address, form.value.barangay, form.value.city, form.value.province, form.value.region].filter(Boolean).join(', ')],
        ],
    },
    {
        title: 'Preferences',
        items: [
            ['Scholarship types', listFromText(form.value.preferred_categories).join(', ')],
            ['Preferred locations', listFromText(form.value.preferred_locations).join(', ')],
            ['Relocation', fieldDisplayValue({ key: 'willing_to_relocate', value: form.value.willing_to_relocate })],
            ['Goal', form.value.scholarship_goal],
        ],
    },
    {
        title: 'Guardian',
        items: [
            ['Name', form.value.guardian_name],
            ['Relationship', relationshipLabel(form.value.guardian_relationship)],
            ['Contact', form.value.guardian_contact],
            ['Email', form.value.guardian_email],
        ],
    },
]);
const providerPreviewRows = computed(() => [
    ['Applicant', [form.value.first_name, form.value.middle_initial ? `${form.value.middle_initial}.` : '', form.value.last_name, form.value.suffix].filter(Boolean).join(' ')],
    ['Account verification', verificationStatusLabel(profileVerificationStatus.value)],
    ['Learner level', educationLevelLabel(form.value.education_level)],
    ['Program path', isFieldRelevant('course_or_strand') ? form.value.course_or_strand : 'Not required'],
    ['School', form.value.school],
    ['Grade / year', form.value.year_level],
    ['Academic record', isFieldRelevant('gwa') ? [form.value.gwa, gradingScaleLabel(form.value.grading_scale)].filter(Boolean).join(' - ') : 'Not required'],
    ['Location', [form.value.city, form.value.province, form.value.region].filter(Boolean).join(', ')],
    ['Need context', [form.value.income_bracket, form.value.support_needs].filter(Boolean).join(' - ')],
    ['Preferences', [form.value.preferred_categories, form.value.preferred_locations].filter(Boolean).join(' - ')],
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

    if (field.key === 'willing_to_relocate') {
        return relocationOptions.find((option) => option.value === field.value)?.label ?? field.value;
    }

    if (field.key === 'account_managed_by') {
        return accountManagerLabel(field.value);
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

function verificationStatusLabel(status) {
    return {
        unsubmitted: 'Not submitted',
        pending: 'Pending admin review',
        approved: 'Admin verified',
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

function verificationProgress(status) {
    return {
        unsubmitted: 0,
        rejected: 25,
        pending: 60,
        approved: 100,
    }[status] ?? 0;
}

function verificationDocumentTypeLabel(type) {
    return verificationDocumentOptions.find((option) => option.value === type)?.label ?? 'Verification proof';
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

function handleVerificationFile(event) {
    verificationDocumentFile.value = event.target.files?.[0] ?? null;
}

async function uploadVerificationDocument() {
    if (!verificationDocumentFile.value) {
        errorMessage.value = 'Choose a proof file before uploading.';
        return;
    }

    if (!verificationDocumentTermsAccepted.value) {
        errorMessage.value = 'Confirm that you are allowed to upload this proof.';
        return;
    }

    isUploadingVerificationDocument.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_type', verificationDocumentType.value);
    payload.append('document_file', verificationDocumentFile.value);
    payload.append('terms_accepted', '1');

    try {
        const response = await window.axios.post('/dashboard/profile/verification-documents', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        user.value = response.data.user;
        verificationDocuments.value = response.data.verification_documents ?? [];
        verificationDocumentFile.value = null;
        verificationDocumentTermsAccepted.value = false;

        if (verificationFileInput.value) {
            verificationFileInput.value.value = '';
        }

        statusMessage.value = response.data.message ?? 'Verification proof sent for review.';
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.document_file?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to upload verification proof.';
    } finally {
        isUploadingVerificationDocument.value = false;
    }
}

async function deleteVerificationDocument(document) {
    const confirmed = await requestConfirmation({
        title: 'Remove verification proof?',
        message: `${document.original_name || 'This proof'} will be removed. Your verified status will return to pending or not submitted.`,
        confirmLabel: 'Remove proof',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    deletingVerificationDocumentId.value = document.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/profile/verification-documents/${document.id}`);

        user.value = response.data.user;
        verificationDocuments.value = response.data.verification_documents ?? [];
        statusMessage.value = response.data.message ?? 'Verification proof removed.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove verification proof.';
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
        matchSummary.value = response.data.match_summary ?? matchSummary.value;
        verificationDocuments.value = response.data.verification_documents ?? [];
        preparedDocumentsCount.value = response.data.prepared_documents_count ?? 0;
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
        statusMessage.value = '';
        openSection(recommendedSection.value);
        return false;
    }

    if (!requireComplete && !hasUnsavedChanges.value) {
        if (nextSectionId) {
            openSection(nextSectionId);
        } else {
            statusMessage.value = 'Your profile is already up to date.';
        }

        return true;
    }

    isSaving.value = true;
    statusMessage.value = '';
    errorMessage.value = '';
    fieldErrors.value = {};

    try {
        const response = await window.axios.patch('/dashboard/profile', form.value);

        user.value = response.data.user;
        fillForm(response.data.user);
        matchSummary.value = response.data.match_summary ?? matchSummary.value;
        markFormSaved();
        statusMessage.value = requireComplete
            ? 'Profile completed. You can now apply for scholarships.'
            : response.data.message ?? 'Profile progress saved.';
        if (requireComplete) {
            profileView.value = 'overview';
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

        errorMessage.value = firstError
            ? fieldErrors.value[firstError]?.[0] ?? 'Review the highlighted profile details.'
            : error.response?.data?.message ?? 'Unable to update applicant profile.';

        return false;
    } finally {
        isSaving.value = false;
    }
}

function handleBeforeUnload(event) {
    if (!hasUnsavedChanges.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);
    loadProfile();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
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

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Student Profile"
                    title="Your scholarship profile"
                    description="A reusable applicant record for matching, verification, and scholarship applications."
                    icon="fa-solid fa-id-card"
                    action-href="/dashboard/scholarships"
                    action-label="See matches"
                    secondary-href="/dashboard/documents"
                    secondary-label="Prepare files"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading profile...
                </div>

                <div v-else-if="profileView === 'overview'" class="mt-6 space-y-5">
                    <div v-if="statusMessage || errorMessage" class="student-card p-4">
                        <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">{{ statusMessage }}</p>
                        <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                    </div>

                    <section class="student-card overflow-hidden">
                        <div class="flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <div class="grid h-16 w-16 shrink-0 place-items-center rounded-md bg-slate-950 text-xl font-bold text-white">
                                    {{ profileInitials }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-2xl font-bold text-slate-950">{{ profileDisplayName }}</h2>
                                        <span
                                            v-if="profileVerificationStatus === 'approved'"
                                            class="rounded-md bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800"
                                        >
                                            <i class="fa-solid fa-circle-check mr-1" aria-hidden="true"></i>
                                            Admin verified
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm font-semibold text-slate-600">{{ profileEducationSummary }}</p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        <i class="fa-solid fa-location-dot mr-1.5 text-slate-400" aria-hidden="true"></i>
                                        {{ profileLocationSummary }}
                                    </p>
                                    <p class="mt-1 truncate text-xs text-slate-400">{{ user?.email }}</p>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                                @click="openProfileEditor(profileComplete ? 'personal' : profileRecommendedAction.section)"
                            >
                                <i class="fa-solid fa-pen mr-2" aria-hidden="true"></i>
                                {{ profileComplete ? 'Edit profile' : 'Continue setup' }}
                            </button>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.6fr)] lg:items-center">
                                <div>
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-bold text-slate-900">Application profile</span>
                                        <span class="font-bold text-slate-700">{{ profileCompletion }}%</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${profileCompletion}%` }"></div>
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ completedRequiredFields }} of {{ requiredFieldData.length }} required details completed.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="border-l-2 border-amber-400 pl-4 text-left"
                                    @click="openProfileEditor(profileRecommendedAction.section)"
                                >
                                    <span class="block text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Next action</span>
                                    <span class="mt-1 block text-sm font-bold text-slate-950">{{ profileRecommendedAction.label }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ profileRecommendedAction.detail }}</span>
                                </button>
                            </div>
                        </div>
                    </section>

                    <div class="grid gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(18rem,0.75fr)] lg:items-start">
                        <section class="student-card overflow-hidden">
                            <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 sm:p-6">
                                <div>
                                    <p class="student-kicker">Profile record</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Your information</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">Review each part without opening the form.</p>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                    @click="openProfileEditor('personal')"
                                >
                                    Edit
                                </button>
                            </div>

                            <div class="divide-y divide-slate-200">
                                <div
                                    v-for="section in profileOverviewSections"
                                    :key="section.id"
                                    class="grid grid-cols-[2.5rem_minmax(0,1fr)_2.25rem] items-center gap-3 px-5 py-4 sm:px-6"
                                >
                                    <div class="grid h-10 w-10 place-items-center rounded-md bg-slate-100 text-slate-600">
                                        <i :class="section.icon" aria-hidden="true"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-bold text-slate-950">{{ section.label }}</p>
                                            <span :class="['rounded-md px-2 py-0.5 text-[11px] font-bold', section.statusClass]">
                                                {{ section.status }}
                                            </span>
                                        </div>
                                        <p class="mt-1 line-clamp-2 text-sm leading-5 text-slate-500">{{ section.summary }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                                        :title="`Edit ${section.label}`"
                                        :aria-label="`Edit ${section.label}`"
                                        @click="openProfileEditor(section.id)"
                                    >
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <aside class="space-y-5">
                            <section class="student-card overflow-hidden">
                                <div class="border-b border-slate-200 p-5">
                                    <p class="student-kicker">Before applying</p>
                                    <h3 class="mt-2 text-lg font-bold text-slate-950">Application setup</h3>
                                </div>
                                <div class="divide-y divide-slate-200">
                                    <div v-for="item in applicationSetupItems" :key="item.id" class="flex items-start gap-3 px-5 py-3.5">
                                        <i
                                            :class="[
                                                'mt-0.5 text-sm',
                                                item.state === 'complete'
                                                    ? 'fa-solid fa-circle-check text-emerald-600'
                                                    : item.state === 'pending'
                                                        ? 'fa-solid fa-clock text-amber-600'
                                                        : item.state === 'optional'
                                                            ? 'fa-regular fa-circle text-slate-300'
                                                            : 'fa-solid fa-circle-exclamation text-amber-600',
                                            ]"
                                            aria-hidden="true"
                                        ></i>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-bold text-slate-900">{{ item.label }}</p>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ item.detail }}</p>
                                        </div>
                                        <button
                                            v-if="item.section"
                                            type="button"
                                            class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-900"
                                            :aria-label="`Open ${item.label}`"
                                            @click="openProfileEditor(item.section)"
                                        >
                                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </button>
                                        <a
                                            v-else-if="item.href"
                                            :href="item.href"
                                            class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-900"
                                            :aria-label="`Open ${item.label}`"
                                        >
                                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            </section>

                            <section class="student-card overflow-hidden">
                                <div class="flex items-center justify-between gap-4 border-b border-slate-200 p-5">
                                    <div>
                                        <p class="student-kicker">Current catalog</p>
                                        <h3 class="mt-2 text-lg font-bold text-slate-950">Matching snapshot</h3>
                                    </div>
                                    <a href="/dashboard/scholarships" class="flex shrink-0 items-center gap-2 text-sm font-bold text-slate-700 transition hover:text-slate-950">
                                        View matches
                                        <i class="fa-solid fa-chevron-right text-xs" aria-hidden="true"></i>
                                    </a>
                                </div>
                                <div class="grid grid-cols-2 lg:grid-cols-4">
                                    <div class="border-b border-r border-slate-200 p-4 lg:border-b-0">
                                        <p class="text-2xl font-bold text-slate-950">{{ matchSummary.strong_matches }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">Strong matches</p>
                                    </div>
                                    <div class="border-b border-slate-200 p-4 lg:border-b-0 lg:border-r">
                                        <p class="text-2xl font-bold text-slate-950">{{ matchSummary.eligible_programs }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">Eligible</p>
                                    </div>
                                    <div class="border-r border-slate-200 p-4">
                                        <p class="text-2xl font-bold text-slate-950">{{ matchSummary.preference_matches }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">Preference fit</p>
                                    </div>
                                    <div class="p-4">
                                        <p class="text-2xl font-bold text-slate-950">{{ matchSummary.available_programs }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">Programs checked</p>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <div v-else class="mt-6 space-y-5">
                    <section class="student-card overflow-hidden">
                        <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <button
                                    type="button"
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 transition hover:bg-slate-100"
                                    title="Back to profile overview"
                                    aria-label="Back to profile overview"
                                    @click="openProfileOverview"
                                >
                                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                                </button>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">
                                        Step {{ visibleActiveSectionIndex + 1 }} of {{ visibleProfileSections.length }}
                                    </p>
                                    <h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ activeProfileSection.label }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ activeProfileSection.impact }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <span :class="['text-xs font-bold', hasUnsavedChanges ? 'text-amber-700' : 'text-slate-400']">
                                    {{ hasUnsavedChanges ? 'Unsaved changes' : 'Saved' }}
                                </span>
                                <button
                                    type="button"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="isSaving || !hasUnsavedChanges"
                                    @click="saveProfile(false)"
                                >
                                    {{ isSaving ? 'Saving...' : 'Save changes' }}
                                </button>
                            </div>
                        </div>

                        <div class="h-1 bg-slate-100">
                            <div class="h-full bg-slate-900 transition-all" :style="{ width: `${((visibleActiveSectionIndex + 1) / visibleProfileSections.length) * 100}%` }"></div>
                        </div>

                        <nav class="grid grid-flow-col auto-cols-[minmax(8rem,1fr)] gap-2 overflow-x-auto border-t border-slate-200 bg-slate-50 p-3" aria-label="Profile sections">
                            <button
                                v-for="section in visibleProfileSections"
                                :key="section.id"
                                type="button"
                                :class="[
                                    'rounded-md border px-3 py-2.5 text-left transition hover:border-slate-400 hover:bg-white',
                                    activeSection === section.id ? 'border-slate-900 bg-white shadow-sm' : 'border-slate-200 bg-slate-50',
                                ]"
                                :aria-current="activeSection === section.id ? 'step' : undefined"
                                @click="openSection(section.id)"
                            >
                                <span class="block min-w-0">
                                    <span class="block truncate text-sm font-bold text-slate-950">{{ section.label }}</span>
                                    <span
                                        :class="[
                                            'mt-0.5 block truncate text-xs font-semibold',
                                            sectionHasErrors(section)
                                                ? 'text-rose-700'
                                                : section.id === 'verification'
                                                    ? profileVerificationStatus === 'approved'
                                                        ? 'text-emerald-700'
                                                        : profileVerificationStatus === 'pending'
                                                            ? 'text-amber-700'
                                                            : 'text-slate-500'
                                                    : sectionProgress(section).complete
                                                        ? 'text-emerald-700'
                                                        : 'text-slate-500',
                                        ]"
                                    >
                                        {{ sectionHasErrors(section)
                                            ? 'Needs attention'
                                            : section.id === 'verification'
                                                ? verificationStatusLabel(profileVerificationStatus)
                                                : sectionStatusLabel(section) }}
                                    </span>
                                </span>
                                <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-slate-200">
                                    <span
                                        class="block h-full rounded-full bg-slate-900"
                                        :style="{ width: `${section.id === 'verification' ? verificationProgress(profileVerificationStatus) : sectionProgress(section).percent}%` }"
                                    ></span>
                                </span>
                            </button>
                        </nav>
                    </section>

                    <section class="space-y-5">
                        <div v-if="statusMessage || errorMessage" class="student-card p-4">
                            <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">
                                {{ statusMessage }}
                            </p>
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

                        <section v-if="activeSection === 'personal'" id="profile-personal" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Personal details</h3>
                                </div>
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('personal'))]">
                                    {{ sectionStatusLabel(profileSection('personal')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="grid gap-4 md:grid-cols-12">
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
                                        <label :class="labelClass" for="profile-suffix">Suffix <span class="font-normal text-sky-600">(optional)</span></label>
                                        <select id="profile-suffix" v-model="form.suffix" :class="inputClass">
                                            <option value="">None</option>
                                            <option v-if="form.suffix && !suffixOptions.includes(form.suffix)" :value="form.suffix">
                                                {{ form.suffix }}
                                            </option>
                                            <option
                                                v-for="option in suffixOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-4 md:grid-cols-3">
                                    <div>
                                        <label :class="labelClass" for="profile-gender">Gender <span class="font-normal text-sky-600">(optional)</span></label>
                                        <select id="profile-gender" v-model="form.gender" :class="inputClass">
                                            <option value="">Select gender</option>
                                            <option
                                                v-for="option in genderOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-birthdate">Birthdate</label>
                                        <input id="profile-birthdate" v-model="form.birthdate" type="date" :max="new Date().toISOString().slice(0, 10)" :class="inputClass">
                                        <p v-if="applicantAge !== null" class="mt-1 text-xs font-semibold text-slate-500">
                                            Age {{ applicantAge }}<span v-if="isMinor"> - guardian details are required</span>
                                        </p>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-contact">Contact number</label>
                                        <input id="profile-contact" :value="form.contact_number" :class="inputClass" @input="handlePhoneInput('contact_number', $event)">
                                    </div>
                                </div>

                                <div class="student-soft-card mt-4 grid gap-4 p-4 md:grid-cols-[1fr_1.2fr] md:items-center">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">
                                            Account context
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Tell the platform who is responsible for managing this profile and its applications.
                                        </p>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-account-manager">
                                            Who manages this account?
                                            <span class="font-normal text-sky-600">{{ needsGuardianContext ? '(required)' : '(optional)' }}</span>
                                        </label>
                                        <select id="profile-account-manager" v-model="form.account_managed_by" :class="inputClass">
                                            <option value="">Select account manager</option>
                                            <option
                                                v-for="option in accountManagerOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'academic'" id="profile-academic" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Learning background</h3>
                                </div>
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('academic'))]">
                                    {{ sectionStatusLabel(profileSection('academic')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="student-soft-card grid gap-3 p-4 md:grid-cols-[1fr_2fr] md:items-center">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Current learner path
                                        </p>
                                        <p class="mt-1 text-lg font-bold text-slate-950">
                                            {{ form.education_level ? educationLevelLabel(form.education_level) : 'Choose education level' }}
                                        </p>
                                    </div>
                                    <p class="text-sm leading-5 text-slate-600">
                                        {{ academicSummary }}
                                    </p>
                                </div>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
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
                                        <label :class="labelClass" for="profile-school">School / learning institution</label>
                                        <input id="profile-school" v-model="form.school" placeholder="School or learning center" :class="inputClass">
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-4 md:grid-cols-3">
                                    <div v-if="isFieldRelevant('course_or_strand')">
                                        <label :class="labelClass" for="profile-course">
                                            {{ courseLabel }}
                                            <span class="font-normal text-sky-600">
                                                {{ requiresProgramPath ? '(required)' : '(optional)' }}
                                            </span>
                                        </label>
                                        <select
                                            v-if="coursePathOptions.length"
                                            id="profile-course"
                                            v-model="form.course_or_strand"
                                            :class="inputClass"
                                        >
                                            <option value="">Select {{ courseLabel.toLowerCase() }}</option>
                                            <option v-if="form.course_or_strand && !coursePathOptions.includes(form.course_or_strand)" :value="form.course_or_strand">
                                                {{ form.course_or_strand }}
                                            </option>
                                            <option
                                                v-for="option in coursePathOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                        <input
                                            v-else
                                            id="profile-course"
                                            v-model="form.course_or_strand"
                                            :placeholder="coursePlaceholder"
                                            :class="inputClass"
                                        >
                                        <input
                                            v-if="coursePathOptions.length && (form.course_or_strand === 'Other' || (hasValue(form.course_or_strand) && !coursePathOptions.includes(form.course_or_strand)))"
                                            v-model="form.course_or_strand"
                                            class="mt-2"
                                            :placeholder="`Type exact ${courseLabel.toLowerCase()}`"
                                            :class="inputClass"
                                        >
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ courseHelpText }}
                                        </p>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-year">{{ yearLabel }}</label>
                                        <select
                                            v-if="yearLevelOptions.length"
                                            id="profile-year"
                                            v-model="form.year_level"
                                            :class="inputClass"
                                        >
                                            <option value="">Select {{ yearLabel.toLowerCase() }}</option>
                                            <option v-if="form.year_level && !yearLevelOptions.includes(form.year_level)" :value="form.year_level">
                                                {{ form.year_level }}
                                            </option>
                                            <option
                                                v-for="option in yearLevelOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                        <input
                                            v-else
                                            id="profile-year"
                                            v-model="form.year_level"
                                            :placeholder="yearPlaceholder"
                                            :class="inputClass"
                                        >
                                    </div>
                                    <div v-if="isFieldRelevant('grading_scale')">
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
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            Different numeric scales are not automatically converted. Pass/fail and other systems are reviewed using supporting records.
                                        </p>
                                    </div>
                                </div>

                                <div v-if="isFieldRelevant('gwa')" class="mt-4 grid gap-4 md:grid-cols-3">
                                    <div>
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

                                <details class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <summary class="cursor-pointer list-none text-sm font-bold text-slate-950">
                                        Optional school details
                                    </summary>
                                    <div class="mt-4 grid gap-4 md:grid-cols-3">
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
                                            <label :class="labelClass" for="profile-school-type">Institution type</label>
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
                                            <label :class="labelClass" for="profile-lrn">{{ learnerIdLabel }}</label>
                                            <input id="profile-lrn" v-model="form.learner_reference_number" :placeholder="learnerIdPlaceholder" :class="inputClass">
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </section>

                        <section v-if="activeSection === 'household'" id="profile-household" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Household and support</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                        This context helps programs understand financial need and the expenses a scholarship would support.
                                    </p>
                                </div>
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('household'))]">
                                    {{ sectionStatusLabel(profileSection('household')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="grid gap-4 md:grid-cols-2">
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
                                        <label :class="labelClass" for="profile-household-size">Household size</label>
                                        <input
                                            id="profile-household-size"
                                            v-model="form.household_size"
                                            type="number"
                                            min="1"
                                            max="30"
                                            placeholder="Number of people in the household"
                                            :class="inputClass"
                                        >
                                    </div>
                                </div>

                                <fieldset class="mt-6 border-t border-slate-200 pt-5">
                                    <legend class="text-sm font-bold text-slate-950">What support would help you continue studying?</legend>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Choose all expenses that are relevant to your situation.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            v-for="option in supportNeedOptions"
                                            :key="option"
                                            type="button"
                                            :aria-pressed="isOptionSelected('support_needs', option)"
                                            :class="optionButtonClass(isOptionSelected('support_needs', option))"
                                            @click="toggleListOption('support_needs', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
                                </fieldset>

                                <p class="mt-6 border-l-2 border-slate-300 pl-3 text-xs leading-5 text-slate-500">
                                    <i class="fa-solid fa-lock mr-1.5" aria-hidden="true"></i>
                                    Financial details are used for eligibility and provider review. They are not shown publicly.
                                </p>
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
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('location'))]">
                                    {{ sectionStatusLabel(profileSection('location')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="grid gap-4 md:grid-cols-3">
                                    <div>
                                        <label :class="labelClass" for="profile-city">City / municipality</label>
                                        <input id="profile-city" v-model="form.city" placeholder="City or municipality" :class="inputClass" @input="clearProfileMapPoint">
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-province">Province</label>
                                        <select id="profile-province" v-model="form.province" :class="inputClass" @change="clearProfileMapPoint">
                                            <option value="">Select province</option>
                                            <option v-if="form.province && form.province !== 'Other' && !provinceOptions.includes(form.province)" :value="form.province">
                                                {{ form.province }}
                                            </option>
                                            <option
                                                v-for="province in provinceOptions"
                                                :key="province"
                                                :value="province"
                                            >
                                                {{ province }}
                                            </option>
                                            <option value="Other">Other / not listed</option>
                                        </select>
                                        <input
                                            v-if="form.province === 'Other' || (hasValue(form.province) && !provinceOptions.includes(form.province))"
                                            v-model="form.province"
                                            class="mt-2"
                                            placeholder="Type province"
                                            :class="inputClass"
                                            @input="clearProfileMapPoint"
                                        >
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-region">Region</label>
                                        <select id="profile-region" v-model="form.region" :class="inputClass" @change="clearProfileMapPoint">
                                            <option value="">Select region</option>
                                            <option v-if="form.region && !regionOptions.includes(form.region)" :value="form.region">
                                                {{ form.region }}
                                            </option>
                                            <option
                                                v-for="region in regionOptions"
                                                :key="region"
                                                :value="region"
                                            >
                                                {{ region }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-4 border-t border-slate-200 pt-5 md:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="profile-address">Street / home address <span class="font-normal text-slate-400">(optional)</span></label>
                                        <input id="profile-address" v-model="form.address" placeholder="Street and house number" :class="inputClass" @input="clearProfileMapPoint">
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-barangay">Barangay <span class="font-normal text-slate-400">(optional)</span></label>
                                        <input id="profile-barangay" v-model="form.barangay" placeholder="Barangay" :class="inputClass" @input="clearProfileMapPoint">
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-slate-900">Map location</p>
                                        <p class="mt-1 text-xs text-slate-500">Confirm the pin so scholarship distance estimates are more accurate.</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        @click="lookupProfileAddress"
                                    >
                                        <i class="fa-solid fa-location-crosshairs mr-1.5" aria-hidden="true"></i>
                                        Find address
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

                        <section v-if="activeSection === 'preferences'" id="profile-preferences" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Optional</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Scholarship preferences</h3>
                                </div>
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('preferences'))]">
                                    {{ sectionStatusLabel(profileSection('preferences')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="student-soft-card p-4">
                                    <p class="text-sm font-bold text-slate-950">Personalize your finder</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        These choices help order scholarship results and explain what support you need. They never override eligibility rules or the provider's final decision.
                                    </p>
                                </div>

                                <fieldset class="mt-5">
                                    <legend class="text-sm font-bold text-slate-950">Preferred scholarship types</legend>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Choose every type you would like to see first.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            v-for="option in categoryOptions"
                                            :key="option"
                                            type="button"
                                            :aria-pressed="isOptionSelected('preferred_categories', option)"
                                            :class="optionButtonClass(isOptionSelected('preferred_categories', option))"
                                            @click="toggleListOption('preferred_categories', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
                                </fieldset>

                                <fieldset class="mt-6 border-t border-slate-200 pt-5">
                                    <legend class="text-sm font-bold text-slate-950">Preferred locations</legend>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Select broad areas, nearby programs, or online-friendly options.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            v-for="option in preferredLocationOptions"
                                            :key="option"
                                            type="button"
                                            :aria-pressed="isOptionSelected('preferred_locations', option)"
                                            :class="optionButtonClass(isOptionSelected('preferred_locations', option))"
                                            @click="toggleListOption('preferred_locations', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
                                </fieldset>

                                <div class="mt-6 grid gap-4 border-t border-slate-200 pt-5 md:grid-cols-2">
                                    <div>
                                        <label :class="labelClass" for="profile-relocation">Willing to relocate</label>
                                        <select id="profile-relocation" v-model="form.willing_to_relocate" :class="inputClass">
                                            <option value="">No preference selected</option>
                                            <option v-for="option in relocationOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-goal">Scholarship goal</label>
                                        <textarea
                                            id="profile-goal"
                                            v-model="form.scholarship_goal"
                                            rows="4"
                                            maxlength="1500"
                                            placeholder="Briefly describe what the scholarship would help you continue or achieve."
                                            :class="inputClass"
                                        ></textarea>
                                        <p class="mt-1 text-right text-xs text-slate-400">{{ form.scholarship_goal.length }}/1500</p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section v-if="activeSection === 'guardian'" id="profile-guardian" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">{{ needsGuardianContext ? 'Required' : 'Optional' }}</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Guardian information</h3>
                                </div>
                                <span :class="[sectionStatusPillClass, sectionStatusClass(profileSection('guardian'))]">
                                    {{ sectionStatusLabel(profileSection('guardian')) }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <div class="student-soft-card p-4">
                                    <p class="text-sm font-bold text-slate-950">
                                        {{ guardianRequirementLabel }}
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        {{ guardianRequirementText }}
                                    </p>
                                </div>

                                <div class="mt-5 grid gap-4 md:grid-cols-3">
                                    <div>
                                        <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                        <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-guardian-relationship">Relationship to learner</label>
                                        <select id="profile-guardian-relationship" v-model="form.guardian_relationship" :class="inputClass">
                                            <option value="">Select relationship</option>
                                            <option v-for="option in guardianRelationshipOptions" :key="option" :value="option">
                                                {{ option }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                        <input id="profile-guardian-contact" :value="form.guardian_contact" placeholder="Guardian contact number" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                    </div>
                                </div>

                                <details class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <summary class="cursor-pointer list-none text-sm font-bold text-slate-950">
                                        Guardian email and account access
                                    </summary>
                                    <div class="mt-4 grid gap-4">
                                        <div>
                                            <label :class="labelClass" for="profile-guardian-email">Guardian email</label>
                                            <input id="profile-guardian-email" v-model="form.guardian_email" type="email" placeholder="guardian@example.com" :class="inputClass">
                                        </div>
                                    </div>

                                    <label class="mt-4 flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                                        <input
                                            v-model="form.guardian_is_account_owner"
                                            type="checkbox"
                                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                                        >
                                        <span>
                                            <span class="block font-bold text-slate-950">Guardian manages this account</span>
                                            <span class="mt-1 block text-xs leading-5">Use this when a parent or guardian signs in and manages applications for the learner.</span>
                                        </span>
                                    </label>
                                </details>
                            </div>
                        </section>

                        <section v-if="activeSection === 'verification'" id="profile-verification" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Account trust</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Profile verification</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                        Confirm your student identity so scholarship providers can trust the profile attached to your applications.
                                    </p>
                                </div>
                                <span :class="[sectionStatusPillClass, verificationStatusClass(profileVerificationStatus)]">
                                    {{ verificationStatusLabel(profileVerificationStatus) }}
                                </span>
                            </div>

                            <ol class="grid border-b border-slate-200 bg-slate-50 md:grid-cols-3 md:divide-x md:divide-slate-200">
                                <li
                                    v-for="step in verificationSteps"
                                    :key="step.number"
                                    class="flex items-start gap-3 border-b border-slate-200 p-4 last:border-b-0 md:border-b-0 sm:p-5"
                                >
                                    <span
                                        :class="[
                                            'grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-black',
                                            step.state === 'complete'
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : step.state === 'current'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'border border-slate-300 bg-white text-slate-500',
                                        ]"
                                    >
                                        <i v-if="step.state === 'complete'" class="fa-solid fa-check" aria-hidden="true"></i>
                                        <span v-else>{{ step.number }}</span>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-950">{{ step.label }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ step.detail }}</p>
                                    </div>
                                </li>
                            </ol>

                            <div
                                v-if="user?.applicant_verification_notes"
                                :class="[
                                    'flex items-start gap-3 border-b p-4 text-sm sm:px-6',
                                    profileVerificationStatus === 'rejected'
                                        ? 'border-rose-200 bg-rose-50 text-rose-800'
                                        : 'border-slate-200 bg-slate-50 text-slate-700',
                                ]"
                            >
                                <i class="fa-solid fa-message mt-1 shrink-0" aria-hidden="true"></i>
                                <div>
                                    <p class="font-bold">Admin review note</p>
                                    <p class="mt-1 leading-6">{{ user.applicant_verification_notes }}</p>
                                </div>
                            </div>

                            <div class="grid border-b border-slate-200 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.58fr)]">
                                <div class="p-5 sm:p-6">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h4 class="text-lg font-bold text-slate-950">{{ verificationUploadCopy.title }}</h4>
                                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">{{ verificationUploadCopy.detail }}</p>
                                        </div>
                                        <span class="shrink-0 text-xs font-bold text-slate-500">
                                            {{ verificationDocuments.length }} of 3 files
                                        </span>
                                    </div>

                                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                        <label>
                                            <span :class="labelClass">Proof type</span>
                                            <select v-model="verificationDocumentType" :class="inputClass">
                                                <option v-for="option in verificationDocumentOptions" :key="option.value" :value="option.value">
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                        </label>

                                        <label class="min-w-0 cursor-pointer">
                                            <span :class="labelClass">Proof file</span>
                                            <span class="flex min-h-11 items-center gap-2 rounded-md border border-dashed border-slate-400 bg-white px-3 py-2.5 text-sm transition hover:border-slate-500 hover:bg-slate-50">
                                                <i class="fa-solid fa-paperclip shrink-0 text-xs text-slate-500" aria-hidden="true"></i>
                                                <span :class="['min-w-0 flex-1 truncate font-semibold', verificationDocumentFile ? 'text-slate-900' : 'text-slate-400']">
                                                    {{ verificationDocumentFile?.name || 'Select from your device' }}
                                                </span>
                                                <span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">Browse</span>
                                            </span>
                                            <input
                                                ref="verificationFileInput"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                class="sr-only"
                                                @change="handleVerificationFile"
                                            >
                                            <span class="mt-1 block text-xs text-slate-500">PDF, image, or Word file up to 5 MB.</span>
                                        </label>
                                    </div>

                                    <p v-if="selectedVerificationDocument" class="mt-3 text-xs font-semibold text-slate-600">
                                        This proof type already has a file. Submitting here will update it and restart admin review.
                                    </p>

                                    <div class="mt-4">
                                        <TermsAgreement v-model="verificationDocumentTermsAccepted" context="document" />
                                    </div>

                                    <button
                                        type="button"
                                        :disabled="isUploadingVerificationDocument"
                                        class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                        @click="uploadVerificationDocument"
                                    >
                                        <i :class="[isUploadingVerificationDocument ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-shield', 'text-xs']" aria-hidden="true"></i>
                                        {{ verificationSubmitLabel }}
                                    </button>
                                </div>

                                <aside class="border-t border-slate-200 bg-slate-50 p-5 lg:border-l lg:border-t-0 sm:p-6">
                                    <p class="student-kicker">Good proof checklist</p>
                                    <h4 class="mt-2 text-base font-bold text-slate-950">Before uploading</h4>
                                    <ul class="mt-4 grid gap-4 text-sm text-slate-600">
                                        <li class="flex items-start gap-3">
                                            <i class="fa-solid fa-check mt-1 text-emerald-600" aria-hidden="true"></i>
                                            <span>Use a current document with all edges visible.</span>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <i class="fa-solid fa-check mt-1 text-emerald-600" aria-hidden="true"></i>
                                            <span>Keep your name, school, and identifying details readable.</span>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <i class="fa-solid fa-check mt-1 text-emerald-600" aria-hidden="true"></i>
                                            <span>Avoid blur, glare, heavy cropping, or password-protected files.</span>
                                        </li>
                                    </ul>
                                </aside>
                            </div>

                            <div class="flex flex-col gap-1 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div>
                                    <h4 class="text-base font-bold text-slate-950">Proofs on file</h4>
                                    <p class="mt-1 text-xs text-slate-500">Files submitted specifically for applicant account verification.</p>
                                </div>
                                <p class="text-xs font-bold text-slate-500">{{ verificationDocuments.length }} submitted</p>
                            </div>

                            <div v-if="verificationDocuments.length" class="divide-y divide-slate-200">
                                <article
                                    v-for="document in verificationDocuments"
                                    :key="document.id"
                                    class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                            <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                                <h5 class="truncate text-sm font-bold text-slate-950">
                                                    {{ verificationDocumentTypeLabel(document.document_type) }}
                                                </h5>
                                                <span :class="['rounded px-2 py-1 text-[11px] font-bold', verificationDocumentStatusClass(document.status)]">
                                                    {{ verificationDocumentStatusLabel(document.status) }}
                                                </span>
                                            </div>
                                            <p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ formatFileSize(document.size) }} - {{ document.uploaded_at }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <a
                                            :href="document.view_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            View
                                        </a>
                                        <button
                                            type="button"
                                            :disabled="deletingVerificationDocumentId === document.id"
                                            class="grid h-9 w-9 place-items-center rounded-md border border-rose-200 bg-white text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            title="Remove proof"
                                            aria-label="Remove verification proof"
                                            @click="deleteVerificationDocument(document)"
                                        >
                                            <i :class="[deletingVerificationDocumentId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can', 'text-xs']" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </article>
                            </div>

                            <div v-else class="flex items-start gap-3 px-5 py-6 sm:px-6">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-500">
                                    <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-slate-950">No verification proof submitted</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">Choose one clear school ID or enrollment document above to begin review.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 text-xs leading-5 text-slate-600 sm:px-6">
                                <i class="fa-solid fa-lock mt-1 shrink-0 text-slate-500" aria-hidden="true"></i>
                                <p>Your proof stays private and is available only to you and authorized admins. Scholarship providers see only your verification result.</p>
                            </div>
                        </section>

                        <section v-if="activeSection === 'review'" id="profile-review" :class="sectionCardClass">
                            <div :class="sectionHeaderClass">
                                <div>
                                    <p class="student-kicker">Final check</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Review profile</h3>
                                </div>
                                <span
                                    :class="[
                                        sectionStatusPillClass,
                                        profileComplete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                                    ]"
                                >
                                    {{ profileQuality.label }}
                                </span>
                            </div>

                            <div :class="sectionBodyClass">
                                <section class="rounded-lg border border-slate-200 bg-slate-950 p-4 text-white">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-200">Saved-profile match check</p>
                                            <h4 class="mt-1 text-lg font-bold">How the current catalog reads your profile</h4>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-300">
                                                This checks structured eligibility only. Documents and the provider's final review remain separate.
                                            </p>
                                        </div>
                                        <a href="/dashboard/scholarships" class="w-fit rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-950">
                                            Open finder
                                        </a>
                                    </div>
                                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t border-white/10 pt-4 text-sm">
                                        <p><span class="font-bold text-amber-200">{{ matchSummary.strong_matches }}</span> strong</p>
                                        <p><span class="font-bold text-amber-200">{{ matchSummary.eligible_programs }}</span> eligible</p>
                                        <p><span class="font-bold text-amber-200">{{ matchSummary.preference_matches }}</span> fit preferences</p>
                                        <p><span class="font-bold text-amber-200">{{ matchSummary.available_programs }}</span> checked</p>
                                    </div>
                                    <div v-if="matchSummary.top_gaps?.length" class="mt-4 border-t border-white/10 pt-4">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-300">Most common missing or conflicting details</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <button
                                                v-for="gap in matchSummary.top_gaps"
                                                :key="gap.key || gap.label"
                                                type="button"
                                                class="rounded-md bg-white/10 px-2.5 py-1.5 text-xs font-semibold text-white ring-1 ring-white/10 transition hover:bg-white/15"
                                                @click="openSection(sectionForMatchGap(gap))"
                                            >
                                                {{ gap.label }} ({{ gap.count }})
                                            </button>
                                        </div>
                                    </div>
                                </section>

                                <div class="mt-5 grid gap-4 md:grid-cols-2">
                                    <article
                                        v-for="group in reviewGroups"
                                        :key="group.title"
                                        class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                                    >
                                        <h4 class="font-bold text-slate-950">
                                            {{ group.title }}
                                        </h4>
                                        <div class="mt-3 grid gap-2">
                                            <div
                                                v-for="item in group.items"
                                                :key="`${group.title}-${item[0]}`"
                                                class="flex items-start justify-between gap-3 text-sm"
                                            >
                                                <span class="text-slate-500">{{ item[0] }}</span>
                                                <span class="max-w-[60%] text-right font-semibold text-slate-900">
                                                    {{ hasValue(item[1]) ? item[1] : 'Not set' }}
                                                </span>
                                            </div>
                                        </div>
                                    </article>
                                </div>

                                <section class="mt-5 rounded-lg border border-slate-200 bg-white p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="student-kicker">
                                                Provider Preview
                                            </p>
                                            <h4 class="mt-2 text-lg font-bold text-slate-950">
                                                What reviewers will scan first
                                            </h4>
                                        </div>
                                        <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                            Preview only
                                        </span>
                                    </div>
                                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                        <div
                                            v-for="row in providerPreviewRows"
                                            :key="row[0]"
                                            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2"
                                        >
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                                                {{ row[0] }}
                                            </p>
                                            <p class="mt-1 line-clamp-2 text-sm font-bold text-slate-900">
                                                {{ hasValue(row[1]) ? row[1] : 'Not set' }}
                                            </p>
                                        </div>
                                    </div>
                                </section>

                                <div v-if="missingProfileFields.length" class="mt-5 rounded-lg border border-amber-100 bg-amber-50 p-4">
                                    <p class="text-sm font-bold text-amber-900">
                                        Add these before applying
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            v-for="field in missingProfileFields"
                                            :key="field.key"
                                            type="button"
                                            class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-amber-800 ring-1 ring-amber-100"
                                            @click="openSection(profileSections.find((section) => sectionAllFields(section).includes(field.key))?.id || 'personal')"
                                        >
                                            {{ field.label }}
                                        </button>
                                    </div>
                                </div>

                                <div v-else class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                                    Profile is application-ready. You can save it as complete.
                                </div>
                            </div>
                        </section>

                        <div class="student-card flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isSaving"
                                @click="visibleActiveSectionIndex <= 0 ? openProfileOverview() : goToPreviousSection()"
                            >
                                {{ visibleActiveSectionIndex <= 0 ? 'Back to profile' : 'Previous' }}
                            </button>

                            <p class="text-center text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                {{ activeProfileSection.label }} - {{ visibleActiveSectionIndex + 1 }} of {{ visibleProfileSections.length }}
                            </p>

                            <button
                                type="button"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isSaving"
                                @click="goToNextSection"
                            >
                                {{ isSaving
                                    ? 'Saving...'
                                    : visibleActiveSectionIndex >= visibleProfileSections.length - 1
                                        ? (hasUnsavedChanges ? 'Save and finish' : 'Finish')
                                        : (hasUnsavedChanges ? 'Save and continue' : 'Next section') }}
                            </button>
                        </div>
                    </section>

                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
