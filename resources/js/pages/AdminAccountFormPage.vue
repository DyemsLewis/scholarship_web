<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import { formatFileSize } from '../support/display';

const accountId = window.location.pathname.match(/\/admin\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(Boolean(accountId));
const isSaving = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');
const formElement = ref(null);
const form = ref(emptyForm());
const account = ref(null);
const accountAction = ref('');
const suspensionReason = ref('');
const supportLink = ref('');
const verificationDocuments = ref([]);
const applicantVerificationNotes = ref('');

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
        password: '',
        passwordConfirmation: '',
    };
}

const accountStatusLabel = computed(() => account.value?.account_status === 'suspended' ? 'Suspended' : 'Active');
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
    approved: 'Admin verified',
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
    school_id: 'School or student ID',
    government_id: 'Government-issued ID',
    enrollment_certificate: 'Enrollment certificate',
    birth_certificate: 'Birth certificate',
    other: 'Other identity or school proof',
};

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.contactNumber = event.target.value.replace(/[^\d+\s().-]/g, '');
}

function resetForm() {
    form.value = emptyForm();
    account.value = null;
    supportLink.value = '';
    suspensionReason.value = '';
    statusMessage.value = '';
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
    statusMessage.value = '';
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

    if (!isEditMode.value || hasPasswordInput) {
        payload.password = form.value.password;
        payload.password_confirmation = form.value.passwordConfirmation;
    }

    try {
        const response = isEditMode.value
            ? await window.axios.patch(`/admin/users/${accountId}`, payload)
            : await window.axios.post('/admin/users', payload);

        statusMessage.value = response.data.message ?? (isEditMode.value ? 'Account updated successfully.' : 'Account created successfully.');

        if (isEditMode.value) {
            fillForm(response.data.user);
        } else {
            resetForm();
            statusMessage.value = response.data.message ?? 'Account created successfully.';
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to save account.';
    } finally {
        isSaving.value = false;
    }
}

async function updateAccountStatus(status) {
    if (!accountId) {
        return;
    }

    statusMessage.value = '';
    errorMessage.value = '';
    supportLink.value = '';

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

        statusMessage.value = response.data.message ?? 'Account status updated.';
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update account status.';
    } finally {
        accountAction.value = '';
    }
}

async function forcePasswordReset() {
    if (!accountId) {
        return;
    }

    statusMessage.value = '';
    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'force-reset';

    try {
        const response = await window.axios.post(`/admin/users/${accountId}/force-password-reset`);

        statusMessage.value = response.data.message ?? 'Password reset required.';
        supportLink.value = response.data.reset_url ?? '';
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to require a password reset.';
    } finally {
        accountAction.value = '';
    }
}

async function verifyEmail() {
    if (!accountId) {
        return;
    }

    statusMessage.value = '';
    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'verify-email';

    try {
        const response = await window.axios.patch(`/admin/users/${accountId}/email-verification`);

        statusMessage.value = response.data.message ?? 'Email marked as verified.';
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to verify this email.';
    } finally {
        accountAction.value = '';
    }
}

async function resendVerificationEmail() {
    if (!accountId) {
        return;
    }

    statusMessage.value = '';
    errorMessage.value = '';
    supportLink.value = '';
    accountAction.value = 'resend-verification';

    try {
        const response = await window.axios.post(`/admin/users/${accountId}/verification-email`);

        statusMessage.value = response.data.message ?? 'Verification email processed.';
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to resend verification email.';
    } finally {
        accountAction.value = '';
    }
}

