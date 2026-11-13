<script setup>
defineProps({ backUrl: { type: String, required: true }, next: { type: Object, default: null }, loading: Boolean, error: { type: String, default: '' } });
defineEmits(['retry']);
</script>

<template>
    <nav class="mb-3 flex min-h-9 items-center justify-between gap-4 text-sm font-bold text-slate-600" aria-label="Review queue navigation" data-admin-review-navigation>
        <a :href="backUrl" class="inline-flex items-center gap-2 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to review queue</a>
        <span v-if="loading" class="text-xs font-normal text-slate-500" role="status">Finding the next matching record...</span>
        <a v-else-if="next" :href="next.url" :aria-label="`Next record: ${next.name}`" class="inline-flex items-center gap-2 hover:text-slate-950">Next record<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
        <button v-else-if="error" type="button" class="text-xs underline" @click="$emit('retry')">{{ error }} Retry</button>
        <span v-else class="text-xs font-normal text-slate-500">No later record in this queue</span>
    </nav>
</template>
