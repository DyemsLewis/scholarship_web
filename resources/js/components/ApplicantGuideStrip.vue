<script setup>
defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <section v-if="items.length" class="grid gap-3 md:grid-cols-3">
        <article
            v-for="item in items"
            :key="item.title"
            class="student-visual-card flex gap-3"
        >
            <span class="student-icon-badge">
                <i :class="[item.icon || 'fa-solid fa-circle-check', 'text-sm']"></i>
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-950">
                    {{ item.title }}
                </span>
                <span class="mt-1 block text-xs leading-5 text-slate-600">
                    {{ item.text }}
                </span>
            </span>
        </article>
    </section>
</template>
