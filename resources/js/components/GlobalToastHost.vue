<script setup>
import { onBeforeUnmount, onMounted, reactive, shallowRef } from 'vue';
import { PORTAL_TOAST_EVENT } from '../support/portalToast';
import ToastMessage from './ToastMessage.vue';

const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
    actionLabel: '',
});
const action = shallowRef(null);

let hideTimer = null;
let lastFingerprint = '';
let lastShownAt = 0;

function closeToast() {
    if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
    }

    toast.show = false;
    action.value = null;
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
        if (typeof detail.action === 'function') {
            toast.actionLabel = detail.actionLabel || 'Try again';
            action.value = detail.action;
        }
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
    toast.actionLabel = typeof detail.action === 'function' ? detail.actionLabel || 'Try again' : '';
    action.value = typeof detail.action === 'function' ? detail.action : null;
    toast.show = true;

    const requestedDuration = Number(detail.duration);
    const duration = Number.isFinite(requestedDuration)
        ? Math.min(Math.max(requestedDuration, 2000), 10000)
        : 4000;

    hideTimer = window.setTimeout(closeToast, duration);
}

function runAction() {
    const retry = action.value;
    closeToast();
    retry?.();
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
        :action-label="toast.actionLabel"
        @action="runAction"
        @close="closeToast"
    />
</template>
