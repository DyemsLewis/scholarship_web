<script setup>
import { computed } from 'vue';

const props = defineProps({ rules: { type: Array, default: () => [] } });
const groups = computed(() => [
    { key: 'required', label: 'Required to apply', description: 'A confirmed mismatch prevents submission.', rules: props.rules.filter((rule) => rule.mode === 'required') },
    { key: 'preferred', label: 'Provider preferences', description: 'A mismatch is highlighted but does not prevent submission.', rules: props.rules.filter((rule) => rule.mode === 'preferred') },
]);
</script>

<template>
    <section class="border border-slate-200" aria-labelledby="eligibility-policy-summary-title" data-eligibility-policy-summary>
        <header class="border-b border-slate-200 bg-slate-50 px-5 py-4">
            <h4 id="eligibility-policy-summary-title" class="text-sm font-bold text-slate-950">Eligibility at submission</h4>
            <p class="mt-1 text-xs leading-5 text-slate-500">Confirm which rules are mandatory and which only express a preference.</p>
        </header>
        <section v-for="group in groups" :key="group.key" :data-rule-group="group.key" class="border-b border-slate-200 px-5 py-4 last:border-b-0">
            <div class="flex items-center justify-between gap-3"><h5 class="text-xs font-bold text-slate-900">{{ group.label }}</h5><span class="text-xs font-semibold text-slate-500">{{ group.rules.length }} rule{{ group.rules.length === 1 ? '' : 's' }}</span></div>
            <p class="mt-1 text-xs text-slate-500">{{ group.description }}</p>
            <dl v-if="group.rules.length" class="mt-3 divide-y divide-slate-100">
                <div v-for="rule in group.rules" :key="rule.key" class="grid grid-cols-[13rem_minmax(0,1fr)] gap-4 py-2 text-xs leading-5"><dt class="font-semibold text-slate-600">{{ rule.label }}</dt><dd class="min-w-0 break-words font-semibold text-slate-950">{{ rule.value }}</dd></div>
            </dl>
            <p v-else class="mt-3 text-xs text-slate-500">{{ group.key === 'required' ? 'No automatic eligibility restrictions are configured.' : 'No additional preferences are configured.' }}</p>
        </section>
        <p class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600">Verified profiles, required files, application dates, and capacity limits still apply. A match does not guarantee selection.</p>
    </section>
</template>
