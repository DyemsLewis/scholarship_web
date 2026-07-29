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
const canManageProfile = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_profile'),
));
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
const inputClass = 'mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const providerInitials = computed(() => {
    const name = user.value?.provider_name || user.value?.name || 'Provider';

    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
});
const representativeName = computed(() => [
    user.value?.first_name,
    user.value?.middle_initial ? `${user.value.middle_initial}.` : null,
    user.value?.last_name,
].filter(Boolean).join(' ') || user.value?.name || 'Not set');
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
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Provider Profile
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Organization and account
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Keep the public organization details and representative contact information accurate.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider profile...
                </div>

                <div v-else class="mt-6 grid gap-5">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="order-1 overflow-hidden rounded-lg border border-slate-800 bg-slate-950 shadow-sm">
                        <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                            <div class="flex min-w-0 items-center gap-4">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-amber-300 text-lg font-black text-slate-950">
                                    {{ providerInitials }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">
                                        {{ providerTypeLabels[user?.provider_type] || 'Scholarship provider' }}
                                    </p>
                                    <h3 class="mt-1 truncate font-display text-2xl font-bold text-white">
                                        {{ user?.provider_name || user?.name || 'Provider' }}
                                    </h3>
                                    <p class="mt-1 line-clamp-2 max-w-2xl text-sm leading-6 text-slate-300">
                                        {{ user?.provider_description || 'Add a short organization description so applicants know who provides the scholarship.' }}
                                    </p>
                                </div>
                            </div>
                            <span :class="['w-fit shrink-0 rounded-md px-3 py-1.5 text-xs font-bold uppercase', verificationClass(user?.verification_status)]">
                                {{ verificationLabel(user?.verification_status) }} provider
                            </span>
                        </div>
                        <div class="grid border-t border-white/10 bg-white/[0.04] sm:grid-cols-3 sm:divide-x sm:divide-white/10">
                            <div class="flex items-start gap-3 p-4">
                                <i class="fa-solid fa-user-tie mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Representative</p>
                                    <p class="mt-1 truncate text-sm font-semibold text-white">{{ representativeName }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 border-t border-white/10 p-4 sm:border-t-0">
                                <i class="fa-solid fa-envelope mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Email</p>
                                    <p class="mt-1 truncate text-sm font-semibold text-white">{{ user?.email || 'Not set' }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 border-t border-white/10 p-4 sm:border-t-0">
                                <i class="fa-solid fa-location-dot mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Office</p>
                                    <p class="mt-1 line-clamp-2 text-sm font-semibold text-white">{{ user?.provider_address || 'Address not set' }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="verification-documents" class="order-3 scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="font-bold text-slate-950">Provider verification</h3>
                                    <p class="mt-0.5 text-sm text-slate-500">Submit organization proof for admin review and publishing access.</p>
                                </div>
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

                        <div v-if="canManageProfile" class="mt-4 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 md:grid-cols-[1fr_1.2fr_auto] md:items-end">
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
                            v-if="canManageProfile"
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
                                        v-if="canManageProfile"
                                        :href="document.download_url"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                    >
                                        Download
                                    </a>
                                    <button
                                        v-if="canManageProfile"
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

                    <form class="order-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" @submit.prevent="saveProviderProfile">
                        <div class="flex items-center gap-3 p-5 sm:p-6">
                            <span class="grid h-10 w-10 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-building" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="font-bold text-slate-950">Profile details</p>
                                <p class="mt-0.5 text-sm text-slate-500">Information shown to applicants and used to contact your organization.</p>
                            </div>
                        </div>

                        <section class="grid gap-5 border-t border-slate-200 p-5 sm:p-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Organization</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    {{ canManageProfile ? 'Public details applicants use to recognize the scholarship provider.' : 'Organization details are managed by authorized provider staff.' }}
                                </p>
                            </div>
                            <div>
                                <div class="grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Provider name</span>
                                    <input v-model="form.provider_name" type="text" placeholder="Provider" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_name') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Provider type</span>
                                    <select v-model="form.provider_type" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                        <option v-for="option in providerTypeOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <span v-if="fieldError('provider_type')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_type') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Website</span>
                                    <input v-model="form.provider_website" type="text" placeholder="https://example.com" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_website')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_website') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Address</span>
                                    <input v-model="form.provider_address" type="text" placeholder="Office address" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_address')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_address') }}</span>
                                </label>
                            </div>

                            <label class="mt-4 block">
                                <span :class="labelClass">Description</span>
                                <textarea
                                    v-model="form.provider_description"
                                    rows="4"
                                    placeholder="Briefly describe the scholarship provider."
                                    :disabled="!canManageProfile"
                                    :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']"
                                ></textarea>
                                <span v-if="fieldError('provider_description')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_description') }}</span>
                            </label>
                            </div>
                        </section>

                        <section class="grid gap-5 border-t border-slate-200 p-5 sm:p-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Representative account</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">The authorized person responsible for this provider account.</p>
                            </div>
                            <div>
                                <div class="grid gap-4 md:grid-cols-[1fr_5rem_1fr]">
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
                            </div>
                        </section>

                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                                <i :class="['fa-solid fa-circle text-[8px]', user?.can_post_scholarships ? 'text-emerald-500' : 'text-amber-500']" aria-hidden="true"></i>
                                {{ user?.can_post_scholarships ? 'Publishing access is active.' : 'Publishing unlocks after provider verification.' }}
                            </p>
                            <button type="submit" :disabled="isSaving" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70">
                                {{ isSaving ? 'Saving...' : 'Save profile' }}
                            </button>
                        </div>
                    </form>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
