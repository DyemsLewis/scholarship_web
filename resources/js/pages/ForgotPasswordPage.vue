<script setup>
import { ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';

const formElement = ref(null);
const email = ref('');
const isSubmitting = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');
const resetUrl = ref('');

const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';

async function submitForm() {
    statusMessage.value = '';
    errorMessage.value = '';
    resetUrl.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await window.axios.post('/forgot-password', {
            email: email.value,
        });

        statusMessage.value = response.data.message ?? 'Password reset link prepared.';
        resetUrl.value = response.data.reset_url ?? '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to prepare reset link.';
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <AuthShell
        eyebrow="Password Help"
        title="Reset your portal password"
        description="Enter the email connected to your scholarship account and follow the reset link."
        switch-href="/login"
        switch-label="Login"
        switch-text="Remember your password?"
        panel-badge="Secure Account Desk"
        panel-title="Keep account access recoverable"
        panel-text="Password reset keeps students, providers, and admins from getting locked out before important deadlines."
        :panel-highlights="[
            'Request a reset link using your registered email.',
            'Use a new password with at least eight characters.',
            'Return to the portal after your password is changed.'
        ]"
        panel-note="For local testing, the reset link is shown after the request. In hosting, connect mail settings to email it."
    >
        <form ref="formElement" class="space-y-4" @submit.prevent="submitForm">
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700" for="reset-email">
                    Email address
                </label>
                <input
                    id="reset-email"
                    v-model="email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="student@example.com"
                    :class="inputClass"
                >
            </div>

            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
            >
                {{ isSubmitting ? 'Preparing link...' : 'Request reset link' }}
            </button>
        </form>

        <p v-if="statusMessage" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm text-emerald-700">
            {{ statusMessage }}
        </p>

        <a
            v-if="resetUrl"
            :href="resetUrl"
            class="mt-3 block rounded-md border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm font-bold text-slate-800 transition hover:bg-slate-100"
        >
            Open local reset link
        </a>

        <p v-if="errorMessage" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">
            {{ errorMessage }}
        </p>
    </AuthShell>
</template>
