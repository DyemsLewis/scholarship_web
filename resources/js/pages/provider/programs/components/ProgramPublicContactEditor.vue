<script setup>
import { computed, ref, watch } from 'vue';
import { copyProgramPublicContact } from '../../../../support/programScheduleDetails.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    disabled: Boolean,
    inputClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const draft = ref(copyProgramPublicContact());
const summary = computed(() => [props.modelValue.contactEmail, props.modelValue.contactNumber].filter(value => value?.trim()).join(' | '));

function show() {
    if (props.disabled) return;
    draft.value = copyProgramPublicContact(props.modelValue);
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled) return;
    emit('update:modelValue', copyProgramPublicContact(draft.value));
    close();
}

watch(() => props.disabled, disabled => { if (disabled) close(); });
watch(() => props.modelValue, close);
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="program-public-contact-title" data-program-public-contact>
        <div class="min-w-0">
            <h4 id="program-public-contact-title" class="text-sm font-bold text-slate-950">Public contact</h4>
            <p class="mt-1 break-words text-xs text-slate-600">{{ summary || 'No email or phone number set' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-sm px-2 py-1 text-xs font-semibold" :class="summary ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'" aria-live="polite">{{ summary ? 'Contact available' : 'Contact needed' }}</span>
            <button type="button" :disabled="disabled" aria-haspopup="dialog" :aria-expanded="open" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show">Edit contact</button>
        </div>

        <EligibilityOptionsModal :open="open" id="program-public-contact" title="Public contact" eyebrow="Schedule" description="Add at least one public email or phone number before submitting the program." apply-label="Apply changes" :busy="disabled" @close="close" @apply="apply">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-semibold text-slate-700">Public email<input v-model="draft.contactEmail" type="email" maxlength="255" :class="[inputClass, 'mt-1.5']"></label>
                <label class="block text-sm font-semibold text-slate-700">Public contact number<input v-model="draft.contactNumber" type="tel" maxlength="30" :class="[inputClass, 'mt-1.5']"></label>
                <label class="block text-sm font-semibold text-slate-700">Contact person <span class="font-normal text-slate-500">(optional)</span><input v-model="draft.contactPerson" v-capitalize-name autocapitalize="sentences" type="text" maxlength="150" :class="[inputClass, 'mt-1.5']"></label>
                <label class="block text-sm font-semibold text-slate-700">Department <span class="font-normal text-slate-500">(optional)</span><input v-model="draft.contactDepartment" type="text" maxlength="150" :class="[inputClass, 'mt-1.5']"></label>
                <label class="block text-sm font-semibold text-slate-700 sm:col-span-2">Official program page <span class="font-normal text-slate-500">(optional)</span><input v-model="draft.officialProgramUrl" type="url" maxlength="2048" :class="[inputClass, 'mt-1.5']" placeholder="https://"></label>
            </div>
        </EligibilityOptionsModal>
    </section>
</template>
