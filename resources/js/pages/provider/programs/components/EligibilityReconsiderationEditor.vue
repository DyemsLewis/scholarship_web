<script setup>
import { computed, ref, watch } from 'vue';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    rules: { type: Array, required: true },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const draft = ref([]);
const requiredRules = computed(() => props.rules.filter(rule => rule.mode === 'required'));
const allowedKeys = computed(() => new Set(requiredRules.value.map(rule => rule.key)));
const selectedCount = computed(() => props.modelValue.filter(key => allowedKeys.value.has(key)).length);

function show() {
    if (props.disabled || !requiredRules.value.length) return;
    draft.value = props.modelValue.filter(key => allowedKeys.value.has(key));
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled) return;
    const selected = new Set(draft.value.filter(key => allowedKeys.value.has(key)));
    // Keep inactive settings as before; only current Required rules can be reconsidered.
    const committed = props.modelValue.filter(key => !allowedKeys.value.has(key) || selected.has(key));
    emit('update:modelValue', [...new Set([...committed, ...selected])]);
    close();
}

watch([() => props.disabled, () => props.modelValue, () => props.rules], close);
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="eligibility-reconsideration-title" data-eligibility-review-policy>
        <div>
            <h4 id="eligibility-reconsideration-title" class="text-sm font-bold text-slate-950">Eligibility reconsideration</h4>
            <p class="mt-1 text-xs text-slate-500">{{ requiredRules.length ? 'Let applicants request a review of selected required rules.' : 'Add a required rule to offer reconsideration.' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-sm bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600" aria-live="polite">{{ selectedCount ? `${selectedCount} selected` : 'None selected' }}</span>
            <button id="program-eligibility-reconsideration" type="button" :disabled="disabled || !requiredRules.length" aria-haspopup="dialog" :aria-expanded="open" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show">{{ selectedCount ? 'Edit options' : 'Choose rules' }}</button>
        </div>

        <EligibilityOptionsModal :open="open" id="program-eligibility-reconsideration" title="Eligibility reconsideration" eyebrow="Program eligibility" description="Choose which required rules an applicant can ask you to reconsider before applying." apply-label="Apply changes" :busy="disabled" @close="close" @apply="apply">
            <fieldset class="grid gap-2 sm:grid-cols-2">
                <legend class="sr-only">Required rules that allow reconsideration</legend>
                <label v-for="rule in requiredRules" :key="rule.key" class="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-sm border border-slate-200 px-3 py-2 text-sm text-slate-800 has-[:checked]:bg-slate-50">
                    <input v-model="draft" type="checkbox" :value="rule.key" class="h-4 w-4 shrink-0">
                    <span>{{ rule.label }}</span>
                </label>
            </fieldset>
            <p class="text-xs leading-5 text-slate-600">Unchecked rules stay mandatory. Reviewers must give a reason to approve an exception. Profile verification, application dates, required files, and intake limits cannot be waived here.</p>
        </EligibilityOptionsModal>
    </section>
</template>
