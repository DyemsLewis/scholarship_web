<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue';
import { evidenceError } from '../../support/agreementConcerns';

const props = defineProps({ modelValue: { type: Object, default: null }, disabled: Boolean, required: { type: Boolean, default: true } });
const emit = defineEmits(['update:modelValue']);
const input = ref(null), error = ref(''), preview = ref('');
const id = useId();
function clearPreview() { if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; }
watch(() => props.modelValue, (file) => {
    clearPreview();
    if (file?.type?.startsWith('image/')) preview.value = URL.createObjectURL(file);
    if (!file && input.value) input.value.value = '';
}, { immediate: true });
function choose(event) {
    const file = event.target.files?.[0] || null;
    error.value = file ? evidenceError([file], 1) : '';
    emit('update:modelValue', error.value ? null : file);
    if (error.value) event.target.value = '';
}
onBeforeUnmount(clearPreview);
</script>

<template>
    <div data-receipt-proof-upload>
        <label :for="id" class="block text-xs font-bold text-slate-700">{{ required ? 'Proof you received the benefit' : 'Supporting evidence' }} <span :class="required ? 'text-amber-700' : 'font-normal text-slate-500'">({{ required ? 'required' : 'optional' }})</span></label>
        <p :id="`${id}-help`" class="mt-1 text-xs leading-5 text-slate-500">{{ required ? 'Attach a receipt, acknowledgement, photo of the received item, or redacted transfer screenshot.' : 'Attach a file if it helps explain the problem.' }} PDF, JPG, or PNG, up to 5 MB. Hide full account numbers, unrelated balances, passwords, and OTPs.</p>
        <input :id="id" ref="input" type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png" :required="required" :disabled="disabled" :aria-describedby="`${id}-help`" :aria-invalid="!!error" class="mt-2 block min-h-11 w-full rounded-sm border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white disabled:opacity-50" @change="choose">
        <p v-if="error" role="alert" class="mt-2 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800">{{ error }}</p>
        <img v-if="preview" :src="preview" alt="Selected receipt proof" class="mt-3 max-h-36 w-full rounded-sm border border-slate-200 bg-white object-contain">
        <p class="mt-2 text-xs leading-5 text-slate-500">Stored privately with your response. Only you and authorized staff can view it.</p>
    </div>
</template>
