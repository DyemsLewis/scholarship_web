<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import ToastMessage from '../components/ToastMessage.vue';

const formElement = ref(null);
const isProviderRegistration = window.location.pathname.startsWith('/provider/register');
const registrationRole = isProviderRegistration ? 'provider' : 'applicant';
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
const showPassword = ref(false);
const toast = ref({
    show: false,
    type: 'success',
    title: '',
    message: '',
});

let toastTimer = null;

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
            panelNote: 'After registration, return to login and sign in with the provider account.',
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
        panelNote: 'After registration, return to login and sign in with the applicant account.',
    };
});

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.number = event.target.value.replace(/[^\d+\s()-]/g, '');
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
        window.location.href = response.data.redirect ?? '/login?registered=1';
    } catch (error) {
        const message = error.response?.data?.message ?? 'Registration failed. Check your details and try again.';
        showToast('error', 'Registration failed', message);
    } finally {
        isSubmitting.value = false;
    }
}

onBeforeUnmount(() => {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
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
        :show-panel="!isProviderRegistration"
    >
        <ToastMessage
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            @close="closeToast"
        />

        <form ref="formElement" class="space-y-5" @submit.prevent="submitForm">
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
                {{ isSubmitting ? 'Saving profile...' : isProviderRegistration ? 'Create provider account' : 'Create applicant account' }}
            </button>
        </form>

    </AuthShell>
</template>
