<script setup>
import { computed, ref, useId, watch } from 'vue';
import { commonProgramDocuments, commonDocumentLabel, splitDocumentRequirements, addCommonDocument, removeCommonDocument, setSpecificDocuments } from '../../../../support/programDocumentRequirements.js';

const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, required: true }, required: Boolean, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const id = useId();
const groups = [...new Set(commonProgramDocuments.map((item) => item.group))];
const parts = computed(() => splitDocumentRequirements(props.modelValue));
const selected = computed(() => [...new Set(parts.value.common.map(commonDocumentLabel))]);
const specific = ref(parts.value.specific.join('\n'));
let editing = false, savedCommon = [];
watch(() => props.modelValue, () => { if (!editing) specific.value = parts.value.specific.join('\n'); });
function startSpecific() { editing = true; savedCommon = [...parts.value.common]; }
function inputSpecific(event) { specific.value = event.target.value; emit('update:modelValue', [...savedCommon, specific.value].join('\n')); }
function finishSpecific() { editing = false; const value = setSpecificDocuments(savedCommon.join('\n'), specific.value); specific.value = splitDocumentRequirements(value).specific.join('\n'); emit('update:modelValue', value); }
function choose(event) { emit('update:modelValue', addCommonDocument(props.modelValue, event.target.value)); event.target.value = ''; }
</script>

<template>
    <fieldset :disabled="disabled" class="min-w-0 space-y-3">
        <legend class="mb-2 text-sm font-bold text-slate-700">{{ label }} <span v-if="required" class="text-amber-700">Required</span></legend>
        <label :for="`${id}-common`" class="block text-xs font-bold text-slate-600">Common documents</label>
        <select :id="`${id}-common`" :aria-describedby="`${id}-hint`" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm text-slate-950 focus:border-slate-800 focus:ring-1 focus:ring-slate-800" @change="choose">
            <option value="">Select a common document</option>
            <optgroup v-for="group in groups" :key="group" :label="group"><option v-for="item in commonProgramDocuments.filter((item) => item.group === group)" :key="item.label" :value="item.label" :disabled="selected.includes(item.label)">{{ item.label }}{{ selected.includes(item.label) ? ' (selected)' : '' }}</option></optgroup>
        </select>
        <p :id="`${id}-hint`" class="text-xs leading-5 text-slate-500">Select common files above. Only type documents specific to your program below.</p>
        <ul v-if="selected.length" class="divide-y divide-slate-200 border-y border-slate-200"><li v-for="document in selected" :key="document" class="flex items-center justify-between gap-3 py-2 text-sm text-slate-800"><span>{{ document }}</span><button type="button" :aria-label="`Remove ${document} from ${label}`" class="grid h-8 w-8 shrink-0 place-items-center text-slate-500 hover:bg-slate-100 hover:text-slate-950" @click="emit('update:modelValue', removeCommonDocument(modelValue, document))"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></li></ul>
        <label :for="`${id}-specific`" class="block text-xs font-bold text-slate-600">Program-specific documents <span class="font-normal text-slate-400">Optional</span></label>
        <textarea :id="`${id}-specific`" :value="specific" rows="3" maxlength="5000" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-950 placeholder:text-slate-400 focus:border-slate-800 focus:ring-1 focus:ring-slate-800" placeholder="For example: Signed research proposal for this program. One document per line." @focus="startSpecific" @input="inputSpecific" @blur="finishSpecific"></textarea>
    </fieldset>
</template>
