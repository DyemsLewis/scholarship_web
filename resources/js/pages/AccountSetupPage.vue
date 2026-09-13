<script setup>
import { computed, onMounted, ref } from 'vue';
import SiteFooter from '../components/SiteFooter.vue';
import ToastMessage from '../components/ToastMessage.vue';

const user = ref(null);
const loading = ref(true);
const resending = ref(false);
const checking = ref(false);
const saving = ref(false);
const showPassword = ref(false);
const showConfirmation = ref(false);
const form = ref({ password: '', password_confirmation: '' });
const errors = ref({});
const toast = ref({ show: false, type: 'success', title: '', message: '' });

const emailVerified = computed(() => Boolean(user.value?.email_verified));
const passwordComplete = computed(() => emailVerified.value && !user.value?.must_reset_password);
const workspaceLabel = computed(() => user.value?.role === 'admin' ? 'admin workspace' : 'provider workspace');

function notify(type, title, message) {
    toast.value = { show: true, type, title, message };
}

async function loadSetup() {
    try {
        const response = await window.axios.get('/account/setup/data');
        user.value = response.data.user;

        if (passwordComplete.value) {
            window.location.replace(user.value.role === 'admin' ? '/admin' : '/provider');
        }
    } catch (error) {
        notify('error', 'Unable to load account', error.response?.data?.message ?? 'Refresh the page and try again.');
    } finally {
        loading.value = false;
    }
}

async function resendVerification() {
    resending.value = true;

    try {
        const response = await window.axios.post('/email/verification-notification');
        notify(
            response.data.email_verification_sent ? 'success' : 'error',
            response.data.email_verification_sent ? 'Verification email sent' : 'Email not sent',
            response.data.message,
        );
    } catch (error) {
        notify('error', 'Unable to resend', error.response?.data?.message ?? 'Please wait and try again.');
    } finally {
        resending.value = false;
    }
}

async function checkVerification() {
    checking.value = true;

    try {
        await loadSetup();
        notify(
            emailVerified.value ? 'success' : 'info',
            emailVerified.value ? 'Email verified' : 'Still waiting for verification',
            emailVerified.value
                ? 'Create a new password to finish your account setup.'
                : 'Open the verification link sent to your email, then check again.',
        );
    } finally {
        checking.value = false;
    }
}

async function savePassword() {
    errors.value = {};
    saving.value = true;

    try {
        const response = await window.axios.post('/account/setup/password', form.value);
        notify('success', 'Account setup complete', response.data.message);
        window.setTimeout(() => window.location.replace(response.data.redirect), 900);
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        notify('error', 'Password not changed', error.response?.data?.message ?? 'Review the password and try again.');
    } finally {
        saving.value = false;
    }
}

async function logout() {
    try {
        await window.axios.post('/logout');
    } finally {
        window.location.replace('/login');
    }
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get('verified') === '1') {
        notify('success', 'Email verified', 'Create a new password to finish your staff account setup.');
        window.history.replaceState({}, '', window.location.pathname);
    }

    loadSetup();
});
</script>

