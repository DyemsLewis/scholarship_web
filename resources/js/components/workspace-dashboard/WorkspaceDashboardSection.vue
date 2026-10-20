<script setup>
defineProps({
    section: {
        type: Object,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <section class="border border-slate-200 bg-white shadow-sm" :aria-labelledby="`${section.key}-title`">
        <header class="flex min-h-20 items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i :class="[section.icon, 'text-sm']"></i></span>
                <div class="min-w-0">
                    <p v-if="section.eyebrow" class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">{{ section.eyebrow }}</p>
                    <h2 :id="`${section.key}-title`" class="text-lg font-black text-slate-950" :class="section.eyebrow ? 'mt-1' : ''">{{ section.title }}</h2>
                    <p v-if="section.description" class="mt-1 text-sm text-slate-500">{{ section.description }}</p>
                </div>
            </div>
            <a v-if="section.action" :href="section.action.url" class="inline-flex h-10 shrink-0 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950 hover:text-slate-950">
                {{ section.action.label }}
                <i :class="[section.action.icon, 'text-[10px]']" aria-hidden="true"></i>
            </a>
        </header>

        <div v-if="loading" class="px-6 py-12 text-center text-sm font-semibold text-slate-500">Loading work items...</div>
        <slot v-else-if="section.items.length" :items="section.items"></slot>
        <div v-else class="px-6 py-10 text-center">
            <i class="fa-solid fa-circle-check text-lg text-emerald-600" aria-hidden="true"></i>
            <p class="mt-2 text-sm font-semibold text-slate-600">{{ section.empty_message }}</p>
        </div>
    </section>
</template>
