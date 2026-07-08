<script setup>
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    mode: {
        type: String,
        default: 'light',
    },
});

const isLoading = ref(true);
const isSending = ref(false);
const isVisible = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');

const isDark = computed(() => props.mode === 'dark');

const wrapperClass = computed(() => [
    'rounded-lg border p-3 text-sm',
    isDark.value
        ? 'border-amber-300/20 bg-amber-300/10 text-amber-50'
        : 'border-amber-200 bg-amber-50 text-slate-800',
]);

const buttonClass = computed(() => [
    'mt-3 inline-flex rounded-md px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-70',
    isDark.value
        ? 'bg-amber-300 text-slate-950 hover:bg-amber-200'
        : 'bg-slate-950 text-white hover:bg-slate-800',
]);

async function checkVerificationReminder() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/notifications');
        const notifications = response.data.notifications ?? [];

        isVisible.value = notifications.some((notification) => notification.type === 'email_verification');
    } catch (error) {
        isVisible.value = false;
    } finally {
        isLoading.value = false;
    }
}

async function resendVerificationEmail() {
    isSending.value = true;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.post('/email/verification-notification');

        if (response.data.email_verified) {
            isVisible.value = false;
        }

        statusMessage.value = response.data.message ?? 'Verification email sent.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to resend the verification email.';
    } finally {
        isSending.value = false;
    }
}

onMounted(checkVerificationReminder);
</script>

<template>
    <div v-if="!isLoading && isVisible" :class="wrapperClass">
        <p class="font-bold">
            Verify your email
        </p>
        <p :class="['mt-1 text-xs leading-5', isDark ? 'text-amber-50/75' : 'text-slate-600']">
            Resend the link if you skipped verification after registering.
        </p>

        <button
            type="button"
            :class="buttonClass"
            :disabled="isSending"
            @click="resendVerificationEmail"
        >
            {{ isSending ? 'Sending...' : 'Resend link' }}
        </button>

        <p v-if="statusMessage" :class="['mt-2 text-xs', isDark ? 'text-emerald-100' : 'text-emerald-700']">
            {{ statusMessage }}
        </p>
        <p v-if="errorMessage" :class="['mt-2 text-xs', isDark ? 'text-rose-100' : 'text-rose-700']">
            {{ errorMessage }}
        </p>
    </div>
</template>
