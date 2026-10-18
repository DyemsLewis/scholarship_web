<script setup>
import WorkspaceDashboardHeader from './WorkspaceDashboardHeader.vue';
import WorkspaceMetricStrip from './WorkspaceMetricStrip.vue';

defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    errorMessage: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="mx-auto max-w-[94rem]">
        <WorkspaceDashboardHeader :workspace="dashboard.workspace" :primary-action="dashboard.primary_action">
            <template v-if="$slots.actions" #actions><slot name="actions"></slot></template>
        </WorkspaceDashboardHeader>

        <div v-if="errorMessage" class="mt-4 border border-rose-200 border-l-4 border-l-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">
            {{ errorMessage }}
        </div>

        <WorkspaceMetricStrip v-if="!errorMessage" :metrics="dashboard.metrics" :loading="loading" />

        <div v-if="!errorMessage && !loading" class="mt-4 space-y-4">
            <slot></slot>
        </div>
    </div>
</template>
