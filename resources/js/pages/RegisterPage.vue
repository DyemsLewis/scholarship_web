<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import ToastMessage from '../components/ToastMessage.vue';

const formElement = ref(null);
const isProviderRegistration = window.location.pathname.startsWith('/provider/register');
const registrationRole = isProviderRegistration ? 'provider' : 'applicant';
const registrationStep = ref('details');
const registrationToken = ref('');
const verificationEmail = ref('');
const verificationCode = ref('');
const resendSeconds = ref(0);
const form = ref({
    firstName: '',
    lastName: '',
    middleInitial: '',
    email: '',
    username: '',
    number: '',
    providerName: '',
    providerType: '',
    providerWebsite: '',
    providerAddress: '',
    providerDescription: '',
    password: '',
    passwordConfirmation: '',
    termsAccepted: false,
});

const providerTypeOptions = [
    { value: 'school', label: 'School / University' },
    { value: 'foundation', label: 'Foundation' },
    { value: 'government', label: 'Government Office' },
    { value: 'company', label: 'Company / Sponsor' },
    { value: 'non_profit', label: 'Non-profit Organization' },
    { value: 'other', label: 'Other Provider' },
];

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-3 text-center text-slate-900 uppercase outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const toggleButtonClass = 'absolute inset-y-0 right-2 my-auto h-9 rounded-md px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900';
const primaryButtonClass = 'w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80';

const isSubmitting = ref(false);
const isVerifying = ref(false);
const isResending = ref(false);
const showPassword = ref(false);
const toast = ref({
    show: false,
    type: 'success',
    title: '',
    message: '',
});

let toastTimer = null;
let resendTimer = null;

function showToast(type, title, message) {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    toast.value = {
        show: true,
        type,
        title,
        message,
    };

    toastTimer = window.setTimeout(() => {
        toast.value.show = false;
    }, 3500);
}

function closeToast() {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    toast.value.show = false;
}

const shellCopy = computed(() => {
    if (registrationStep.value === 'verification') {
        return {
            eyebrow: 'Email Verification',
            title: 'Check your email',
            description: `Enter the six-digit code sent to ${verificationEmail.value || 'your email'}.`,
            panelBadge: 'Secure Registration',
            panelTitle: 'Verify before account creation',
            panelText: 'Your account is created only after the email code is confirmed.',
            panelHighlights: [],
            panelNote: 'The code expires after 10 minutes.',
        };
    }

    if (isProviderRegistration) {
        return {
            eyebrow: 'Provider Registration',
            title: 'Create your provider account',
            description: 'Add the organization and contact details needed to manage scholarship programs.',
            panelBadge: 'Provider Access Desk',
            panelTitle: 'A workspace for scholarship providers',
            panelText: 'Provider accounts are separate from applicant accounts so scholarship management stays organized.',
            panelHighlights: [
                'Save organization details for scholarship listings.',
                'Keep one contact person connected to the account.',
                'Manage programs from a separate provider dashboard.',
            ],
            panelNote: 'We verify the contact email before creating the provider account.',
        };
    }

    return {
        eyebrow: 'Scholarship Registration',
        title: 'Create your applicant profile',
        description: 'Set up your applicant account so you can continue scholarship activity after logging in.',
        panelBadge: 'Student Funding Desk',
        panelTitle: 'Start with an applicant profile',
        panelText: 'Applicant registration is for students preparing to use scholarship opportunities in the portal.',
        panelHighlights: [
            'Create a scholarship applicant account.',
            'Keep your basic profile details ready.',
            'Continue setup or browse the web after signing in.',
        ],
        panelNote: 'We verify your email before creating the applicant account.',
    };
});

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.number = event.target.value.replace(/[^\d+\s()-]/g, '');
}

function handleVerificationCodeInput(event) {
    verificationCode.value = event.target.value.replace(/\D/g, '').slice(0, 6);
}

