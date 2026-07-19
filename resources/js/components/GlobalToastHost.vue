<script setup>
import { onBeforeUnmount, onMounted, reactive } from 'vue';
import { PORTAL_TOAST_EVENT } from '../support/portalToast';
import ToastMessage from './ToastMessage.vue';

const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
});

let hideTimer = null;
let lastFingerprint = '';
let lastShownAt = 0;

function closeToast() {
    if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
    }

    toast.show = false;
}

function handleToast(event) {
    const detail = event.detail ?? {};
    const message = typeof detail.message === 'string' ? detail.message.trim() : '';

    if (!message) {
        return;
    }

    const type = detail.type === 'error' ? 'error' : 'success';
    const fingerprint = `${type}:${message}`;
    const shownAt = Date.now();

    if (fingerprint === lastFingerprint && shownAt - lastShownAt < 800) {
        return;
    }

    lastFingerprint = fingerprint;
    lastShownAt = shownAt;

    if (hideTimer) {
        window.clearTimeout(hideTimer);
    }

    toast.type = type;
    toast.title = detail.title
        || (type === 'error' ? 'Action failed' : 'Action successful');
    toast.message = message;
    toast.show = true;

    const requestedDuration = Number(detail.duration);
    const duration = Number.isFinite(requestedDuration)
        ? Math.min(Math.max(requestedDuration, 2000), 10000)
        : 4000;

    hideTimer = window.setTimeout(closeToast, duration);
}

onMounted(() => {
    window.addEventListener(PORTAL_TOAST_EVENT, handleToast);
});

onBeforeUnmount(() => {
    window.removeEventListener(PORTAL_TOAST_EVENT, handleToast);
    closeToast();
});
</script>

<template>
    <ToastMessage
        :show="toast.show"
        :type="toast.type"
        :title="toast.title"
        :message="toast.message"
        @close="closeToast"
    />
</template>
