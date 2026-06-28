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
const preferredLocationOptions = ['Anywhere in the Philippines', 'Near my home address', 'NCR', 'Region III', 'Region IV-A', 'Cebu', 'Davao', 'Online-friendly'];
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
];

const fieldLabels = {
    first_name: 'First name',
    last_name: 'Last name',
    middle_initial: 'Middle initial',
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
        impact: 'Basic applicant information.',
        required: true,
        fields: ['first_name', 'middle_initial', 'last_name', 'birthdate', 'contact_number', 'account_managed_by'],
        requiredFields: ['first_name', 'middle_initial', 'last_name', 'birthdate', 'contact_number', 'account_managed_by'],
    },
    {
        id: 'academic',
        label: 'Learning',
        detail: 'School record',
        icon: 'fa-solid fa-book-open-reader',
        impact: 'Used for matching and eligibility.',
        required: true,
        fields: ['education_level', 'school', 'school_type', 'learner_reference_number', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
        requiredFields: ['education_level', 'school', 'course_or_strand', 'year_level', 'enrollment_status', 'grading_scale', 'gwa'],
    },
    {
        id: 'location',
        label: 'Location and need',
        detail: 'Address and need',
        icon: 'fa-solid fa-location-dot',
        impact: 'Used for distance and need-based matching.',
        required: true,
        fields: ['income_bracket', 'household_size', 'address', 'barangay', 'city', 'province', 'region'],
        requiredFields: ['income_bracket', 'address', 'barangay', 'city', 'province', 'region'],
    },
    {
        id: 'guardian',
        label: 'Guardian',
        detail: 'Contact person',
        icon: 'fa-solid fa-user-shield',
        impact: 'Parent or guardian contact.',
        required: true,
        fields: ['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email', 'guardian_is_account_owner'],
        requiredFields: ['guardian_name', 'guardian_relationship', 'guardian_contact'],
    },
    {
        id: 'preferences',
        label: 'Finder preferences',
        detail: 'Optional',
        icon: 'fa-solid fa-sliders',
        impact: 'Optional details for better suggestions.',
        required: false,
        fields: ['preferred_categories', 'preferred_locations', 'willing_to_relocate', 'support_needs', 'scholarship_goal'],
    },
    {
        id: 'review',
        label: 'Review',
        detail: 'Final check',
        icon: 'fa-solid fa-clipboard-check',
        impact: 'Confirm the profile before applying.',
        required: false,
        fields: [],
    },
];

