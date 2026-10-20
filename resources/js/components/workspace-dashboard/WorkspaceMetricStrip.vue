<script setup>
import { computed } from 'vue';
import { dashboardToneClass } from '../../support/workspaceDashboard.js';

const props = defineProps({
    metrics: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${Math.max(1, props.metrics.length)}, minmax(0, 1fr))`,
}));
</script>

<template>
    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Workspace summary">
        <div v-if="loading" class="px-6 py-7 text-sm font-semibold text-slate-500">Loading dashboard summary...</div>
        <ul v-else-if="metrics.length" class="grid divide-x divide-slate-200" :style="gridStyle">
            <li v-for="metric in metrics" :key="metric.key" class="min-w-0">
                <component :is="metric.url ? 'a' : 'div'" :href="metric.url || undefined" class="flex min-w-0 items-start gap-4 px-5 py-4 hover:bg-slate-50">
                    <span :class="['grid h-9 w-9 shrink-0 place-items-center', metric.tone === 'attention' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600']" aria-hidden="true">
                        <i :class="[metric.icon, 'text-sm']"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ metric.label }}</p>
                        <p :class="['mt-1 text-2xl font-black', dashboardToneClass(metric.tone)]">{{ metric.value }}</p>
                        <p v-if="metric.detail" class="mt-1 truncate text-xs text-slate-500">{{ metric.detail }}</p>
                    </div>
                </component>
            </li>
        </ul>
    </section>
</template>
