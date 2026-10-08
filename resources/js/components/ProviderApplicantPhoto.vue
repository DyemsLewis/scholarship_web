<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, default: '' },
    name: { type: String, default: 'Applicant' },
});

const failed = ref(false);

watch(() => props.src, () => {
    failed.value = false;
});
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        :alt="`${name} profile photo`"
        class="h-10 w-10 shrink-0 rounded-sm border border-slate-200 bg-slate-100 object-cover"
        @error="failed = true"
    >
    <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-100 text-slate-500" aria-hidden="true">
        <i class="fa-solid fa-user"></i>
    </span>
</template>