const courseRequiredLevels = ['senior_high_school', 'college', 'tvet'];
const gradesRequiredLevels = ['elementary', 'junior_high_school', 'senior_high_school', 'college', 'tvet', 'als', 'other'];
const guardianRequiredLevels = ['preschool', 'elementary', 'junior_high_school', 'senior_high_school'];
const requiresProgramPath = computed(() => courseRequiredLevels.includes(form.value.education_level));
const requiresGrades = computed(() => gradesRequiredLevels.includes(form.value.education_level));
const needsGuardianContext = computed(() => guardianRequiredLevels.includes(form.value.education_level)
    || ['parent_guardian', 'relative', 'school_representative', 'other'].includes(form.value.account_managed_by));
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

    if (['gwa', 'grading_scale'].includes(field)) {
        return requiresGrades.value || hasValue(form.value[field]);
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
    if (field === 'course_or_strand') {
        return requiresProgramPath.value;
    }

    if (['gwa', 'grading_scale'].includes(field)) {
        return requiresGrades.value;
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
const guardianRequirementLabel = computed(() => needsGuardianContext.value ? 'Required for this profile' : 'Optional contact');
const guardianRequirementText = computed(() => needsGuardianContext.value
    ? 'Younger learners or parent-managed accounts need a guardian contact for scholarship follow-ups.'
    : 'Add a trusted contact if someone else helps with applications.');
const reviewGroups = computed(() => [
    {
        title: 'Learner',
        items: [
            ['Name', [form.value.first_name, form.value.middle_initial ? `${form.value.middle_initial}.` : '', form.value.last_name].filter(Boolean).join(' ')],
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
        title: 'Location and Need',
        items: [
            ['Income bracket', form.value.income_bracket],
            ['Household size', form.value.household_size],
            ['Address', [form.value.address, form.value.barangay, form.value.city, form.value.province, form.value.region].filter(Boolean).join(', ')],
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
    {
        title: 'Preferences',
        items: [
            ['Scholarship types', listFromText(form.value.preferred_categories).join(', ')],
            ['Support needs', listFromText(form.value.support_needs).join(', ')],
            ['Preferred locations', form.value.preferred_locations],
            ['Relocation', relocationOptions.find((option) => option.value === form.value.willing_to_relocate)?.label],
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
                        </div>

                        <div class="student-soft-card w-full p-4 lg:max-w-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Application readiness
                                    </p>
                                    <p class="mt-1 text-sm font-bold text-slate-950">
                                        {{ profileQuality.label }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ completedRequiredFields }}/{{ requiredFieldData.length }} required details
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

                <div v-else class="mt-6 grid gap-5 xl:grid-cols-[17rem_minmax(0,1fr)] xl:items-start">
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
                                    <span class="flex items-center gap-2 font-bold text-slate-950">
                                        <i :class="[section.icon, 'w-4 text-center text-xs text-sky-700']"></i>
                                        {{ section.label }}
                                    </span>
                                    <span
                                        v-if="section.fields.length"
                                        :class="[
                                            'rounded-md px-2 py-0.5 text-xs font-bold',
                                            sectionProgress(section).complete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                                        ]"
                                    >
                                        {{ sectionProgress(section).completed }}/{{ sectionProgress(section).total }}
                                    </span>
                                    <span v-else class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                                        Check
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

                    <section class="space-y-5 xl:col-start-2 xl:row-start-1">
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
                                <div class="flex gap-4">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-slate-900 text-white shadow-sm">
                                        <i :class="[activeProfileSection.icon, 'text-lg']"></i>
                                    </div>
                                    <div>
                                    <p class="student-kicker">
                                        Page {{ activeSectionIndex + 1 }} of {{ profileSections.length }}
                                    </p>
                                    <h3 class="mt-2 text-2xl font-bold text-slate-950">
                                        {{ activeProfileSection.label }}
                                    </h3>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">
                                        {{ activeProfileSection.impact }}
                                    </p>
                                    </div>
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
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Personal details</h3>
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

                            <div class="student-soft-card mt-4 grid gap-4 p-4 md:grid-cols-[1fr_1.2fr] md:items-center">
                                <div>
                                    <p class="text-sm font-bold text-slate-950">
                                        Account context
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        Helps the portal handle child or guardian-managed profiles correctly.
                                    </p>
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-account-manager">Who manages this account?</label>
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
                        </section>

                        <section v-if="activeSection === 'academic'" id="profile-academic" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Learning background</h3>
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
                                <p class="text-sm leading-5 text-slate-600">
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
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-3">
                                <div v-if="isFieldRelevant('gwa')">
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
                                    <p class="student-kicker">Required</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Location and financial need</h3>
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

                            <div class="student-soft-card mt-5 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">
                                            Address map preview
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
                                    <p class="student-kicker">{{ needsGuardianContext ? 'Required' : 'Optional' }}</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Guardian information</h3>
                                </div>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    {{ sectionProgress(profileSections[3]).percent }}% ready
                                </span>
                            </div>

                            <div class="student-soft-card mt-5 p-4">
                                <p class="text-sm font-bold text-slate-950">
                                    {{ guardianRequirementLabel }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    {{ guardianRequirementText }}
                                </p>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                    <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-relationship">Relationship to learner</label>
                                    <select id="profile-guardian-relationship" v-model="form.guardian_relationship" :class="inputClass">
                                        <option value="">Select relationship</option>
                                        <option
                                            v-for="option in guardianRelationshipOptions"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                    <input id="profile-guardian-contact" :value="form.guardian_contact" placeholder="Guardian contact number" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-email">Guardian email <span class="font-normal text-sky-600">(optional)</span></label>
                                    <input id="profile-guardian-email" v-model="form.guardian_email" type="email" placeholder="guardian@example.com" :class="inputClass">
                                </div>
                            </div>

                            <label class="mt-4 flex items-start gap-3 rounded-lg border border-slate-200 bg-[#f6faf8] p-4 text-sm text-slate-600">
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
                        </section>

                        <section v-if="activeSection === 'preferences'" id="profile-preferences" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Optional</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Finder preferences</h3>
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
                                    <p :class="labelClass">Preferred locations</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="option in preferredLocationOptions"
                                            :key="option"
                                            type="button"
                                            :class="[
                                                'rounded-md border px-3 py-2 text-xs font-bold transition',
                                                isOptionSelected('preferred_locations', option) ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500',
                                            ]"
                                            @click="toggleListOption('preferred_locations', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
                                    <textarea
                                        id="profile-preferred-locations"
                                        v-model="form.preferred_locations"
                                        rows="2"
                                        placeholder="Add custom location if needed"
                                        class="mt-2"
                                        :class="inputClass"
                                    ></textarea>
                                </div>
                                <div class="md:col-span-2">
                                    <p :class="labelClass">Preferred scholarship types</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="option in categoryOptions"
                                            :key="option"
                                            type="button"
                                            :class="[
                                                'rounded-md border px-3 py-2 text-xs font-bold transition',
                                                isOptionSelected('preferred_categories', option) ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500',
                                            ]"
                                            @click="toggleListOption('preferred_categories', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
                                </div>
                                <div class="md:col-span-2">
                                    <p :class="labelClass">Support needs</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="option in supportNeedOptions"
                                            :key="option"
                                            type="button"
                                            :class="[
                                                'rounded-md border px-3 py-2 text-xs font-bold transition',
                                                isOptionSelected('support_needs', option) ? 'border-sky-700 bg-sky-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-500',
                                            ]"
                                            @click="toggleListOption('support_needs', option)"
                                        >
                                            {{ option }}
                                        </button>
                                    </div>
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

                        <section v-if="activeSection === 'review'" id="profile-review" class="student-card p-6">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">Final check</p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">Review profile</h3>
                                </div>
                                <span
                                    :class="[
                                        'rounded-md px-3 py-2 text-xs font-bold uppercase tracking-[0.14em]',
                                        profileComplete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800',
                                    ]"
                                >
                                    {{ profileQuality.label }}
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <article
                                    v-for="group in reviewGroups"
                                    :key="group.title"
                                    class="rounded-lg border border-slate-200 bg-[#f6faf8] p-4"
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
                        </section>
                    </section>

                    <aside class="space-y-5 xl:col-start-2">
                        <section class="student-card p-5">
                            <p class="student-kicker">
                                Readiness
                            </p>
                            <div class="mt-3 flex items-end justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold text-slate-950">
                                        {{ profileQuality.label }}
                                    </h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        {{ profileQuality.detail }}
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
                                Sections
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
                                        <p v-if="section.fields.length" class="text-xs font-bold text-slate-500">
                                            {{ sectionProgress(section).completed }}/{{ sectionProgress(section).total }}
                                        </p>
                                        <p v-else class="text-xs font-bold text-slate-500">
                                            Check
                                        </p>
                                    </div>
                                    <div v-if="section.fields.length" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            class="h-full rounded-full transition-all"
                                            :class="section.required ? 'bg-slate-900' : 'bg-sky-600'"
                                            :style="{ width: `${sectionProgress(section).percent}%` }"
                                        ></div>
                                    </div>
                                    <p v-if="section.fields.length && sectionMissingFields(section).length" class="mt-2 text-xs leading-5 text-slate-500">
                                        Missing: {{ sectionMissingFields(section).slice(0, 3).join(', ') }}
                                    </p>
                                    <p v-else-if="section.fields.length" class="mt-2 text-xs font-semibold text-emerald-700">
                                        {{ section.required ? 'Ready' : 'Added' }}
                                    </p>
                                    <p v-else class="mt-2 text-xs font-semibold text-slate-500">
                                        Confirm before completing
                                    </p>
                                </div>
                            </div>
                        </section>

                        <details class="student-card p-5">
                            <summary class="cursor-pointer list-none">
                                <p class="student-kicker">Checklist</p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">Required fields</h3>
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
