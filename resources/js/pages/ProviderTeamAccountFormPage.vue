<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { limitPhoneNumber } from '../support/phoneNumber';

const accountId = window.location.pathname.match(/\/provider\/team\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(Boolean(accountId));
const isSaving = ref(false);
const errorMessage = ref('');
const formElement = ref(null);
const availablePrograms = ref([]);
const canAssignAllPrograms = ref(true);

const allPermissions = [
    { value: 'manage_programs', label: 'Manage programs', description: 'Create, edit, duplicate, and submit scholarship programs.' },
    { value: 'review_applications', label: 'Review applicants', description: 'Open applicant records, review files, and record decisions.' },
    { value: 'manage_reports', label: 'Manage reported issues', description: 'Review and resolve applicant concerns about your programs.' },
    { value: 'manage_profile', label: 'Manage organization profile', description: 'Update provider details and verification documents.' },
    { value: 'manage_team', label: 'Manage team accounts', description: 'Create and maintain other provider staff accounts.' },
    { value: 'manage_billing', label: 'Manage optional services', description: 'Start provider service payments and review order status.' },
];
const availablePermissions = computed(() => allPermissions.filter((permission) => (
    window.portalUser?.has_full_access || window.portalUser?.permissions?.includes(permission.value)
)));
const roleOptions = [
    { value: 'manager', label: 'Manager', description: 'Oversees the provider workspace and team.' },
    { value: 'program_coordinator', label: 'Program coordinator', description: 'Creates and maintains scholarship programs.' },
    { value: 'application_reviewer', label: 'Application reviewer', description: 'Reviews applicant records and decisions.' },
    { value: 'support_staff', label: 'Support staff', description: 'Handles applicant concerns and reports.' },
    { value: 'billing_staff', label: 'Billing staff', description: 'Manages optional provider service purchases.' },
    { value: 'custom', label: 'Custom role', description: 'Build a role by selecting permissions manually.' },
];
const rolePresets = {
    manager: ['manage_programs', 'review_applications', 'manage_reports', 'manage_profile', 'manage_team', 'manage_billing'],
    program_coordinator: ['manage_programs'],
    application_reviewer: ['review_applications'],
    support_staff: ['manage_reports'],
    billing_staff: ['manage_billing'],
    custom: [],
};
const assignableRoleOptions = computed(() => {
    const allowed = availablePermissions.value.map((permission) => permission.value);

    return roleOptions.filter((role) => (
        role.value === 'custom'
        || (rolePresets[role.value] ?? []).every((permission) => allowed.includes(permission))
    ));
});
const selectedRoleOption = computed(() => roleOptions.find((role) => role.value === form.value.accountTitle));
const permissionsLocked = computed(() => form.value.accountTitle !== 'custom');
const selectedPermissions = computed(() => availablePermissions.value.filter((permission) => (
    form.value.permissions.includes(permission.value)
)));
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
        programAccessMode: 'all',
        assignedProgramIds: [],
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
    form.value.contactNumber = limitPhoneNumber(event.target.value);
}

