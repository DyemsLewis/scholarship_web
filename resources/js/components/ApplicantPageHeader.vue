<script setup>
import TaskPageHeader from './TaskPageHeader.vue';

const props = defineProps({
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
        default: 'fa-solid fa-award',
    },
    actionHref: {
        type: String,
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
    secondaryHref: {
        type: String,
        default: '',
    },
    secondaryLabel: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <TaskPageHeader
        theme="applicant"
        :eyebrow="props.eyebrow"
        :title="props.title"
        :description="props.description"
        :icon="props.icon"
        :action-href="props.actionHref"
        :action-label="props.actionLabel"
        :secondary-href="props.secondaryHref"
        :secondary-label="props.secondaryLabel"
    >
        <template v-if="$slots.actions" #actions>
            <slot name="actions"></slot>
        </template>
    </TaskPageHeader>
</template>
