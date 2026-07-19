<script setup>
import { computed, onUnmounted, watch } from 'vue';
import { getTermsContent } from '../support/termsContent';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    context: {
        type: String,
        default: 'general',
    },
});

const emit = defineEmits(['update:modelValue']);

const selectedContent = computed(() => getTermsContent(props.context));

function closeModal() {
    emit('update:modelValue', false);
}

watch(() => props.modelValue, (isOpen) => {
    document.body.classList.toggle('overflow-hidden', isOpen);
}, { immediate: true });

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="modelValue"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
            role="dialog"
            aria-modal="true"
            tabindex="-1"
            @click.self="closeModal"
            @keydown.esc="closeModal"
        >
            <section class="max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-xl bg-white text-slate-900 shadow-2xl">
                <header class="relative overflow-hidden bg-slate-950 px-5 py-5 text-white sm:px-6">
                    <div class="absolute right-5 top-5 flex gap-1 opacity-70">
                        <span class="h-2 w-8 rounded-full bg-amber-300"></span>
                        <span class="h-2 w-2 rounded-full bg-white/40"></span>
                    </div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                        Terms and Conditions
                    </p>
                    <h2 class="mt-2 pr-16 font-display text-2xl font-bold leading-tight">
                        {{ selectedContent.title }}
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">
                        {{ selectedContent.summary }}
                    </p>
                </header>

                <div class="max-h-[58vh] overflow-y-auto p-5 sm:p-6">
                    <div class="grid gap-3">
                        <div
                            v-for="detail in selectedContent.details"
                            :key="detail"
                            class="flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"
                        >
                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-check text-xs"></i>
                            </span>
                            <span>{{ detail }}</span>
                        </div>
                    </div>

                    <div v-if="selectedContent.sections?.length" class="mt-5 grid gap-3 sm:grid-cols-2">
                        <article
                            v-for="section in selectedContent.sections"
                            :key="section.title"
                            class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <span class="flex h-10 w-10 items-center justify-center rounded-md bg-slate-950 text-amber-200">
                                <i :class="[section.icon, 'text-sm']"></i>
                            </span>
                            <h3 class="mt-3 font-bold text-slate-950">
                                {{ section.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ section.text }}
                            </p>
                        </article>
                    </div>

                </div>

                <footer class="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-6">
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        @click="closeModal"
                    >
                        I understand
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