function startResendCountdown(seconds) {
    if (resendTimer) {
        window.clearInterval(resendTimer);
    }

    resendSeconds.value = Number(seconds) || 0;

    if (resendSeconds.value <= 0) {
        return;
    }

    resendTimer = window.setInterval(() => {
        resendSeconds.value = Math.max(0, resendSeconds.value - 1);

        if (resendSeconds.value === 0) {
            window.clearInterval(resendTimer);
            resendTimer = null;
        }
    }, 1000);
}

function returnToDetails() {
    registrationStep.value = 'details';
    registrationToken.value = '';
    verificationCode.value = '';
    verificationEmail.value = '';
    startResendCountdown(0);
}

async function submitForm() {
    if (!formElement.value?.reportValidity()) {
        return;
    }

    const numberDigits = form.value.number.replace(/\D/g, '');

    if (numberDigits.length < 10) {
        const message = 'Enter at least 10 digits in your contact number.';
        showToast('error', 'Registration failed', message);
        formElement.value
            ?.querySelector('#number')
            ?.setCustomValidity(message);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#number')?.setCustomValidity('');

    if (form.value.password !== form.value.passwordConfirmation) {
        const message = 'Passwords must match.';
        showToast('error', 'Registration failed', message);
        formElement.value
            ?.querySelector('#password-confirmation')
            ?.setCustomValidity(message);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#password-confirmation')?.setCustomValidity('');

    if (!form.value.termsAccepted) {
        showToast('error', 'Registration failed', 'Please accept the Terms and Privacy Notice before creating an account.');
        return;
    }

    isSubmitting.value = true;

    const payload = {
        first_name: form.value.firstName,
        last_name: form.value.lastName,
        middle_initial: form.value.middleInitial,
        email: form.value.email,
        username: form.value.username,
        number: form.value.number,
        role: registrationRole,
        password: form.value.password,
        password_confirmation: form.value.passwordConfirmation,
        terms_accepted: form.value.termsAccepted ? '1' : '',
    };

    if (isProviderRegistration) {
        Object.assign(payload, {
            provider_name: form.value.providerName,
            provider_type: form.value.providerType,
            provider_website: form.value.providerWebsite,
            provider_address: form.value.providerAddress,
            provider_description: form.value.providerDescription,
        });
    }

    try {
        const response = await window.axios.post('/register', payload);
        registrationToken.value = response.data.registration_token;
        verificationEmail.value = response.data.email ?? form.value.email;
        verificationCode.value = '';
        registrationStep.value = 'verification';
        startResendCountdown(response.data.resend_after ?? 60);
        showToast('success', 'Code sent', response.data.message ?? 'Check your email for the verification code.');
    } catch (error) {
        const message = error.response?.data?.message ?? 'Registration failed. Check your details and try again.';
        showToast('error', 'Registration failed', message);
    } finally {
        isSubmitting.value = false;
    }
}

async function verifyRegistration() {
    if (!/^\d{6}$/.test(verificationCode.value)) {
        showToast('error', 'Verification failed', 'Enter the complete six-digit verification code.');
        return;
    }

    isVerifying.value = true;

    try {
        const response = await window.axios.post('/register/verify', {
            registration_token: registrationToken.value,
            code: verificationCode.value,
        });
        showToast('success', 'Account created', response.data.message);
        window.setTimeout(() => {
            window.location.href = response.data.redirect ?? '/login?registered=1&verified=1';
        }, 500);
    } catch (error) {
        const message = error.response?.data?.message ?? 'The verification code could not be confirmed.';
        showToast('error', 'Verification failed', message);

        if (error.response?.data?.restart_required) {
            returnToDetails();
        }
    } finally {
        isVerifying.value = false;
    }
}

async function resendVerificationCode() {
    if (resendSeconds.value > 0 || isResending.value) {
        return;
    }

    isResending.value = true;

    try {
        const response = await window.axios.post('/register/resend-code', {
            registration_token: registrationToken.value,
        });
        verificationCode.value = '';
        startResendCountdown(response.data.resend_after ?? 60);
        showToast('success', 'New code sent', response.data.message);
    } catch (error) {
        const message = error.response?.data?.message ?? 'Unable to resend the verification code.';
        showToast('error', 'Code not sent', message);

        if (error.response?.status === 429) {
            startResendCountdown(error.response?.data?.retry_after ?? 60);
        }

        if (error.response?.data?.restart_required) {
            returnToDetails();
        }
    } finally {
        isResending.value = false;
    }
}

onBeforeUnmount(() => {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    if (resendTimer) {
        window.clearInterval(resendTimer);
    }
});
</script>

<template>
    <AuthShell
        :eyebrow="shellCopy.eyebrow"
        :title="shellCopy.title"
        :description="shellCopy.description"
        switch-href="/login"
        switch-label="Login"
        :panel-badge="shellCopy.panelBadge"
        :panel-title="shellCopy.panelTitle"
        :panel-text="shellCopy.panelText"
        :panel-highlights="shellCopy.panelHighlights"
        :panel-note="shellCopy.panelNote"
        :show-panel="registrationStep === 'details' && !isProviderRegistration"
    >
        <ToastMessage
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            @close="closeToast"
        />

        <form v-if="registrationStep === 'details'" ref="formElement" class="space-y-5" @submit.prevent="submitForm">
            <div v-if="isProviderRegistration" class="rounded-md border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                    Provider Details
                </p>
                <p class="mt-1 text-sm leading-6 text-slate-500">
                    These details identify the organization that will manage scholarship programs.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="labelClass" for="provider-name">
                            Organization name
                        </label>
                        <input
                            id="provider-name"
                            v-model="form.providerName"
                            type="text"
                            autocomplete="organization"
                            required
                            placeholder="Scholarship provider name"
                            :class="inputClass"
                        >
                    </div>

                    <div>
                        <label :class="labelClass" for="provider-type">
                            Provider type
                        </label>
                        <select
                            id="provider-type"
                            v-model="form.providerType"
                            required
                            :class="inputClass"
                        >
                            <option value="" disabled>
                                Select provider type
                            </option>
                            <option
                                v-for="option in providerTypeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label :class="labelClass" for="provider-website">
                            Website
                        </label>
                        <input
                            id="provider-website"
                            v-model="form.providerWebsite"
                            type="url"
                            inputmode="url"
                            autocomplete="url"
                            placeholder="https://example.edu"
                            :class="inputClass"
                        >
                    </div>

                    <div>
                        <label :class="labelClass" for="provider-address">
                            Office address
                        </label>
                        <input
                            id="provider-address"
                            v-model="form.providerAddress"
                            type="text"
                            autocomplete="street-address"
                            required
                            placeholder="Office or campus address"
                            :class="inputClass"
                        >
                    </div>
                </div>

                <div class="mt-4">
                    <label :class="labelClass" for="provider-description">
                        Short description
                    </label>
                    <textarea
                        id="provider-description"
                        v-model="form.providerDescription"
                        rows="3"
                        placeholder="Briefly describe the provider or scholarship office"
                        :class="inputClass"
                    ></textarea>
                </div>
            </div>

            <div v-if="isProviderRegistration" class="border-t border-slate-200 pt-5">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Account Contact
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_5.5rem_minmax(0,1fr)] sm:items-end">
                <div>
                    <label :class="labelClass" for="first-name">
                        {{ isProviderRegistration ? 'Contact first name' : 'First name' }}
                    </label>
                    <input
                        id="first-name"
                        v-model="form.firstName"
                        type="text"
                        autocomplete="given-name"
                        required
                        :placeholder="isProviderRegistration ? 'Contact first name' : 'First name'"
                        :class="inputClass"
                    >
                </div>

                <div class="sm:mx-auto sm:w-[5.5rem]">
                    <label :class="[labelClass, 'sm:text-center']" for="middle-initial">
                        Middle initial
                    </label>
                    <input
                        id="middle-initial"
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
                    <label :class="labelClass" for="last-name">
                        {{ isProviderRegistration ? 'Contact last name' : 'Last name' }}
                    </label>
                    <input
                        id="last-name"
                        v-model="form.lastName"
                        type="text"
                        autocomplete="family-name"
                        required
                        :placeholder="isProviderRegistration ? 'Contact last name' : 'Last name'"
                        :class="inputClass"
                    >
                </div>
            </div>

            <div>
                <label :class="labelClass" for="username">
                    Username
                </label>
                <input
                    id="username"
                    v-model="form.username"
                    type="text"
                    autocomplete="username"
                    pattern="[A-Za-z0-9_.-]{4,}"
                    required
                    placeholder="Username"
                    :class="inputClass"
                >
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label :class="labelClass" for="email">
                        Email address
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        placeholder="Email address"
                        :class="inputClass"
                    >
                </div>

                <div>
                    <label :class="labelClass" for="number">
                        Contact number
                    </label>
                    <input
                        id="number"
                        :value="form.number"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel"
                        required
                        placeholder="Contact number"
                        :class="inputClass"
                        @input="(event) => { event.target.setCustomValidity(''); handleNumberInput(event); }"
                    >
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label :class="labelClass" for="password">
                        Password
                    </label>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            minlength="8"
                            placeholder="Password"
                            :class="[inputClass, 'pr-16']"
                        >
                        <button
                            type="button"
                            :class="toggleButtonClass"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </div>

                <div>
                    <label :class="labelClass" for="password-confirmation">
                        Confirm password
                    </label>
                    <input
                        id="password-confirmation"
                        v-model="form.passwordConfirmation"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        minlength="8"
                        placeholder="Confirm password"
                        :class="inputClass"
                        @input="$event.target.setCustomValidity('')"
                    >
                </div>
            </div>

            <TermsAgreement v-model="form.termsAccepted" context="account" />

            <button
                type="submit"
                :disabled="isSubmitting"
                :class="primaryButtonClass"
            >
                {{ isSubmitting ? 'Sending code...' : 'Continue to email verification' }}
            </button>
        </form>

        <form v-else class="space-y-5" @submit.prevent="verifyRegistration">
            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-center">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-amber-200 text-amber-900">
                    <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                </span>
                <p class="mt-3 text-sm font-bold text-slate-950">
                    Code sent to {{ verificationEmail }}
                </p>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    No account has been created yet. Enter the code below to finish registration.
                </p>
            </div>

            <div>
                <label :class="labelClass" for="registration-code">
                    Six-digit verification code
                </label>
                <input
                    id="registration-code"
                    :value="verificationCode"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    pattern="[0-9]{6}"
                    required
                    autofocus
                    placeholder="000000"
                    class="w-full rounded-md border border-slate-300 bg-white px-4 py-3 text-center text-2xl font-bold tracking-[0.45em] text-slate-950 outline-none transition placeholder:text-slate-300 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                    @input="handleVerificationCodeInput"
                >
                <p class="mt-2 text-center text-xs text-slate-500">
                    The code expires in 10 minutes.
                </p>
            </div>

            <button
                type="submit"
                :disabled="isVerifying || verificationCode.length !== 6"
                :class="primaryButtonClass"
            >
                {{ isVerifying ? 'Verifying...' : 'Verify and create account' }}
            </button>

            <div class="flex flex-col items-center justify-between gap-3 border-t border-slate-200 pt-4 text-sm sm:flex-row">
                <button
                    type="button"
                    class="font-semibold text-slate-600 transition hover:text-slate-950"
                    @click="returnToDetails"
                >
                    Change registration details
                </button>
                <button
                    type="button"
                    :disabled="resendSeconds > 0 || isResending"
                    class="font-bold text-slate-900 transition hover:text-amber-700 disabled:cursor-not-allowed disabled:text-slate-400"
                    @click="resendVerificationCode"
                >
                    {{ isResending ? 'Sending...' : resendSeconds > 0 ? `Resend code in ${resendSeconds}s` : 'Resend code' }}
                </button>
            </div>
        </form>

    </AuthShell>
</template>
