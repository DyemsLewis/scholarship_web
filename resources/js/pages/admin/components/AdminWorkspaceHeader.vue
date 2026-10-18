<script setup>
import TaskPageHeader from '../../../components/TaskPageHeader.vue';

defineProps({
    eyebrow: {
        type: String,
        required: true,
    },
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
        default: 'fa-solid fa-list-check',
    },
});
</script>

<template>
    <TaskPageHeader
        theme="admin"
        :eyebrow="eyebrow"
        :title="title"
        :description="description"
        :icon="icon"
    >
        <template v-if="$slots.meta" #meta>
            <slot name="meta"></slot>
        </template>
        <template v-if="$slots.actions" #actions>
            <slot name="actions"></slot>
        </template>
    </TaskPageHeader>
</template>
