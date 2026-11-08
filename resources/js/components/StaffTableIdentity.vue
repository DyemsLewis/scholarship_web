<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, default: '' },
    kind: { type: String, default: 'applicant' },
    icon: { type: String, default: '' },
});

const placeholders = {
    applicant: { image: '/images/demo/default-applicant-avatar.jpg', icon: 'fa-solid fa-user' },
    provider: { icon: 'fa-solid fa-building-columns' },
    program: { image: '/uploads/scholarship-default.jpg', icon: 'fa-solid fa-graduation-cap' },
};
const placeholder = computed(() => placeholders[props.kind] ?? {});
const primary = computed(() => props.src?.trim() || '');
const fallback = computed(() => placeholder.value.image || '');
const primaryFailed = ref(false);
const fallbackFailed = ref(false);
const imageSrc = computed(() => primary.value && !primaryFailed.value
    ? primary.value
    : (!fallbackFailed.value && fallback.value !== primary.value ? fallback.value : ''));
const isLogo = computed(() => props.kind === 'provider' || props.kind === 'program');

watch([primary, fallback], () => {
    primaryFailed.value = false;
    fallbackFailed.value = false;
});

function useFallback(event) {
    const failedSrc = event.currentTarget.getAttribute('src');
    if (failedSrc !== imageSrc.value) return;
    if (failedSrc === primary.value) primaryFailed.value = true;
    if (failedSrc === fallback.value) fallbackFailed.value = true;
}
</script>

<template>
    <span class="grid shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500" aria-hidden="true">
        <img
            v-if="imageSrc"
            :key="imageSrc"
            :src="imageSrc"
            alt=""
            class="h-full w-full min-h-0 min-w-0"
            :class="isLogo ? 'bg-white object-contain p-1' : 'object-cover'"
            @error="useFallback"
        >
        <i v-else :class="icon || placeholder.icon || 'fa-solid fa-user'" class="text-sm"></i>
    </span>
</template>
