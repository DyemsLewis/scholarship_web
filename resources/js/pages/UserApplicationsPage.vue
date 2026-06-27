<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const isSubmitting = ref(false);
const errorMessage = ref('');
const submitMessage = ref('');
const user = ref(null);
const stats = ref({
    available_scholarships: 0,
    applications: 0,
    saved: 0,
});
const scholarships = ref([]);
const applications = ref([]);
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});
const currentStep = ref(0);
const selectedScholarshipId = ref('');
const documentChecklist = ref([]);
const notes = ref('');
const uploadForms = ref({});
const uploadFiles = ref({});
const uploadingId = ref(null);

const steps = [
    { label: 'Program', detail: 'Use selected scholarship' },
    { label: 'Details', detail: 'Review requirements' },
    { label: 'Documents', detail: 'Confirm checklist' },
    { label: 'Submit', detail: 'Final review' },
];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const dssFormula = [
    { label: 'Eligibility match', weight: '35%', detail: 'Fit against average, track, grade/year level, location, and income rules.' },
    { label: 'Documents', weight: '25%', detail: 'Prepared, uploaded, and accepted requirements.' },
    { label: 'Academic merit', weight: '20%', detail: 'Student GWA or average compared with the program minimum.' },
    { label: 'Financial need', weight: '15%', detail: 'Income bracket priority for assistance-focused grants.' },
    { label: 'Review status', weight: '5%', detail: 'Provider review progress and decision signal.' },
];
const statusDescriptions = {
    submitted: 'Your application was received and is waiting for provider review.',
    under_review: 'The provider is checking your details and uploaded documents.',
    qualified: 'Your application passed initial requirements and remains in review.',
    shortlisted: 'You are in a smaller candidate pool for closer evaluation.',
    interview: 'The provider may contact you for interview or follow-up requirements.',
    approved: 'Your application was approved by the provider.',
    awarded: 'The provider recorded an award outcome for your application.',
    disbursed: 'Award release or disbursement has been recorded.',
    renewed: 'This scholarship support was renewed.',
    rejected: 'The application was closed and not approved.',
    not_awarded: 'The application completed review but was not awarded.',
};

const selectedScholarship = computed(() => scholarships.value.find((scholarship) => scholarship.id === Number(selectedScholarshipId.value)));
const selectedRequirements = computed(() => documentRequirements(selectedScholarship.value?.requirements));
const appliedScholarshipIds = computed(() => new Set(applications.value.map((application) => application.scholarship?.id).filter(Boolean)));
const selectedAlreadyApplied = computed(() => selectedScholarship.value && appliedScholarshipIds.value.has(selectedScholarship.value.id));
const allDocumentsChecked = computed(() => selectedRequirements.value.every((requirement) => documentChecklist.value.includes(requirement)));
const checkedDocumentCount = computed(() => selectedRequirements.value.filter((requirement) => documentChecklist.value.includes(requirement)).length);
const canApply = computed(() => profileReadiness.value.complete);
const selectedPreparedDocuments = computed(() => selectedScholarship.value?.prepared_documents?.matched ?? []);
const selectedMissingPreparedDocuments = computed(() => selectedScholarship.value?.prepared_documents?.missing ?? selectedRequirements.value);
const selectedDocumentReadiness = computed(() => selectedScholarship.value?.prepared_documents?.percent ?? (selectedRequirements.value.length === 0 ? 100 : 0));
const selectedApplicationMode = computed(() => applicationModeLabel(selectedScholarship.value?.application_mode));
const selectedSlotsLabel = computed(() => selectedScholarship.value?.slots_available ?? 'Not listed');
const readyApplicationCount = computed(() => applications.value.filter((application) => Number(application.document_readiness?.accepted_percent ?? application.document_readiness?.uploaded_percent ?? 0) >= 100).length);
const activeApplicationCount = computed(() => applications.value.filter((application) => ['submitted', 'under_review', 'qualified'].includes(application.status ?? 'submitted')).length);
const applicationQueue = computed(() => [...applications.value].sort((first, second) => {
    const statusRank = {
        interview: 7,
        shortlisted: 6,
        under_review: 5,
        qualified: 4,
        submitted: 3,
        approved: 2,
        awarded: 1,
        disbursed: 1,
        renewed: 1,
        rejected: 0,
        not_awarded: 0,
    };

    return (statusRank[second.status ?? 'submitted'] ?? 0) - (statusRank[first.status ?? 'submitted'] ?? 0);
}));
const wizardReadinessItems = computed(() => [
    {
        label: 'Profile ready',
        complete: canApply.value,
        detail: canApply.value ? 'Student profile is complete.' : `${profileReadiness.value.percent}% profile readiness.`,
    },
    {
        label: 'Program selected',
        complete: Boolean(selectedScholarship.value),
        detail: selectedScholarship.value ? selectedScholarship.value.title : 'Choose from Scholarships first.',
    },
    {
        label: 'Documents prepared',
        complete: selectedRequirements.value.length === 0 || selectedMissingPreparedDocuments.value.length === 0,
        detail: selectedRequirements.value.length === 0
            ? 'No document requirements listed.'
            : `${selectedPreparedDocuments.value.length} of ${selectedRequirements.value.length} already uploaded in Documents.`,
    },
    {
        label: 'Checklist confirmed',
        complete: selectedRequirements.value.length === 0 || allDocumentsChecked.value,
        detail: selectedRequirements.value.length === 0
            ? 'No checklist needed.'
            : `${checkedDocumentCount.value} of ${selectedRequirements.value.length} confirmed.`,
    },
]);
const wizardReadinessPercent = computed(() => Math.round((wizardReadinessItems.value.filter((item) => item.complete).length / wizardReadinessItems.value.length) * 100));
const canGoNext = computed(() => {
    if (!canApply.value || !selectedScholarship.value || selectedAlreadyApplied.value) {
        return false;
    }

    if (currentStep.value === 2) {
        return allDocumentsChecked.value;
    }

    return true;
});

