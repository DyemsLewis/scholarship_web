<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: 'fa-solid fa-check',
    },
});
</script>

<template>
    <div class="px-6 py-12 text-center">
        <span class="mx-auto grid h-11 w-11 place-items-center rounded-sm bg-slate-100 text-slate-500">
            <i :class="icon" aria-hidden="true"></i>
        </span>
        <p class="mt-3 font-bold text-slate-950">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-lg text-sm text-slate-500">{{ description }}</p>
        <div v-if="$slots.actions" class="mt-4 flex justify-center">
            <slot name="actions"></slot>
        </div>
    </div>
</template>
