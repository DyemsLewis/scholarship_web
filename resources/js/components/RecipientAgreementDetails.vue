<script setup>
import { computed } from 'vue';
import { recipientAgreementReviewRows } from '../support/recipientAgreement.js';

const props = defineProps({ scholarship: { type: Object, required: true } });
const rows = computed(() => recipientAgreementReviewRows(props.scholarship));
</script>

<template>
    <dl v-if="rows.length" data-recipient-agreement-details class="space-y-4">
        <div v-for="row in rows" :key="row.key">
            <dt :class="['text-xs font-bold', row.issue ? 'text-amber-800' : 'text-slate-700']">{{ row.label }}</dt>
            <dd :class="['mt-1 whitespace-pre-line break-words text-sm leading-6 [overflow-wrap:anywhere]', row.issue ? 'text-amber-900' : 'text-slate-600']">{{ row.value }}</dd>
        </div>
    </dl>
    <p v-else class="text-sm text-slate-500">No recipient agreement has been provided.</p>
</template>
