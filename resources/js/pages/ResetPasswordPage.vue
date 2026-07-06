<script setup>
import { onMounted, ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';

const formElement = ref(null);
const form = ref({
    email: '',
    token: '',
    password: '',
    passwordConfirmation: '',
});
const isSubmitting = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');

const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';

async function submitForm() {
    statusMessage.value = '';
    errorMessage.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    if (form.value.password !== form.value.passwordConfirmation) {
        errorMessage.value = 'Passwords must match.';
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await window.axios.post('/reset-password', {
            email: form.value.email,
            token: form.value.token,
            password: form.value.password,
            password_confirmation: form.value.passwordConfirmation,
        });

        statusMessage.value = response.data.message ?? 'Password reset successful.';
        window.setTimeout(() => {
            window.location.href = response.data.redirect ?? '/login';
        }, 1000);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to reset password.';
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    form.value.email = params.get('email') ?? '';
    form.value.token = params.get('token') ?? '';
});
</script>

<template>
    <AuthShell
        eyebrow="New Password"
        title="Create a new password"
        description="Use the reset link details to set a new password for your portal account."
        switch-href="/login"
        switch-label="Login"
        switch-text="Back to sign in?"
        panel-badge="Account Recovery"
        panel-title="Finish password recovery"
        panel-text="After this step, you can return to the scholarship portal using your new password."
        :panel-highlights="[
            'Confirm the registered email address.',
            'Use a secure password.',
            'Log back in after reset.'
        ]"
        panel-note="Reset links expire after one hour."
    >
        <form ref="formElement" class="space-y-4" @submit.prevent="submitForm">
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700" for="reset-email">
                    Email address
                </label>
                <input id="reset-email" v-model="form.email" type="email" required :class="inputClass">
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700" for="reset-token">
                    Reset token
                </label>
                <input id="reset-token" v-model="form.token" type="text" required :class="inputClass">
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700" for="new-password">
                        New password
                    </label>
                    <input id="new-password" v-model="form.password" type="password" required minlength="8" :class="inputClass">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-700" for="new-password-confirmation">
                        Confirm password
                    </label>
                    <input id="new-password-confirmation" v-model="form.passwordConfirmation" type="password" required minlength="8" :class="inputClass">
                </div>
            </div>

            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
            >
                {{ isSubmitting ? 'Saving password...' : 'Reset password' }}
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
