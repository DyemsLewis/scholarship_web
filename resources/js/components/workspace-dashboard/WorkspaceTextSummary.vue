<script setup>
import { computed } from 'vue';

const props = defineProps({
    text: { type: String, default: '' },
    label: { type: String, default: 'note' },
});
const textValue = computed(() => String(props.text ?? ''));
const isLong = computed(() => textValue.value.length > 160);
const preview = computed(() => `${textValue.value.slice(0, 160).trimEnd()}...`);
</script>

<template>
    <div class="min-w-0" data-workspace-text-summary>
        <p v-if="!isLong" class="application-text whitespace-pre-line">{{ textValue }}</p>
        <details v-else class="group">
            <summary class="cursor-pointer list-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700">
                <span class="line-clamp-2 group-open:hidden">{{ preview }}</span>
                <span class="mt-1 inline-flex min-h-8 items-center gap-2 font-semibold text-slate-700">
                    <span class="group-open:hidden">Read full {{ label }}</span>
                    <span class="hidden group-open:inline">Hide {{ label }}</span>
                    <i class="fa-solid fa-chevron-down text-xs group-open:rotate-180" aria-hidden="true"></i>
                </span>
            </summary>
            <p class="application-text mt-2 whitespace-pre-line">{{ textValue }}</p>
        </details>
    </div>
</template>
