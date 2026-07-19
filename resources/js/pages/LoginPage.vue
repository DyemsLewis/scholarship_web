<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';
import ToastMessage from '../components/ToastMessage.vue';

const formElement = ref(null);
const form = ref({
    email: '',
    password: '',
    remember: true,
});

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const toggleButtonClass = 'absolute inset-y-0 right-2 my-auto h-9 rounded-md px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900';
const primaryButtonClass = 'w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80';

const showPassword = ref(false);
const isSubmitting = ref(false);
const toast = ref({
    show: false,
    type: 'success',
    title: '',
    message: '',
});

let toastTimer = null;
let redirectTimer = null;

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

async function submitForm() {
    if (!formElement.value?.reportValidity()) {
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await window.axios.post('/login', {
            email: form.value.email,
            password: form.value.password,
            remember: form.value.remember,
        });

        const isEmailVerified = response.data.email_verified !== false;
        const message = response.data.message ?? 'Login successful.';

        showToast(
            'success',
            isEmailVerified ? 'Login successful' : 'Email verification reminder',
            message,
        );

        redirectTimer = window.setTimeout(() => {
            window.location.href = response.data.redirect ?? '/';
        }, isEmailVerified ? 900 : 1400);
    } catch (error) {
        const message = error.response?.data?.message ?? 'Login failed. Check your details and try again.';
        showToast('error', 'Login failed', message);
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get('verified') === '1') {
        showToast('success', 'Email verified', 'Email verified successfully. You can now sign in.');
        window.history.replaceState({}, '', window.location.pathname);
    }
});

onBeforeUnmount(() => {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    if (redirectTimer) {
        window.clearTimeout(redirectTimer);
    }
});
</script>

<template>
    <AuthShell
        eyebrow="Scholarship Access"
        title="Return to your scholarship record"
        description="Sign in to review your application progress, upload supporting documents, and keep track of award updates."
        switch-href="/register"
        switch-label="Register"
        panel-badge="Merit and Grants Office"
        panel-title="One place for your application requirements"
        panel-text="The scholarship portal keeps your academic profile, contact information, and review updates together in a secure student workspace."
        :panel-highlights="[
            'Review scholarship deadlines and checklist items.',
            'Track submitted forms and supporting documents.',
            'Receive updates from the scholarship committee.'
        ]"
        panel-note="Use the same account details connected to your scholarship application to continue where you left off."
    >
        <ToastMessage
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            @close="closeToast"
        />

        <form ref="formElement" class="space-y-4" @submit.prevent="submitForm">
            <div class="grid gap-4">
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
                        placeholder="student@example.com"
                        :class="inputClass"
                    >
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-4">
                        <label class="block text-sm font-semibold text-slate-700" for="password">
                            Password
                        </label>
                        <a href="/forgot-password" class="text-sm font-semibold text-amber-700 transition hover:text-slate-900">
                            Forgot password?
                        </a>
                    </div>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            required
                            minlength="8"
                            placeholder="Enter your password"
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
            </div>

            <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-1">
                <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300 bg-white text-amber-600 focus:ring-amber-300"
                    >
                    Remember me
                </label>

                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
                    Secure login
                </span>
            </div>

            <button
                type="submit"
                :disabled="isSubmitting"
                :class="primaryButtonClass"
            >
                {{ isSubmitting ? 'Signing in...' : 'Log in to portal' }}
            </button>
        </form>

    </AuthShell>
</template>