function canOpenWizardStep(index) {
    if (index === 0) {
        return true;
    }

    if (!canApply.value || !selectedScholarship.value || selectedAlreadyApplied.value) {
        return false;
    }

    if (index === 1 || index === 2) {
        return true;
    }

    if (index === 3) {
        return allDocumentsChecked.value;
    }

    return false;
}

function goToWizardStep(index) {
    if (canOpenWizardStep(index)) {
        currentStep.value = index;
        errorMessage.value = '';
        return;
    }

    errorMessage.value = canApply.value
        ? 'Complete the current application step before moving forward.'
        : 'Complete your student profile before starting an application.';
}

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Amount not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function statusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview'].includes(status)) {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function statusDescription(status) {
    return statusDescriptions[status ?? 'submitted'] ?? 'The provider will update this status as review progresses.';
}

function matchClass(score) {
    if (Number(score) >= 80) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (Number(score) >= 50) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-rose-100 text-rose-800';
}

function recommendationClass(recommendation) {
    if (recommendation === 'highly_recommended') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (recommendation === 'recommended') {
        return 'bg-sky-100 text-sky-800';
    }

    if (recommendation === 'needs_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (recommendation === 'not_recommended') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-rose-100 text-rose-800';
}

function criterionClass(status) {
    if (status === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    if (status === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }

    if (status === 'missing') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function documentStatusClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'needs_replacement') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-sky-100 text-sky-800';
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? labelFromKey(value || 'not_listed');
}

function documentRequirements(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function ensureUploadForm(application) {
    if (!uploadForms.value[application.id]) {
        uploadForms.value[application.id] = {
            documentName: documentRequirements(application.scholarship?.requirements)[0] ?? '',
        };
    }
}

function requiredDocumentsForApplication(application) {
    return documentRequirements(application.scholarship?.requirements);
}

function uploadedDocumentNames(application) {
    return new Set((application.documents ?? []).map((document) => document.document_name));
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

function handleFileChange(application, event) {
    uploadFiles.value[application.id] = event.target.files?.[0] ?? null;
}

function nextStep() {
    if (currentStep.value < steps.length - 1 && canGoNext.value) {
        currentStep.value += 1;
    }
}

function previousStep() {
    if (currentStep.value > 0) {
        currentStep.value -= 1;
    }
}

function resetWizard() {
    selectedScholarshipId.value = '';
    documentChecklist.value = [];
    notes.value = '';
    errorMessage.value = '';
    submitMessage.value = '';
    currentStep.value = 0;

    if (window.location.search) {
        window.history.replaceState({}, '', window.location.pathname);
    }
}

function applyPreparedDocuments() {
    documentChecklist.value = [...new Set([...documentChecklist.value, ...selectedPreparedDocuments.value])];
}

function selectAllDocuments() {
    documentChecklist.value = [...selectedRequirements.value];
}

function clearDocumentChecklist() {
    documentChecklist.value = [];
}

async function loadApplications() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/applications/data');

        user.value = response.data.user;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        applications.value.forEach(ensureUploadForm);

        const requestedScholarshipId = new URLSearchParams(window.location.search).get('scholarship');

        if (requestedScholarshipId) {
            const requestedScholarship = scholarships.value.find((scholarship) => scholarship.id === Number(requestedScholarshipId));

            if (requestedScholarship) {
                selectedScholarshipId.value = String(requestedScholarship.id);
                documentChecklist.value = [];
                notes.value = '';
                currentStep.value = 1;

                if (appliedScholarshipIds.value.has(requestedScholarship.id)) {
                    errorMessage.value = 'You already submitted an application for this scholarship.';
                    currentStep.value = 0;
                }
            } else {
                errorMessage.value = 'The selected scholarship was not found. Please choose a published scholarship from the scholarship page.';
            }
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applications.';
    } finally {
        isLoading.value = false;
    }
}

async function submitApplication() {
    if (!canApply.value) {
        errorMessage.value = 'Complete your student profile before submitting an application.';
        return;
    }

    if (!selectedScholarship.value || !allDocumentsChecked.value) {
        return;
    }

    isSubmitting.value = true;
    submitMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.post('/dashboard/applications', {
            scholarship_id: selectedScholarship.value.id,
            document_checklist: documentChecklist.value,
            notes: notes.value,
        });

        const message = response.data.message ?? 'Application submitted successfully.';
        await loadApplications();
        resetWizard();
        submitMessage.value = message;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to submit application.';
        if (error.response?.data?.profile_readiness) {
            profileReadiness.value = error.response.data.profile_readiness;
        }
    } finally {
        isSubmitting.value = false;
    }
}

async function uploadDocument(application) {
    const uploadForm = uploadForms.value[application.id];
    const file = uploadFiles.value[application.id];

    if (!uploadForm?.documentName || !file) {
        errorMessage.value = 'Choose a document type and file before uploading.';
        return;
    }

    uploadingId.value = application.id;
    errorMessage.value = '';
    submitMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', uploadForm.documentName);
    payload.append('document_file', file);

    try {
        const response = await window.axios.post(`/dashboard/applications/${application.id}/documents`, payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        applications.value = applications.value.map((item) => (item.id === application.id ? response.data.application : item));
        ensureUploadForm(response.data.application);
        uploadFiles.value[application.id] = null;
        submitMessage.value = response.data.message ?? 'Document uploaded.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to upload document.';
    } finally {
        uploadingId.value = null;
    }
}

async function deleteDocument(application, document) {
    uploadingId.value = application.id;
    errorMessage.value = '';
    submitMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/documents/${document.id}`);

        applications.value = applications.value.map((item) => (item.id === application.id ? response.data.application : item));
        submitMessage.value = response.data.message ?? 'Document removed.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove document.';
    } finally {
        uploadingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadApplications);

watch(selectedScholarship, (scholarship) => {
    if (!scholarship) {
        documentChecklist.value = [];
        return;
    }

    documentChecklist.value = [...(scholarship.prepared_documents?.matched ?? [])];
});
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-white shadow-[0_24px_70px_rgba(15,23,42,0.22)]">
                    <div class="grid gap-5 p-5 lg:grid-cols-[1fr_18rem] lg:items-end">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-200">
                                Application Desk
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold sm:text-3xl">
                                Build one clean submission
                            </h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                                Pick a program, confirm the requirements, then submit a trackable application record.
                            </p>
                        </div>

                        <div class="rounded-lg border border-white/10 bg-white/10 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-300">
                                Application readiness
                            </p>
                            <p class="mt-1 font-display text-3xl font-bold">
                                {{ wizardReadinessPercent }}%
                            </p>
                            <p class="mt-1 text-sm text-slate-300">
                                Step {{ currentStep + 1 }} of {{ steps.length }}: {{ steps[currentStep]?.detail }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading application wizard...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <div v-if="submitMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ submitMessage }}
                    </div>

                    <div v-if="!canApply" class="student-card border-amber-200 bg-amber-50/90 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
                                    Profile required
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    Complete your student profile before applying
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-700">
                                    Your profile is {{ profileReadiness.percent }}% complete. Missing:
                                    {{ profileReadiness.missing.slice(0, 4).map((field) => field.label).join(', ') }}{{ profileReadiness.missing.length > 4 ? ', and more' : '' }}.
                                </p>
                            </div>
                            <a
                                href="/dashboard/profile"
                                class="inline-flex justify-center rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Complete profile
                            </a>
                        </div>
                    </div>

                    <section class="student-card p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="student-kicker">
                                    Application Readiness
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ selectedScholarship ? selectedScholarship.title : 'Choose a scholarship to start' }}
                                </h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    {{ activeApplicationCount }} active application{{ activeApplicationCount === 1 ? '' : 's' }}, {{ readyApplicationCount }} with uploaded document requirements complete.
                                </p>
                            </div>
                            <div class="w-full lg:max-w-xs">
                                <div class="flex items-center justify-between text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                    <span>Ready to submit</span>
                                    <span>{{ wizardReadinessPercent }}%</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${wizardReadinessPercent}%` }"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-2 md:grid-cols-4">
                            <div
                                v-for="item in wizardReadinessItems"
                                :key="item.label"
                                class="rounded-md border border-slate-200 bg-[#f6faf8] p-3 text-sm"
                            >
                                <div class="flex items-start gap-2">
                                    <span :class="['mt-1 h-2.5 w-2.5 shrink-0 rounded-full', item.complete ? 'bg-emerald-500' : 'bg-amber-400']"></span>
                                    <div>
                                        <p class="font-bold text-slate-950">{{ item.label }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ item.detail }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <details class="rounded-lg border border-indigo-100 bg-indigo-50/80 p-5 shadow-sm">
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                        Decision Support System
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        How recommendation scores work
                                    </h3>
                                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                        Optional context. DSS is guidance only and providers still make final decisions.
                                    </p>
                                </div>
                                <span class="h-fit rounded-md bg-slate-100 px-3 py-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                                    Show details
                                </span>
                            </div>
                        </summary>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div
                                v-for="item in dssFormula"
                                :key="item.label"
                                class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-3"
                            >
                                <p class="font-display text-2xl font-bold text-indigo-700">
                                    {{ item.weight }}
                                </p>
                                <p class="mt-1 text-sm font-bold text-slate-950">
                                    {{ item.label }}
                                </p>
                                <p class="mt-2 text-xs leading-5 text-slate-500">
                                    {{ item.detail }}
                                </p>
                            </div>
                        </div>
                    </details>

                    <section class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 shadow-[0_24px_70px_rgba(15,23,42,0.18)]">
                        <div class="grid lg:grid-cols-[16rem_1fr]">
                            <aside class="p-4 text-white">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                    Process
                                </p>
                                <h3 class="mt-2 font-display text-xl font-bold">
                                    Application flow
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-300">
                                    Move through the checklist from top to bottom.
                                </p>

                                <div class="mt-4 grid gap-2">
                            <button
                                v-for="(step, index) in steps"
                                :key="step.label"
                                type="button"
                                :disabled="!canOpenWizardStep(index)"
                                :class="[
                                    'rounded-md border p-3 text-left text-current transition disabled:cursor-not-allowed disabled:opacity-50',
                                    currentStep === index
                                        ? 'border-amber-300 bg-amber-300 text-slate-950 shadow-sm'
                                        : index < currentStep
                                            ? 'border-emerald-300/40 bg-emerald-300/10 text-emerald-100'
                                            : 'border-white/10 bg-white/5 text-slate-300 hover:bg-white/10',
                                ]"
                                @click="goToWizardStep(index)"
                            >
                                <span class="text-xs font-bold uppercase tracking-[0.16em] opacity-70">
                                    Step {{ index + 1 }}
                                </span>
                                <span class="mt-1 block font-bold">
                                    {{ step.label }}
                                </span>
                                <span class="mt-1 block text-xs opacity-75">
                                    {{ step.detail }}
                                </span>
                            </button>
                                </div>
                            </aside>

                            <div class="bg-white p-5">

                        <div class="mt-6">
                            <div v-if="currentStep === 0">
                                <div class="rounded-lg border border-slate-200 bg-[#f6faf8] p-5">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                                Selected Program
                                            </p>
                                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                                Choose from Scholarships first
                                            </h3>
                                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                                Applications now use the program selected from the Scholarships page, so this area stays focused on review, checklist, and submission.
                                            </p>
                                        </div>
                                        <a
                                            href="/dashboard/scholarships"
                                            class="inline-flex justify-center rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                        >
                                            Choose scholarship
                                        </a>
                                    </div>

                                    <div v-if="selectedScholarship" class="mt-5 rounded-lg border border-sky-100 bg-white p-4">
                                        <img
                                            :src="selectedScholarship.image_url"
                                            :alt="selectedScholarship.title"
                                            class="mb-3 h-14 w-14 rounded-md bg-[#f8fafc] object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">
                                            {{ selectedScholarship.provider?.name || 'Scholarship Provider' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ selectedScholarship.title }}
                                        </h4>
                                        <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-600">
                                            {{ selectedScholarship.description }}
                                        </p>
                                        <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                                            <div class="rounded-md bg-[#f6faf8] p-3">
                                                <p class="font-semibold text-slate-500">Award</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ formatAmount(selectedScholarship.award_amount) }}</p>
                                            </div>
                                            <div class="rounded-md bg-[#f6faf8] p-3">
                                                <p class="font-semibold text-slate-500">Deadline</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedScholarship.deadline || 'No deadline' }}</p>
                                            </div>
                                            <div class="rounded-md bg-[#f6faf8] p-3">
                                                <p class="font-semibold text-slate-500">Documents</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedRequirements.length }}</p>
                                            </div>
                                            <div class="rounded-md bg-[#f6faf8] p-3">
                                                <p class="font-semibold text-slate-500">Mode</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedApplicationMode }}</p>
                                            </div>
                                            <div class="rounded-md bg-[#f6faf8] p-3">
                                                <p class="font-semibold text-slate-500">Match</p>
                                                <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(selectedScholarship.eligibility_match?.score)]">
                                                    {{ selectedScholarship.eligibility_match?.score ?? 0 }}%
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-else class="mt-5 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">
                                        No scholarship is selected yet. Open Scholarships, pick a program, then start the application from there.
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 1 && selectedScholarship" class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
                                <section class="rounded-lg border border-slate-200/80 bg-[#f6faf8] p-5">
                                    <img
                                        :src="selectedScholarship.image_url"
                                        :alt="selectedScholarship.title"
                                        class="mb-3 h-16 w-16 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                                    >
                                    <p class="student-kicker">
                                        Scholarship Details
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        {{ selectedScholarship.title }}
                                    </h3>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                        {{ selectedScholarship.description }}
                                    </p>

                                    <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Provider
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.provider?.name || 'Scholarship Provider' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Award
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ formatAmount(selectedScholarship.award_amount) }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Minimum GWA / avg
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.minimum_gwa || 'Not listed yet' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Deadline
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.deadline || 'No deadline' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Match score
                                            </p>
                                            <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(selectedScholarship.eligibility_match?.score)]">
                                                {{ selectedScholarship.eligibility_match?.score ?? 0 }}% - {{ selectedScholarship.eligibility_match?.label || 'Needs review' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Application mode
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedApplicationMode }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Slots
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedSlotsLabel }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Documents ready
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedDocumentReadiness }}%
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility
                                        </p>
                                        <p class="mt-1 leading-6 text-slate-700">
                                            {{ selectedScholarship.eligibility || 'Not listed yet' }}
                                        </p>
                                    </div>

                                    <div class="mt-4 rounded-md border border-sky-100 bg-white p-3 text-sm">
                                        <p class="font-semibold text-slate-500">
                                            Match guide
                                        </p>
                                        <p class="mt-1 leading-6 text-slate-700">
                                            {{ selectedScholarship.eligibility_match?.summary || selectedScholarship.eligibility_guide?.note || 'Review the scholarship requirements before submitting.' }}
                                        </p>
                                        <div v-if="selectedScholarship.eligibility_match?.criteria?.length" class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-for="criterion in selectedScholarship.eligibility_match.criteria"
                                                :key="criterion.key"
                                                :class="['rounded-md border px-2.5 py-1.5 text-xs font-bold', criterionClass(criterion.status)]"
                                            >
                                                {{ criterion.label }}: {{ criterion.status }}
                                            </span>
                                        </div>
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200/80 bg-white/90 p-5">
                                    <p class="student-kicker">
                                        Applicant Record
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Confirm your details
                                    </h3>
                                    <div class="mt-4 grid gap-3 text-sm">
                                        <div class="rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                            <p class="font-semibold text-slate-500">
                                                Name
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.name }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                            <p class="font-semibold text-slate-500">
                                                Email
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.email }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                            <p class="font-semibold text-slate-500">
                                                Contact number
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.contact_number || 'Not provided' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                            <p class="font-semibold text-slate-500">
                                                Academic details
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.course_or_strand || 'Track not set' }} - {{ user?.year_level || 'Grade/year not set' }}
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <div v-else-if="currentStep === 2 && selectedScholarship">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Document Checklist
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Confirm prepared documents
                                        </h3>
                                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                            Mark only the documents you already have. The checklist will be saved with your application record.
                                        </p>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-[#f6faf8] px-4 py-3 text-sm">
                                        <p class="font-bold text-slate-950">
                                            {{ checkedDocumentCount }} / {{ selectedRequirements.length }}
                                        </p>
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                            Prepared
                                        </p>
                                    </div>
                                </div>

                                <div v-if="selectedRequirements.length" class="mt-5 grid gap-3 lg:grid-cols-[0.9fr_1.1fr]">
                                    <div class="rounded-lg border border-sky-100 bg-sky-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-sky-700">
                                                    Document Library Match
                                                </p>
                                                <p class="mt-1 text-sm font-bold text-slate-950">
                                                    {{ selectedPreparedDocuments.length }} of {{ selectedRequirements.length }} already uploaded
                                                </p>
                                            </div>
                                            <span class="font-display text-2xl font-bold text-sky-800">
                                                {{ selectedDocumentReadiness }}%
                                            </span>
                                        </div>
                                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                            <div class="h-full rounded-full bg-sky-700 transition-all" :style="{ width: `${selectedDocumentReadiness}%` }"></div>
                                        </div>
                                        <button
                                            type="button"
                                            class="mt-3 rounded-md border border-sky-200 bg-white px-3 py-2 text-xs font-bold text-sky-700 transition hover:bg-sky-50"
                                            @click="applyPreparedDocuments"
                                        >
                                            Confirm uploaded documents
                                        </button>
                                    </div>

                                    <div class="rounded-lg border border-slate-200 bg-[#f6faf8] p-4">
                                        <p class="text-sm font-bold text-slate-950">
                                            Missing from Documents
                                        </p>
                                        <div v-if="selectedMissingPreparedDocuments.length" class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-for="requirement in selectedMissingPreparedDocuments"
                                                :key="requirement"
                                                class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-100"
                                            >
                                                {{ requirement }}
                                            </span>
                                        </div>
                                        <p v-else class="mt-2 text-sm text-slate-600">
                                            All listed requirements already have a matching prepared document.
                                        </p>
                                        <a
                                            href="/dashboard/documents"
                                            class="mt-3 inline-flex rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            Upload missing documents
                                        </a>
                                    </div>
                                </div>

                                <div v-if="selectedRequirements.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-[#f6faf8] p-6 text-sm text-slate-500">
                                    This scholarship has no listed document requirements, so you can continue to review.
                                </div>

                                <div v-else class="mt-5 space-y-3">
                                    <div class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-[#f6faf8] p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-sm font-semibold text-slate-700">
                                            Checklist progress: {{ checkedDocumentCount }} prepared, {{ selectedRequirements.length - checkedDocumentCount }} remaining.
                                        </p>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                                @click="selectAllDocuments"
                                            >
                                                Mark all prepared
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                                @click="clearDocumentChecklist"
                                            >
                                                Clear
                                            </button>
                                        </div>
                                    </div>

                                    <div class="grid gap-2 md:grid-cols-2">
                                        <label
                                            v-for="requirement in selectedRequirements"
                                            :key="requirement"
                                            :class="[
                                                'flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition',
                                                documentChecklist.includes(requirement)
                                                    ? 'border-sky-300 bg-sky-50 shadow-sm ring-1 ring-sky-100'
                                                    : 'border-slate-200 bg-[#f6faf8] hover:border-sky-200 hover:bg-white',
                                            ]"
                                        >
                                            <input
                                                v-model="documentChecklist"
                                                type="checkbox"
                                                :value="requirement"
                                                class="mt-1 rounded border-slate-300 text-sky-700 focus:ring-sky-200"
                                            >
                                            <span class="flex-1">
                                                <span class="block font-semibold text-slate-800">
                                                    {{ requirement }}
                                                </span>
                                                <span :class="['mt-1 inline-flex rounded-md px-2 py-0.5 text-xs font-bold', documentChecklist.includes(requirement) ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-500']">
                                                    {{ selectedPreparedDocuments.includes(requirement) ? 'Uploaded in Documents' : documentChecklist.includes(requirement) ? 'Confirmed manually' : 'Needed' }}
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <label for="application-notes" class="mb-2 block text-sm font-semibold text-slate-700">
                                        Optional note to provider
                                    </label>
                                    <textarea
                                        id="application-notes"
                                        v-model="notes"
                                        rows="4"
                                        maxlength="1000"
                                        placeholder="Add a short note about your application if needed"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                    ></textarea>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 3 && selectedScholarship" class="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
                                <section class="rounded-lg border border-slate-200/80 bg-[#f6faf8] p-5">
                                    <p class="student-kicker">
                                        Final Review
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Ready to submit
                                    </h3>
                                    <div class="mt-4 grid gap-3 text-sm">
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Scholarship
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.title }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Applicant
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.name }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Documents confirmed
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ documentChecklist.length }} of {{ selectedRequirements.length }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Uploaded from Documents
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedPreparedDocuments.length }} of {{ selectedRequirements.length }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Match score
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.eligibility_match?.score ?? 0 }}%
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-white p-3">
                                            <p class="font-semibold text-slate-500">
                                                Application mode
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedApplicationMode }}
                                            </p>
                                        </div>
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200/80 bg-white/90 p-5">
                                    <p class="student-kicker">
                                        Notes
                                    </p>
                                    <div class="mt-3 rounded-md border border-sky-100 bg-sky-50 p-3 text-sm leading-6 text-sky-900">
                                        Prepared documents that match this checklist will be attached automatically after submission. You can still upload or replace files from the application record below.
                                    </div>
                                    <p class="mt-3 rounded-md border border-slate-200 bg-[#f6faf8] p-3 text-sm leading-6 text-slate-600">
                                        {{ notes || 'No note added.' }}
                                    </p>

                                    <button
                                        type="button"
                                        :disabled="isSubmitting || !allDocumentsChecked || !canApply"
                                        class="mt-5 w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                        @click="submitApplication"
                                    >
                                        {{ isSubmitting ? 'Submitting...' : 'Submit application' }}
                                    </button>
                                </section>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="currentStep === 0"
                                @click="previousStep"
                            >
                                Back
                            </button>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                    @click="resetWizard"
                                >
                                    Reset
                                </button>
                                <button
                                    v-if="currentStep < steps.length - 1"
                                    type="button"
                                    :disabled="!canGoNext"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                    @click="nextStep"
                                >
                                    Continue
                                </button>
                            </div>
                        </div>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 bg-[#f6faf8] p-5">
                            <p class="student-kicker">
                                Submitted Applications
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Review timeline
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Submitted records, document uploads, and provider review movement stay here.
                            </p>
                        </div>

                        <div v-if="applications.length === 0" class="m-5 rounded-lg border border-dashed border-slate-300 bg-[#f6faf8] p-6 text-sm text-slate-500">
                            No submitted applications yet. Complete the wizard above to create one.
                        </div>

                        <div v-else class="grid gap-4 p-5">
                            <article
                                v-for="application in applicationQueue"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 border-l-4 border-l-slate-900 bg-white p-4 shadow-sm"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex min-w-0 gap-3">
                                        <img
                                            :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                            :alt="application.scholarship?.title || 'Scholarship'"
                                            class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <h4 class="truncate font-bold text-slate-950">
                                                {{ application.scholarship?.title || 'Scholarship' }}
                                            </h4>
                                            <p class="mt-1 text-sm text-slate-500">
                                                Submitted {{ application.submitted_at || 'recently' }}
                                            </p>
                                            <p class="mt-1 truncate text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                        {{ statusLabel(application.status) }}
                                    </span>
                                </div>
                                <p class="mt-3 rounded-md border border-slate-200 bg-[#f6faf8] px-3 py-2 text-xs leading-5 text-slate-600">
                                    {{ statusDescription(application.status) }}
                                </p>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md border border-indigo-100 bg-indigo-50 p-3">
                                        <p class="font-semibold text-indigo-800">
                                            Decision support
                                        </p>
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            <span class="font-display text-2xl font-bold text-indigo-950">
                                                {{ application.dss_score ?? 0 }}%
                                            </span>
                                            <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                                {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-indigo-900">
                                            {{ application.dss_breakdown?.summary || 'This score helps reviewers prioritize applications.' }}
                                        </p>
                                        <details v-if="application.dss_breakdown?.criteria?.length" class="mt-3 rounded-md border border-indigo-100 bg-white/70 p-2">
                                            <summary class="cursor-pointer text-xs font-bold uppercase tracking-[0.12em] text-indigo-800">
                                                Why this score?
                                            </summary>
                                            <div class="mt-2 grid gap-2">
                                                <div
                                                    v-for="criterion in application.dss_breakdown.criteria"
                                                    :key="criterion.key"
                                                    class="rounded-md bg-white p-2 text-xs"
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="font-bold text-slate-900">{{ criterion.label }}</span>
                                                        <span class="font-bold text-indigo-700">{{ criterion.score }}% x {{ criterion.weight }}%</span>
                                                    </div>
                                                    <p class="mt-1 leading-5 text-slate-500">
                                                        {{ criterion.note }}
                                                    </p>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility match
                                        </p>
                                        <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                            {{ application.eligibility_score ?? 0 }}% - {{ application.eligibility_breakdown?.label || 'Needs review' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Decision reason
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.decision_reason ? labelFromKey(application.decision_reason) : 'Not set yet' }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-500">
                                    DSS is a guide for prioritizing review. Final scholarship decisions are still made by the provider.
                                </p>

                                <div
                                    v-if="application.awarded_amount || application.outcome_notes || application.outcome_at || ['awarded', 'not_awarded', 'disbursed', 'renewed'].includes(application.status)"
                                    class="mt-4 rounded-md border border-emerald-100 bg-emerald-50 p-3 text-sm"
                                >
                                    <p class="font-semibold text-emerald-800">
                                        Outcome details
                                    </p>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <p class="rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-700">
                                            Amount: {{ application.awarded_amount || 'Not listed' }}
                                        </p>
                                        <p class="rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-700">
                                            Date: {{ application.outcome_at || 'Not listed' }}
                                        </p>
                                        <p class="rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-700">
                                            Status: {{ statusLabel(application.status) }}
                                        </p>
                                    </div>
                                    <p v-if="application.outcome_notes" class="mt-2 rounded-md bg-white p-3 text-sm leading-6 text-slate-700">
                                        {{ application.outcome_notes }}
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Confirmed documents
                                        </p>
                                        <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </span>
                                    </div>
                                    <div v-if="application.document_checklist?.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="document in application.document_checklist"
                                            :key="document"
                                            class="rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800"
                                        >
                                            {{ document }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No checklist items saved.
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Uploaded files
                                        </p>
                                        <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                            {{ application.document_readiness?.uploaded ?? 0 }} uploaded
                                        </span>
                                    </div>

                                    <div v-if="application.documents?.length" class="mt-3 grid gap-2">
                                        <div
                                            v-for="document in application.documents"
                                            :key="document.id"
                                            class="flex flex-col gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div>
                                                <p class="font-bold text-slate-950">
                                                    {{ document.document_name }}
                                                </p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                                </p>
                                                <p v-if="document.review_notes" class="mt-1 text-xs font-semibold text-amber-700">
                                                    {{ document.review_notes }}
                                                </p>
                                            </div>
                                            <div class="flex gap-2">
                                                <span :class="['h-fit rounded-md px-2.5 py-2 text-xs font-bold uppercase', documentStatusClass(document.status)]">
                                                    {{ labelFromKey(document.status || 'pending') }}
                                                </span>
                                                <a
                                                    :href="document.download_url"
                                                    class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                                >
                                                    Download
                                                </a>
                                                <button
                                                    type="button"
                                                    :disabled="uploadingId === application.id"
                                                    class="rounded-md border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                                    @click="deleteDocument(application, document)"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No uploaded files yet.
                                    </p>

                                    <div class="mt-4 grid gap-3 border-t border-slate-200 pt-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                        <div>
                                            <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                Requirement
                                            </label>
                                            <select
                                                v-if="requiredDocumentsForApplication(application).length"
                                                v-model="uploadForms[application.id].documentName"
                                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                            >
                                                <option
                                                    v-for="requirement in requiredDocumentsForApplication(application)"
                                                    :key="requirement"
                                                    :value="requirement"
                                                >
                                                    {{ requirement }}{{ uploadedDocumentNames(application).has(requirement) ? ' (replace)' : '' }}
                                                </option>
                                            </select>
                                            <input
                                                v-else
                                                v-model="uploadForms[application.id].documentName"
                                                type="text"
                                                placeholder="Document name"
                                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                            >
                                        </div>

                                        <div>
                                            <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                File
                                            </label>
                                            <input
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                                                @change="handleFileChange(application, $event)"
                                            >
                                        </div>

                                        <button
                                            type="button"
                                            :disabled="uploadingId === application.id"
                                            class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                            @click="uploadDocument(application)"
                                        >
                                            {{ uploadingId === application.id ? 'Uploading...' : 'Upload' }}
                                        </button>
                                    </div>
                                </div>

                                <div v-if="application.review_notes" class="mt-4 rounded-md border border-amber-100 bg-amber-50 p-3 text-sm">
                                    <p class="font-semibold text-amber-800">
                                        Provider review note
                                    </p>
                                    <p class="mt-1 leading-6 text-amber-900">
                                        {{ application.review_notes }}
                                    </p>
                                </div>

                                <div v-if="application.timeline?.length" class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        Application timeline
                                    </p>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="event in application.timeline"
                                            :key="event.id"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                        >
                                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="font-bold text-slate-950">
                                                    {{ statusLabel(event.to_status) }}
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    {{ event.changed_at || 'Recently' }}
                                                </p>
                                            </div>
                                            <p class="mt-1 text-xs text-slate-500">
                                                By {{ event.actor || 'System' }}
                                            </p>
                                            <p v-if="event.review_notes" class="mt-2 leading-5 text-slate-600">
                                                {{ event.review_notes }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