async function updateApplicantVerification(status) {
    if (!accountId || account.value?.role !== 'applicant') {
        return;
    }

    if (status === 'rejected' && !applicantVerificationNotes.value.trim()) {
        errorMessage.value = 'Add a review note explaining what the applicant needs to replace.';
        return;
    }

    accountAction.value = `profile-verification-${status}`;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/users/${accountId}/profile-verification`, {
            verification_status: status,
            verification_notes: applicantVerificationNotes.value.trim() || null,
        });

        fillForm(response.data.user);
        verificationDocuments.value = response.data.verification_documents ?? [];
        applicantVerificationNotes.value = response.data.user?.applicant_verification_notes ?? '';
        statusMessage.value = response.data.message ?? 'Applicant profile verification updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update applicant profile verification.';
    } finally {
        accountAction.value = '';
    }
}

function applicantVerificationDocumentLabel(type) {
    return applicantVerificationDocumentOptions[type] ?? 'Verification proof';
}

onMounted(loadAccount);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="users" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Account Form
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit account' : 'Create account' }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Keep account creation and editing separate from the Manage Users table.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
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
                    class="mt-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                    @submit.prevent="saveAccount"
                >
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_5.5rem_minmax(0,1fr)] lg:items-end">
                        <div>
                            <label :class="labelClass" for="admin-first-name">First name</label>
                            <input id="admin-first-name" v-model="form.firstName" type="text" autocomplete="given-name" required placeholder="First name" :class="inputClass">
                        </div>

                        <div class="lg:mx-auto lg:w-[5.5rem]">
                            <label :class="[labelClass, 'lg:text-center']" for="admin-middle-initial">M.I.</label>
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

                        <div>
                            <label :class="labelClass" for="admin-last-name">Last name</label>
                            <input id="admin-last-name" v-model="form.lastName" type="text" autocomplete="family-name" required placeholder="Last name" :class="inputClass">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label :class="labelClass" for="admin-email">Email address</label>
                            <input id="admin-email" v-model="form.email" type="email" autocomplete="email" required placeholder="Email address" :class="inputClass">
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-username">Username</label>
                            <input id="admin-username" v-model="form.username" type="text" autocomplete="username" pattern="[A-Za-z0-9_.-]{4,}" required placeholder="Username" :class="inputClass">
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-contact-number">Contact number</label>
                            <input
                                id="admin-contact-number"
                                :value="form.contactNumber"
                                type="tel"
                                inputmode="numeric"
                                autocomplete="tel"
                                required
                                placeholder="Contact number"
                                :class="inputClass"
                                @input="(event) => { event.target.setCustomValidity(''); handleNumberInput(event); }"
                            >
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-role">Role</label>
                            <select id="admin-role" v-model="form.role" required :class="inputClass">
                                <option value="applicant">Applicant</option>
                                <option value="provider">Provider</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label :class="labelClass" for="admin-password">
                                {{ isEditMode ? 'New password' : 'Password' }}
                            </label>
                            <input
                                id="admin-password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                minlength="8"
                                :required="!isEditMode"
                                :placeholder="isEditMode ? 'Leave blank to keep current password' : 'Password'"
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
                                placeholder="Confirm password"
                                :class="inputClass"
                                @input="$event.target.setCustomValidity('')"
                            >
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-h-5">
                            <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">
                                {{ statusMessage }}
                            </p>
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                            <p v-if="supportLink" class="mt-1 text-sm text-slate-600">
                                Reset link:
                                <a :href="supportLink" class="break-all font-semibold text-slate-900 underline">
                                    {{ supportLink }}
                                </a>
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
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                            >
                                {{ isSaving ? 'Saving...' : isEditMode ? 'Update account' : 'Create account' }}
                            </button>
                        </div>
                    </div>
                </form>

                <section
                    v-if="isEditMode && account"
                    class="mt-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Account controls
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Support and security
                            </h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', accountStatusClass]">
                                    {{ accountStatusLabel }}
                                </span>
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', emailStatusClass]">
                                    {{ emailStatusLabel }}
                                </span>
                                <span
                                    v-if="account.must_reset_password"
                                    class="rounded-md bg-slate-900 px-2.5 py-1 text-xs font-bold text-white"
                                >
                                    Password reset required
                                </span>
                            </div>
                            <p v-if="account.suspended_at" class="mt-3 text-sm text-slate-500">
                                Suspended {{ account.suspended_at }}.
                            </p>
                            <p v-if="account.password_reset_required_at" class="mt-1 text-sm text-slate-500">
                                Password reset required since {{ account.password_reset_required_at }}.
                            </p>
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2 lg:min-w-[24rem]">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70"
                                :disabled="Boolean(accountAction)"
                                @click="forcePasswordReset"
                            >
                                {{ accountAction === 'force-reset' ? 'Preparing reset...' : 'Force password reset' }}
                            </button>

                            <button
                                v-if="!account.email_verified"
                                type="button"
                                class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70"
                                :disabled="Boolean(accountAction)"
                                @click="resendVerificationEmail"
                            >
                                {{ accountAction === 'resend-verification' ? 'Sending...' : 'Resend verification' }}
                            </button>

                            <button
                                v-if="!account.email_verified"
                                type="button"
                                class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70"
                                :disabled="Boolean(accountAction)"
                                @click="verifyEmail"
                            >
                                {{ accountAction === 'verify-email' ? 'Verifying...' : 'Mark email verified' }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 border-t border-slate-200 pt-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                        <div>
                            <label :class="labelClass" for="admin-suspension-reason">Suspension reason</label>
                            <textarea
                                id="admin-suspension-reason"
                                v-model="suspensionReason"
                                rows="3"
                                placeholder="Reason shown in admin records"
                                class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            />
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row lg:justify-end">
                            <button
                                v-if="account.account_status === 'suspended'"
                                type="button"
                                class="rounded-md bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-80"
                                :disabled="Boolean(accountAction)"
                                @click="updateAccountStatus('active')"
                            >
                                {{ accountAction === 'activate' ? 'Reactivating...' : 'Reactivate account' }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-80"
                                :disabled="Boolean(accountAction)"
                                @click="updateAccountStatus('suspended')"
                            >
                                {{ accountAction === 'suspend' ? 'Suspending...' : 'Suspend account' }}
                            </button>
                        </div>
                    </div>
                </section>

                <section
                    v-if="isEditMode && account?.role === 'applicant'"
                    class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                >
                    <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Applicant verification
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Review profile proof
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Check the applicant's private proof, then approve the account badge or explain what needs replacement.
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
                                <a
                                    :href="document.view_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="w-fit rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    View proof
                                </a>
                            </div>
                        </div>
                        <div v-else class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                            This applicant has not submitted a verification proof yet.
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
                                    :disabled="Boolean(accountAction) || verificationDocuments.length === 0"
                                    class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('pending')"
                                >
                                    {{ accountAction === 'profile-verification-pending' ? 'Updating...' : 'Keep pending' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="Boolean(accountAction) || verificationDocuments.length === 0"
                                    class="rounded-md border border-rose-300 bg-rose-50 px-3.5 py-2.5 text-sm font-bold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('rejected')"
                                >
                                    {{ accountAction === 'profile-verification-rejected' ? 'Updating...' : 'Needs replacement' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="Boolean(accountAction) || verificationDocuments.length === 0"
                                    class="rounded-md bg-slate-900 px-3.5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="updateApplicantVerification('approved')"
                                >
                                    {{ accountAction === 'profile-verification-approved' ? 'Verifying...' : 'Verify profile' }}
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
