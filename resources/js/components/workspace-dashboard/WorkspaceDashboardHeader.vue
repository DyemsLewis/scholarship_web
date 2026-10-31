<script setup>
import { computed } from 'vue';

const props = defineProps({
    workspace: {
        type: Object,
        required: true,
    },
    primaryAction: {
        type: Object,
        default: null,
    },
});

const headingId = computed(() => `${props.workspace.key || 'workspace'}-dashboard-title`);
</script>

<template>
    <header class="workspace-dashboard-header border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
        <div class="workspace-dashboard-header-row flex min-h-20 items-center justify-between gap-8">
            <div class="workspace-dashboard-heading flex min-w-0 items-start gap-4">
                <span class="workspace-dashboard-icon grid h-11 w-11 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true">
                    <i :class="workspace.icon"></i>
                </span>
                <div class="min-w-0">
                    <p class="workspace-dashboard-label text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">{{ workspace.eyebrow }}</p>
                    <h1 :id="headingId" class="workspace-dashboard-title mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ workspace.title }}</h1>
                    <p v-if="workspace.description" class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ workspace.description }}</p>
                </div>
            </div>

            <slot name="actions">
                <a v-if="primaryAction" :href="primaryAction.url" class="workspace-dashboard-action inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">
                    {{ primaryAction.label }}
                    <i :class="[primaryAction.icon, 'text-xs text-amber-300']" aria-hidden="true"></i>
                </a>
            </slot>
        </div>
    </header>
</template>