<template>
    <main class="min-h-screen bg-slate-100 text-slate-900">
        <header class="border-b border-slate-800 bg-slate-950 text-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4 sm:px-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-md bg-amber-300 text-slate-950">
                        <i class="fa-solid fa-award" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p class="font-display text-lg font-bold">Scholarship Portal</p>
                        <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Staff account setup</p>
                    </div>
                </div>
                <button class="rounded-md border border-slate-700 px-3 py-2 text-sm font-bold text-slate-200 transition hover:bg-slate-800" type="button" @click="logout">
                    Sign out
                </button>
            </div>
        </header>

        <ToastMessage
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            @close="toast.show = false"
        />

        <section class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:py-12">
            <div v-if="loading" class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
                <i class="fa-solid fa-circle-notch fa-spin text-amber-600" aria-hidden="true"></i>
                <p class="mt-3 text-sm font-semibold text-slate-600">Loading account setup...</p>
            </div>

            <div v-else-if="user" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-950 px-6 py-7 text-white sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-amber-300">First sign-in</p>
                    <h1 class="mt-2 font-display text-3xl font-bold">Secure your staff account</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                        Verify that this email belongs to you, then replace the temporary password before entering the {{ workspaceLabel }}.
                    </p>
                </div>

                <div class="grid border-b border-slate-200 sm:grid-cols-2">
                    <div :class="['flex items-center gap-3 px-6 py-4 sm:px-8', emailVerified ? 'bg-emerald-50' : 'bg-amber-50']">
                        <span :class="['grid h-8 w-8 place-items-center rounded-full text-xs', emailVerified ? 'bg-emerald-600 text-white' : 'bg-amber-300 text-slate-950']">
                            <i :class="emailVerified ? 'fa-solid fa-check' : 'fa-solid fa-envelope'" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-sm font-bold text-slate-950">1. Verify email</p>
                            <p class="text-xs text-slate-600">{{ emailVerified ? 'Completed' : 'Required first' }}</p>
                        </div>
                    </div>
                    <div :class="['flex items-center gap-3 border-t border-slate-200 px-6 py-4 sm:border-l sm:border-t-0 sm:px-8', emailVerified ? 'bg-amber-50' : 'bg-slate-50']">
                        <span :class="['grid h-8 w-8 place-items-center rounded-full text-xs', passwordComplete ? 'bg-emerald-600 text-white' : emailVerified ? 'bg-amber-300 text-slate-950' : 'bg-slate-200 text-slate-500']">
                            <i :class="passwordComplete ? 'fa-solid fa-check' : 'fa-solid fa-key'" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-sm font-bold text-slate-950">2. Change password</p>
                            <p class="text-xs text-slate-600">{{ emailVerified ? 'Ready' : 'Available after verification' }}</p>
                        </div>
                    </div>
                </div>

                <div class="p-6 sm:p-8">
                    <section v-if="!emailVerified" class="mx-auto max-w-2xl">
                        <div class="flex items-start gap-4 rounded-lg border border-amber-200 bg-amber-50 p-5">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-lg font-bold text-slate-950">Check your email</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    We sent a verification link to <strong class="break-all text-slate-900">{{ user.email }}</strong>. Open that link to confirm the address.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                            <button class="rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60" type="button" :disabled="checking" @click="checkVerification">
                                {{ checking ? 'Checking...' : 'I verified my email' }}
                            </button>
                            <button class="rounded-md border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" type="button" :disabled="resending" @click="resendVerification">
                                {{ resending ? 'Sending...' : 'Resend verification email' }}
                            </button>
                        </div>
                    </section>

                    <form v-else class="mx-auto max-w-2xl" @submit.prevent="savePassword">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                            <p class="text-sm font-bold text-emerald-900"><i class="fa-solid fa-circle-check mr-2" aria-hidden="true"></i>Email verified</p>
                            <p class="mt-1 text-sm text-emerald-800">Create a private password that is different from the temporary password.</p>
                        </div>

                        <div class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="new-password" class="mb-2 block text-sm font-bold text-slate-700">New password</label>
                                <div class="relative">
                                    <input id="new-password" v-model="form.password" :type="showPassword ? 'text' : 'password'" minlength="8" required autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3.5 py-3 pr-12 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <button type="button" class="absolute inset-y-0 right-2 px-2 text-slate-500" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
                                        <i :class="showPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <p v-if="errors.password" class="mt-2 text-xs font-semibold text-rose-700">{{ errors.password[0] }}</p>
                            </div>
                            <div>
                                <label for="confirm-password" class="mb-2 block text-sm font-bold text-slate-700">Confirm new password</label>
                                <div class="relative">
                                    <input id="confirm-password" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" minlength="8" required autocomplete="new-password" class="w-full rounded-md border border-slate-300 px-3.5 py-3 pr-12 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <button type="button" class="absolute inset-y-0 right-2 px-2 text-slate-500" :aria-label="showConfirmation ? 'Hide confirmation' : 'Show confirmation'" @click="showConfirmation = !showConfirmation">
                                        <i :class="showConfirmation ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-between gap-4 border-t border-slate-200 pt-5">
                            <p class="text-xs leading-5 text-slate-500">Use at least eight characters and do not share your password.</p>
                            <button class="shrink-0 rounded-md bg-slate-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60" type="submit" :disabled="saving">
                                {{ saving ? 'Saving...' : 'Finish setup' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <SiteFooter variant="dark" />
    </main>
</template>
