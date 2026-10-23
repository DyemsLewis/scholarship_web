<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AlertDialog from '../../../components/AlertDialog.vue';
import ApplicantPagePreviewModal from '../../../components/ApplicantPagePreviewModal.vue';
import ConfirmationDialog from '../../../components/ConfirmationDialog.vue';
import CoverImagePickerModal from '../../../components/CoverImagePickerModal.vue';
import LocationMapModal from '../../../components/LocationMapModal.vue';
import { useConfirmationDialog } from '../../../composables/useConfirmationDialog';
import UserScholarshipDetailPage from '../../UserScholarshipDetailPage.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const pathMatch = window.location.pathname.match(/\/provider\/programs\/(\d+)\/edit(?:\/([a-z-]+))?$/);
const portalUser = window.portalUser ?? {};
const programId = ref(pathMatch?.[1] ?? null);
const steps = [
    { id: 'basics', label: 'Basics', hint: 'Program identity', icon: 'fa-solid fa-pen-to-square' },
    { id: 'support', label: 'Support', hint: 'Award package', icon: 'fa-solid fa-hand-holding-heart' },
    { id: 'dates-location', label: 'Schedule', hint: 'Dates and location', icon: 'fa-regular fa-calendar' },
    { id: 'eligibility', label: 'Eligibility', hint: 'Applicant rules', icon: 'fa-solid fa-user-check' },
    { id: 'application', label: 'Application', hint: 'Files and handoff', icon: 'fa-solid fa-folder-open' },
    { id: 'selection', label: 'Selection', hint: 'Review process', icon: 'fa-solid fa-list-check' },
    { id: 'review', label: 'Review', hint: 'Confirm and submit', icon: 'fa-solid fa-circle-check' },
];
const validStepIds = new Set(steps.map((step) => step.id));
const activeStep = ref(validStepIds.has(pathMatch?.[2]) ? pathMatch[2] : 'basics');
const isLoading = ref(Boolean(programId.value));
const isSaving = ref(false);
const savedMessage = ref('');
const originalStatus = ref('draft');
const applicationsCount = ref(0);
const logoInput = ref(null);
const logoFile = ref(null);
const logoPreviewUrl = ref('');
const coverPickerOpen = ref(false);
const coverImages = ref([]);
const coverSource = ref('default');
const coverKey = ref('');
const coverFile = ref(null);
const coverPreviewUrl = ref('');
const recommendedCoverKey = ref('learning-together');
const providerCoverUrl = ref(portalUser.provider_cover_url ?? '');
const showLocationMap = ref(false);
const mapMessage = ref('');
const benefitType = ref('');
const benefitDialogOpen = ref(false);
const benefitDraft = ref(null);
const editingBenefitIndex = ref(null);
const benefitTitleInput = ref(null);
const alert = ref({ open: false, title: '', message: '' });
const previewOpen = ref(false);
const previewScenario = ref('general');
const { confirmation, requestConfirmation, confirmConfirmation, cancelConfirmation } = useConfirmationDialog();

const inputClass = 'h-11 w-full border border-slate-300 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-slate-800 focus:ring-1 focus:ring-slate-800';
const textareaClass = 'w-full border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-slate-800 focus:ring-1 focus:ring-slate-800';
const labelClass = 'mb-1.5 block text-xs font-bold text-slate-700';
const categories = ['Academic merit', 'Financial assistance', 'Community grant', 'STEM scholarship', 'Leadership grant', 'Athletic scholarship'];
const educationLevels = [
    ['preschool', 'Preschool / Kindergarten'],
    ['elementary', 'Elementary'],
    ['junior_high_school', 'Junior High School'],
    ['senior_high_school', 'Senior High School'],
    ['college', 'College / University'],
    ['tvet', 'TVET / Vocational'],
    ['als', 'ALS / Alternative Learning'],
];
const schoolTypes = [
    ['public', 'Public school'],
    ['private', 'Private school'],
    ['state_university', 'State university / college'],
    ['local_college', 'Local college / university'],
    ['tvet_center', 'TVET center'],
    ['als_center', 'ALS center'],
];
const benefitTypes = [
    ['cash_grant', 'Cash grant'],
    ['tuition_coverage', 'Tuition coverage'],
    ['allowance', 'Allowance'],
    ['school_supplies', 'School supplies'],
    ['device_support', 'Device support'],
    ['transportation', 'Transportation support'],
    ['accommodation', 'Accommodation support'],
    ['training', 'Training or certification'],
    ['mentorship', 'Mentorship'],
    ['fee_waiver', 'Fee waiver'],
    ['other', 'Other benefit'],
];
const frequencyOptions = [
    ['one_time', 'One-time'],
    ['monthly', 'Monthly'],
    ['per_term', 'Per term or semester'],
    ['annual', 'Annual'],
    ['entire_program', 'Entire program'],
];
const stageOptions = [
    { id: 'screening', label: 'Pre-screening', detail: 'Eligibility, profile, and document review', locked: true },
    { id: 'formal_application', label: 'Formal application', detail: 'Provider forms or original document verification', locked: true },
    { id: 'exam', label: 'Exam', detail: 'Provider-managed assessment', locked: false },
    { id: 'interview', label: 'Interview', detail: 'Online or in-person conversation', locked: false },
    { id: 'decision', label: 'Final decision', detail: 'Selected, waitlisted, or not selected', locked: true },
];
const defaultRubric = [
    { key: 'eligibility_fit', label: 'Eligibility fit', weight: 35, guidance: 'Confirm the applicant meets the program rules.' },
    { key: 'academic_merit', label: 'Academic merit', weight: 25, guidance: 'Review grades using the stated scale.' },
    { key: 'financial_need', label: 'Financial need', weight: 20, guidance: 'Review declared need and income evidence.' },
    { key: 'document_quality', label: 'Document quality', weight: 20, guidance: 'Check that required files are readable and current.' },
];

function emptyForm() {
    return {
        title: '', category: '', programCycle: '', description: '',
        benefits: [], slotsAvailable: '', applicationLimit: '', supportStartsAt: '', supportEndsAt: '',
        applicationOpensAt: '', deadline: '', expectedResultsAt: '',
        locationName: portalUser.provider_name ?? '',
        locationAddress: portalUser.provider_address ?? '',
        latitude: '', longitude: '',
        contactEmail: portalUser.provider_contact_email ?? '',
        contactNumber: portalUser.provider_contact_number ?? '',
        contactPerson: '', contactDepartment: portalUser.provider_contact_department ?? '',
        officialProgramUrl: '',
        eligibility: '', eligibleEducationLevels: [], eligibleCourses: '', eligibleSchoolTypes: [],
        eligibleYearLevels: '', eligibleLocations: '', incomeRequirement: 'Any',
        minimumGradeScale: '', minimumGwa: '', excludeCurrentRecipients: false,
        applicationMode: 'online',
        requirements: 'Certificate of enrollment\nLatest report card or grades\nRecent school ID',
        optionalRequirements: '',
        postQualificationRequirements: 'Original copies of submitted records',
        handoffMode: 'onsite',
        handoffInstructions: 'Bring the listed original records for final verification.',
        handoffDeadline: '', handoffLocationName: '', handoffLocationAddress: '', handoffUrl: '',
        selectionStages: ['screening', 'formal_application', 'decision'],
        reviewRubric: structuredClone(defaultRubric),
        recipientAgreement: {
            commitment_type: 'provider_briefing', responsibilities: '', required_evidence: '',
            release_conditions: '', duration: '', noncompliance_consequence: '', exit_or_exception_process: '',
        },
        termsAccepted: false,
        logoPath: '',
        logoUrl: '',
        coverUrl: '',
    };
}

