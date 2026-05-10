<script setup>
import { ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';

const formElement = ref(null);
const form = ref({
    firstName: '',
    lastName: '',
    middleInitial: '',
    email: '',
    username: '',
    number: '',
    password: '',
    passwordConfirmation: '',
});

const isSubmitting = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');
const showPassword = ref(false);

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.number = event.target.value.replace(/[^\d+\s()-]/g, '');
}

async function submitForm() {
    statusMessage.value = '';
    errorMessage.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    const numberDigits = form.value.number.replace(/\D/g, '');

    if (numberDigits.length < 10) {
        formElement.value
            ?.querySelector('#number')
            ?.setCustomValidity('Enter at least 10 digits in your contact number.');
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#number')?.setCustomValidity('');

    if (form.value.password !== form.value.passwordConfirmation) {
        formElement.value
            ?.querySelector('#password-confirmation')
            ?.setCustomValidity('Passwords must match.');
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#password-confirmation')?.setCustomValidity('');
    isSubmitting.value = true;

    try {
        const response = await window.axios.post('/register', {
            first_name: form.value.firstName,
            last_name: form.value.lastName,
            middle_initial: form.value.middleInitial,
            email: form.value.email,
            username: form.value.username,
            number: form.value.number,
            password: form.value.password,
            password_confirmation: form.value.passwordConfirmation,
        });

        statusMessage.value = response.data.message ?? 'Registration complete.';
        window.location.href = response.data.redirect ?? '/';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Registration failed. Check your details and try again.';
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <AuthShell
        eyebrow="Scholarship Registration"
        title="Create your applicant profile"
        description="Set up your basic scholarship details so you can proceed with applications, requirements, and official updates."
        switch-href="/login"
        switch-label="Login"
        switch-text="Already have a scholarship profile?"
        panel-badge="Student Funding Desk"
        panel-title="Start with a clear academic profile"
        panel-text="Your registration details help the scholarship office identify your student record and connect you with the right grant opportunities."
        :panel-highlights="[
            'Provide the name details used in your school records.',
            'Choose a username for future portal access.',
            'Add an active email address and contact number.'
        ]"
        panel-note="Keep your information accurate. Scholarship notices, document requests, and approval updates will rely on these details."
    >
        <form ref="formElement" class="space-y-5" @submit.prevent="submitForm">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_5.5rem_minmax(0,1fr)] sm:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="first-name">
                        First name
                    </label>
                    <input
                        id="first-name"
                        v-model="form.firstName"
                        type="text"
                        autocomplete="given-name"
                        required
                        placeholder="First name"
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                    >
                </div>

                <div class="sm:mx-auto sm:w-[5.5rem]">
                    <label class="mb-2 block text-sm font-medium text-slate-700 sm:text-center" for="middle-initial">
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
                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-3 text-center text-slate-900 uppercase outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                        @input="handleMiddleInitialInput"
                    >
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="last-name">
                        Last name
                    </label>
                    <input
                        id="last-name"
                        v-model="form.lastName"
                        type="text"
                        autocomplete="family-name"
                        required
                        placeholder="Last name"
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                    >
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="username">
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
                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                >
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="email">
                        Email address
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        placeholder="Email address"
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                    >
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="number">
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
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                        @input="(event) => { event.target.setCustomValidity(''); handleNumberInput(event); }"
                    >
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="password">
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
                            class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 pr-16 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                        >
                        <button
                            type="button"
                            class="absolute inset-y-0 right-2 my-auto h-9 rounded-md px-3 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700" for="password-confirmation">
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
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                        @input="event.target.setCustomValidity('')"
                    >
                </div>
            </div>

            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
            >
                {{ isSubmitting ? 'Saving profile...' : 'Create scholarship profile' }}
            </button>
        </form>

        <p v-if="statusMessage" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm text-emerald-700">
            {{ statusMessage }}
        </p>

        <p v-if="errorMessage" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">
            {{ errorMessage }}
        </p>
    </AuthShell>
</template>
