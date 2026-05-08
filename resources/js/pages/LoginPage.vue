<script setup>
import { ref } from 'vue';
import AuthShell from '../components/AuthShell.vue';

const formElement = ref(null);
const form = ref({
    email: '',
    password: '',
    remember: true,
});

const showPassword = ref(false);
const isSubmitting = ref(false);
const statusMessage = ref('');

async function submitForm() {
    statusMessage.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    isSubmitting.value = true;

    await new Promise((resolve) => {
        window.setTimeout(resolve, 1200);
    });

    statusMessage.value = `Welcome back, ${form.value.email}. Your dashboard is ready.`;
    isSubmitting.value = false;
}
</script>

<template>
    <AuthShell
        eyebrow="Scholarship Access"
        title="Return to your scholarship record"
        description="Sign in to review your application progress, upload supporting documents, and keep track of award updates."
        switch-href="/register"
        switch-label="Register"
        switch-text="Need a student profile?"
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
        <form ref="formElement" class="space-y-4" @submit.prevent="submitForm">
            <div class="grid gap-4">
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
                        placeholder="student@example.com"
                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                    >
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between gap-4">
                        <label class="block text-sm font-medium text-slate-700" for="password">
                            Password
                        </label>
                        <a href="#" class="text-sm font-medium text-sky-700 transition hover:text-slate-900">
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
            </div>

            <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-1">
                <label class="flex items-center gap-3 text-sm text-slate-600">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300 bg-white text-sky-700 focus:ring-sky-300"
                    >
                    Remember me
                </label>

                <span class="text-xs font-medium uppercase tracking-[0.18em] text-slate-400">
                    Secure login
                </span>
            </div>

            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
            >
                {{ isSubmitting ? 'Signing in...' : 'Log in to portal' }}
            </button>
        </form>

        <p v-if="statusMessage" class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm text-emerald-700">
            {{ statusMessage }}
        </p>
    </AuthShell>
</template>
