<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { formatFileSize } from '../support/display';

const accountId = window.location.pathname.match(/\/admin\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(Boolean(accountId));
const isSaving = ref(false);
const errorMessage = ref('');
const formElement = ref(null);
const form = ref(emptyForm());
const account = ref(null);
const accountAction = ref('');
const suspensionReason = ref('');
const supportLink = ref('');
const verificationDocuments = ref([]);
const previewDocument = ref(null);
const applicantVerificationNotes = ref('');
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();
const adminPermissionOptions = [
    { value: 'manage_accounts', label: 'Manage accounts', description: 'Create and maintain applicant and provider accounts.' },
    { value: 'manage_reviews', label: 'Manage reviews', description: 'Verify providers and applicants, and publish programs.' },
    { value: 'manage_reports', label: 'Manage reports', description: 'Review and resolve concerns submitted through the portal.' },
    { value: 'manage_billing', label: 'Manage service payments', description: 'Review paid provider services and track fulfillment.' },
    { value: 'view_logs', label: 'View activity logs', description: 'Inspect recorded administrative and platform actions.' },
    { value: 'export_data', label: 'Export data', description: 'Download user and application CSV files.' },
];
const adminRolePresets = [
    {
        value: 'Account manager',
        label: 'Account manager',
        description: 'Creates and maintains portal user accounts.',
        permissions: ['manage_accounts'],
    },
    {
        value: 'Review officer',
        label: 'Review officer',
        description: 'Reviews applicants, providers, and scholarship programs.',
        permissions: ['manage_reviews'],
    },
    {
        value: 'Support officer',
        label: 'Support officer',
        description: 'Reviews and resolves submitted platform concerns.',
        permissions: ['manage_reports'],
    },
    {
        value: 'Billing officer',
        label: 'Billing officer',
        description: 'Tracks paid optional provider services through fulfillment.',
        permissions: ['manage_billing'],
    },
    {
        value: 'Records officer',
        label: 'Records officer',
        description: 'Reviews activity records and exports authorized data.',
        permissions: ['view_logs', 'export_data'],
    },
    {
        value: 'Portal manager',
        label: 'Portal manager',
        description: 'Has access to all delegated administrative work areas.',
        permissions: adminPermissionOptions.map((permission) => permission.value),
    },
    {
        value: 'Custom role',
        label: 'Custom role',
        description: 'Build a role by selecting permissions manually.',
        permissions: [],
    },
];
const accountRoleOptions = [
    {
        value: 'applicant',
        label: 'Applicant',
        description: 'Student or learner scholarship account.',
        icon: 'fa-graduation-cap',
    },
    {
        value: 'provider',
        label: 'Provider',
        description: 'Organization account for scholarship programs.',
        icon: 'fa-building-columns',
    },
    {
        value: 'admin',
        label: 'Admin staff',
        description: 'Portal staff with selected administrative access.',
        icon: 'fa-user-shield',
    },
];

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm text-slate-900 uppercase outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';

function emptyForm() {
    return {
        firstName: '',
        lastName: '',
        middleInitial: '',
        email: '',
        username: '',
        contactNumber: '',
        role: 'applicant',
        accountTitle: '',
        permissions: [],
        password: '',
        passwordConfirmation: '',
    };
}

function fillForm(user) {
    account.value = user ?? null;
    suspensionReason.value = user?.suspension_reason ?? '';

    form.value = {
        firstName: user?.first_name ?? '',
        lastName: user?.last_name ?? '',
        middleInitial: user?.middle_initial ?? '',
        email: user?.email ?? '',
        username: user?.username ?? '',
        contactNumber: user?.contact_number ?? '',
        role: user?.role ?? 'applicant',
        accountTitle: user?.account_title ?? '',
        permissions: [...(user?.permissions ?? [])],
        password: '',
        passwordConfirmation: '',
    };
}

const accountStatusLabel = computed(() => account.value?.account_status === 'suspended' ? 'Suspended' : 'Active');
const isCurrentAdminAccount = computed(() => Boolean(
    account.value?.role === 'admin'
    && Number(account.value?.id) === Number(window.portalUser?.id),
));
const canChooseAdminRole = computed(() => !window.portalUser?.is_managed_account);
const visibleAccountRoleOptions = computed(() => accountRoleOptions.filter((role) => {
    if (isEditMode.value) {
        return role.value === account.value?.role;
    }

    return role.value !== 'admin' || canChooseAdminRole.value;
}));
const selectableAdminRolePresets = computed(() => {
    const currentTitle = form.value.accountTitle;

    if (!currentTitle || adminRolePresets.some((role) => role.value === currentTitle)) {
        return adminRolePresets;
    }

    return [
        {
            value: currentTitle,
            label: currentTitle,
            description: 'Existing custom admin role.',
            permissions: [...form.value.permissions],
        },
        ...adminRolePresets,
    ];
});
const selectedAdminRolePreset = computed(() => selectableAdminRolePresets.value.find(
    (role) => role.value === form.value.accountTitle,
));
const adminPermissionsLocked = computed(() => form.value.role === 'admin'
    && form.value.accountTitle !== 'Custom role'
    && adminRolePresets.some((role) => role.value === form.value.accountTitle));
const needsAdminPermissions = computed(() => form.value.role === 'admin'
    && (!isEditMode.value || account.value?.is_managed_account || account.value?.role !== 'admin'));
const accountStatusClass = computed(() => account.value?.account_status === 'suspended'
    ? 'bg-rose-100 text-rose-800'
    : 'bg-emerald-100 text-emerald-800');
const emailStatusLabel = computed(() => account.value?.email_verified ? 'Email verified' : 'Email unverified');
const emailStatusClass = computed(() => account.value?.email_verified
    ? 'bg-emerald-100 text-emerald-800'
    : 'bg-amber-100 text-amber-800');
const applicantVerificationStatus = computed(() => account.value?.applicant_verification_status ?? 'unsubmitted');
const applicantVerificationLabel = computed(() => ({
    unsubmitted: 'Not submitted',
    pending: 'Pending review',
    approved: 'Academic verified',
    rejected: 'Needs replacement',
}[applicantVerificationStatus.value] ?? 'Not submitted'));
const applicantVerificationClass = computed(() => {
    if (applicantVerificationStatus.value === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (applicantVerificationStatus.value === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (applicantVerificationStatus.value === 'pending') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-600';
});
const applicantVerificationDocumentOptions = {
    academic_record: 'Academic record',
    school_record: 'School enrollment proof',
};
const hasAcademicVerificationDocument = computed(() => verificationDocuments.value.some(
    (document) => document.document_type === 'academic_record',
));

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.contactNumber = event.target.value.replace(/[^\d+\s().-]/g, '');
}

function handleRoleChange() {
    if (form.value.role !== 'admin') {
        form.value.accountTitle = '';
        form.value.permissions = [];
        return;
    }

    if (!adminRolePresets.some((role) => role.value === form.value.accountTitle)) {
        form.value.accountTitle = adminRolePresets[0].value;
    }

    applyAdminRolePreset();
}

function applyAdminRolePreset() {
    const preset = adminRolePresets.find((role) => role.value === form.value.accountTitle);

    if (preset) {
        form.value.permissions = [...preset.permissions];
    }
}

function resetForm() {
    form.value = emptyForm();
    account.value = null;
    supportLink.value = '';
    suspensionReason.value = '';
    errorMessage.value = '';
}

async function loadAccount() {
    if (!accountId) {
        return;
    }

    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/admin/users/${accountId}`);

        fillForm(response.data.user);
        verificationDocuments.value = response.data.verification_documents ?? [];
        applicantVerificationNotes.value = response.data.user?.applicant_verification_notes ?? '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load account.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAccount() {
    errorMessage.value = '';
    supportLink.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    const numberDigits = form.value.contactNumber.replace(/\D/g, '');

    if (numberDigits.length < 10) {
        errorMessage.value = 'Enter at least 10 digits in the contact number.';
        formElement.value
            ?.querySelector('#admin-contact-number')
            ?.setCustomValidity(errorMessage.value);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#admin-contact-number')?.setCustomValidity('');

    const hasPasswordInput = form.value.password || form.value.passwordConfirmation;

    if ((!isEditMode.value || hasPasswordInput) && form.value.password !== form.value.passwordConfirmation) {
        errorMessage.value = 'Passwords must match.';
        formElement.value
            ?.querySelector('#admin-password-confirmation')
            ?.setCustomValidity(errorMessage.value);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#admin-password-confirmation')?.setCustomValidity('');

    if (needsAdminPermissions.value && form.value.permissions.length === 0) {
        errorMessage.value = 'Select at least one admin permission.';
        return;
    }

    isSaving.value = true;

    const payload = {
        first_name: form.value.firstName,
        last_name: form.value.lastName,
        middle_initial: form.value.middleInitial,
        email: form.value.email,
        username: form.value.username,
        contact_number: form.value.contactNumber,
        role: form.value.role,
    };

    if (needsAdminPermissions.value) {
        payload.account_title = form.value.accountTitle;
        payload.permissions = form.value.permissions;
    }

    if (!isEditMode.value || hasPasswordInput) {
        payload.password = form.value.password;
        payload.password_confirmation = form.value.passwordConfirmation;
    }

    try {
        const response = isEditMode.value
            ? await window.axios.patch(`/admin/users/${accountId}`, payload)
            : await window.axios.post('/admin/users', payload);

        if (isEditMode.value) {
            fillForm(response.data.user);
        } else {
            resetForm();
        }
    } catch (handledError) {
        void handledError;
    } finally {
        isSaving.value = false;
    }
}

async function updateAccountStatus(status) {
    if (!accountId) {
        return;
    }

    errorMessage.value = '';
    supportLink.value = '';

    if (status === 'suspended' && isCurrentAdminAccount.value) {
        errorMessage.value = 'You cannot suspend the admin account you are currently using.';
        return;
    }

    if (status === 'suspended' && !suspensionReason.value.trim()) {
        errorMessage.value = 'Add a reason before suspending this account.';
        return;
    }

    accountAction.value = status === 'suspended' ? 'suspend' : 'activate';

    try {
        const response = await window.axios.patch(`/admin/users/${accountId}/status`, {
            account_status: status,
            suspension_reason: status === 'suspended' ? suspensionReason.value.trim() : null,
        });

        fillForm(response.data.user);
    } catch (handledError) {
        void handledError;
    } finally {
        accountAction.value = '';
    }
}

async function forcePasswordReset() {
    if (!accountId) {
        return;
    }

    const isResending = Boolean(account.value?.must_reset_password);
    const confirmed = await requestConfirmation({
        title: isResending ? 'Send a new reset link?' : 'Require a password reset?',
        message: isResending
            ? `A new password-reset link will be sent to ${account.value?.email}. The previous link will no longer be valid.`
            : `This will block ${account.value?.email} from signing in until they set a new password from the emailed reset link.`,
        confirmLabel: isResending ? 'Send new link' : 'Require reset',
    });

    if (!confirmed) {
        return;
    }

    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'force-reset';

    try {
        const response = await window.axios.post(`/admin/users/${accountId}/force-password-reset`);

        supportLink.value = response.data.reset_url ?? '';
        fillForm(response.data.user);
    } catch (handledError) {
        void handledError;
    } finally {
        accountAction.value = '';
    }
}

async function verifyEmail() {
    if (!accountId) {
        return;
    }

    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'verify-email';

    try {
        const response = await window.axios.patch(`/admin/users/${accountId}/email-verification`);

        fillForm(response.data.user);
    } catch (handledError) {
        void handledError;
    } finally {
        accountAction.value = '';
    }
}

async function resendVerificationEmail() {
    if (!accountId) {
        return;
    }

    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'resend-verification';

    try {
        const response = await window.axios.post(`/admin/users/${accountId}/verification-email`);

        fillForm(response.data.user);
    } catch (handledError) {
        void handledError;
    } finally {
        accountAction.value = '';
    }
}

async function updateApplicantVerification(status) {
    if (!accountId || account.value?.role !== 'applicant') {
        return;
    }

    if (!hasAcademicVerificationDocument.value) {
        errorMessage.value = 'The applicant must upload an academic record before academic verification can be updated.';
        return;
    }

    if (status === 'rejected' && !applicantVerificationNotes.value.trim()) {
        errorMessage.value = 'Add a review note explaining what the applicant needs to replace.';
        return;
    }

    accountAction.value = `profile-verification-${status}`;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/users/${accountId}/profile-verification`, {
            verification_status: status,
            verification_notes: applicantVerificationNotes.value.trim() || null,
        });

        fillForm(response.data.user);
        verificationDocuments.value = response.data.verification_documents ?? [];
        applicantVerificationNotes.value = response.data.user?.applicant_verification_notes ?? '';
    } catch (handledError) {
        void handledError;
    } finally {
        accountAction.value = '';
    }
}

