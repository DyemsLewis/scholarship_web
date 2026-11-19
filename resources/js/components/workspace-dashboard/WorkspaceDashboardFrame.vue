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
    <div class="workspace-dashboard-frame mx-auto w-full max-w-[94rem]">
        <WorkspaceDashboardHeader :workspace="dashboard.workspace" :primary-action="dashboard.primary_action">
            <template v-if="$slots.actions" #actions><slot name="actions"></slot></template>
        </WorkspaceDashboardHeader>

        <template v-if="errorMessage">
            <slot name="error">
                <FeedbackToast class="mt-4" :message="errorMessage" fallback />
            </slot>
        </template>

        <WorkspaceMetricStrip v-if="!errorMessage" :metrics="dashboard.metrics" :loading="loading" />

        <div v-if="!errorMessage && !loading" class="mt-4 space-y-4">
            <slot></slot>
        </div>
    </div>
</template>
