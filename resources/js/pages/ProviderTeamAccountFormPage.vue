<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const accountId = window.location.pathname.match(/\/provider\/team\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(Boolean(accountId));
const isSaving = ref(false);
const errorMessage = ref('');
const formElement = ref(null);

const allPermissions = [
    { value: 'manage_programs', label: 'Manage programs', description: 'Create, edit, duplicate, and submit scholarship programs.' },
    { value: 'review_applications', label: 'Review applications', description: 'Open applicant records, review files, and record decisions.' },
    { value: 'manage_reports', label: 'Manage reports', description: 'Review and resolve applicant concerns about your programs.' },
    { value: 'manage_profile', label: 'Manage organization profile', description: 'Update provider details and verification documents.' },
    { value: 'manage_team', label: 'Manage team accounts', description: 'Create and maintain other provider staff accounts.' },
];
const availablePermissions = computed(() => allPermissions.filter((permission) => (
    window.portalUser?.has_full_access || window.portalUser?.permissions?.includes(permission.value)
)));
const roleOptions = [
    { value: 'manager', label: 'Manager', description: 'Oversees the provider workspace and team.' },
    { value: 'program_coordinator', label: 'Program coordinator', description: 'Creates and maintains scholarship programs.' },
    { value: 'application_reviewer', label: 'Application reviewer', description: 'Reviews applicant records and decisions.' },
    { value: 'support_staff', label: 'Support staff', description: 'Handles applicant concerns and reports.' },
    { value: 'custom', label: 'Custom role', description: 'Build a role by selecting permissions manually.' },
];
const rolePresets = {
    manager: ['manage_programs', 'review_applications', 'manage_reports', 'manage_profile', 'manage_team'],
    program_coordinator: ['manage_programs'],
    application_reviewer: ['review_applications'],
    support_staff: ['manage_reports'],
    custom: [],
};
const selectedRoleOption = computed(() => roleOptions.find((role) => role.value === form.value.accountTitle));
const permissionsLocked = computed(() => form.value.accountTitle !== 'custom');
const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm uppercase text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';

const form = ref(emptyForm());

function emptyForm() {
    return {
        firstName: '',
        lastName: '',
        middleInitial: '',
        email: '',
        username: '',
        contactNumber: '',
        accountTitle: 'program_coordinator',
        permissions: ['manage_programs'],
        password: '',
        passwordConfirmation: '',
    };
}

function applyRolePreset() {
    const allowed = availablePermissions.value.map((permission) => permission.value);
    form.value.permissions = (rolePresets[form.value.accountTitle] ?? []).filter((permission) => allowed.includes(permission));
}

function handleMiddleInitial(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleContactNumber(event) {
    form.value.contactNumber = event.target.value.replace(/[^\d+\s().-]/g, '');
}

async function loadAccount() {
    if (!accountId) {
        applyRolePreset();
        return;
    }

    isLoading.value = true;

    try {
        const response = await window.axios.get(`/provider/team/accounts/${accountId}`);
        const account = response.data.account;
        form.value = {
            firstName: account.first_name ?? '',
            lastName: account.last_name ?? '',
            middleInitial: account.middle_initial ?? '',
            email: account.email ?? '',
            username: account.username ?? '',
            contactNumber: account.contact_number ?? '',
            accountTitle: account.team_role ?? 'program_coordinator',
            permissions: [...(account.permissions ?? [])],
            password: '',
            passwordConfirmation: '',
        };
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this team account.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAccount() {
    errorMessage.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    if (!form.value.permissions.length) {
        errorMessage.value = 'Select at least one permission.';
        return;
    }

    const hasPassword = Boolean(form.value.password || form.value.passwordConfirmation);

    if ((!isEditMode.value || hasPassword) && form.value.password !== form.value.passwordConfirmation) {
        errorMessage.value = 'Passwords must match.';
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
        account_title: form.value.accountTitle,
        permissions: form.value.permissions,
    };

    if (!isEditMode.value || hasPassword) {
        payload.password = form.value.password;
        payload.password_confirmation = form.value.passwordConfirmation;
    }

    try {
        await (isEditMode.value
            ? window.axios.patch(`/provider/team/accounts/${accountId}`, payload)
            : window.axios.post('/provider/team/accounts', payload));
        window.location.href = '/provider/team';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to save this team account.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadAccount);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Team Access</p>
                            <h1 class="mt-2 font-display text-2xl font-bold text-slate-950">{{ isEditMode ? 'Edit team account' : 'Create team account' }}</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Add the staff member's details, choose a role, and set their provider access.</p>
                        </div>
                        <a href="/provider/team" class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                            <i class="fa-solid fa-arrow-left mr-2" aria-hidden="true"></i>
                            Back to team
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">Loading account...</div>

                <form v-else ref="formElement" class="mt-6 grid gap-5" @submit.prevent="saveAccount">
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-white">
                                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold text-slate-950">Staff identity</h2>
                                    <p class="mt-0.5 text-xs text-slate-500">Contact details shown in the provider workspace.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">01</span>
                        </div>

                        <div class="grid gap-4 p-5 sm:p-6">
                            <div class="grid grid-cols-[minmax(0,1fr)_4.75rem] gap-4 lg:grid-cols-[minmax(0,1fr)_5rem_minmax(0,1fr)] lg:items-end">
                                <div>
                                    <label :class="labelClass" for="team-first-name">First name</label>
                                    <input id="team-first-name" v-model="form.firstName" type="text" autocomplete="given-name" required placeholder="First name" :class="inputClass">
                                </div>

                                <div>
                                    <label :class="[labelClass, 'text-center']" for="team-middle-initial">M.I.</label>
                                    <input
                                        id="team-middle-initial"
                                        :value="form.middleInitial"
                                        type="text"
                                        inputmode="text"
                                        maxlength="1"
                                        pattern="[A-Za-z]"
                                        required
                                        placeholder="M"
                                        :class="compactInputClass"
                                        @input="handleMiddleInitial"
                                    >
                                </div>

                                <div class="col-span-2 lg:col-span-1">
                                    <label :class="labelClass" for="team-last-name">Last name</label>
                                    <input id="team-last-name" v-model="form.lastName" type="text" autocomplete="family-name" required placeholder="Last name" :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="team-email">Email address</label>
                                    <input id="team-email" v-model="form.email" type="email" autocomplete="email" required placeholder="name@example.com" :class="inputClass">
                                </div>

                                <div>
                                    <label :class="labelClass" for="team-username">Username</label>
                                    <input id="team-username" v-model="form.username" type="text" autocomplete="username" minlength="4" pattern="[A-Za-z0-9_.-]+" required placeholder="At least 4 characters" :class="inputClass">
                                </div>

                                <div class="md:col-span-2 xl:col-span-1">
                                    <label :class="labelClass" for="team-contact">Contact number</label>
                                    <input
                                        id="team-contact"
                                        :value="form.contactNumber"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        pattern="[0-9+(). -]{10,30}"
                                        required
                                        placeholder="09XX XXX XXXX"
                                        :class="inputClass"
                                        @input="handleContactNumber"
                                    >
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold text-slate-950">Role and access</h2>
                                    <p class="mt-0.5 text-xs text-slate-500">Start with a role preset, then adjust access if needed.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">02</span>
                        </div>

                        <div class="p-5 sm:p-6">
                            <div class="max-w-xl">
                                <label :class="labelClass" for="team-role">Team role</label>
                                <select
                                    id="team-role"
                                    v-model="form.accountTitle"
                                    required
                                    :class="inputClass"
                                    @change="applyRolePreset"
                                >
                                    <option v-for="role in roleOptions" :key="role.value" :value="role.value">
                                        {{ role.label }}
                                    </option>
                                </select>
                                <p class="mt-2 text-xs leading-5 text-slate-500">
                                    {{ selectedRoleOption?.description }} Recommended permissions are selected automatically and can be adjusted below.
                                </p>
                            </div>

                            <div class="mt-5 border-t border-slate-200 pt-5">
                                <div class="flex items-end justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-bold text-slate-900">Portal permissions</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ permissionsLocked ? 'Permissions are fixed by the selected role. Choose Custom role to set them manually.' : 'Select the permissions this custom role needs.' }}
                                        </p>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ form.permissions.length }} selected</span>
                                </div>

                                <div class="mt-3 grid gap-2 md:grid-cols-2">
                                    <label
                                        v-for="permission in availablePermissions"
                                        :key="permission.value"
                                        :class="[
                                            'flex gap-3 rounded-md border p-3 transition',
                                            permissionsLocked ? 'cursor-not-allowed' : 'cursor-pointer',
                                            form.permissions.includes(permission.value)
                                                ? 'border-amber-400 bg-amber-50'
                                                : 'border-slate-200 bg-slate-50 hover:border-slate-300',
                                        ]"
                                    >
                                        <input
                                            v-model="form.permissions"
                                            type="checkbox"
                                            :value="permission.value"
                                            :disabled="permissionsLocked"
                                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-amber-400"
                                        >
                                        <span>
                                            <span class="block text-sm font-bold text-slate-900">{{ permission.label }}</span>
                                            <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ permission.description }}</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="form.permissions.length === 0" class="mt-2 text-xs font-semibold text-rose-700">Select at least one permission.</p>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-200 text-slate-800">
                                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h2 class="text-base font-bold text-slate-950">Sign-in details</h2>
                                    <p class="mt-0.5 text-xs text-slate-500">Set a temporary password for this staff account.</p>
                                </div>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">03</span>
                        </div>

                        <div class="grid gap-4 p-5 md:grid-cols-2 sm:p-6">
                            <div>
                                <label :class="labelClass" for="team-password">{{ isEditMode ? 'New password (optional)' : 'Temporary password' }}</label>
                                <input
                                    id="team-password"
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
                                <label :class="labelClass" for="team-password-confirmation">Confirm password</label>
                                <input
                                    id="team-password-confirmation"
                                    v-model="form.passwordConfirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    :required="!isEditMode || Boolean(form.password)"
                                    placeholder="Enter the password again"
                                    :class="inputClass"
                                >
                            </div>
                        </div>
                        <p v-if="!isEditMode" class="border-t border-slate-200 bg-amber-50 px-5 py-3 text-xs leading-5 text-amber-900 sm:px-6">
                            A welcome email will include the username and sign-in link. Share the temporary password separately. Staff can update their email, username, and contact details in Profile after signing in.
                        </p>
                    </section>

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <p class="min-h-5 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                        <button type="submit" :disabled="isSaving" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-70">
                            {{ isSaving ? 'Saving...' : isEditMode ? 'Update account' : 'Create account' }}
                        </button>
                    </div>
                </form>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
