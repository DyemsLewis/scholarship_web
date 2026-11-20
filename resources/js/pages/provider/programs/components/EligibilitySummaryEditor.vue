<script setup>
import { computed, ref, watch } from 'vue';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    automatic: Boolean,
    generatedSummary: { type: String, default: '' },
    textareaClass: { type: String, default: '' },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue', 'update:automatic']);
const open = ref(false);
const draftAutomatic = ref(true);
const draftCustom = ref('');
const currentSummary = computed(() => props.automatic ? props.generatedSummary : props.modelValue);
const draftSummary = computed(() => draftAutomatic.value ? props.generatedSummary : draftCustom.value);
const tooLong = computed(() => draftSummary.value.length > 5000);

function show() {
    if (props.disabled) return;
    draftAutomatic.value = props.automatic;
    draftCustom.value = currentSummary.value;
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled || tooLong.value) return;
    if (!draftAutomatic.value) emit('update:modelValue', draftCustom.value);
    emit('update:automatic', draftAutomatic.value);
    close();
}

watch([() => props.disabled, () => props.modelValue, () => props.automatic, () => props.generatedSummary], close);
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="eligibility-summary-title" data-eligibility-summary-panel>
        <div>
            <h4 id="eligibility-summary-title" class="text-sm font-bold text-slate-950">Applicant-facing summary</h4>
            <p class="mt-1 text-xs text-slate-500">{{ automatic ? 'Built from your Required and Preferred rules.' : 'Uses your custom wording.' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-sm bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ automatic ? 'Automatic' : 'Custom' }}</span>
            <span v-if="currentSummary.length > 5000" class="rounded-sm bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">Too long</span>
            <button id="program-eligibility-summary-edit" type="button" :disabled="disabled" aria-haspopup="dialog" :aria-expanded="open" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show">Edit summary</button>
        </div>

        <EligibilityOptionsModal :open="open" id="program-summary-editor" title="Applicant-facing summary" eyebrow="Program eligibility" description="Review what applicants see. You can use the automatic summary or write your own." apply-label="Apply changes" :busy="disabled" :apply-disabled="tooLong" @close="close" @apply="apply">
            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <label for="program-eligibility-summary" class="text-sm font-semibold text-slate-700">Eligibility summary <span class="text-xs text-amber-700">Required</span></label>
                    <button v-if="draftAutomatic" type="button" class="text-xs font-bold text-slate-600 underline underline-offset-2" @click="draftAutomatic = false">Write custom summary</button>
                    <button v-else type="button" class="text-xs font-bold text-slate-600 underline underline-offset-2" @click="draftAutomatic = true">Use rule-based summary</button>
                </div>
                <textarea id="program-eligibility-summary" :value="draftSummary" :readonly="draftAutomatic" :aria-invalid="tooLong || undefined" aria-describedby="program-eligibility-summary-hint" rows="7" maxlength="5000" :class="textareaClass" :placeholder="draftAutomatic ? 'Configure eligibility rules to generate this summary.' : 'Describe who qualifies and what you prefer.'" @input="draftCustom = $event.target.value"></textarea>
                <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs leading-5 text-slate-500">
                    <p id="program-eligibility-summary-hint" :class="tooLong ? 'font-semibold text-rose-700' : ''">{{ tooLong ? 'Shorten the rule lists or write a shorter custom summary.' : (draftAutomatic ? 'Updates when you change the rules.' : 'Keep the wording consistent with the configured rules.') }}</p>
                    <span>{{ draftSummary.length }} / 5000</span>
                </div>
            </div>
        </EligibilityOptionsModal>
    </section>
</template>
