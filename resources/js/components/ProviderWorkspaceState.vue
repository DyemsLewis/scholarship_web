<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'loading',
        validator: (value) => ['loading', 'error', 'empty'].includes(value),
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: '',
    },
});

const iconClass = computed(() => props.icon || ({
    loading: 'fa-solid fa-circle-notch animate-spin',
    error: 'fa-solid fa-triangle-exclamation',
    empty: 'fa-solid fa-inbox',
}[props.tone]));
const panelClass = computed(() => ({
    loading: 'border-slate-200 bg-white text-slate-700',
    error: 'border-slate-200 bg-slate-50 text-slate-700',
    empty: 'border-slate-200 bg-white text-slate-700',
}[props.tone]));
const iconToneClass = computed(() => ({
    loading: 'bg-slate-100 text-slate-500',
    error: 'bg-slate-100 text-slate-500',
    empty: 'bg-slate-100 text-slate-500',
}[props.tone]));
</script>

<template>
    <section
        :class="['flex items-start gap-3 rounded-sm border border-l-[3px] p-5', panelClass]"
        role="status"
        aria-live="polite"
    >
        <FeedbackToast v-if="tone === 'error'" :message="message || title" :title="title" />
        <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', iconToneClass]">
            <i :class="iconClass" aria-hidden="true"></i>
        </span>
        <div class="min-w-0 pt-0.5">
            <h2 class="text-sm font-bold">{{ title }}</h2>
            <p v-if="message && tone !== 'error'" class="mt-1 text-sm leading-5 opacity-80">{{ message }}</p>
            <slot></slot>
        </div>
    </section>
</template>
