<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { limitPhoneNumber } from '../../support/phoneNumber';
import ProviderSidebar from './ProviderSidebar.vue';

const isLoading = ref(true);
const isSavingAccount = ref(false);
const isSavingPassword = ref(false);
const loadError = ref('');
const accountMessage = ref({ type: '', text: '' });
const passwordMessage = ref({ type: '', text: '' });
const accountErrors = ref({});
const passwordErrors = ref({});
const account = ref(null);
const accountForm = reactive({
    first_name: '',
    last_name: '',
    middle_initial: '',
    email: '',
    username: '',
    contact_number: '',
    current_password: '',
});
const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const labelClass = 'text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500';
const inputClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3.5 text-sm text-slate-950 outline-none focus:border-slate-950';
const initials = computed(() => String(account.value?.name || 'Provider')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join(''));

function applyAccount(payload) {
    account.value = payload;
    accountForm.first_name = payload?.first_name ?? '';
    accountForm.last_name = payload?.last_name ?? '';
    accountForm.middle_initial = payload?.middle_initial ?? '';
    accountForm.email = payload?.email ?? '';
    accountForm.username = payload?.username ?? '';
    accountForm.contact_number = payload?.contact_number ?? '';
}

function fieldError(errors, field) {
    return errors?.[field]?.[0] ?? '';
}

function messageClass(type) {
    return type === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-rose-200 bg-rose-50 text-rose-800';
}

