<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <section class="admin-panel overflow-hidden">
        <header v-if="title || description || $slots.actions" class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div class="min-w-0">
                <h2 v-if="title" class="text-base font-black text-slate-950">{{ title }}</h2>
                <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="shrink-0">
                <slot name="actions"></slot>
            </div>
        </header>

        <div v-if="$slots.filters" class="border-b border-slate-200 bg-slate-50 p-3">
            <slot name="filters"></slot>
        </div>

        <div class="staff-table-viewport">
            <slot></slot>
        </div>

        <footer v-if="$slots.footer" class="border-t border-slate-200 bg-slate-50 px-5 py-3">
            <slot name="footer"></slot>
        </footer>
    </section>
</template>