const form = ref(emptyForm());
const activeStepIndex = computed(() => steps.findIndex((step) => step.id === activeStep.value));
const currentStep = computed(() => steps[activeStepIndex.value] ?? steps[0]);
const isCreateMode = computed(() => !programId.value);
const isReadOnly = computed(() => originalStatus.value === 'pending_review');
const canDelete = computed(() => originalStatus.value === 'draft' && applicationsCount.value === 0);
const hasScholarshipLogo = computed(() => Boolean(logoFile.value || form.value.logoPath));
const scholarshipLogo = computed(() => logoPreviewUrl.value || (form.value.logoPath ? form.value.logoUrl : ''));
const selectedSystemCover = computed(() => coverImages.value.find((image) => image.key === (coverKey.value || recommendedCoverKey.value)) ?? null);
const programImage = computed(() => {
    if (coverPreviewUrl.value) return coverPreviewUrl.value;
    if (coverSource.value === 'provider') return providerCoverUrl.value;
    if (coverSource.value === 'system') return selectedSystemCover.value?.url ?? '';
    if (coverSource.value === 'upload') return form.value.coverUrl;
    return form.value.coverUrl || selectedSystemCover.value?.url || '';
});
const coverSourceLabel = computed(() => ({
    default: 'Recommended image',
    legacy: 'Current program image',
    system: 'System image',
    provider: 'Organization background',
    upload: 'Uploaded image',
}[coverSource.value] ?? 'Program background'));
const mapAddress = computed(() => form.value.locationAddress.trim());
const requiresAgreementDetails = computed(() => !['provider_briefing', 'none'].includes(form.value.recipientAgreement.commitment_type));
const selectedStages = computed(() => stageOptions.filter((stage) => form.value.selectionStages.includes(stage.id)));

const stepChecks = computed(() => ({
    basics: Boolean(
        form.value.title.trim()
        && form.value.category
        && form.value.programCycle.trim()
        && form.value.description.trim()
        && hasScholarshipLogo.value
        && Boolean(programImage.value || ['default', 'system', 'provider'].includes(coverSource.value))
    ),
    support: Boolean(
        form.value.benefits.length
        && positiveNumber(form.value.slotsAvailable)
        && positiveNumber(form.value.applicationLimit)
        && form.value.supportStartsAt
        && form.value.supportEndsAt
        && form.value.supportEndsAt >= form.value.supportStartsAt
    ),
    'dates-location': Boolean(
        form.value.deadline
        && form.value.locationName.trim()
        && form.value.locationAddress.trim()
        && String(form.value.latitude).trim()
        && String(form.value.longitude).trim()
        && (form.value.contactEmail.trim() || form.value.contactNumber.trim())
    ),
    eligibility: Boolean(
        form.value.eligibility.trim()
        && (
            form.value.eligibleEducationLevels.length
            || form.value.eligibleCourses.trim()
            || form.value.eligibleSchoolTypes.length
            || form.value.eligibleYearLevels.trim()
            || form.value.eligibleLocations.trim()
            || form.value.minimumGradeScale
            || form.value.incomeRequirement !== 'Any'
            || form.value.excludeCurrentRecipients
        )
        && (!['percentage', 'grade_point'].includes(form.value.minimumGradeScale) || numericValue(form.value.minimumGwa) !== null)
    ),
    application: Boolean(
        form.value.applicationMode
        && (form.value.applicationMode === 'provider_review' || form.value.requirements.trim())
        && form.value.postQualificationRequirements.trim()
        && form.value.handoffMode
        && form.value.handoffInstructions.trim()
        && (form.value.handoffMode !== 'onsite' || form.value.handoffLocationAddress.trim())
        && (form.value.handoffMode !== 'online' || validWebUrl(form.value.handoffUrl))
    ),
    selection: Boolean(
        ['screening', 'formal_application', 'decision'].every((stage) => form.value.selectionStages.includes(stage))
        && form.value.reviewRubric.length
        && form.value.recipientAgreement.commitment_type !== 'provider_briefing'
        && form.value.recipientAgreement.release_conditions.trim()
        && (!requiresAgreementDetails.value || [
            'responsibilities', 'required_evidence', 'duration', 'noncompliance_consequence', 'exit_or_exception_process',
        ].every((field) => form.value.recipientAgreement[field].trim()))
    ),
}));

const completedStepCount = computed(() => Object.values(stepChecks.value).filter(Boolean).length);
const allConfigurationComplete = computed(() => Object.values(stepChecks.value).every(Boolean));

function positiveNumber(value) {
    return numericValue(value) !== null && Number(value) >= 1;
}

