<script setup>
defineProps({
    message: { type: String, required: true },
});
defineEmits(['retry']);
</script>

<template>
    <div class="flex items-center justify-between gap-4 border-b border-rose-200 bg-rose-50 px-5 py-4" data-staff-table-load-error>
        <div class="min-w-0 break-words text-sm text-rose-800" role="alert">
            <p class="font-bold">Table results unavailable</p>
            <p class="mt-1">{{ message }}</p>
        </div>
        <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-700" @click="$emit('retry')">
            <i class="fa-solid fa-rotate-right text-xs" aria-hidden="true"></i>Retry
        </button>
    </div>
</template>
