<script setup>
import { watch } from 'vue';
import { showPortalToast } from '../support/portalToast';

const props = defineProps({
    message: { type: String, default: '' },
    type: { type: String, default: 'error' },
    title: { type: String, default: '' },
    retryLabel: { type: String, default: '' },
    fallback: { type: Boolean, default: false },
});
const emit = defineEmits(['retry']);

watch(() => props.message, (message) => {
    if (!message?.trim()) return;
    showPortalToast({
        type: props.type,
        title: props.title || (props.type === 'success' ? 'Changes saved' : 'Please review'),
        message,
        actionLabel: props.retryLabel || (props.fallback ? 'Reload page' : ''),
        action: props.retryLabel || props.fallback ? retry : null,
        duration: props.type === 'success' ? 4000 : 8000,
    });
}, { immediate: true, flush: 'post' });

function retry() {
    if (props.retryLabel) emit('retry');
    else window.location.reload();
}
</script>

<template>
    <div v-if="fallback" class="flex min-h-32 flex-col items-center justify-center gap-3 rounded-md border border-slate-200 bg-white p-6 text-center">
        <i class="fa-regular fa-folder-open text-xl text-slate-400" aria-hidden="true"></i>
        <p class="text-sm font-semibold text-slate-700">This section is currently unavailable.</p>
        <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="retry">{{ retryLabel || 'Reload page' }}</button>
    </div>
</template>