function applicantVerificationDocumentLabel(type) {
    return applicantVerificationDocumentOptions[type] ?? 'Older verification file';
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

onMounted(loadAccount);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="users" />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <FilePreviewModal
            :file="previewDocument"
            :title="applicantVerificationDocumentLabel(previewDocument?.document_type)"
            :context="account?.name || account?.username || 'Applicant'"
            @close="closeDocumentPreview"
        />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                User Access
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit user account' : 'Create user account' }}
                            </h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Add the person's details, choose an account type, and set their portal access.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            <i class="fa-solid fa-arrow-left mr-2" aria-hidden="true"></i>
                            Back to users
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading account...
                </div>

                <form
                    v-else
                    ref="formElement"
                    class="mt-6 grid gap-5"
                    @submit.prevent="saveAccount"
                >
                    <section class="admin-panel overflow-hidden">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-white">
                                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Identity and contact</h3>
                                    <p class="mt-0.5 text-xs text-slate-500">Basic details used across the portal.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">01</span>
                        </div>

                        <div class="grid gap-4 p-5 sm:p-6">
                            <div class="grid grid-cols-[minmax(0,1fr)_4.75rem] gap-4 lg:grid-cols-[minmax(0,1fr)_5rem_minmax(0,1fr)] lg:items-end">
                                <div>
                                    <label :class="labelClass" for="admin-first-name">First name</label>
                                    <input id="admin-first-name" v-model="form.firstName" type="text" autocomplete="given-name" required placeholder="First name" :class="inputClass">
                                </div>

                                <div>
                                    <label :class="[labelClass, 'text-center']" for="admin-middle-initial">M.I.</label>
                                    <input
                                        id="admin-middle-initial"
                                        :value="form.middleInitial"
                                        type="text"
                                        inputmode="text"
                                        maxlength="1"
                                        pattern="[A-Za-z]"
                                        required
                                        placeholder="M"
                                        :class="compactInputClass"
                                        @input="handleMiddleInitialInput"
                                    >
                                </div>

                                <div class="col-span-2 lg:col-span-1">
                                    <label :class="labelClass" for="admin-last-name">Last name</label>
                                    <input id="admin-last-name" v-model="form.lastName" type="text" autocomplete="family-name" required placeholder="Last name" :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="admin-email">Email address</label>
                                    <input id="admin-email" v-model="form.email" type="email" autocomplete="email" required placeholder="name@example.com" :class="inputClass">
                                </div>

                                <div>
                                    <label :class="labelClass" for="admin-username">Username</label>
                                    <input id="admin-username" v-model="form.username" type="text" autocomplete="username" pattern="[A-Za-z0-9_.-]{4,}" required placeholder="At least 4 characters" :class="inputClass">
                                </div>

                                <div class="md:col-span-2 xl:col-span-1">
                                    <label :class="labelClass" for="admin-contact-number">Contact number</label>
                                    <input
                                        id="admin-contact-number"
                                        :value="form.contactNumber"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        required
                                        placeholder="09XX XXX XXXX"
                                        :class="inputClass"
                                        @input="(event) => { event.target.setCustomValidity(''); handleNumberInput(event); }"
                                    >
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Role and access</h3>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ isEditMode ? 'The portal role is fixed after account creation.' : 'Choose what kind of account this person needs.' }}
                                    </p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">02</span>
                        </div>

                        <div class="p-5 sm:p-6">
                            <div class="grid gap-3 md:grid-cols-3">
                                <label
                                    v-for="role in visibleAccountRoleOptions"
                                    :key="role.value"
                                    :class="[
                                        'flex cursor-pointer gap-3 rounded-md border p-4 transition',
                                        form.role === role.value
                                            ? 'border-slate-900 bg-slate-900 text-white shadow-sm'
                                            : 'border-slate-200 bg-white text-slate-900 hover:border-slate-400',
                                    ]"
                                >
                                    <input
                                        v-model="form.role"
                                        type="radio"
                                        name="admin-role"
                                        :value="role.value"
                                        :disabled="isEditMode"
                                        class="sr-only"
                                        @change="handleRoleChange"
                                    >
                                    <i :class="['fa-solid mt-0.5 w-5 text-center', role.icon, form.role === role.value ? 'text-amber-300' : 'text-amber-700']" aria-hidden="true"></i>
                                    <span>
                                        <span class="block text-sm font-bold">{{ role.label }}</span>
                                        <span :class="['mt-1 block text-xs leading-5', form.role === role.value ? 'text-slate-300' : 'text-slate-500']">
                                            {{ role.description }}
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div v-if="form.role === 'admin'" class="mt-5 border-t border-slate-200 pt-5">
                                <div v-if="needsAdminPermissions" class="grid gap-5">
                                    <div class="max-w-xl">
                                        <label :class="labelClass" for="admin-account-title">Admin staff role</label>
                                        <select
                                            id="admin-account-title"
                                            v-model="form.accountTitle"
                                            required
                                            :class="inputClass"
                                            @change="applyAdminRolePreset"
                                        >
                                            <option
                                                v-for="role in selectableAdminRolePresets"
                                                :key="role.value"
                                                :value="role.value"
                                            >
                                                {{ role.label }}
                                            </option>
                                        </select>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            {{ selectedAdminRolePreset?.description }} Recommended permissions are selected automatically and can be adjusted below.
                                        </p>
                                    </div>

                                    <div>
                                        <div class="flex items-end justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-bold text-slate-900">Portal permissions</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ adminPermissionsLocked ? 'Permissions are fixed by the selected role. Choose Custom role to set them manually.' : 'Select the work areas this custom role needs.' }}
                                                </p>
                                            </div>
                                            <span class="text-xs font-bold text-slate-500">{{ form.permissions.length }} selected</span>
                                        </div>
                                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                                            <label
                                                v-for="permission in adminPermissionOptions"
                                                :key="permission.value"
                                                :class="[
                                                    'flex gap-3 rounded-md border p-3 transition',
                                                    adminPermissionsLocked ? 'cursor-not-allowed' : 'cursor-pointer',
                                                    form.permissions.includes(permission.value)
                                                        ? 'border-amber-400 bg-amber-50'
                                                        : 'border-slate-200 bg-slate-50 hover:border-slate-300',
                                                ]"
                                            >
                                                <input
                                                    v-model="form.permissions"
                                                    type="checkbox"
                                                    :value="permission.value"
                                                    :disabled="adminPermissionsLocked"
                                                    class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-amber-400"
                                                >
                                                <span>
                                                    <span class="block text-sm font-bold text-slate-900">{{ permission.label }}</span>
                                                    <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ permission.description }}</span>
                                                </span>
                                            </label>
                                        </div>
                                        <p v-if="form.permissions.length === 0" class="mt-2 text-xs font-semibold text-rose-700">
                                            Select at least one permission.
                                        </p>
                                    </div>
                                </div>

                                <div v-else class="rounded-md bg-slate-50 p-4">
                                    <p class="text-sm font-bold text-slate-900">Primary administrator</p>
                                    <p class="mt-1 text-sm text-slate-500">This existing primary account keeps full admin access.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-200 text-slate-800">
                                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-slate-950">Sign-in details</h3>
                                    <p class="mt-0.5 text-xs text-slate-500">Use a temporary password the account owner can replace.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">03</span>
                        </div>

                        <div class="grid gap-4 p-5 md:grid-cols-2 sm:p-6">
                            <div>
                                <label :class="labelClass" for="admin-password">
                                    {{ isEditMode ? 'New password (optional)' : 'Temporary password' }}
                                </label>
                                <input
                                    id="admin-password"
                                    v-model="form.password"
                                    type="password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    :required="!isEditMode"
                                    :placeholder="isEditMode ? 'Leave blank to keep current password' : 'At least 8 characters'"
                                    :class="inputClass"
                                >
                            </div>

                            <div>
                                <label :class="labelClass" for="admin-password-confirmation">Confirm password</label>
                                <input
                                    id="admin-password-confirmation"
                                    v-model="form.passwordConfirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    :required="!isEditMode || Boolean(form.password)"
                                    placeholder="Enter the password again"
                                    :class="inputClass"
                                    @input="$event.target.setCustomValidity('')"
                                >
                            </div>
                        </div>
                        <p v-if="!isEditMode && form.role === 'admin'" class="border-t border-slate-200 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-900 sm:px-6">
                            A welcome email will include the username and sign-in link. Share the temporary password separately. Staff can update their email, username, and contact details in Profile after signing in.
                        </p>
                    </section>

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-h-5">
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button
                                v-if="!isEditMode"
                                type="button"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                @click="resetForm"
                            >
                                Clear
                            </button>
                            <button
                                type="submit"
                                :disabled="isSaving"
                                class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                            >
                                {{ isSaving ? 'Saving...' : isEditMode ? 'Update account' : 'Create account' }}
                            </button>
                        </div>
                    </div>
                </form>

                <section
                    v-if="isEditMode && account"
                    class="admin-panel mt-4 overflow-hidden"
                >
                    <div class="border-b border-slate-200 p-5 sm:p-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Account controls
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Security and access
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Manage sign-in recovery, email verification, and access to this account.
                            </p>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-200">
                        <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-sm font-bold text-slate-950">Email verification</h4>
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', emailStatusClass]">
                                            {{ emailStatusLabel }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">
                                        {{ account.email_verified
                                            ? 'The email address can receive security and account notifications.'
                                            : 'Resend the verification message, or verify manually only after confirming ownership.' }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="!account.email_verified" class="flex flex-col gap-2 sm:flex-row lg:justify-end">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70"
                                    :disabled="Boolean(accountAction)"
                                    @click="resendVerificationEmail"
                                >
                                    {{ accountAction === 'resend-verification' ? 'Sending...' : 'Resend email' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-slate-900 px-3.5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                    :disabled="Boolean(accountAction)"
                                    @click="verifyEmail"
                                >
                                    {{ accountAction === 'verify-email' ? 'Verifying...' : 'Mark verified' }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-sm font-bold text-slate-950">Password security</h4>
                                        <span v-if="account.must_reset_password" class="rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase text-amber-800">
                                            Reset required
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">
                                        Require the user to set a new password through a secure email link before signing in again.
                                    </p>
                                    <p v-if="account.password_reset_required_at" class="mt-1 text-xs font-semibold text-slate-500">
                                        Required since {{ account.password_reset_required_at }}
                                    </p>
                                    <p v-if="supportLink" class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-2.5 text-xs leading-5 text-amber-900">
                                        Password reset link:
                                        <a :href="supportLink" class="break-all font-bold underline">{{ supportLink }}</a>
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70"
                                :disabled="Boolean(accountAction)"
                                @click="forcePasswordReset"
                            >
                                {{ accountAction === 'force-reset'
                                    ? 'Preparing reset...'
                                    : account.must_reset_password ? 'Send reset link again' : 'Require password reset' }}
                            </button>
                        </div>

                        <div :class="['p-5 sm:p-6', account.account_status === 'suspended' ? 'bg-amber-50/60' : 'bg-rose-50/40']">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                                <div class="flex items-start gap-3">
                                    <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md', account.account_status === 'suspended' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-700']">
                                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-bold text-slate-950">Account access</h4>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', accountStatusClass]">
                                                {{ accountStatusLabel }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ isCurrentAdminAccount
                                                ? 'This is the admin account currently signed in. Its access cannot be suspended from this session.'
                                                : account.account_status === 'suspended'
                                                ? 'This user cannot sign in until an administrator restores access.'
                                                : 'Suspend access only when the account presents a security, policy, or ownership concern.' }}
                                        </p>
                                        <p v-if="account.account_status === 'suspended' && account.suspension_reason" class="mt-2 text-sm font-semibold text-slate-800">
                                            Reason: {{ account.suspension_reason }}
                                        </p>
                                        <p v-if="account.suspended_at" class="mt-1 text-xs font-semibold text-slate-500">
                                            Suspended {{ account.suspended_at }}
                                        </p>
                                    </div>
                                </div>

                                <button
                                    v-if="account.account_status === 'suspended'"
                                    type="button"
                                    class="rounded-md bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-80"
                                    :disabled="Boolean(accountAction)"
                                    @click="updateAccountStatus('active')"
                                >
                                    {{ accountAction === 'activate' ? 'Reactivating...' : 'Reactivate account' }}
                                </button>
                            </div>

                            <div v-if="account.account_status !== 'suspended' && !isCurrentAdminAccount" class="mt-4 grid gap-3 border-t border-rose-200 pt-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                                <div>
                                    <label :class="labelClass" for="admin-suspension-reason">Reason for suspension</label>
                                    <textarea
                                        id="admin-suspension-reason"
                                        v-model="suspensionReason"
                                        rows="2"
                                        placeholder="Required for the security and activity record"
                                        class="w-full rounded-md border border-rose-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-rose-400 focus:ring-3 focus:ring-rose-100"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-80"
                                    :disabled="Boolean(accountAction)"
                                    @click="updateAccountStatus('suspended')"
                                >
                                    {{ accountAction === 'suspend' ? 'Suspending...' : 'Suspend account' }}
                                </button>
                            </div>
                            <div v-else-if="isCurrentAdminAccount" class="mt-4 flex items-center gap-3 border-t border-slate-200 pt-4 text-sm text-slate-600">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                </span>
                                <p><span class="font-bold text-slate-900">Self-suspension is disabled.</span> Another administrator can manage this account if access changes are needed.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    v-if="isEditMode && account?.role === 'applicant'"
                    class="admin-panel mt-4 overflow-hidden"
                >
                    <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Profile proof
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Review academic and school records
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Compare the saved academic result with the submitted grade record. School enrollment proof provides optional supporting context.
                            </p>
                        </div>
                        <span :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase tracking-[0.12em]', applicantVerificationClass]">
                            {{ applicantVerificationLabel }}
                        </span>
                    </div>

                    <div class="p-5 sm:p-6">
                        <div v-if="verificationDocuments.length" class="divide-y divide-slate-200 rounded-md border border-slate-200">
                            <div
                                v-for="document in verificationDocuments"
                                :key="document.id"
                                class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">
                                        {{ applicantVerificationDocumentLabel(document.document_type) }}
                                    </p>
                                    <p class="mt-1 truncate text-xs text-slate-500">
                                        {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="w-fit rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                    @click="openDocumentPreview(document)"
                                >
                                    View record
                                </button>
                            </div>
                        </div>
                        <div v-else class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                            This applicant has not submitted an academic record yet.
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                            <div>
                                <label :class="labelClass" for="admin-applicant-verification-notes">Review note</label>
                                <textarea
                                    id="admin-applicant-verification-notes"
                                    v-model="applicantVerificationNotes"
                                    rows="3"
                                    placeholder="Required when proof needs replacement"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                />
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row lg:justify-end">
                                <button
                                    type="button"
                                    :disabled="Boolean(accountAction) || !hasAcademicVerificationDocument"
                                    class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('pending')"
                                >
                                    {{ accountAction === 'profile-verification-pending' ? 'Updating...' : 'Keep pending' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="Boolean(accountAction) || !hasAcademicVerificationDocument"
                                    class="rounded-md border border-rose-300 bg-rose-50 px-3.5 py-2.5 text-sm font-bold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('rejected')"
                                >
                                    {{ accountAction === 'profile-verification-rejected' ? 'Updating...' : 'Needs replacement' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="Boolean(accountAction) || !hasAcademicVerificationDocument"
                                    class="rounded-md bg-slate-900 px-3.5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('approved')"
                                >
                                    {{ accountAction === 'profile-verification-approved' ? 'Verifying...' : 'Verify academic result' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
