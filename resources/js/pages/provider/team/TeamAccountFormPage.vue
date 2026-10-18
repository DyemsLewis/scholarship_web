<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const accountId = window.location.pathname.match(/\/provider\/team\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const errors = ref({});
const step = ref(1);
const availableRoles = ref([]);
const permissionCatalog = ref([]);
const rolePresets = ref({});
const availablePrograms = ref([]);
const canAssignAllPrograms = ref(true);
const form = reactive({
    first_name: '',
    last_name: '',
    middle_initial: '',
    email: '',
    username: '',
    contact_number: '',
    account_title: '',
    permissions: [],
    program_access_mode: 'all',
    assigned_program_ids: [],
    password: '',
    password_confirmation: '',
});
const steps = [
    { number: 1, label: 'Account details' },
    { number: 2, label: 'Workspace role' },
    { number: 3, label: 'Program access' },
];
const fieldClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none focus:border-slate-950';
const labelClass = 'text-sm font-bold text-slate-800';

const selectedRole = computed(() => availableRoles.value.find((role) => role.value === form.account_title));
const selectedPermissions = computed(() => permissionCatalog.value.filter((permission) => form.permissions.includes(permission.value)));
const selectedPrograms = computed(() => availablePrograms.value.filter((program) => form.assigned_program_ids.includes(program.id)));
const isCustomRole = computed(() => form.account_title === 'custom');

function firstError(field) {
    return errors.value?.[field]?.[0] ?? '';
}

function applyConfiguration(data) {
    availableRoles.value = data.available_roles ?? [];
    permissionCatalog.value = data.permission_catalog ?? [];
    rolePresets.value = data.role_presets ?? {};
    availablePrograms.value = data.available_programs ?? [];
    canAssignAllPrograms.value = Boolean(data.can_assign_all_programs);

    if (!canAssignAllPrograms.value) form.program_access_mode = 'selected';
}

function applyAccount(account) {
    form.first_name = account.first_name ?? '';
    form.last_name = account.last_name ?? '';
    form.middle_initial = account.middle_initial ?? '';
    form.email = account.email ?? '';
    form.username = account.username ?? '';
    form.contact_number = account.contact_number ?? '';
    form.account_title = account.team_role ?? '';
    form.permissions = [...(account.permissions ?? [])];
    form.program_access_mode = account.program_access_mode ?? 'all';
    form.assigned_program_ids = [...(account.assigned_program_ids ?? [])];
}

function applyRolePreset() {
    if (isCustomRole.value) {
        form.permissions = form.permissions.filter((permission) => permissionCatalog.value.some((item) => item.value === permission));
        return;
    }

    form.permissions = [...(rolePresets.value?.[form.account_title] ?? [])];
}

function togglePermission(value) {
    form.permissions = form.permissions.includes(value)
        ? form.permissions.filter((permission) => permission !== value)
        : [...form.permissions, value];
}

function toggleProgram(id) {
    form.assigned_program_ids = form.assigned_program_ids.includes(id)
        ? form.assigned_program_ids.filter((programId) => programId !== id)
        : [...form.assigned_program_ids, id];
}

function validateStep(currentStep) {
    errorMessage.value = '';
    if (currentStep === 1) {
        const required = ['first_name', 'last_name', 'middle_initial', 'email', 'username', 'contact_number'];
        if (required.some((field) => !String(form[field]).trim())) {
            errorMessage.value = 'Complete all required account details.';
            return false;
        }
        if (!isEditMode.value && (!form.password || form.password.length < 8)) {
            errorMessage.value = 'Create a temporary password with at least 8 characters.';
            return false;
        }
        if ((form.password || form.password_confirmation) && form.password !== form.password_confirmation) {
            errorMessage.value = 'The password confirmation does not match.';
            return false;
        }
    }
    if (currentStep === 2 && (!form.account_title || !form.permissions.length)) {
        errorMessage.value = 'Choose a role with at least one responsibility.';
        return false;
    }
    if (currentStep === 3 && form.program_access_mode === 'selected' && !form.assigned_program_ids.length) {
        errorMessage.value = 'Select at least one program for this account.';
        return false;
    }
    return true;
}

function nextStep() {
    if (!validateStep(step.value)) return;
    step.value = Math.min(3, step.value + 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function previousStep() {
    errorMessage.value = '';
    step.value = Math.max(1, step.value - 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function loadAccount() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(accountId ? `/provider/team/accounts/${accountId}` : '/provider/team/data');
        applyConfiguration(response.data);
        if (response.data.account) {
            applyAccount(response.data.account);
        } else {
            form.account_title = availableRoles.value[0]?.value ?? 'custom';
            applyRolePreset();
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The team account form could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAccount() {
    if (![1, 2, 3].every(validateStep)) {
        if (!String(form.first_name).trim()) step.value = 1;
        else if (!form.account_title || !form.permissions.length) step.value = 2;
        else step.value = 3;
        return;
    }
    isSaving.value = true;
    errors.value = {};
    errorMessage.value = '';
    const payload = {
        first_name: form.first_name,
        last_name: form.last_name,
        middle_initial: form.middle_initial,
        email: form.email,
        username: form.username,
        contact_number: form.contact_number,
        account_title: form.account_title,
        permissions: form.permissions,
        program_access_mode: form.program_access_mode,
        assigned_program_ids: form.program_access_mode === 'selected' ? form.assigned_program_ids : [],
    };
    if (!isEditMode.value || form.password || form.password_confirmation) {
        payload.password = form.password;
        payload.password_confirmation = form.password_confirmation;
    }
    try {
        await (isEditMode.value
            ? window.axios.patch(`/provider/team/accounts/${accountId}`, payload)
            : window.axios.post('/provider/team/accounts', payload));
        window.location.href = isEditMode.value ? '/provider/workspaces/team/active' : '/provider/workspaces/team';
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message || 'The team account could not be saved.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadAccount);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="team-account-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/team" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to team access</a>

                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8"><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Team access</p><h1 id="team-account-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ isEditMode ? 'Edit team member' : 'Add team member' }}</h1><p class="mt-2 text-sm leading-6 text-slate-600">{{ isEditMode ? 'Update this account without changing unrelated staff access.' : 'Create a separate login with one clear role and appropriate program access.' }}</p></div><span class="border border-slate-300 bg-slate-100 px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-slate-700">Step {{ step }} of 3</span></div>
                </header>

                <nav class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Account setup progress">
                    <button v-for="item in steps" :key="item.number" type="button" :disabled="item.number > step" :class="['flex min-h-16 items-center gap-3 border-r border-slate-200 px-5 text-left last:border-r-0', item.number === step ? 'bg-slate-950 text-white' : item.number < step ? 'bg-white text-slate-800' : 'cursor-not-allowed bg-slate-50 text-slate-400']" @click="item.number < step && (step = item.number)"><span :class="['grid h-8 w-8 place-items-center text-xs font-black', item.number === step ? 'bg-amber-300 text-slate-950' : item.number < step ? 'bg-slate-950 text-white' : 'bg-slate-200 text-slate-500']">{{ item.number < step ? '✓' : item.number }}</span><span class="text-sm font-bold">{{ item.label }}</span></button>
                </nav>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading account setup...</div>

                <form v-else class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="saveAccount">
                    <section v-if="step === 1" aria-labelledby="account-details-heading">
                        <header class="border-b border-slate-200 px-6 py-5"><h2 id="account-details-heading" class="text-lg font-bold text-slate-950">Account details</h2><p class="mt-1 text-sm text-slate-500">Use the staff member's own contact details and login name.</p></header>
                        <div class="grid grid-cols-3 gap-5 px-6 py-6">
                            <label><span :class="labelClass">First name</span><input v-model="form.first_name" type="text" :class="fieldClass"><span v-if="firstError('first_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('first_name') }}</span></label>
                            <label><span :class="labelClass">Middle initial</span><input v-model="form.middle_initial" type="text" maxlength="1" :class="fieldClass" @input="form.middle_initial = form.middle_initial.replace(/[^a-z]/gi, '').slice(0, 1).toUpperCase()"></label>
                            <label><span :class="labelClass">Last name</span><input v-model="form.last_name" type="text" :class="fieldClass"><span v-if="firstError('last_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('last_name') }}</span></label>
                            <label><span :class="labelClass">Email</span><input v-model="form.email" type="email" :class="fieldClass"><span v-if="firstError('email')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('email') }}</span></label>
                            <label><span :class="labelClass">Username</span><input v-model="form.username" type="text" :class="fieldClass"><span v-if="firstError('username')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('username') }}</span></label>
                            <label><span :class="labelClass">Contact number</span><input v-model="form.contact_number" type="tel" :class="fieldClass" placeholder="09XX XXX XXXX"><span v-if="firstError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('contact_number') }}</span></label>
                            <label><span :class="labelClass">{{ isEditMode ? 'New password (optional)' : 'Temporary password' }}</span><input v-model="form.password" type="password" :class="fieldClass" autocomplete="new-password"><span v-if="firstError('password')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('password') }}</span></label>
                            <label><span :class="labelClass">Confirm password</span><input v-model="form.password_confirmation" type="password" :class="fieldClass" autocomplete="new-password"></label>
                            <div class="self-end border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-xs leading-5 text-slate-600">New staff must verify their email and replace the temporary password before entering a workspace.</div>
                        </div>
                    </section>

                    <section v-else-if="step === 2" aria-labelledby="workspace-role-heading">
                        <header class="border-b border-slate-200 px-6 py-5"><h2 id="workspace-role-heading" class="text-lg font-bold text-slate-950">Workspace role</h2><p class="mt-1 text-sm text-slate-500">Choose one role that matches the staff member's actual responsibility.</p></header>
                        <div class="px-6 py-6">
                            <label class="block max-w-xl"><span :class="labelClass">Assigned role</span><select v-model="form.account_title" :class="fieldClass" @change="applyRolePreset"><option value="">Select a role</option><option v-for="role in availableRoles" :key="role.value" :value="role.value">{{ role.label }}</option></select><span v-if="firstError('account_title')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('account_title') }}</span></label>

                            <div class="mt-6 border border-slate-200">
                                <header class="border-b border-slate-200 bg-slate-50 px-5 py-4"><h3 class="text-sm font-bold text-slate-950">Assigned responsibilities</h3><p class="mt-1 text-xs text-slate-500">{{ isCustomRole ? 'Select only the workspaces this custom role needs.' : `${selectedRole?.label || 'This role'} receives its standard workspace access.` }}</p></header>
                                <div v-if="!permissionCatalog.length" class="px-5 py-10 text-center text-sm text-slate-500">No responsibilities are available to assign.</div>
                                <div v-else class="grid grid-cols-2 divide-x divide-y divide-slate-200">
                                    <label v-for="permission in permissionCatalog" :key="permission.value" :class="['flex min-h-24 items-start gap-3 px-5 py-4', !isCustomRole && !form.permissions.includes(permission.value) ? 'bg-slate-50 opacity-45' : 'bg-white', isCustomRole ? 'cursor-pointer' : 'cursor-default']"><input type="checkbox" class="mt-1 h-4 w-4 accent-slate-950" :checked="form.permissions.includes(permission.value)" :disabled="!isCustomRole" @change="togglePermission(permission.value)"><span><span class="block text-sm font-bold text-slate-900">{{ permission.label }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ permission.description }}</span></span></label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section v-else aria-labelledby="program-access-heading">
                        <header class="border-b border-slate-200 px-6 py-5"><h2 id="program-access-heading" class="text-lg font-bold text-slate-950">Program access</h2><p class="mt-1 text-sm text-slate-500">Limit which scholarship records are visible inside the assigned workspace.</p></header>
                        <div class="px-6 py-6">
                            <div class="grid grid-cols-2 border border-slate-200">
                                <label :class="['flex cursor-pointer items-start gap-3 border-r border-slate-200 px-5 py-4', form.program_access_mode === 'all' ? 'bg-amber-50' : 'bg-white', !canAssignAllPrograms ? 'cursor-not-allowed opacity-50' : '']"><input v-model="form.program_access_mode" type="radio" value="all" class="mt-1 h-4 w-4 accent-slate-950" :disabled="!canAssignAllPrograms"><span><span class="block text-sm font-bold text-slate-950">All organization programs</span><span class="mt-1 block text-xs text-slate-500">Use when this staff member works across the full program portfolio.</span></span></label>
                                <label :class="['flex cursor-pointer items-start gap-3 px-5 py-4', form.program_access_mode === 'selected' ? 'bg-amber-50' : 'bg-white']"><input v-model="form.program_access_mode" type="radio" value="selected" class="mt-1 h-4 w-4 accent-slate-950"><span><span class="block text-sm font-bold text-slate-950">Selected programs only</span><span class="mt-1 block text-xs text-slate-500">Restrict this account to specific scholarship programs.</span></span></label>
                            </div>

                            <div v-if="form.program_access_mode === 'selected'" class="mt-5 border border-slate-200">
                                <header class="border-b border-slate-200 bg-slate-50 px-5 py-3"><p class="text-sm font-bold text-slate-900">Choose programs</p></header>
                                <div v-if="!availablePrograms.length" class="px-5 py-10 text-center text-sm text-slate-500">No programs are available within your access scope.</div>
                                <div v-else class="divide-y divide-slate-200">
                                    <label v-for="program in availablePrograms" :key="program.id" class="flex cursor-pointer items-center gap-3 px-5 py-3.5 hover:bg-slate-50"><input type="checkbox" class="h-4 w-4 accent-slate-950" :checked="form.assigned_program_ids.includes(program.id)" @change="toggleProgram(program.id)"><span class="min-w-0 flex-1 truncate text-sm font-bold text-slate-900">{{ program.title }}</span><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ program.status }}</span></label>
                                </div>
                            </div>

                            <section class="mt-5 border border-slate-300 bg-slate-50 px-5 py-4" aria-labelledby="access-summary-heading"><h3 id="access-summary-heading" class="text-sm font-bold text-slate-950">Access summary</h3><p class="mt-2 text-sm text-slate-600"><strong>{{ selectedRole?.label || 'No role selected' }}</strong> · {{ selectedPermissions.length }} responsibility area{{ selectedPermissions.length === 1 ? '' : 's' }} · {{ form.program_access_mode === 'all' ? 'All programs' : `${selectedPrograms.length} selected program${selectedPrograms.length === 1 ? '' : 's'}` }}</p></section>
                        </div>
                    </section>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4"><button v-if="step > 1" type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" @click="previousStep">Previous</button><span v-else class="text-xs font-semibold text-slate-500">Only the selected role and program records will be available.</span><button v-if="step < 3" type="button" class="h-10 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="nextStep">Continue</button><button v-else type="submit" :disabled="isSaving" class="h-10 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50">{{ isSaving ? 'Saving...' : isEditMode ? 'Save account access' : 'Create team account' }}</button></footer>
                </form>
            </div>
        </section>
    </main>
</template>
