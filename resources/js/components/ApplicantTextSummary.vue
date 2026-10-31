<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const props = defineProps({
    text: { type: String, default: '' },
    label: { type: String, default: 'benefit summary' },
});
const textId = useId();
const paragraph = ref(null);
const expanded = ref(false);
const overflowing = ref(false);
let observer;

function measure() {
    if (!paragraph.value || expanded.value) return;
    overflowing.value = paragraph.value.scrollHeight > paragraph.value.clientHeight + 1;
}

onMounted(async () => {
    await nextTick();
    if (!paragraph.value) return;
    measure();
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure);
        observer.observe(paragraph.value);
    }
    document.fonts?.ready.then(measure);
    window.addEventListener('resize', measure);
});

watch(() => props.text, async () => {
    expanded.value = false;
    await nextTick();
    measure();
});
watch(expanded, async () => {
    await nextTick();
    measure();
});

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('resize', measure);
});
</script>

<template>
    <div class="min-w-0" data-applicant-text-summary>
        <p :id="textId" ref="paragraph" :class="['whitespace-pre-line break-words text-sm leading-6 text-slate-600', { 'line-clamp-2': !expanded }]" data-summary-text>{{ text }}</p>
        <button v-if="overflowing || expanded" type="button" :aria-controls="textId" :aria-expanded="expanded" :aria-label="expanded ? `Collapse ${label}` : `Read full ${label}`" class="student-button student-button-secondary student-button-compact mt-2" data-summary-toggle @click="expanded = !expanded">
            {{ expanded ? 'Show less' : 'Show more' }}
            <i :class="expanded ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" aria-hidden="true"></i>
        </button>
    </div>
</template>