async function loadAccount() {
    isLoading.value = true;

    try {
        const response = await window.axios.get(accountId
            ? `/provider/team/accounts/${accountId}`
            : '/provider/team/data');
        availablePrograms.value = response.data.available_programs ?? [];
        canAssignAllPrograms.value = response.data.can_assign_all_programs !== false;

        if (!accountId) {
            if (!assignableRoleOptions.value.some((role) => role.value === form.value.accountTitle)) {
                form.value.accountTitle = assignableRoleOptions.value.find((role) => role.value !== 'custom')?.value ?? 'custom';
            }
            if (!canAssignAllPrograms.value) {
                form.value.programAccessMode = 'selected';
            }
            applyRolePreset();
            return;
        }

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
            programAccessMode: account.program_access_mode ?? 'all',
            assignedProgramIds: [...(account.assigned_program_ids ?? [])],
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

    if (form.value.programAccessMode === 'selected' && !form.value.assignedProgramIds.length) {
        errorMessage.value = 'Select at least one program or allow access to all programs.';
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
        program_access_mode: form.value.programAccessMode,
        assigned_program_ids: form.value.programAccessMode === 'selected'
            ? form.value.assignedProgramIds
            : [],
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
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <TaskPageHeader
                    theme="provider"
                    eyebrow="Team and access"
                    :title="isEditMode ? 'Edit team member' : 'Add team member'"
                    :description="isEditMode ? 'Update this member\'s account, workspace role, and program access.' : 'Create a delegated account without sharing the provider representative login.'"
                    icon="fa-solid fa-user-shield"
                    secondary-href="/provider/team"
                    secondary-label="Back to team"
                >
                    <template #meta>
                        <span>{{ isEditMode ? 'Existing delegated account' : 'New delegated account' }}</span>
                        <span>{{ form.permissions.length }} permission{{ form.permissions.length === 1 ? '' : 's' }}</span>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">Loading account...</div>

                <form v-else ref="formElement" class="mt-4 grid gap-4" @submit.prevent="saveAccount">
                    <section class="provider-panel overflow-hidden">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="font-bold text-slate-950">Account details</h2>
                            <p class="mt-1 text-sm text-slate-500">Identity and contact information for this staff member.</p>
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
                                        maxlength="11"
                                        required
                                        placeholder="09XX XXX XXXX"
                                        :class="inputClass"
                                        @input="handleContactNumber"
                                    >
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="provider-panel overflow-hidden">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="font-bold text-slate-950">Role and access</h2>
                            <p class="mt-1 text-sm text-slate-500">Choose the work this member can perform and the programs they can open.</p>
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
                                    <option v-for="role in assignableRoleOptions" :key="role.value" :value="role.value">
                                        {{ role.label }}
                                    </option>
                                </select>
                                <p class="mt-2 text-xs text-slate-500">{{ selectedRoleOption?.description }}</p>
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

                                <div v-if="permissionsLocked" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="permission in selectedPermissions"
                                            :key="permission.value"
                                            class="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700"
                                        >
                                            {{ permission.label }}
                                        </span>
                                    </div>
                                </div>

                                <div v-else class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    <label
                                        v-for="permission in availablePermissions"
                                        :key="permission.value"
                                        :class="[
                                            'flex items-center gap-3 rounded-md border p-3 transition',
                                            'cursor-pointer',
                                            form.permissions.includes(permission.value)
                                                ? 'border-amber-400 bg-amber-50'
                                                : 'border-slate-200 bg-slate-50 hover:border-slate-300',
                                        ]"
                                    >
                                        <input
                                            v-model="form.permissions"
                                            type="checkbox"
                                            :value="permission.value"
                                            class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-amber-400"
                                        >
                                        <span class="text-sm font-bold text-slate-900">{{ permission.label }}</span>
                                    </label>
                                </div>
                                <p v-if="form.permissions.length === 0" class="mt-2 text-xs font-semibold text-rose-700">Select at least one permission.</p>
                            </div>

                            <div class="mt-5 border-t border-slate-200 pt-5">
                                <div>
                                    <p class="text-sm font-bold text-slate-900">Program access</p>
                                </div>

                                <div class="mt-3 grid gap-2 md:grid-cols-2">
                                    <label
                                        v-for="option in [
                                            { value: 'all', label: 'All organization programs', detail: 'Includes programs created later.' },
                                            { value: 'selected', label: 'Selected programs only', detail: 'Access stays limited to the programs checked below.' },
                                        ]"
                                        :key="option.value"
                                        :class="[
                                            'flex cursor-pointer items-start gap-3 rounded-md border p-3 transition',
                                            option.value === 'all' && !canAssignAllPrograms ? 'cursor-not-allowed opacity-50' : '',
                                            form.programAccessMode === option.value
                                                ? 'border-amber-400 bg-amber-50'
                                                : 'border-slate-200 bg-slate-50 hover:border-slate-300',
                                        ]"
                                    >
                                        <input
                                            v-model="form.programAccessMode"
                                            type="radio"
                                            :value="option.value"
                                            :disabled="option.value === 'all' && !canAssignAllPrograms"
                                            class="mt-1 h-4 w-4 border-slate-300 text-slate-900 focus:ring-amber-400 disabled:cursor-not-allowed"
                                        >
                                        <span>
                                            <span class="block text-sm font-bold text-slate-900">{{ option.label }}</span>
                                            <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ option.detail }}</span>
                                        </span>
                                    </label>
                                </div>

                                <p v-if="!canAssignAllPrograms" class="mt-2 text-xs font-semibold text-slate-500">
                                    You can delegate only the programs assigned to your account.
                                </p>

                                <div v-if="form.programAccessMode === 'selected'" class="mt-3 overflow-hidden rounded-md border border-slate-200 bg-white">
                                    <label
                                        v-for="program in availablePrograms"
                                        :key="program.id"
                                        class="flex cursor-pointer items-center gap-3 border-b border-slate-200 px-4 py-3 last:border-b-0 hover:bg-slate-50"
                                    >
                                        <input v-model="form.assignedProgramIds" type="checkbox" :value="program.id" class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-amber-400">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-bold text-slate-900">{{ program.title }}</span>
                                            <span class="mt-0.5 block text-xs font-semibold capitalize text-slate-500">{{ program.status }}</span>
                                        </span>
                                    </label>
                                    <p v-if="availablePrograms.length === 0" class="px-4 py-4 text-sm text-slate-500">Create a program before limiting this account to selected programs.</p>
                                </div>
                                <p v-if="form.programAccessMode === 'selected' && form.assignedProgramIds.length === 0" class="mt-2 text-xs font-semibold text-rose-700">Select at least one program.</p>
                            </div>
                        </div>
                    </section>

                    <section class="provider-panel overflow-hidden">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="font-bold text-slate-950">{{ isEditMode ? 'Password reset' : 'Temporary password' }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ isEditMode ? 'Leave both fields blank to keep the current password.' : 'The member verifies their email before choosing a new password.' }}</p>
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
                        <p v-if="!isEditMode" class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6">Share the temporary password separately; it is not included in the welcome email.</p>
                    </section>

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                        <span v-else class="text-xs font-semibold text-slate-500">The member can only receive access available to your account.</span>
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