function numericValue(value) {
    if (value === '' || value === null || value === undefined) return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function validWebUrl(value) {
    try {
        return ['http:', 'https:'].includes(new URL(value).protocol);
    } catch {
        return false;
    }
}

function splitList(value) {
    if (!value) return [];
    return String(value).split(/\r?\n|,/).map((item) => item.trim()).filter(Boolean);
}

function showAlert(title, message) {
    alert.value = { open: true, title, message };
}

function closeAlert() {
    alert.value.open = false;
}

function stepMessage(stepId) {
    return {
        basics: 'Add the title, category, cycle, summary, scholarship logo, and background image.',
        support: 'Add at least one benefit, both capacity limits, and a valid support period.',
        'dates-location': 'Add the deadline, public contact, complete program location, and map pin.',
        eligibility: 'Describe eligible applicants and add at least one matching rule.',
        application: 'Complete the portal review, required files, and formal application handoff.',
        selection: 'Confirm the selection stages and disclose the complete recipient agreement.',
    }[stepId] ?? 'Complete the required information before continuing.';
}

function validateStep(stepId = activeStep.value) {
    if (stepId === 'review') return allConfigurationComplete.value;
    if (stepChecks.value[stepId]) return true;
    showAlert('Complete this step', stepMessage(stepId));
    return false;
}

function programStepUrl(stepId) {
    return programId.value ? `/provider/programs/${programId.value}/edit/${stepId}` : '/provider/programs/create';
}

function openStep(stepId, replace = false) {
    if (!validStepIds.has(stepId)) return;
    if (!programId.value && stepId !== 'basics') {
        showAlert('Save the basics first', 'Complete the first step to create the draft before opening the rest of the program builder.');
        return;
    }

    activeStep.value = stepId;
    const url = programStepUrl(stepId);
    if (window.location.pathname !== url) {
        window.history[replace ? 'replaceState' : 'pushState']({}, '', url);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function handleHistory() {
    const match = window.location.pathname.match(/\/provider\/programs\/(\d+)\/edit(?:\/([a-z-]+))?$/);
    const step = match?.[2] ?? 'basics';
    if (validStepIds.has(step)) activeStep.value = step;
}

function toggleArrayValue(field, value) {
    form.value[field] = form.value[field].includes(value)
        ? form.value[field].filter((item) => item !== value)
        : [...form.value[field], value];
}

function toggleStage(stage) {
    if (stage.locked || isReadOnly.value) return;
    const optional = form.value.selectionStages.filter((item) => ['exam', 'interview'].includes(item));
    const nextOptional = optional.includes(stage.id)
        ? optional.filter((item) => item !== stage.id)
        : [...optional, stage.id];
    form.value.selectionStages = ['screening', 'formal_application', ...nextOptional, 'decision'];
}

function benefitLabel(type) {
    return benefitTypes.find(([value]) => value === type)?.[1] ?? 'Benefit';
}

function emptyBenefit(type) {
    return {
        type,
        title: benefitLabel(type),
        amount: '', coverage: '', frequency: 'one_time', duration: '', description: '',
    };
}

function openBenefitDialog(index = null) {
    if (isReadOnly.value) return;
    if (index === null) {
        if (!benefitType.value || form.value.benefits.length >= 10) return;
        benefitDraft.value = emptyBenefit(benefitType.value);
    } else {
        benefitDraft.value = normalizedBenefit(form.value.benefits[index]);
    }

    editingBenefitIndex.value = index;
    benefitDialogOpen.value = true;
    benefitType.value = '';
    nextTick(() => benefitTitleInput.value?.focus());
}

function closeBenefitDialog() {
    benefitDialogOpen.value = false;
    benefitDraft.value = null;
    editingBenefitIndex.value = null;
}

function saveBenefit() {
    if (!benefitDraft.value) return;
    const benefit = normalizedBenefit({
        ...benefitDraft.value,
        title: benefitDraft.value.title.trim() || benefitLabel(benefitDraft.value.type),
    });

    if (editingBenefitIndex.value === null) {
        if (form.value.benefits.length >= 10) return;
        form.value.benefits.push(benefit);
    } else {
        form.value.benefits.splice(editingBenefitIndex.value, 1, benefit);
    }
    closeBenefitDialog();
}

function removeBenefit(index) {
    form.value.benefits.splice(index, 1);
}

function benefitSummary(benefit) {
    const parts = [];
    if (benefit.amount !== '' && benefit.amount !== null) parts.push(`PHP ${Number(benefit.amount).toLocaleString('en-PH')}`);
    const frequency = frequencyOptions.find(([value]) => value === benefit.frequency)?.[1];
    if (frequency) parts.push(frequency);
    if (benefit.duration) parts.push(benefit.duration);
    return parts.join(' | ') || 'Details optional';
}

function clearCoverUpload() {
    coverFile.value = null;
    if (coverPreviewUrl.value) URL.revokeObjectURL(coverPreviewUrl.value);
    coverPreviewUrl.value = '';
}

function selectScholarshipLogo(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 4 * 1024 * 1024) {
        event.target.value = '';
        showAlert('Logo could not be selected', 'Choose a JPG, PNG, or WebP image up to 4 MB.');
        return;
    }

    if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
    logoFile.value = file;
    logoPreviewUrl.value = URL.createObjectURL(file);
}

function openLogoPicker() {
    if (!logoInput.value) return;

    logoInput.value.value = '';
    logoInput.value.click();
}

function selectSystemCover(image) {
    clearCoverUpload();
    coverSource.value = 'system';
    coverKey.value = image.key;
    coverPickerOpen.value = false;
}

function selectProviderCover() {
    clearCoverUpload();
    coverSource.value = 'provider';
    coverKey.value = '';
    coverPickerOpen.value = false;
}

function selectUploadedCover(file) {
    clearCoverUpload();
    coverFile.value = file;
    coverPreviewUrl.value = URL.createObjectURL(file);
    coverSource.value = 'upload';
    coverKey.value = '';
    coverPickerOpen.value = false;
}

function resetCover() {
    clearCoverUpload();
    coverSource.value = 'default';
    coverKey.value = recommendedCoverKey.value;
    coverPickerOpen.value = false;
}

function handleMapResolved(location) {
    form.value.latitude = Number(location.latitude).toFixed(7);
    form.value.longitude = Number(location.longitude).toFixed(7);
    mapMessage.value = 'Map pin set from the address.';
}

function handleMapPicked(location) {
    handleMapResolved(location);
    if (location.displayName) form.value.locationAddress = location.displayName;
    mapMessage.value = 'Map pin updated.';
}

function normalizedBenefit(benefit) {
    return {
        type: benefit.type,
        title: benefit.title || benefitLabel(benefit.type),
        amount: benefit.amount ?? '',
        coverage: benefit.coverage ?? '',
        frequency: benefit.frequency || 'one_time',
        duration: benefit.duration ?? '',
        description: benefit.description ?? '',
    };
}

function fillForm(program) {
    form.value = {
        ...emptyForm(),
        title: program.title ?? '',
        category: program.category ?? '',
        programCycle: program.program_cycle ?? '',
        description: program.description ?? '',
        benefits: (program.benefits ?? []).map(normalizedBenefit),
        slotsAvailable: program.slots_available ?? '',
        applicationLimit: program.application_limit ?? '',
        supportStartsAt: program.support_starts_at ?? '',
        supportEndsAt: program.support_ends_at ?? '',
        applicationOpensAt: program.application_opens_at ?? '',
        deadline: program.deadline ?? '',
        expectedResultsAt: program.expected_results_at ?? '',
        locationName: program.location_name ?? portalUser.provider_name ?? '',
        locationAddress: program.location_address ?? portalUser.provider_address ?? '',
        latitude: program.latitude ?? '',
        longitude: program.longitude ?? '',
        contactEmail: program.contact_email ?? portalUser.provider_contact_email ?? '',
        contactNumber: program.contact_number ?? portalUser.provider_contact_number ?? '',
        contactPerson: program.contact_person ?? '',
        contactDepartment: program.contact_department ?? '',
        officialProgramUrl: program.official_program_url ?? '',
        eligibility: program.eligibility ?? '',
        eligibleEducationLevels: splitList(program.eligible_education_levels),
        eligibleCourses: program.eligible_courses ?? '',
        eligibleSchoolTypes: splitList(program.eligible_school_types),
        eligibleYearLevels: program.eligible_year_levels ?? '',
        eligibleLocations: program.eligible_locations ?? '',
        incomeRequirement: program.income_requirement || 'Any',
        minimumGradeScale: program.minimum_grade_scale ?? '',
        minimumGwa: program.minimum_gwa ?? '',
        excludeCurrentRecipients: Boolean(program.exclude_current_scholarship_recipients),
        applicationMode: program.application_mode || 'online',
        requirements: program.requirements ?? '',
        optionalRequirements: program.optional_requirements ?? '',
        postQualificationRequirements: program.post_qualification_requirements ?? '',
        handoffMode: program.handoff_mode || 'onsite',
        handoffInstructions: program.handoff_instructions ?? '',
        handoffDeadline: program.handoff_deadline ?? '',
        handoffLocationName: program.handoff_location_name ?? '',
        handoffLocationAddress: program.handoff_location_address ?? '',
        handoffUrl: program.handoff_url ?? '',
        selectionStages: program.selection_stages?.length ? program.selection_stages : ['screening', 'formal_application', 'decision'],
        reviewRubric: program.review_rubric?.length ? program.review_rubric : structuredClone(defaultRubric),
        recipientAgreement: {
            ...emptyForm().recipientAgreement,
            ...(program.recipient_agreement ?? {}),
        },
        termsAccepted: false,
        logoPath: program.image_path ?? '',
        logoUrl: program.logo_url ?? program.image_url ?? '',
        coverUrl: program.cover_image_url ?? '',
    };
    originalStatus.value = program.status ?? 'draft';
    applicationsCount.value = Number(program.applications_count ?? 0);
    coverSource.value = program.cover_image_source ?? 'default';
    coverKey.value = program.cover_image_key ?? '';
    logoFile.value = null;
    if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
    logoPreviewUrl.value = '';
    clearCoverUpload();
}

function append(payload, key, value) {
    payload.append(key, value === null || value === undefined ? '' : String(value));
}

function createPayload(status, termsAccepted = false) {
    const payload = new FormData();
    const fields = {
        title: form.value.title.trim(), category: form.value.category, program_cycle: form.value.programCycle.trim(),
        description: form.value.description.trim(), benefits: JSON.stringify(form.value.benefits),
        slots_available: form.value.slotsAvailable, application_limit: form.value.applicationLimit,
        support_starts_at: form.value.supportStartsAt, support_ends_at: form.value.supportEndsAt,
        application_opens_at: form.value.applicationOpensAt, deadline: form.value.deadline,
        expected_results_at: form.value.expectedResultsAt, location_name: form.value.locationName.trim(),
        location_address: form.value.locationAddress.trim(), latitude: form.value.latitude, longitude: form.value.longitude,
        contact_email: form.value.contactEmail.trim(), contact_number: form.value.contactNumber.trim(),
        contact_person: form.value.contactPerson.trim(), contact_department: form.value.contactDepartment.trim(),
        official_program_url: form.value.officialProgramUrl.trim(), eligibility: form.value.eligibility.trim(),
        eligible_education_levels: form.value.eligibleEducationLevels.join('\n'),
        eligible_courses: form.value.eligibleCourses.trim(), eligible_school_types: form.value.eligibleSchoolTypes.join('\n'),
        eligible_year_levels: form.value.eligibleYearLevels.trim(), eligible_locations: form.value.eligibleLocations.trim(),
        income_requirement: form.value.incomeRequirement, minimum_grade_scale: form.value.minimumGradeScale,
        minimum_gwa: form.value.minimumGwa, exclude_current_scholarship_recipients: form.value.excludeCurrentRecipients ? 1 : 0,
        application_mode: form.value.applicationMode, requirements: form.value.requirements.trim(),
        optional_requirements: form.value.optionalRequirements.trim(),
        post_qualification_requirements: form.value.postQualificationRequirements.trim(),
        handoff_mode: form.value.handoffMode, handoff_instructions: form.value.handoffInstructions.trim(),
        handoff_deadline: form.value.handoffDeadline, handoff_location_name: form.value.handoffLocationName.trim(),
        handoff_location_address: form.value.handoffLocationAddress.trim(), handoff_url: form.value.handoffUrl.trim(),
        selection_stages: JSON.stringify(form.value.selectionStages), review_rubric: JSON.stringify(form.value.reviewRubric),
        recipient_agreement: JSON.stringify(form.value.recipientAgreement), status,
        terms_accepted: termsAccepted ? 1 : 0,
    };
    if (coverSource.value !== 'legacy') {
        fields.cover_source = coverSource.value;
        fields.cover_key = coverKey.value;
    }
    Object.entries(fields).forEach(([key, value]) => append(payload, key, value));
    if (logoFile.value) payload.append('image_file', logoFile.value);
    if (coverFile.value) payload.append('cover_file', coverFile.value);
    if (programId.value) payload.append('_method', 'PUT');
    return payload;
}

function firstError(error) {
    const errors = error.response?.data?.errors ?? {};
    return Object.values(errors).flat()[0] || error.response?.data?.message || 'The program could not be saved right now.';
}

function stepForBackendField(field) {
    if (/^(title|category|program_cycle|description|image|cover)/.test(field)) return 'basics';
    if (/^(benefits|slots_available|application_limit|support_)/.test(field)) return 'support';
    if (/^(application_opens_at|deadline|expected_results_at|location_|latitude|longitude|contact_|official_program_url)/.test(field)) return 'dates-location';
    if (/^(eligibility|eligible_|income_|minimum_|exclude_)/.test(field)) return 'eligibility';
    if (/^(application_mode|requirements|optional_requirements|post_qualification|handoff_)/.test(field)) return 'application';
    if (/^(selection_stages|review_rubric|recipient_agreement)/.test(field)) return 'selection';
    return activeStep.value;
}

function showBackendError(error) {
    const errors = error.response?.data?.errors ?? {};
    const field = Object.keys(errors)[0];
    if (field) openStep(stepForBackendField(field), true);
    showAlert('Check the program details', firstError(error));
}

async function save(status = 'draft', termsAccepted = false) {
    isSaving.value = true;
    savedMessage.value = '';
    try {
        const payload = createPayload(status, termsAccepted);
        const response = programId.value
            ? await window.axios.post(`/provider/scholarships/${programId.value}`, payload)
            : await window.axios.post('/provider/scholarships', payload);
        const saved = response.data.scholarship;
        programId.value = String(saved.id);
        fillForm(saved);
        savedMessage.value = response.data.message ?? 'Program saved.';
        return saved;
    } catch (error) {
        showBackendError(error);
        return null;
    } finally {
        isSaving.value = false;
    }
}

async function nextStep() {
    if (!validateStep()) return;
    const next = steps[activeStepIndex.value + 1];
    if (!next) return;

    if (!programId.value || originalStatus.value === 'draft') {
        const saved = await save('draft');
        if (!saved) return;
    }
    openStep(next.id, !pathMatch && activeStep.value === 'basics');
}

function previousStep() {
    const previous = steps[activeStepIndex.value - 1];
    if (previous) openStep(previous.id);
}

async function saveDraft() {
    if (!form.value.title.trim()) {
        showAlert('Add a program title', 'A title is required before this draft can be saved.');
        openStep('basics');
        return;
    }
    const saved = await save('draft');
    if (saved) showAlert('Draft saved', 'Your program remains private. You can continue editing it from Drafts & changes.');
}

async function submitProgram() {
    const firstIncomplete = steps.slice(0, -1).find((step) => !stepChecks.value[step.id]);
    if (firstIncomplete) {
        openStep(firstIncomplete.id);
        showAlert('Program is not ready', stepMessage(firstIncomplete.id));
        return;
    }
    if (!form.value.termsAccepted) {
        showAlert('Confirm the declaration', 'Confirm that the program details and recipient terms are complete before submitting.');
        return;
    }

    const requestedStatus = originalStatus.value === 'published' ? 'published' : 'pending_review';
    const saved = await save(requestedStatus, true);
    if (saved) window.location.assign(`/provider/programs/${saved.id}`);
}

async function deleteDraft() {
    if (!canDelete.value) return;
    const confirmed = await requestConfirmation({
        title: 'Delete this draft?',
        message: 'This removes the unfinished program permanently. This action cannot be undone.',
        confirmLabel: 'Delete draft',
        tone: 'danger',
    });
    if (!confirmed) return;

    isSaving.value = true;
    try {
        await window.axios.delete(`/provider/scholarships/${programId.value}`);
        window.location.assign('/provider/programs/drafts');
    } catch (error) {
        showBackendError(error);
    } finally {
        isSaving.value = false;
    }
}

async function loadProgram() {
    if (!programId.value) {
        isLoading.value = false;
        return;
    }
    try {
        const response = await window.axios.get(`/provider/scholarships/${programId.value}`);
        fillForm(response.data.scholarship);
    } catch (error) {
        showAlert('Program unavailable', error.response?.data?.message || 'This program could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function loadCoverOptions() {
    try {
        const response = await window.axios.get('/provider/cover-images');
        coverImages.value = response.data.images ?? [];
        recommendedCoverKey.value = response.data.recommended_key || 'learning-together';
        providerCoverUrl.value = response.data.provider_cover?.url || providerCoverUrl.value;
        if (!programId.value && !coverKey.value) coverKey.value = recommendedCoverKey.value;
    } catch (error) {
        showAlert('Background choices unavailable', error.response?.data?.message || 'The image library could not be loaded.');
    }
}

onMounted(() => {
    loadProgram();
    loadCoverOptions();
    window.addEventListener('popstate', handleHistory);
});
onBeforeUnmount(() => {
    window.removeEventListener('popstate', handleHistory);
    if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
    if (coverPreviewUrl.value) URL.revokeObjectURL(coverPreviewUrl.value);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="program-builder-title">
            <div class="mx-auto w-full max-w-[94rem]">
                <a href="/provider/programs" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to programs
                </a>

                <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true"><i :class="isCreateMode ? 'fa-solid fa-plus' : 'fa-solid fa-pen-to-square'"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Program builder</p>
                                <h1 id="program-builder-title" class="mt-1 truncate font-display text-3xl font-bold tracking-tight text-slate-950">
                                    {{ isCreateMode ? 'Create a scholarship program' : form.title || 'Edit scholarship program' }}
                                </h1>
                                <p class="mt-2 text-sm text-slate-600">Complete one section at a time. Draft changes stay private until submission.</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <p v-if="savedMessage" class="text-xs font-semibold text-slate-500">{{ savedMessage }}</p>
                            <span v-if="!isCreateMode" class="border border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-slate-700">
                                {{ originalStatus.replace('_', ' ') }}
                            </span>
                            <button v-if="programId" type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="previewOpen = true">
                                <i class="fa-solid fa-eye text-[10px]" aria-hidden="true"></i>
                                Preview as applicant
                            </button>
                        </div>
                    </div>
                </header>

                <section v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-circle-notch animate-spin text-sm"></i></span>
                    <p class="mt-2 text-sm font-semibold text-slate-500">Loading program builder...</p>
                </section>

                <template v-else>
                    <div v-if="isReadOnly" class="mt-4 flex items-center justify-between border-l-4 border-sky-600 bg-sky-50 px-5 py-4 text-sm text-sky-950">
                        <div class="flex items-center gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-sky-100 text-sky-700" aria-hidden="true"><i class="fa-solid fa-lock text-sm"></i></span>
                            <div><p class="font-bold">This program is under review.</p><p class="mt-1 text-sky-800">Editing is paused until the administrator records a decision.</p></div>
                        </div>
                        <a :href="`/provider/programs/${programId}`" class="inline-flex items-center gap-2 bg-slate-950 px-4 py-2.5 text-xs font-bold text-white"><i class="fa-solid fa-folder-open text-[10px] text-amber-300" aria-hidden="true"></i>Open record</a>
                    </div>

                    <nav class="mt-4 grid grid-cols-7 border border-slate-200 bg-white shadow-sm" aria-label="Program builder steps">
                        <button
                            v-for="(step, index) in steps"
                            :key="step.id"
                            type="button"
                            :class="[
                                'min-w-0 border-r border-slate-200 px-3 py-3 text-left last:border-r-0',
                                activeStep === step.id ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50',
                            ]"
                            @click="openStep(step.id)"
                        >
                            <span class="flex items-center gap-2">
                                <span :class="['grid h-6 w-6 shrink-0 place-items-center text-[10px] font-bold', activeStep === step.id ? 'bg-amber-300 text-slate-950' : step.id !== 'review' && stepChecks[step.id] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">
                                    <i v-if="step.id !== 'review' && stepChecks[step.id]" class="fa-solid fa-check" aria-hidden="true"></i>
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span class="truncate text-xs font-bold">{{ step.label }}</span>
                            </span>
                            <span :class="['mt-1 block truncate pl-8 text-[10px]', activeStep === step.id ? 'text-slate-300' : 'text-slate-400']">{{ step.hint }}</span>
                        </button>
                    </nav>

                    <form class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="nextStep">
                        <header class="flex items-center gap-4 border-b border-slate-200 px-7 py-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-950 text-amber-300">
                                <i :class="[currentStep.icon, 'text-sm']" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Step {{ activeStepIndex + 1 }} of {{ steps.length }}</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">{{ currentStep.label }}</h2>
                            </div>
                        </header>

                        <fieldset :disabled="isReadOnly || isSaving" class="min-w-0">
                            <section v-if="activeStep === 'basics'" class="px-7 py-6">
                                <div class="w-full">
                                    <h3 class="text-base font-bold text-slate-950">Identify the program</h3>
                                    <p class="mt-1 text-sm text-slate-500">Use a clear public title and a short summary applicants can scan.</p>
                                </div>
                                <div class="mt-6 grid w-full grid-cols-[minmax(0,1fr)_18rem] gap-8">
                                    <div class="grid grid-cols-2 gap-5">
                                        <label class="col-span-2">
                                            <span :class="labelClass">Program title <span class="text-amber-700">Required</span></span>
                                            <input v-model="form.title" type="text" maxlength="255" :class="inputClass" placeholder="Example: College Readiness Scholarship">
                                        </label>
                                        <label>
                                            <span :class="labelClass">Category <span class="text-amber-700">Required</span></span>
                                            <select v-model="form.category" :class="inputClass">
                                                <option value="">Choose category</option>
                                                <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                                            </select>
                                        </label>
                                        <label>
                                            <span :class="labelClass">Program cycle <span class="text-amber-700">Required</span></span>
                                            <input v-model="form.programCycle" type="text" maxlength="100" :class="inputClass" placeholder="Example: School year 2027-2028">
                                        </label>
                                        <label class="col-span-2">
                                            <span :class="labelClass">Short program summary <span class="text-amber-700">Required</span></span>
                                            <textarea v-model="form.description" rows="6" maxlength="5000" :class="textareaClass" placeholder="Explain who the scholarship supports and what it provides."></textarea>
                                            <span class="mt-1 block text-right text-[10px] font-semibold text-slate-400">{{ form.description.length }} / 5000</span>
                                        </label>
                                    </div>
                                    <aside class="border-l border-slate-200 pl-6">
                                        <div>
                                            <p class="text-xs font-bold text-slate-700">Scholarship logo <span class="text-amber-700">Required</span></p>
                                            <p class="mt-1 text-[11px] leading-4 text-slate-500">Shown in program tables and compact records.</p>
                                            <div class="mt-3 flex items-center gap-4">
                                                <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-white">
                                                    <img v-if="scholarshipLogo" :src="scholarshipLogo" alt="Scholarship logo preview" class="h-full w-full object-contain p-2">
                                                    <i v-else class="fa-solid fa-award text-2xl text-slate-300" aria-hidden="true"></i>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <input ref="logoInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="selectScholarshipLogo">
                                                    <button type="button" class="inline-flex h-9 w-full items-center justify-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:border-slate-950" @click="openLogoPicker"><i class="fa-solid fa-arrow-up-from-bracket text-[10px]" aria-hidden="true"></i>{{ hasScholarshipLogo ? 'Replace logo' : 'Upload logo' }}</button>
                                                    <p class="mt-2 text-[10px] leading-4 text-slate-400">Square JPG, PNG, or WebP. Up to 4 MB.</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-6 border-t border-slate-200 pt-5">
                                            <div class="flex items-start justify-between gap-3">
                                                <div><p class="text-xs font-bold text-slate-700">Background image <span class="text-amber-700">Required</span></p><p class="mt-1 text-[11px] leading-4 text-slate-500">Used on scholarship cards and detail pages.</p></div>
                                                <span class="shrink-0 bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">{{ coverSourceLabel }}</span>
                                            </div>
                                            <div class="mt-3 grid aspect-video place-items-center overflow-hidden border border-slate-200 bg-slate-50">
                                                <img v-if="programImage" :src="programImage" alt="Scholarship background preview" class="h-full w-full object-cover">
                                                <i v-else class="fa-regular fa-image text-3xl text-slate-300" aria-hidden="true"></i>
                                            </div>
                                            <button type="button" class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:border-slate-950" @click="coverPickerOpen = true"><i class="fa-regular fa-image" aria-hidden="true"></i>Choose background</button>
                                        </div>
                                    </aside>
                                </div>
                            </section>

                            <section v-else-if="activeStep === 'support'" class="px-7 py-6">
                                <div class="flex items-end justify-between gap-8">
                                    <div>
                                        <h3 class="text-base font-bold text-slate-950">Define what recipients receive</h3>
                                        <p class="mt-1 text-sm text-slate-500">Add the award package, capacity, and support period.</p>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ form.benefits.length }} of 10 benefits</span>
                                </div>

                                <div class="mt-6 w-full border border-slate-200">
                                    <div class="flex gap-3 border-b border-slate-200 bg-slate-50 p-4">
                                        <select v-model="benefitType" :class="inputClass" aria-label="Benefit type">
                                            <option value="">Choose a benefit to add</option>
                                            <option v-for="([value, label]) in benefitTypes" :key="value" :value="value">{{ label }}</option>
                                        </select>
                                        <button type="button" class="inline-flex shrink-0 items-center gap-2 bg-slate-950 px-5 text-xs font-bold text-white disabled:opacity-40" :disabled="!benefitType || form.benefits.length >= 10" @click="openBenefitDialog()"><i class="fa-solid fa-plus text-[10px] text-amber-300" aria-hidden="true"></i>Add benefit</button>
                                    </div>
                                    <div v-if="!form.benefits.length" class="px-5 py-8 text-center text-sm text-slate-500">No benefits added yet.</div>
                                    <article v-for="(benefit, index) in form.benefits" :key="`${benefit.type}-${index}`" class="border-b border-slate-200 last:border-b-0">
                                        <div class="flex items-center gap-4 px-5 py-4">
                                            <span class="grid h-9 w-9 place-items-center bg-slate-100 text-slate-700"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                                            <div class="min-w-0 flex-1">
                                                <p class="font-bold text-slate-950">{{ benefit.title || benefitLabel(benefit.type) }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ benefitSummary(benefit) }}</p>
                                            </div>
                                            <button type="button" class="inline-flex items-center gap-2 border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" @click="openBenefitDialog(index)"><i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>Edit</button>
                                            <button type="button" class="px-2 py-2 text-xs font-bold text-rose-700" @click="removeBenefit(index)">Remove</button>
                                        </div>
                                    </article>
                                </div>

                                <div class="mt-6 grid w-full grid-cols-2 gap-5 border-t border-slate-200 pt-6">
                                    <label><span :class="labelClass">Recipient slots <span class="text-amber-700">Required</span></span><input v-model="form.slotsAvailable" type="number" min="1" max="1000000" :class="inputClass"></label>
                                    <label><span :class="labelClass">Application limit <span class="text-amber-700">Required</span></span><input v-model="form.applicationLimit" type="number" min="1" max="1000000" :class="inputClass"></label>
                                    <label><span :class="labelClass">Support starts <span class="text-amber-700">Required</span></span><input v-model="form.supportStartsAt" type="date" :class="inputClass"></label>
                                    <label><span :class="labelClass">Support ends <span class="text-amber-700">Required</span></span><input v-model="form.supportEndsAt" type="date" :class="inputClass"></label>
                                </div>
                            </section>

                            <section v-else-if="activeStep === 'dates-location'" class="px-7 py-6">
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Set the public schedule and contact point</h3>
                                    <p class="mt-1 text-sm text-slate-500">Applicants use these details before they submit.</p>
                                </div>
                                <div class="mt-6 grid w-full grid-cols-3 gap-5">
                                    <label><span :class="labelClass">Applications open <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.applicationOpensAt" type="date" :class="inputClass"></label>
                                    <label><span :class="labelClass">Application deadline <span class="text-amber-700">Required</span></span><input v-model="form.deadline" type="date" :class="inputClass"></label>
                                    <label><span :class="labelClass">Expected results <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.expectedResultsAt" type="date" :class="inputClass"></label>
                                </div>

                                <div class="mt-7 w-full border-t border-slate-200 pt-6">
                                    <div class="flex items-center justify-between gap-6">
                                        <div>
                                            <h4 class="text-sm font-bold text-slate-950">Program location</h4>
                                            <p class="mt-1 text-xs text-slate-500">Use the office or service location applicants should recognize.</p>
                                        </div>
                                        <button type="button" class="border border-slate-300 px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="showLocationMap = true">
                                            <i class="fa-solid fa-map-location-dot mr-2 text-amber-700" aria-hidden="true"></i>Set map pin
                                        </button>
                                    </div>
                                    <div class="mt-4 grid grid-cols-2 gap-5">
                                        <label><span :class="labelClass">Location name <span class="text-amber-700">Required</span></span><input v-model="form.locationName" type="text" maxlength="255" :class="inputClass" placeholder="Office or community desk"></label>
                                        <label><span :class="labelClass">Complete address <span class="text-amber-700">Required</span></span><input v-model="form.locationAddress" type="text" maxlength="500" :class="inputClass"></label>
                                    </div>
                                    <p class="mt-3 text-xs font-semibold" :class="form.latitude && form.longitude ? 'text-emerald-700' : 'text-amber-700'">
                                        <i :class="form.latitude && form.longitude ? 'fa-solid fa-circle-check' : 'fa-solid fa-location-dot'" class="mr-1.5" aria-hidden="true"></i>
                                        {{ form.latitude && form.longitude ? 'Map pin saved' : 'Map pin still needed' }}
                                    </p>
                                </div>

                                <div class="mt-7 grid w-full grid-cols-2 gap-5 border-t border-slate-200 pt-6">
                                    <label><span :class="labelClass">Public email</span><input v-model="form.contactEmail" type="email" maxlength="255" :class="inputClass"></label>
                                    <label><span :class="labelClass">Public contact number</span><input v-model="form.contactNumber" type="text" maxlength="30" :class="inputClass"></label>
                                    <label><span :class="labelClass">Contact person <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.contactPerson" type="text" maxlength="150" :class="inputClass"></label>
                                    <label><span :class="labelClass">Department <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.contactDepartment" type="text" maxlength="150" :class="inputClass"></label>
                                    <label class="col-span-2"><span :class="labelClass">Official program page <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.officialProgramUrl" type="url" maxlength="2048" :class="inputClass" placeholder="https://"></label>
                                </div>
                            </section>

                            <section v-else-if="activeStep === 'eligibility'" class="px-7 py-6">
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Describe who can apply</h3>
                                    <p class="mt-1 text-sm text-slate-500">Only add rules that the provider will actually verify.</p>
                                </div>
                                <div class="mt-6 w-full">
                                    <label><span :class="labelClass">Eligibility summary <span class="text-amber-700">Required</span></span><textarea v-model="form.eligibility" rows="4" maxlength="5000" :class="textareaClass" placeholder="Example: Open to enrolled Grade 12 STEM students who meet the stated academic requirement."></textarea></label>

                                    <div class="mt-6 border-t border-slate-200 pt-6">
                                        <p class="text-xs font-bold text-slate-700">Education levels</p>
                                        <div class="mt-3 grid grid-cols-4 gap-2">
                                            <label v-for="([value, label]) in educationLevels" :key="value" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 text-xs font-semibold text-slate-700">
                                                <input type="checkbox" :checked="form.eligibleEducationLevels.includes(value)" class="border-slate-300 text-slate-950 focus:ring-slate-700" @change="toggleArrayValue('eligibleEducationLevels', value)">
                                                {{ label }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mt-6 grid grid-cols-2 gap-5">
                                        <label><span :class="labelClass">Courses, tracks, or strands <span class="font-normal text-slate-400">Optional</span></span><textarea v-model="form.eligibleCourses" rows="3" :class="textareaClass" placeholder="One per line, or Any"></textarea></label>
                                        <label><span :class="labelClass">Grade or year levels <span class="font-normal text-slate-400">Optional</span></span><textarea v-model="form.eligibleYearLevels" rows="3" :class="textareaClass" placeholder="Example: Grade 12"></textarea></label>
                                        <label><span :class="labelClass">Covered locations <span class="font-normal text-slate-400">Optional</span></span><textarea v-model="form.eligibleLocations" rows="3" :class="textareaClass" placeholder="Example: Antipolo City, Rizal"></textarea></label>
                                        <label><span :class="labelClass">Household income rule</span><select v-model="form.incomeRequirement" :class="inputClass"><option>Any</option><option>Below PHP 10,000</option><option>PHP 10,000 - 20,000</option><option>PHP 20,001 - 40,000</option><option>PHP 40,001 - 60,000</option><option>Above PHP 60,000</option></select></label>
                                    </div>

                                    <div class="mt-6 border-t border-slate-200 pt-6">
                                        <p class="text-xs font-bold text-slate-700">School types <span class="font-normal text-slate-400">Optional</span></p>
                                        <div class="mt-3 grid grid-cols-4 gap-2">
                                            <label v-for="([value, label]) in schoolTypes" :key="value" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 text-xs font-semibold text-slate-700">
                                                <input type="checkbox" :checked="form.eligibleSchoolTypes.includes(value)" class="border-slate-300 text-slate-950 focus:ring-slate-700" @change="toggleArrayValue('eligibleSchoolTypes', value)">
                                                {{ label }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mt-6 grid grid-cols-2 gap-5 border-t border-slate-200 pt-6">
                                        <label><span :class="labelClass">Academic rule</span><select v-model="form.minimumGradeScale" :class="inputClass"><option value="">No academic minimum</option><option value="percentage">General average / percentage</option><option value="grade_point">GWA / GPA grade point</option><option value="pass_fail">Pass/fail or competency</option><option value="other">Other / manual review</option></select></label>
                                        <label v-if="['percentage', 'grade_point'].includes(form.minimumGradeScale)"><span :class="labelClass">{{ form.minimumGradeScale === 'grade_point' ? 'Maximum GWA / GPA' : 'Minimum average' }}</span><input v-model="form.minimumGwa" type="number" min="0" :max="form.minimumGradeScale === 'grade_point' ? 5 : 100" step="0.01" :class="inputClass"></label>
                                        <label class="col-span-2 flex items-center gap-3 border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700"><input v-model="form.excludeCurrentRecipients" type="checkbox" class="border-slate-300 text-slate-950 focus:ring-slate-700">Exclude applicants who currently receive another scholarship</label>
                                    </div>
                                </div>
                            </section>

                            <section v-else-if="activeStep === 'application'" class="px-7 py-6">
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Set the application handoff</h3>
                                    <p class="mt-1 text-sm text-slate-500">Separate portal pre-screening from the provider's formal process.</p>
                                </div>
                                <div class="mt-6 w-full">
                                    <div class="grid grid-cols-3 border border-slate-200">
                                        <label v-for="option in [
                                            { value: 'online', label: 'Portal review', text: 'Review profile and uploaded files.' },
                                            { value: 'onsite', label: 'Portal + in-person', text: 'Review uploads, then verify originals.' },
                                            { value: 'provider_review', label: 'Profile review only', text: 'No files required before submission.' },
                                        ]" :key="option.value" :class="['cursor-pointer border-r border-slate-200 p-4 last:border-r-0', form.applicationMode === option.value ? 'bg-amber-50' : 'bg-white']">
                                            <span class="flex items-center gap-2"><input v-model="form.applicationMode" type="radio" :value="option.value" class="border-slate-300 text-slate-950 focus:ring-slate-700"><span class="text-sm font-bold text-slate-950">{{ option.label }}</span></span>
                                            <span class="mt-2 block pl-6 text-xs leading-5 text-slate-500">{{ option.text }}</span>
                                        </label>
                                    </div>

                                    <div class="mt-6 grid grid-cols-2 gap-5">
                                        <label><span :class="labelClass">Pre-screening files <span v-if="form.applicationMode !== 'provider_review'" class="text-amber-700">Required</span></span><textarea v-model="form.requirements" rows="5" :class="textareaClass" placeholder="One file per line"></textarea></label>
                                        <label><span :class="labelClass">Optional files</span><textarea v-model="form.optionalRequirements" rows="5" :class="textareaClass" placeholder="One file per line"></textarea></label>
                                        <label class="col-span-2"><span :class="labelClass">Files needed after qualification <span class="text-amber-700">Required</span></span><textarea v-model="form.postQualificationRequirements" rows="3" :class="textareaClass" placeholder="One item per line"></textarea></label>
                                    </div>

                                    <div class="mt-7 border-t border-slate-200 pt-6">
                                        <p class="text-sm font-bold text-slate-950">How qualified applicants continue</p>
                                        <div class="mt-3 grid grid-cols-3 gap-2">
                                            <label v-for="option in [
                                                { value: 'onsite', label: 'Visit provider office' },
                                                { value: 'online', label: 'Use an official link' },
                                                { value: 'provider_contact', label: 'Wait for provider contact' },
                                            ]" :key="option.value" :class="['flex cursor-pointer items-center gap-2 border px-4 py-3 text-sm font-bold', form.handoffMode === option.value ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 text-slate-700']"><input v-model="form.handoffMode" type="radio" :value="option.value" class="sr-only">{{ option.label }}</label>
                                        </div>
                                        <div class="mt-5 grid grid-cols-2 gap-5">
                                            <label class="col-span-2"><span :class="labelClass">Instructions <span class="text-amber-700">Required</span></span><textarea v-model="form.handoffInstructions" rows="3" maxlength="3000" :class="textareaClass"></textarea></label>
                                            <label><span :class="labelClass">Formal application deadline <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.handoffDeadline" type="date" :class="inputClass"></label>
                                            <label v-if="form.handoffMode === 'online'"><span :class="labelClass">Official link <span class="text-amber-700">Required</span></span><input v-model="form.handoffUrl" type="url" :class="inputClass" placeholder="https://"></label>
                                            <template v-if="form.handoffMode === 'onsite'">
                                                <label><span :class="labelClass">Location name <span class="font-normal text-slate-400">Optional</span></span><input v-model="form.handoffLocationName" type="text" :class="inputClass"></label>
                                                <label class="col-span-2"><span :class="labelClass">Address <span class="text-amber-700">Required</span></span><input v-model="form.handoffLocationAddress" type="text" maxlength="500" :class="inputClass"></label>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-else-if="activeStep === 'selection'" class="px-7 py-6">
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Define the review process</h3>
                                    <p class="mt-1 text-sm text-slate-500">Choose optional activities and disclose the recipient agreement.</p>
                                </div>
                                <div class="mt-6 w-full divide-y divide-slate-200 border-y border-slate-200">
                                    <button v-for="(stage, index) in stageOptions" :key="stage.id" type="button" class="flex w-full items-center gap-4 px-3 py-4 text-left" :disabled="stage.locked" @click="toggleStage(stage)">
                                        <span :class="['grid h-8 w-8 place-items-center text-xs font-bold', form.selectionStages.includes(stage.id) ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-500']">{{ index + 1 }}</span>
                                        <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">{{ stage.label }}</span><span class="mt-1 block text-xs text-slate-500">{{ stage.detail }}</span></span>
                                        <span v-if="stage.locked" class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Required</span>
                                        <span v-else :class="['border px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide', form.selectionStages.includes(stage.id) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-500']">{{ form.selectionStages.includes(stage.id) ? 'Included' : 'Not included' }}</span>
                                    </button>
                                </div>

                                <div class="mt-7 w-full border-t border-slate-200 pt-6">
                                    <div class="flex items-start justify-between gap-8">
                                        <div><p class="text-sm font-bold text-slate-950">Review scoring</p><p class="mt-1 text-xs text-slate-500">The standard rubric supports consistent review without making the final decision.</p></div>
                                        <span class="text-xs font-bold text-slate-500">100% total</span>
                                    </div>
                                    <div class="mt-4 grid grid-cols-4 border border-slate-200">
                                        <div v-for="criterion in form.reviewRubric" :key="criterion.key" class="border-r border-slate-200 p-4 last:border-r-0">
                                            <p class="text-2xl font-bold text-slate-950">{{ criterion.weight }}%</p>
                                            <p class="mt-1 text-xs font-bold text-slate-800">{{ criterion.label }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-7 w-full border-t border-slate-200 pt-6">
                                    <label><span :class="labelClass">Recipient commitment <span class="text-amber-700">Required</span></span><select v-model="form.recipientAgreement.commitment_type" :class="inputClass"><option value="provider_briefing" disabled>Choose the agreement type</option><option value="none">No ongoing recipient commitment</option><option value="renewal">Enrollment or renewal requirements</option><option value="service">Service or community commitment</option><option value="activities">Required program activities</option><option value="reporting">Progress or utilization reports</option><option value="custom">Custom commitment</option></select></label>
                                    <div class="mt-5 grid grid-cols-2 gap-5">
                                        <label :class="requiresAgreementDetails ? '' : 'col-span-2'"><span :class="labelClass">Conditions for receiving benefits <span class="text-amber-700">Required</span></span><textarea v-model="form.recipientAgreement.release_conditions" rows="3" :class="textareaClass" placeholder="State when and how support will be released."></textarea></label>
                                        <template v-if="requiresAgreementDetails">
                                            <label><span :class="labelClass">Recipient responsibilities <span class="text-amber-700">Required</span></span><textarea v-model="form.recipientAgreement.responsibilities" rows="3" :class="textareaClass"></textarea></label>
                                            <label><span :class="labelClass">Required evidence <span class="text-amber-700">Required</span></span><textarea v-model="form.recipientAgreement.required_evidence" rows="3" :class="textareaClass"></textarea></label>
                                            <label><span :class="labelClass">Commitment duration <span class="text-amber-700">Required</span></span><input v-model="form.recipientAgreement.duration" type="text" maxlength="500" :class="inputClass"></label>
                                            <label><span :class="labelClass">If requirements are not met <span class="text-amber-700">Required</span></span><textarea v-model="form.recipientAgreement.noncompliance_consequence" rows="3" :class="textareaClass"></textarea></label>
                                            <label><span :class="labelClass">Exception or withdrawal process <span class="text-amber-700">Required</span></span><textarea v-model="form.recipientAgreement.exit_or_exception_process" rows="3" :class="textareaClass"></textarea></label>
                                        </template>
                                    </div>
                                </div>
                            </section>

                            <section v-else class="px-7 py-6">
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Review the complete program</h3>
                                    <p class="mt-1 text-sm text-slate-500">Open only the section that still needs attention.</p>
                                </div>
                                <div class="mt-6 w-full border border-slate-200">
                                    <button v-for="(step, index) in steps.slice(0, -1)" :key="step.id" type="button" class="flex w-full items-center gap-4 border-b border-slate-200 px-5 py-4 text-left last:border-b-0 hover:bg-slate-50" @click="openStep(step.id)">
                                        <span :class="['grid h-8 w-8 place-items-center text-xs font-bold', stepChecks[step.id] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']"><i :class="stepChecks[step.id] ? 'fa-solid fa-check' : 'fa-solid fa-exclamation'" aria-hidden="true"></i></span>
                                        <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">{{ index + 1 }}. {{ step.label }}</span><span class="mt-1 block text-xs text-slate-500">{{ step.hint }}</span></span>
                                        <span :class="['text-[10px] font-bold uppercase tracking-wide', stepChecks[step.id] ? 'text-emerald-700' : 'text-amber-700']">{{ stepChecks[step.id] ? 'Complete' : 'Needs attention' }}</span>
                                        <i class="fa-solid fa-arrow-right text-xs text-slate-400" aria-hidden="true"></i>
                                    </button>
                                </div>

                                <div class="mt-6 w-full border-l-4 border-amber-400 bg-amber-50 px-5 py-4">
                                    <label class="flex items-start gap-3">
                                        <input v-model="form.termsAccepted" type="checkbox" class="mt-1 border-amber-400 text-slate-950 focus:ring-slate-700">
                                        <span><span class="block text-sm font-bold text-slate-950">Provider declaration</span><span class="mt-1 block text-xs leading-5 text-slate-600">I confirm that the published details, eligibility rules, benefits, and recipient terms are complete and accurate.</span></span>
                                    </label>
                                </div>

                                <div class="mt-6 flex w-full items-center justify-between border-t border-slate-200 pt-6">
                                    <p class="text-sm font-semibold text-slate-600">{{ completedStepCount }} of 6 sections complete</p>
                                    <div class="flex gap-3">
                                        <button v-if="originalStatus === 'draft'" type="button" class="inline-flex items-center gap-2 border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="saveDraft"><i class="fa-regular fa-floppy-disk text-xs" aria-hidden="true"></i>Save draft</button>
                                        <button type="button" class="inline-flex items-center gap-2 bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-40" :disabled="isSaving" @click="submitProgram"><i class="fa-solid fa-paper-plane text-xs text-amber-300" aria-hidden="true"></i>{{ originalStatus === 'published' ? 'Submit changes for review' : 'Submit for review' }}</button>
                                    </div>
                                </div>
                            </section>
                        </fieldset>

                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-7 py-4">
                            <div class="flex items-center gap-3">
                                <button v-if="activeStepIndex > 0" type="button" class="inline-flex items-center gap-2 border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-40" :disabled="isSaving" @click="previousStep"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Previous</button>
                                <button v-if="canDelete" type="button" class="inline-flex items-center gap-2 px-3 py-2.5 text-sm font-bold text-rose-700 disabled:opacity-40" :disabled="isSaving" @click="deleteDraft"><i class="fa-regular fa-trash-can text-xs" aria-hidden="true"></i>Delete draft</button>
                            </div>
                            <div v-if="activeStep !== 'review'" class="flex items-center gap-3">
                                <button v-if="programId && originalStatus === 'draft'" type="button" class="inline-flex items-center gap-2 border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-40" :disabled="isSaving" @click="saveDraft"><i class="fa-regular fa-floppy-disk text-xs" aria-hidden="true"></i>Save draft</button>
                                <button type="submit" class="bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-40" :disabled="isSaving || isReadOnly">{{ isSaving ? 'Saving...' : activeStepIndex === steps.length - 2 ? 'Review program' : 'Save and continue' }} <i class="fa-solid fa-arrow-right ml-2 text-xs" aria-hidden="true"></i></button>
                            </div>
                        </footer>
                    </form>
                </template>
            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="benefitDialogOpen && benefitDraft"
                class="fixed inset-0 z-[2050] flex items-center justify-center bg-slate-950/55 p-6"
                role="presentation"
                @click.self="closeBenefitDialog"
                @keydown.esc="closeBenefitDialog"
            >
                <section
                    class="flex max-h-[calc(100vh-3rem)] w-full max-w-2xl flex-col overflow-hidden border border-slate-200 bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="benefit-dialog-title"
                    aria-describedby="benefit-dialog-description"
                >
                    <header class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true"><i class="fa-solid fa-gift"></i></span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Benefit details</p>
                                <h2 id="benefit-dialog-title" class="mt-1 text-xl font-bold text-slate-950">{{ editingBenefitIndex === null ? 'Add benefit' : 'Edit benefit' }}</h2>
                                <p id="benefit-dialog-description" class="mt-1 text-sm text-slate-500">Set what the recipient receives and how often.</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-600 hover:bg-slate-50 hover:text-slate-950" aria-label="Close benefit form" @click="closeBenefitDialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>

                    <form class="min-h-0 overflow-y-auto" @submit.prevent="saveBenefit">
                        <div class="grid grid-cols-2 gap-5 px-6 py-6">
                            <div class="col-span-2 flex items-center justify-between border border-slate-200 bg-slate-50 px-4 py-3">
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Benefit type</span>
                                <span class="text-sm font-bold text-slate-950">{{ benefitLabel(benefitDraft.type) }}</span>
                            </div>
                            <label class="col-span-2">
                                <span :class="labelClass">Display name <span class="text-amber-700">Required</span></span>
                                <input ref="benefitTitleInput" v-model="benefitDraft.title" type="text" maxlength="150" required :class="inputClass">
                            </label>
                            <label>
                                <span :class="labelClass">Amount or value <span class="font-normal text-slate-400">Optional</span></span>
                                <input v-model="benefitDraft.amount" type="number" min="0" step="0.01" :class="inputClass" placeholder="0.00">
                            </label>
                            <label>
                                <span :class="labelClass">Frequency</span>
                                <select v-model="benefitDraft.frequency" :class="inputClass"><option v-for="([value, label]) in frequencyOptions" :key="value" :value="value">{{ label }}</option></select>
                            </label>
                            <label>
                                <span :class="labelClass">Duration <span class="font-normal text-slate-400">Optional</span></span>
                                <input v-model="benefitDraft.duration" type="text" maxlength="100" :class="inputClass" placeholder="Example: One school year">
                            </label>
                            <label>
                                <span :class="labelClass">Coverage <span class="font-normal text-slate-400">Optional</span></span>
                                <select v-model="benefitDraft.coverage" :class="inputClass"><option value="">Not specified</option><option value="full">Full</option><option value="partial">Partial</option><option value="fixed">Fixed value</option></select>
                            </label>
                            <label class="col-span-2">
                                <span :class="labelClass">What is included? <span class="font-normal text-slate-400">Optional</span></span>
                                <textarea v-model="benefitDraft.description" rows="3" maxlength="1000" :class="textareaClass" placeholder="Briefly describe what this benefit covers."></textarea>
                            </label>
                        </div>
                        <footer class="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                            <button type="button" class="border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeBenefitDialog">Cancel</button>
                            <button type="submit" class="inline-flex items-center gap-2 bg-slate-950 px-5 py-2.5 text-sm font-bold text-white"><i class="fa-solid fa-check text-xs text-amber-300" aria-hidden="true"></i>{{ editingBenefitIndex === null ? 'Add benefit' : 'Save changes' }}</button>
                        </footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <CoverImagePickerModal
            :open="coverPickerOpen"
            title="Choose a scholarship background"
            description="Use a system image, a copy of the organization background, or upload a program-specific image."
            :images="coverImages"
            :selected-source="coverSource"
            :selected-key="coverKey"
            :provider-cover-url="providerCoverUrl"
            :busy="isSaving"
            @close="coverPickerOpen = false"
            @select-system="selectSystemCover"
            @select-provider="selectProviderCover"
            @upload="selectUploadedCover"
            @reset="resetCover"
        />

        <LocationMapModal
            :open="showLocationMap"
            eyebrow="Program location"
            title="Set the public map pin"
            :address="mapAddress"
            :latitude="form.latitude"
            :longitude="form.longitude"
            :marker-text="form.locationName"
            :picker="true"
            :auto-geocode="true"
            :location-message="mapMessage"
            confirm-label="Keep this pin"
            @close="showLocationMap = false"
            @resolved="handleMapResolved"
            @picked="handleMapPicked"
            @error="mapMessage = $event"
        />
        <ApplicantPagePreviewModal
            :open="previewOpen"
            :title="form.title || 'Scholarship program'"
            description="This preview uses the last saved program details. Save recent edits before checking them here."
            :status="originalStatus.replaceAll('_', ' ')"
            :scenario="previewScenario"
            show-scenario
            :full-page-url="programId ? `/provider/previews/programs/${programId}` : ''"
            @close="previewOpen = false"
            @update:scenario="previewScenario = $event"
        >
            <UserScholarshipDetailPage
                v-if="previewOpen && programId"
                :key="`${programId}-${previewScenario}`"
                embedded
                :scholarship-id="programId"
                :preview-data-url="`/provider/previews/programs/${programId}/data`"
                :initial-scenario="previewScenario"
                :preview-status="originalStatus.replaceAll('_', ' ')"
            />
        </ApplicantPagePreviewModal>
        <AlertDialog :open="alert.open" :title="alert.title" :message="alert.message" @close="closeAlert" />
        <ConfirmationDialog
            :open="confirmation.open"
            :title="confirmation.title"
            :message="confirmation.message"
            :confirm-label="confirmation.confirmLabel"
            :tone="confirmation.tone"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />
    </main>
</template>
