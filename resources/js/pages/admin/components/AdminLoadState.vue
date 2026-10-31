<script setup>
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    loadingMessage: { type: String, default: 'Loading records...' },
    errorTitle: { type: String, default: 'Unable to load this section' },
});
defineEmits(['retry']);
</script>

<template>
    <div v-if="loading" class="flex min-h-32 items-center justify-center gap-3 px-6 py-8 text-sm font-semibold text-slate-600" role="status" aria-live="polite">
        <i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i>
        <span>{{ loadingMessage }}</span>
    </div>
    <div v-else-if="error" class="flex items-center justify-between gap-6 border-l-4 border-rose-500 bg-rose-50 px-6 py-5" role="alert">
        <div class="min-w-0">
            <h2 class="text-sm font-bold text-rose-900">{{ errorTitle }}</h2>
            <p class="mt-1 break-words text-sm leading-6 text-rose-800">{{ error }}</p>
        </div>
        <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-700" @click="$emit('retry')">
            <i class="fa-solid fa-rotate-right text-xs" aria-hidden="true"></i>Try again
        </button>
    </div>
</template>
