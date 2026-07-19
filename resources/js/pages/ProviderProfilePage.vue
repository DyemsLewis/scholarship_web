<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { formatFileSize } from '../support/display';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const validationErrors = ref({});
const user = ref(null);
const verificationDocuments = ref([]);
const verificationDocumentType = ref('organization_registration');
const verificationDocumentFile = ref(null);
const verificationDocumentTermsAccepted = ref(false);
const isUploadingDocument = ref(false);
const deletingDocumentId = ref(null);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();
const form = reactive({
    first_name: '',
    last_name: '',
    middle_initial: '',
    email: '',
    username: '',
    contact_number: '',
    provider_name: '',
    provider_type: '',
    provider_website: '',
    provider_address: '',
    provider_description: '',
});

const providerTypeOptions = [
    { value: '', label: 'Select provider type' },
    { value: 'school', label: 'School / University' },
    { value: 'foundation', label: 'Foundation' },
    { value: 'government', label: 'Government Office' },
    { value: 'company', label: 'Company / Sponsor' },
    { value: 'non_profit', label: 'Non-profit Organization' },
    { value: 'other', label: 'Other Provider' },
];
const verificationDocumentOptions = [
    { value: 'organization_registration', label: 'Organization registration' },
    { value: 'authorization_letter', label: 'Authorization letter' },
    { value: 'valid_id', label: 'Authorized representative ID' },
    { value: 'school_or_office_proof', label: 'School / office proof' },
    { value: 'other', label: 'Other proof document' },
];
const providerTypeLabels = Object.fromEntries(
    providerTypeOptions.filter((option) => option.value).map((option) => [option.value, option.label]),
);
const labelClass = 'text-xs font-bold uppercase tracking-[0.14em] text-slate-500';
const inputClass = 'mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const verificationGuidance = computed(() => {
    if (user.value?.can_post_scholarships) {
        return {
            title: 'Provider account verified',
            description: 'Your organization has publishing access and can create scholarship programs.',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        };
    }

    if (!user.value?.email_verified) {
        return {
            title: 'Email verification is still required',
            description: 'Use the verification link sent to your email. You may upload organization proof while waiting.',
            className: 'border-amber-200 bg-amber-50 text-amber-900',
        };
    }

    if (user.value?.verification_status === 'rejected') {
        return {
            title: 'Replacement proof needed',
            description: 'Review the admin note, then upload a corrected document to return the account for review.',
            className: 'border-rose-200 bg-rose-50 text-rose-900',
        };
    }

    if (verificationDocuments.value.length > 0) {
        return {
            title: 'Proof submitted for admin review',
            description: 'You will be notified after an admin approves or requests changes to the provider account.',
            className: 'border-amber-200 bg-amber-50 text-amber-900',
        };
    }

    return {
        title: 'Upload proof to request verification',
        description: 'Add at least one valid organization document. Program creation unlocks after admin approval.',
        className: 'border-amber-200 bg-amber-50 text-amber-900',
    };
});

function applyUser(payload) {
    user.value = payload;
    form.first_name = payload?.first_name ?? '';
    form.last_name = payload?.last_name ?? '';
    form.middle_initial = payload?.middle_initial ?? '';
    form.email = payload?.email ?? '';
    form.username = payload?.username ?? '';
    form.contact_number = payload?.contact_number ?? '';
    form.provider_name = payload?.provider_name ?? payload?.name ?? '';
    form.provider_type = payload?.provider_type ?? '';
    form.provider_website = payload?.provider_website ?? '';
    form.provider_address = payload?.provider_address ?? '';
    form.provider_description = payload?.provider_description ?? '';
}

function applyVerificationDocuments(documents) {
    verificationDocuments.value = documents ?? [];
}

function fieldError(field) {
    return validationErrors.value?.[field]?.[0] ?? '';
}

function verificationLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function verificationClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function documentTypeLabel(type) {
    return verificationDocumentOptions.find((option) => option.value === type)?.label
        ?? String(type ?? 'Document').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function handleVerificationFile(event) {
    verificationDocumentFile.value = event.target.files?.[0] ?? null;
}

async function loadProviderProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile/data');

        applyUser(response.data.user);
        applyVerificationDocuments(response.data.verification_documents);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider profile.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadVerificationDocument() {
    if (!verificationDocumentFile.value) {
        errorMessage.value = 'Choose a verification file before uploading.';
        return;
    }

    if (!verificationDocumentTermsAccepted.value) {
        errorMessage.value = 'Please accept the provider document terms before uploading.';
        return;
    }

    isUploadingDocument.value = true;
    errorMessage.value = '';

    const payload = new FormData();
    payload.append('document_type', verificationDocumentType.value);
    payload.append('document_file', verificationDocumentFile.value);
    payload.append('terms_accepted', '1');

    try {
        const response = await window.axios.post('/provider/verification-documents', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.data.user) {
            applyUser(response.data.user);
        }
        applyVerificationDocuments(response.data.verification_documents);
        verificationDocumentFile.value = null;
        verificationDocumentTermsAccepted.value = false;
    } catch (handledError) {
        void handledError;
    } finally {
        isUploadingDocument.value = false;
    }
}

async function deleteVerificationDocument(document) {
    const confirmed = await requestConfirmation({
        title: 'Remove verification document?',
        message: `${document.original_name || document.document_type || 'This file'} will be permanently removed from the provider verification record.`,
        confirmLabel: 'Remove document',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    deletingDocumentId.value = document.id;
    errorMessage.value = '';

    try {
        const response = await window.axios.delete(`/provider/verification-documents/${document.id}`);

        applyVerificationDocuments(response.data.verification_documents);
    } catch (handledError) {
        void handledError;
    } finally {
        deletingDocumentId.value = null;
    }
}

async function saveProviderProfile() {
    isSaving.value = true;
    errorMessage.value = '';
    validationErrors.value = {};

    try {
        const response = await window.axios.patch('/provider/profile', { ...form });

        applyUser(response.data.user);
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProviderProfile);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="provider-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Provider Profile
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Edit organization details
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Keep provider contact, organization, and verification details current for students and admins.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider profile...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">
                                    Organization
                                </p>
                                <h3 class="mt-2 font-display text-2xl font-bold text-slate-950">
                                    {{ user?.provider_name || user?.name || 'Provider' }}
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    {{ user?.provider_description || 'No provider description added yet.' }}
                                </p>
                                <p class="mt-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                    {{ providerTypeLabels[user?.provider_type] || 'Provider type not set' }}
                                </p>
                            </div>
                            <span :class="['w-fit rounded-md px-3 py-1.5 text-xs font-bold uppercase', verificationClass(user?.verification_status)]">
                                {{ verificationLabel(user?.verification_status) }}
                            </span>
                        </div>
                    </section>

                    <section id="verification-documents" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Verification Documents
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Upload proof for admin review
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Add organization registration, authorization letter, representative ID, or school/office proof. Admins can use these files before approving scholarship publishing access.
                                </p>
                            </div>
                            <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
                                {{ verificationDocuments.length }} file{{ verificationDocuments.length === 1 ? '' : 's' }}
                            </span>
                        </div>

                        <div :class="['mt-5 rounded-md border p-4 text-sm', verificationGuidance.className]">
                            <p class="font-bold">
                                {{ verificationGuidance.title }}
                            </p>
                            <p class="mt-1 leading-6">
                                {{ verificationGuidance.description }}
                            </p>
                            <p v-if="user?.verification_notes && !user?.can_post_scholarships" class="mt-2 text-xs leading-5">
                                <span class="font-bold">Admin note:</span> {{ user.verification_notes }}
                            </p>
                        </div>

                        <div class="mt-5 grid gap-3 md:grid-cols-[1fr_1.2fr_auto] md:items-end">
                            <label>
                                <span :class="labelClass">Document type</span>
                                <select v-model="verificationDocumentType" :class="inputClass">
                                    <option
                                        v-for="option in verificationDocumentOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span :class="labelClass">File</span>
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                                    @change="handleVerificationFile"
                                >
                            </label>
                            <button
                                type="button"
                                :disabled="isUploadingDocument"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                @click="uploadVerificationDocument"
                            >
                                {{ isUploadingDocument ? 'Uploading...' : 'Upload proof' }}
                            </button>
                        </div>

                        <TermsAgreement
                            v-model="verificationDocumentTermsAccepted"
                            class="mt-4"
                            context="providerDocument"
                        />

                        <div v-if="verificationDocuments.length === 0" class="mt-5 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            No verification documents uploaded yet.
                        </div>

                        <div v-else class="mt-5 grid gap-3">
                            <div
                                v-for="document in verificationDocuments"
                                :key="document.id"
                                class="flex flex-col gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p class="font-bold text-slate-950">
                                        {{ documentTypeLabel(document.document_type) }}
                                    </p>
                                    <p class="mt-1 truncate text-xs text-slate-500">
                                        {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at || 'Recently uploaded' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a
                                        :href="document.download_url"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                    >
                                        Download
                                    </a>
                                    <button
                                        type="button"
                                        :disabled="deletingDocumentId === document.id"
                                        class="rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="deleteVerificationDocument(document)"
                                    >
                                        {{ deletingDocumentId === document.id ? 'Removing...' : 'Remove' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <form class="grid gap-6" @submit.prevent="saveProviderProfile">
                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Organization Details
                            </p>

                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Provider name</span>
                                    <input v-model="form.provider_name" type="text" placeholder="Provider" :class="inputClass">
                                    <span v-if="fieldError('provider_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_name') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Provider type</span>
                                    <select v-model="form.provider_type" :class="inputClass">
                                        <option v-for="option in providerTypeOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <span v-if="fieldError('provider_type')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_type') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Website</span>
                                    <input v-model="form.provider_website" type="text" placeholder="https://example.com" :class="inputClass">
                                    <span v-if="fieldError('provider_website')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_website') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Address</span>
                                    <input v-model="form.provider_address" type="text" placeholder="Office address" :class="inputClass">
                                    <span v-if="fieldError('provider_address')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_address') }}</span>
                                </label>
                            </div>

                            <label class="mt-4 block">
                                <span :class="labelClass">Description</span>
                                <textarea
                                    v-model="form.provider_description"
                                    rows="4"
                                    placeholder="Briefly describe the scholarship provider."
                                    :class="inputClass"
                                ></textarea>
                                <span v-if="fieldError('provider_description')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_description') }}</span>
                            </label>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Contact and Account
                            </p>

                            <div class="mt-5 grid gap-4 md:grid-cols-[1fr_5rem_1fr]">
                                <label>
                                    <span :class="labelClass">First name</span>
                                    <input v-model="form.first_name" type="text" placeholder="First name" :class="inputClass">
                                    <span v-if="fieldError('first_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('first_name') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">M.I.</span>
                                    <input v-model="form.middle_initial" maxlength="1" type="text" placeholder="P" :class="inputClass">
                                    <span v-if="fieldError('middle_initial')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('middle_initial') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Last name</span>
                                    <input v-model="form.last_name" type="text" placeholder="Last name" :class="inputClass">
                                    <span v-if="fieldError('last_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('last_name') }}</span>
                                </label>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Email</span>
                                    <input v-model="form.email" type="email" placeholder="provider@example.com" :class="inputClass">
                                    <span v-if="fieldError('email')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('email') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Username</span>
                                    <input v-model="form.username" type="text" placeholder="provider" :class="inputClass">
                                    <span v-if="fieldError('username')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('username') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Contact number</span>
                                    <input v-model="form.contact_number" type="text" placeholder="0917 000 0000" :class="inputClass">
                                    <span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('contact_number') }}</span>
                                </label>
                            </div>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                        Publishing Access
                                    </p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ user?.can_post_scholarships ? 'This account is approved and can publish scholarships.' : 'This account needs admin approval before publishing scholarships.' }}
                                    </p>
                                    <p v-if="user?.verification_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                        {{ user.verification_notes }}
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    :disabled="isSaving"
                                    class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {{ isSaving ? 'Saving...' : 'Save profile' }}
                                </button>
                            </div>
                        </section>
                    </form>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
