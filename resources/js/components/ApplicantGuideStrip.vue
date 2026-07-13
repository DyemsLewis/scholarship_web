<script setup>
defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <section v-if="items.length" class="student-card grid overflow-hidden md:grid-cols-3">
        <article
            v-for="(item, index) in items"
            :key="item.title"
            :class="[
                'flex min-h-20 gap-3 p-4',
                index ? 'border-t border-slate-200 md:border-l md:border-t-0' : '',
            ]"
        >
            <span class="student-icon-badge">
                <i :class="[item.icon || 'fa-solid fa-circle-check', 'text-sm']"></i>
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-bold text-slate-950">
                    {{ item.title }}
                </span>
                <span class="mt-0.5 block line-clamp-2 text-xs leading-5 text-slate-500">
                    {{ item.text }}
                </span>
            </span>
        </article>
    </section>
</template>
