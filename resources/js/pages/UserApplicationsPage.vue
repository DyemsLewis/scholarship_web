<script setup>
import { computed, onMounted, ref } from 'vue';
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
const currentStep = ref(0);
const selectedScholarshipId = ref('');
const documentChecklist = ref([]);
const notes = ref('');
const uploadForms = ref({});
const uploadFiles = ref({});
const uploadingId = ref(null);

const steps = [
    { label: 'Program', detail: 'Choose scholarship' },
    { label: 'Details', detail: 'Review requirements' },
    { label: 'Documents', detail: 'Confirm checklist' },
    { label: 'Submit', detail: 'Final review' },
];

const selectedScholarship = computed(() => scholarships.value.find((scholarship) => scholarship.id === Number(selectedScholarshipId.value)));
const selectedRequirements = computed(() => documentRequirements(selectedScholarship.value?.requirements));
const appliedScholarshipIds = computed(() => new Set(applications.value.map((application) => application.scholarship?.id).filter(Boolean)));
const selectedAlreadyApplied = computed(() => selectedScholarship.value && appliedScholarshipIds.value.has(selectedScholarship.value.id));
const allDocumentsChecked = computed(() => selectedRequirements.value.every((requirement) => documentChecklist.value.includes(requirement)));
const canGoNext = computed(() => {
    if (currentStep.value === 0) {
        return Boolean(selectedScholarship.value) && !selectedAlreadyApplied.value;
    }

    if (currentStep.value === 2) {
        return allDocumentsChecked.value;
    }

    return Boolean(selectedScholarship.value);
});

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

function providerTypeLabel(type) {
    return String(type ?? 'Provider')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'under_review') {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-amber-100 text-amber-800';
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

function chooseScholarship(scholarship) {
    if (appliedScholarshipIds.value.has(scholarship.id)) {
        return;
    }

    selectedScholarshipId.value = String(scholarship.id);
    documentChecklist.value = [];
    notes.value = '';
    submitMessage.value = '';
    currentStep.value = 1;
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
    submitMessage.value = '';
    currentStep.value = 0;
}

async function loadApplications() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/applications/data');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        applications.value.forEach(ensureUploadForm);

        const requestedScholarshipId = new URLSearchParams(window.location.search).get('scholarship');

        if (requestedScholarshipId) {
            const requestedScholarship = scholarships.value.find((scholarship) => scholarship.id === Number(requestedScholarshipId));

            if (requestedScholarship && !appliedScholarshipIds.value.has(requestedScholarship.id)) {
                selectedScholarshipId.value = requestedScholarshipId;
                currentStep.value = 1;
            }
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applications.';
    } finally {
        isLoading.value = false;
    }
}