async function loadAccount() {
    isLoading.value = true;
    loadError.value = '';

    try {
        const response = await window.axios.get('/provider/account/data');
        applyAccount(response.data.account);
    } catch (error) {
        loadError.value = error.response?.data?.message ?? 'Your account could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAccount() {
    isSavingAccount.value = true;
    accountErrors.value = {};
    accountMessage.value = { type: '', text: '' };

    try {
        const response = await window.axios.patch('/provider/account', { ...accountForm });
        applyAccount(response.data.account);
        accountForm.current_password = '';
        accountMessage.value = { type: 'success', text: response.data.message };
    } catch (error) {
        accountErrors.value = error.response?.data?.errors ?? {};
        accountMessage.value = {
            type: 'error',
            text: error.response?.data?.message ?? 'Your account changes could not be saved.',
        };
    } finally {
        isSavingAccount.value = false;
    }
}

async function savePassword() {
    isSavingPassword.value = true;
    passwordErrors.value = {};
    passwordMessage.value = { type: '', text: '' };

    try {
        const response = await window.axios.patch('/provider/account/password', { ...passwordForm });
        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        passwordMessage.value = { type: 'success', text: response.data.message };
    } catch (error) {
        passwordErrors.value = error.response?.data?.errors ?? {};
        passwordMessage.value = {
            type: 'error',
            text: error.response?.data?.message ?? 'Your password could not be changed.',
        };
    } finally {
        isSavingPassword.value = false;
    }
}

onMounted(loadAccount);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page provider-page" aria-labelledby="provider-account-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true"><i class="fa-solid fa-user-gear"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Personal account</p>
                                <h1 id="provider-account-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">My profile</h1>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Manage your own identity and sign-in details without changing the organization profile.</p>
                            </div>
                        </div>
                        <span v-if="account" class="inline-flex items-center gap-2 border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700"><i class="fa-solid fa-shield-halved text-slate-400" aria-hidden="true"></i>{{ account.role_label }}</span>
                    </div>
                </header>

                <div v-if="loadError" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ loadError }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm font-semibold text-slate-500 shadow-sm">Loading your account...</div>

                <template v-else-if="account && !loadError">
                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="account-identity-title">
                        <header class="flex items-center justify-between gap-8 border-b border-slate-200 px-6 py-5">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-sm font-black text-amber-300">{{ initials }}</span>
                                <div class="min-w-0">
                                    <h2 id="account-identity-title" class="truncate text-xl font-bold text-slate-950">{{ account.name }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ account.organization_name }}</p>
                                </div>
                            </div>
                            <span :class="['inline-flex items-center gap-2 border px-3 py-2 text-xs font-bold', account.email_verified ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800']"><i :class="account.email_verified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" aria-hidden="true"></i>{{ account.email_verified ? 'Email verified' : 'Email verification required' }}</span>
                        </header>

                        <p v-if="accountMessage.text" :class="['m-6 border px-4 py-3 text-sm font-semibold', messageClass(accountMessage.type)]" role="status">{{ accountMessage.text }}</p>

                        <form @submit.prevent="saveAccount">
                            <section class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-id-card text-sm" aria-hidden="true"></i></span>
                                    <div><h3 class="text-base font-bold text-slate-950">Personal identity</h3><p class="mt-1 text-xs text-slate-500">Shown to other authorized members of your provider team.</p></div>
                                </div>
                                <div class="mt-5 grid grid-cols-[1fr_8rem_1fr] gap-5">
                                    <label><span :class="labelClass">First name</span><input v-model="accountForm.first_name" required type="text" autocomplete="given-name" :class="inputClass"><span v-if="fieldError(accountErrors, 'first_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'first_name') }}</span></label>
                                    <label><span :class="labelClass">M.I.</span><input v-model="accountForm.middle_initial" maxlength="1" type="text" :class="inputClass"><span v-if="fieldError(accountErrors, 'middle_initial')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'middle_initial') }}</span></label>
                                    <label><span :class="labelClass">Last name</span><input v-model="accountForm.last_name" required type="text" autocomplete="family-name" :class="inputClass"><span v-if="fieldError(accountErrors, 'last_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'last_name') }}</span></label>
                                </div>
                            </section>

                            <section class="border-t border-slate-200 px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-at text-sm" aria-hidden="true"></i></span>
                                    <div><h3 class="text-base font-bold text-slate-950">Sign-in and contact</h3><p class="mt-1 text-xs text-slate-500">Your username or email can be used to sign in.</p></div>
                                </div>
                                <div class="mt-5 grid grid-cols-3 gap-5">
                                    <label><span :class="labelClass">Email address</span><input v-model="accountForm.email" required type="email" autocomplete="email" :class="inputClass"><span v-if="fieldError(accountErrors, 'email')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'email') }}</span></label>
                                    <label><span :class="labelClass">Username</span><input v-model="accountForm.username" required type="text" autocomplete="username" :class="inputClass"><span v-if="fieldError(accountErrors, 'username')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'username') }}</span></label>
                                    <label><span :class="labelClass">Contact number</span><input :value="accountForm.contact_number" required type="tel" inputmode="tel" autocomplete="tel" maxlength="20" :class="inputClass" @input="accountForm.contact_number = limitPhoneNumber($event.target.value)"><span v-if="fieldError(accountErrors, 'contact_number')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'contact_number') }}</span></label>
                                </div>
                            </section>

                            <footer class="flex items-end justify-between gap-8 border-t border-slate-200 bg-slate-50 px-6 py-5">
                                <label class="w-full max-w-md"><span :class="labelClass">Current password to confirm changes</span><input v-model="accountForm.current_password" required type="password" autocomplete="current-password" :class="inputClass"><span v-if="fieldError(accountErrors, 'current_password')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(accountErrors, 'current_password') }}</span></label>
                                <button type="submit" :disabled="isSavingAccount" class="inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"><i class="fa-solid fa-floppy-disk text-xs text-amber-300" aria-hidden="true"></i>{{ isSavingAccount ? 'Saving...' : 'Save account' }}</button>
                            </footer>
                        </form>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="account-security-title">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-key text-sm" aria-hidden="true"></i></span>
                            <div><h2 id="account-security-title" class="text-base font-bold text-slate-950">Password security</h2><p class="mt-1 text-xs text-slate-500">Use at least eight characters and do not share your password.</p></div>
                        </header>

                        <p v-if="passwordMessage.text" :class="['mx-6 mt-5 border px-4 py-3 text-sm font-semibold', messageClass(passwordMessage.type)]" role="status">{{ passwordMessage.text }}</p>

                        <form class="px-6 py-5" @submit.prevent="savePassword">
                            <div class="grid grid-cols-3 gap-5">
                                <label><span :class="labelClass">Current password</span><input v-model="passwordForm.current_password" required type="password" autocomplete="current-password" :class="inputClass"><span v-if="fieldError(passwordErrors, 'current_password')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(passwordErrors, 'current_password') }}</span></label>
                                <label><span :class="labelClass">New password</span><input v-model="passwordForm.password" required minlength="8" type="password" autocomplete="new-password" :class="inputClass"><span v-if="fieldError(passwordErrors, 'password')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError(passwordErrors, 'password') }}</span></label>
                                <label><span :class="labelClass">Confirm new password</span><input v-model="passwordForm.password_confirmation" required minlength="8" type="password" autocomplete="new-password" :class="inputClass"></label>
                            </div>
                            <div class="mt-5 flex justify-end"><button type="submit" :disabled="isSavingPassword" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:border-slate-950 disabled:cursor-not-allowed disabled:opacity-50"><i class="fa-solid fa-shield-halved text-xs" aria-hidden="true"></i>{{ isSavingPassword ? 'Updating...' : 'Update password' }}</button></div>
                        </form>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
