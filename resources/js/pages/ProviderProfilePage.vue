<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { formatFileSize } from '../support/display';
import { limitPhoneNumber } from '../support/phoneNumber';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const validationErrors = ref({});
const user = ref(null);
const currentProfilePath = window.location.pathname.replace(/\/$/, '');
const activeProfileSection = ref(
    currentProfilePath.endsWith('/verification') || window.location.hash === '#verification-documents'
        ? 'verification'
        : currentProfilePath.endsWith('/representative') || window.location.hash === '#representative-account'
            ? 'representative'
            : 'details',
);
const verificationDocuments = ref([]);
const verificationDocumentType = ref('organization_registration');
const verificationDocumentFile = ref(null);
const verificationDocumentTermsAccepted = ref(false);
const isUploadingDocument = ref(false);
const deletingDocumentId = ref(null);
const previewDocument = ref(null);
const providerLogoInput = ref(null);
const providerLogoFile = ref(null);
const providerLogoPreviewUrl = ref('');
const isUploadingLogo = ref(false);
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
    provider_contact_email: '',
    provider_contact_number: '',
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
const labelClass = 'text-xs font-bold uppercase tracking-[0.14em] text-slate-500';
const inputClass = 'mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const profilePageCopy = computed(() => ({
    details: {
        kicker: 'Organization profile',
        title: 'Provider details',
        description: 'Keep the organization identity and applicant contact information accurate.',
        icon: 'fa-solid fa-building',
    },
    verification: {
        kicker: 'Organization access',
        title: 'Provider verification',
        description: 'Submit organization proof and follow its administrator review status.',
        icon: 'fa-solid fa-shield-halved',
    },
    representative: {
        kicker: 'Account owner',
        title: 'Representative account',
        description: 'Manage the private identity and sign-in details for this provider account.',
        icon: 'fa-solid fa-user-tie',
    },
}[activeProfileSection.value]));
const providerInitials = computed(() => {
    const name = user.value?.provider_name || user.value?.name || 'Provider';

    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
});
const providerLogoPreview = computed(() => providerLogoPreviewUrl.value || user.value?.provider_logo_url || '');
const representativeName = computed(() => [
    user.value?.first_name,
    user.value?.middle_initial ? `${user.value.middle_initial}.` : null,
    user.value?.last_name,
].filter(Boolean).join(' ') || user.value?.name || 'Not set');
const verificationDocumentCount = computed(() => (
    canManageProfile.value
        ? verificationDocuments.value.length
        : Number(user.value?.verification_documents_count ?? 0)
));
const hasVerificationDocument = computed(() => verificationDocumentCount.value > 0);
const providerProfileComplete = computed(() => [
    user.value?.provider_name,
    user.value?.provider_type,
    user.value?.provider_address,
    user.value?.provider_contact_email,
    user.value?.provider_contact_number,
].every((value) => String(value ?? '').trim()));
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

    if (!canManageProfile.value) {
        return {
            title: 'Verification is managed by authorized staff',
            description: 'Ask the provider owner or a team member with organization profile access to manage verification proof.',
            className: 'border-slate-200 bg-slate-50 text-slate-800',
        };
    }

    if (!providerProfileComplete.value) {
        return {
            title: 'Complete the provider profile first',
            description: 'Add the organization name, type, office address, and public contacts before uploading verification proof.',
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

    if (hasVerificationDocument.value) {
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
    form.provider_name = payload?.provider_name ?? '';
    form.provider_type = payload?.provider_type ?? '';
    form.provider_website = payload?.provider_website ?? '';
    form.provider_address = payload?.provider_address ?? '';
    form.provider_description = payload?.provider_description ?? '';
    form.provider_contact_email = payload?.provider_contact_email ?? '';
    form.provider_contact_number = payload?.provider_contact_number ?? '';
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

function documentTypeLabel(type) {
    return verificationDocumentOptions.find((option) => option.value === type)?.label
        ?? String(type ?? 'Document').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function selectProfileSection(section) {
    window.location.href = {
        representative: '/provider/profile/representative',
        verification: '/provider/profile/verification',
        details: '/provider/profile/details',
    }[section] ?? '/provider/profile/details';
}

function handleVerificationFile(event) {
    verificationDocumentFile.value = event.target.files?.[0] ?? null;
}

function handleProviderLogo(event) {
    providerLogoFile.value = event.target.files?.[0] ?? null;

    if (providerLogoPreviewUrl.value) {
        URL.revokeObjectURL(providerLogoPreviewUrl.value);
    }

    providerLogoPreviewUrl.value = providerLogoFile.value
        ? URL.createObjectURL(providerLogoFile.value)
        : '';
}

async function uploadProviderLogo() {
    if (!providerLogoFile.value || isUploadingLogo.value) {
        return;
    }

    isUploadingLogo.value = true;
    errorMessage.value = '';
    validationErrors.value = {};
    const payload = new FormData();
    payload.append('logo_file', providerLogoFile.value);

    try {
        const response = await window.axios.post('/provider/profile/logo', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        applyUser(response.data.user);
        providerLogoFile.value = null;

        if (providerLogoPreviewUrl.value) {
            URL.revokeObjectURL(providerLogoPreviewUrl.value);
            providerLogoPreviewUrl.value = '';
        }

        if (providerLogoInput.value) {
            providerLogoInput.value.value = '';
        }
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
    } finally {
        isUploadingLogo.value = false;
    }
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

        if (response.data.user) {
            applyUser(response.data.user);
        }
        applyVerificationDocuments(response.data.verification_documents);
    } catch (handledError) {
        void handledError;
    } finally {
        deletingDocumentId.value = null;
    }
}

async function saveProviderProfile(section) {
    isSaving.value = true;
    errorMessage.value = '';
    validationErrors.value = {};

    const payload = section === 'organization'
        ? {
            profile_section: 'organization',
            provider_name: form.provider_name,
            provider_type: form.provider_type,
            provider_website: form.provider_website,
            provider_address: form.provider_address,
            provider_description: form.provider_description,
            provider_contact_email: form.provider_contact_email,
            provider_contact_number: form.provider_contact_number,
        }
        : {
            profile_section: 'representative',
            first_name: form.first_name,
            last_name: form.last_name,
            middle_initial: form.middle_initial,
            email: form.email,
            username: form.username,
            contact_number: form.contact_number,
        };

    try {
        const response = await window.axios.patch('/provider/profile', payload);

        applyUser(response.data.user);

        if (response.data.email_changed) {
            window.dispatchEvent(new CustomEvent('portal:email-verification-changed', {
                detail: { email_verified: false },
            }));
        }
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProviderProfile);
onBeforeUnmount(() => {
    if (providerLogoPreviewUrl.value) {
        URL.revokeObjectURL(providerLogoPreviewUrl.value);
    }
});
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="user?.provider_name || user?.name || 'Provider'"
            @close="closeDocumentPreview"
        />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="provider-page">
            <div class="provider-container">
                <TaskPageHeader
                    theme="provider"
                    :eyebrow="profilePageCopy.kicker"
                    :title="profilePageCopy.title"
                    :description="profilePageCopy.description"
                    :icon="profilePageCopy.icon"
                >
                    <template #meta>
                        <span v-if="activeProfileSection === 'details'">
                            {{ providerProfileComplete ? 'Required details complete' : 'Required details incomplete' }}
                        </span>
                        <span v-if="activeProfileSection === 'verification'">
                            {{ verificationDocumentCount }} proof file{{ verificationDocumentCount === 1 ? '' : 's' }}
                        </span>
                        <span v-if="activeProfileSection === 'representative'">{{ representativeName }}</span>
                        <span>{{ verificationLabel(user?.verification_status) }} provider</span>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider profile...
                </div>

                <div v-else class="mt-4 grid gap-4">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section v-show="activeProfileSection === 'verification'" id="verification-documents" class="provider-panel scroll-mt-6 p-5 sm:p-6">
                        <div :class="['rounded-md border p-4 text-sm', verificationGuidance.className]">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
                                <div>
                            <p class="font-bold">
                                {{ verificationGuidance.title }}
                            </p>
                            <p class="mt-1 leading-5">
                                {{ verificationGuidance.description }}
                            </p>
                            <p v-if="user?.verification_notes && !user?.can_post_scholarships" class="mt-2 text-xs leading-5">
                                <span class="font-bold">Admin note:</span> {{ user.verification_notes }}
                            </p>
                                </div>
                            </div>
                        </div>

                        <div v-if="canManageProfile && providerProfileComplete" class="mt-5">
                            <h3 class="font-bold text-slate-950">Submit organization proof</h3>
                            <p class="mt-1 text-sm text-slate-500">Attach a readable document for administrator review.</p>
                        </div>

                        <TermsAgreement
                            v-if="canManageProfile && providerProfileComplete"
                            v-model="verificationDocumentTermsAccepted"
                            class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3"
                            context="providerDocument"
                        />

                        <div v-if="canManageProfile && providerProfileComplete" class="mt-3 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 md:items-end">
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
                                :disabled="isUploadingDocument || !verificationDocumentTermsAccepted || !verificationDocumentFile"
                                class="w-fit rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70 md:col-span-2"
                                @click="uploadVerificationDocument"
                            >
                                {{ isUploadingDocument ? 'Uploading...' : 'Upload proof' }}
                            </button>
                        </div>

                        <div v-else-if="canManageProfile" class="mt-4 flex flex-col gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm leading-6 text-slate-600">Finish the organization details before submitting proof for administrator review.</p>
                            <button type="button" class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="selectProfileSection('details')">
                                Complete profile
                            </button>
                        </div>

                        <div v-if="!canManageProfile" class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                            Organization proof files are visible only to the provider owner and staff with organization profile access.
                        </div>

                        <div v-else-if="verificationDocuments.length === 0" class="mt-5 border-t border-slate-200 pt-5">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="font-bold text-slate-950">Submitted proof</h3>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">0 files</span>
                            </div>
                            <p class="mt-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">No verification documents uploaded yet.</p>
                        </div>

                        <div v-else class="mt-5 grid gap-3 border-t border-slate-200 pt-5">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="font-bold text-slate-950">Submitted proof</h3>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ verificationDocuments.length }} file{{ verificationDocuments.length === 1 ? '' : 's' }}</span>
                            </div>
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
                                    <button
                                        v-if="canManageProfile"
                                        type="button"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        @click="openDocumentPreview(document)"
                                    >
                                        View file
                                    </button>
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

                    <form v-show="activeProfileSection === 'details'" class="provider-panel overflow-hidden" @submit.prevent="saveProviderProfile('organization')">
                        <section class="p-5 sm:p-6">
                            <div class="mb-5 border-b border-slate-200 pb-4">
                                <p class="font-bold text-slate-950">Organization identity</p>
                                <p class="mt-1 text-sm text-slate-500">Shown with your scholarships and public provider profile.</p>
                            </div>
                            <div>
                                <div class="mb-5 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 sm:grid-cols-[4rem_minmax(0,1fr)] sm:items-center">
                                    <img
                                        v-if="providerLogoPreview"
                                        :src="providerLogoPreview"
                                        alt="Provider logo preview"
                                        class="h-14 w-14 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div v-else class="grid h-14 w-14 place-items-center rounded-md bg-white text-sm font-black text-slate-700 ring-1 ring-slate-200">
                                        {{ providerInitials }}
                                    </div>
                                    <div class="min-w-0">
                                        <span :class="labelClass">Organization logo</span>
                                        <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                                            <input
                                                ref="providerLogoInput"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                :disabled="!canManageProfile || isUploadingLogo"
                                                class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 file:mr-2 file:rounded file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-xs file:font-bold file:text-white disabled:cursor-not-allowed disabled:opacity-60"
                                                @change="handleProviderLogo"
                                            >
                                            <button
                                                type="button"
                                                :disabled="!providerLogoFile || isUploadingLogo || !canManageProfile"
                                                class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="uploadProviderLogo"
                                            >
                                                {{ isUploadingLogo ? 'Uploading...' : 'Save logo' }}
                                            </button>
                                        </div>
                                        <p class="mt-1.5 text-xs leading-5 text-slate-500">JPG, PNG, or WebP up to 4MB. Reused as the default program logo.</p>
                                        <span v-if="fieldError('logo_file')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('logo_file') }}</span>
                                    </div>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Provider name</span>
                                    <input v-model="form.provider_name" type="text" required placeholder="Organization name" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_name') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Provider type</span>
                                    <select v-model="form.provider_type" required :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
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
                                    <span :class="labelClass">Office address</span>
                                    <input v-model="form.provider_address" type="text" required placeholder="Office address" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_address')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_address') }}</span>
                                </label>
                            </div>

                            <div class="mt-5 border-t border-slate-200 pt-5">
                                <h3 class="font-bold text-slate-950">About the provider</h3>
                                <label class="mt-3 block">
                                <span :class="labelClass">Public description</span>
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

                            <div class="mt-5 border-t border-slate-200 pt-5">
                                <h3 class="font-bold text-slate-950">Applicant contact</h3>
                                <p class="mt-1 text-sm text-slate-500">Applicants use these details for scholarship questions.</p>
                                <div class="mt-3 grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Provider email</span>
                                    <input v-model="form.provider_contact_email" type="email" autocomplete="organization-email" required placeholder="scholarships@example.org" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']">
                                    <span v-if="fieldError('provider_contact_email')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_contact_email') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Provider phone</span>
                                    <input :value="form.provider_contact_number" type="tel" inputmode="numeric" autocomplete="organization-tel" required maxlength="11" placeholder="09170000000" :disabled="!canManageProfile" :class="[inputClass, !canManageProfile ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '']" @input="form.provider_contact_number = limitPhoneNumber($event.target.value)">
                                    <span v-if="fieldError('provider_contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('provider_contact_number') }}</span>
                                </label>
                                </div>
                            </div>
                            </div>
                        </section>

                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600">
                                <i :class="['fa-solid fa-circle text-[8px]', providerProfileComplete ? 'text-emerald-500' : 'text-amber-500']" aria-hidden="true"></i>
                                {{ providerProfileComplete ? 'Required details complete' : 'Complete required details before verification' }}
                            </p>
                            <button type="submit" :disabled="isSaving || !canManageProfile" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70">
                                {{ isSaving ? 'Saving...' : 'Save provider details' }}
                            </button>
                        </div>
                    </form>

                    <form v-show="activeProfileSection === 'representative'" id="representative-account" class="provider-panel overflow-hidden" @submit.prevent="saveProviderProfile('representative')">
                        <section class="p-5 sm:p-6">
                            <div class="mb-5 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                                <i class="fa-solid fa-lock mr-2 text-slate-500" aria-hidden="true"></i>
                                These private account details are separate from the public provider contacts shown to applicants.
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-950">Representative identity</h3>
                                <div class="mt-4 grid gap-4 md:grid-cols-[1fr_5rem_1fr]">
                                <label>
                                    <span :class="labelClass">First name</span>
                                    <input v-model="form.first_name" type="text" required placeholder="First name" :class="inputClass">
                                    <span v-if="fieldError('first_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('first_name') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">M.I.</span>
                                    <input v-model="form.middle_initial" maxlength="1" type="text" required placeholder="P" :class="inputClass">
                                    <span v-if="fieldError('middle_initial')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('middle_initial') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Last name</span>
                                    <input v-model="form.last_name" type="text" required placeholder="Last name" :class="inputClass">
                                    <span v-if="fieldError('last_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('last_name') }}</span>
                                </label>
                            </div>

                            <div class="mt-5 border-t border-slate-200 pt-5">
                                <h3 class="font-bold text-slate-950">Sign-in and contact</h3>
                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Login email</span>
                                    <input v-model="form.email" type="email" required placeholder="provider@example.com" :class="inputClass">
                                    <span v-if="fieldError('email')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('email') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Username</span>
                                    <input v-model="form.username" type="text" required placeholder="provider" :class="inputClass">
                                    <span v-if="fieldError('username')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('username') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Representative phone</span>
                                    <input :value="form.contact_number" type="tel" inputmode="numeric" required maxlength="11" placeholder="09170000000" :class="inputClass" @input="form.contact_number = limitPhoneNumber($event.target.value)">
                                    <span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('contact_number') }}</span>
                                </label>
                                </div>
                            </div>
                            </div>
                        </section>

                        <div class="flex justify-end border-t border-slate-200 bg-slate-50 p-4 sm:px-6">
                            <button type="submit" :disabled="isSaving" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70">
                                {{ isSaving ? 'Saving...' : 'Save representative details' }}
                            </button>
                        </div>
                    </form>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