async function submitApplication() {
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
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ApplicantSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Application Wizard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Submit a scholarship application
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Follow the guided steps to choose a scholarship, confirm requirements, and submit your application record.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Applicant
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.name || 'Applicant' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application wizard...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <div v-if="submitMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ submitMessage }}
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Submitted
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-emerald-700">
                                {{ stats.applications }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Available Programs
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-sky-700">
                                {{ stats.available_scholarships }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Wizard Step
                            </p>
                            <p class="mt-3 font-display text-2xl font-bold text-amber-600">
                                {{ currentStep + 1 }} of {{ steps.length }}
                            </p>
                        </article>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="grid gap-3 md:grid-cols-4">
                            <button
                                v-for="(step, index) in steps"
                                :key="step.label"
                                type="button"
                                :class="[
                                    'rounded-lg border p-3 text-left transition',
                                    currentStep === index
                                        ? 'border-sky-300 bg-sky-50'
                                        : index < currentStep
                                            ? 'border-emerald-200 bg-emerald-50'
                                            : 'border-slate-200 bg-slate-50',
                                ]"
                                @click="currentStep = index"
                            >
                                <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                    Step {{ index + 1 }}
                                </span>
                                <span class="mt-1 block font-bold text-slate-950">
                                    {{ step.label }}
                                </span>
                                <span class="mt-1 block text-xs text-slate-500">
                                    {{ step.detail }}
                                </span>
                            </button>
                        </div>

                        <div class="mt-6">
                            <div v-if="currentStep === 0">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                            Choose Program
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Select one scholarship to apply for
                                        </h3>
                                    </div>
                                    <a
                                        href="/dashboard/scholarships"
                                        class="text-sm font-bold text-sky-700 transition hover:text-sky-900"
                                    >
                                        Browse scholarship details
                                    </a>
                                </div>

                                <div v-if="scholarships.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                                    No published scholarships are available yet.
                                </div>

                                <div v-else class="mt-5 grid gap-4 lg:grid-cols-2">
                                    <article
                                        v-for="scholarship in scholarships"
                                        :key="scholarship.id"
                                        :class="[
                                            'rounded-lg border p-4 transition',
                                            selectedScholarship?.id === scholarship.id
                                                ? 'border-sky-300 bg-sky-50'
                                                : 'border-slate-200 bg-slate-50',
                                            appliedScholarshipIds.has(scholarship.id) ? 'opacity-70' : 'hover:border-sky-200 hover:bg-white',
                                        ]"
                                    >
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">
                                                    {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                                </p>
                                                <h4 class="mt-2 text-lg font-bold text-slate-950">
                                                    {{ scholarship.title }}
                                                </h4>
                                                <p class="mt-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                                    {{ providerTypeLabel(scholarship.provider?.type) }}
                                                </p>
                                            </div>
                                            <span class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                                {{ scholarship.deadline || 'No deadline' }}
                                            </span>
                                        </div>

                                        <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-600">
                                            {{ scholarship.description }}
                                        </p>

                                        <div class="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                                            <div class="rounded-md bg-white p-3">
                                                <p class="font-semibold text-slate-500">
                                                    Award
                                                </p>
                                                <p class="mt-1 font-bold text-slate-950">
                                                    {{ formatAmount(scholarship.award_amount) }}
                                                </p>
                                            </div>
                                            <div class="rounded-md bg-white p-3">
                                                <p class="font-semibold text-slate-500">
                                                    Min GWA
                                                </p>
                                                <p class="mt-1 font-bold text-slate-950">
                                                    {{ scholarship.minimum_gwa || 'Not set' }}
                                                </p>
                                            </div>
                                            <div class="rounded-md bg-white p-3">
                                                <p class="font-semibold text-slate-500">
                                                    Documents
                                                </p>
                                                <p class="mt-1 font-bold text-slate-950">
                                                    {{ documentRequirements(scholarship.requirements).length }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-4 rounded-md border border-sky-100 bg-white p-3 text-sm">
                                            <p class="font-semibold text-slate-500">
                                                Eligibility guide
                                            </p>
                                            <p class="mt-1 leading-6 text-slate-700">
                                                {{ scholarship.eligibility_guide?.note || 'Review the listed eligibility before applying.' }}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            :disabled="appliedScholarshipIds.has(scholarship.id)"
                                            class="mt-4 w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                            @click="chooseScholarship(scholarship)"
                                        >
                                            {{ appliedScholarshipIds.has(scholarship.id) ? 'Already submitted' : 'Use this scholarship' }}
                                        </button>
                                    </article>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 1 && selectedScholarship" class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
                                <section class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
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
                                            {{ selectedScholarship.eligibility_guide?.note || 'Review the scholarship requirements before submitting.' }}
                                        </p>
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200 bg-white p-5">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                        Applicant Record
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Confirm your details
                                    </h3>
                                    <div class="mt-4 grid gap-3 text-sm">
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Name
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.name }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Email
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.email }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Contact number
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.contact_number || 'Not provided' }}
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <div v-else-if="currentStep === 2 && selectedScholarship">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Document Checklist
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Confirm prepared documents
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Check each document you have prepared. This saves your checklist with the application record.
                                </p>

                                <div v-if="selectedRequirements.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                                    This scholarship has no listed document requirements, so you can continue to review.
                                </div>

                                <div v-else class="mt-5 grid gap-2 md:grid-cols-2">
                                    <label
                                        v-for="requirement in selectedRequirements"
                                        :key="requirement"
                                        class="flex cursor-pointer gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm transition hover:border-sky-200 hover:bg-white"
                                    >
                                        <input
                                            v-model="documentChecklist"
                                            type="checkbox"
                                            :value="requirement"
                                            class="mt-1"
                                        >
                                        <span class="font-semibold text-slate-700">
                                            {{ requirement }}
                                        </span>
                                    </label>
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
                                <section class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
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
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200 bg-white p-5">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                        Notes
                                    </p>
                                    <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                        {{ notes || 'No note added.' }}
                                    </p>

                                    <button
                                        type="button"
                                        :disabled="isSubmitting || !allDocumentsChecked"
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
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Submitted Applications
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Application records
                        </h3>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No submitted applications yet. Complete the wizard above to create one.
                        </div>

                        <div v-else class="mt-5 grid gap-4 lg:grid-cols-2">
                            <article
                                v-for="application in applications"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h4 class="font-bold text-slate-950">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                        {{ statusLabel(application.status) }}
                                    </span>
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
                                                    {{ document.original_name }} · {{ formatFileSize(document.size) }} · {{ document.uploaded_at }}
                                                </p>
                                            </div>
                                            <div class="flex gap-2">
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
                            </article>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
