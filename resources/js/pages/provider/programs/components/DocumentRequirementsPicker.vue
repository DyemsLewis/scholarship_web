<script setup>
import { computed, ref, useId, watch } from 'vue';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';
import { commonProgramDocuments, commonDocumentLabel, requirementLines, splitDocumentRequirements, addCommonDocument, removeCommonDocument, setSpecificDocuments, documentGroupConflict } from '../../../../support/programDocumentRequirements.js';

const props = defineProps({ modelValue: { type: String, default: '' }, otherGroupValue: { type: String, default: '' }, label: { type: String, required: true }, required: Boolean, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const id = useId();
const groups = [...new Set(commonProgramDocuments.map(item => item.group))].map(group => ({
    name: group,
    documents: commonProgramDocuments.filter(item => item.group === group),
}));
const selectedCount = computed(() => requirementLines(props.modelValue).length);
const open = ref(false);
const draftCommon = ref('');
const draftSpecific = ref('');
const draftSelected = computed(() => new Set(requirementLines(draftCommon.value).map(commonDocumentLabel)));
const draftValue = computed(() => setSpecificDocuments(draftCommon.value, draftSpecific.value));
const draftCount = computed(() => requirementLines(draftValue.value).length);
const tooLong = computed(() => [...draftValue.value].length > 5000);
const duplicate = computed(() => documentGroupConflict(props.otherGroupValue, draftValue.value));
let initialDraft = '';

function show() {
    if (props.disabled) return;
    const { common, specific } = splitDocumentRequirements(props.modelValue);
    draftCommon.value = common.join('\n');
    draftSpecific.value = specific.join('\n');
    initialDraft = draftValue.value;
    open.value = true;
}

function toggleDocument(label, checked) {
    draftCommon.value = checked ? addCommonDocument(draftCommon.value, label) : removeCommonDocument(draftCommon.value, label);
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled || tooLong.value || duplicate.value) return;
    if (draftValue.value !== initialDraft) emit('update:modelValue', draftValue.value);
    close();
}

watch(() => props.disabled, disabled => { if (disabled) close(); });
</script>

<template>
    <div class="min-w-0" data-document-requirements-picker>
        <div class="flex min-h-14 items-center gap-x-3 gap-y-2 rounded-sm border border-slate-200 bg-white px-3 py-2">
            <div class="min-w-0 flex-1">
                <span class="text-sm font-bold text-slate-950">{{ label }}</span>
                <span v-if="required" class="ml-2 text-xs font-semibold text-amber-700">Required</span>
            </div>
            <span :id="`${id}-count`" class="shrink-0 rounded-sm bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600" aria-live="polite">{{ selectedCount ? `${selectedCount} selected` : 'None selected' }}</span>
            <button type="button" :disabled="disabled" :aria-label="`${selectedCount ? 'Edit' : 'Choose'} ${label}`" :aria-describedby="`${id}-count`" aria-haspopup="dialog" :aria-expanded="open" :aria-controls="open ? `${id}-options` : undefined" class="inline-flex min-h-8 shrink-0 items-center gap-1.5 rounded-sm px-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50" @click="show"><i class="fa-solid fa-sliders text-[10px]" aria-hidden="true"></i>{{ selectedCount ? 'Edit' : 'Choose' }}</button>
        </div>

        <EligibilityOptionsModal :open="open" :id="id" :title="label" eyebrow="Application documents" description="Select common documents. Only type documents specific to your program." :busy="disabled" :apply-disabled="tooLong || Boolean(duplicate)" @close="close" @apply="apply">
            <p v-if="duplicate" role="status" class="sticky top-0 z-10 rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">{{ duplicate }}</p>
            <div class="text-xs font-bold uppercase tracking-wider text-slate-600">Common documents <span class="ml-2 font-normal normal-case tracking-normal" aria-live="polite">{{ draftCount }} selected in total</span></div>
            <fieldset v-for="group in groups" :key="group.name" class="min-w-0">
                <legend class="mb-2 text-xs font-bold text-slate-700">{{ group.name }}</legend>
                <div class="grid gap-1.5 sm:grid-cols-2">
                    <label v-for="item in group.documents" :key="item.label" class="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-sm border border-slate-200 px-3 py-2 text-sm text-slate-800 has-[:checked]:bg-slate-50">
                        <input type="checkbox" :checked="draftSelected.has(item.label)" :value="item.label" class="shrink-0 rounded border-slate-300 text-slate-950 focus:ring-slate-700" @change="toggleDocument(item.label, $event.target.checked)">
                        <span>{{ item.label }}</span>
                    </label>
                </div>
            </fieldset>
            <div class="border-t border-slate-200 pt-4">
                <label :for="`${id}-specific`" class="mb-2 block text-xs font-bold text-slate-700">Program-specific documents <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea :id="`${id}-specific`" v-model="draftSpecific" rows="3" maxlength="5000" :aria-invalid="tooLong || undefined" :aria-describedby="`${id}-specific-hint`" class="w-full rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-950 placeholder:text-slate-400 focus:border-slate-800 focus:ring-1 focus:ring-slate-800" placeholder="Example: Signed research proposal"></textarea>
                <p :id="`${id}-specific-hint`" class="mt-1 text-xs" :class="tooLong ? 'text-rose-700' : 'text-slate-500'" aria-live="polite">{{ tooLong ? 'Shorten the list to 5,000 characters or fewer, including common documents.' : 'Enter one document per line.' }}</p>
            </div>
        </EligibilityOptionsModal>
    </div>
</template>
