<script setup>
import { computed } from 'vue';
import { educationPolicySummary } from '../support/educationEligibilityPolicy.js';

const props = defineProps({ policy: { type: Object, default: null }, modes: { type: Object, default: () => ({}) } });
const rows = computed(() => educationPolicySummary(props.policy, props.modes));
</script>

<template>
    <dl v-if="rows.length" data-education-policy-summary class="mt-4 divide-y divide-slate-200 border-y border-slate-200 text-sm">
        <div v-for="row in rows" :key="row.key" class="grid grid-cols-[12rem_minmax(0,1fr)] gap-6 py-3">
            <dt class="font-semibold text-slate-600">{{ row.label }}<span v-if="row.mode" class="mt-1 block text-xs font-normal text-slate-500">{{ row.mode }}</span></dt>
            <dd class="min-w-0 break-words leading-6 text-slate-800">{{ row.value }}</dd>
        </div>
    </dl>
</template>
