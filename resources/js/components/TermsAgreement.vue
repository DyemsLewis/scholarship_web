<script setup>
import { computed, ref } from 'vue';
import TermsModal from './TermsModal.vue';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    context: {
        type: String,
        default: 'account',
    },
    required: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const showTermsModal = ref(false);
const agreementLabels = {
    account: 'I agree to the terms and privacy notice.',
    application: 'I agree to the application terms.',
    document: 'I agree to the document upload terms.',
    providerDocument: 'I agree to the provider verification terms.',
    scholarship: 'I agree to the scholarship posting terms.',
    acceptance: 'I agree to the scholarship response terms.',
};
const agreementTitle = computed(() => agreementLabels[props.context] ?? 'I agree to the terms and conditions.');

function updateValue(event) {
    emit('update:modelValue', event.target.checked);
}
</script>

<template>
    <div class="text-sm">
        <label class="flex cursor-pointer items-center gap-2.5">
            <input
                type="checkbox"
                class="h-4 w-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-slate-200"
                :checked="modelValue"
                :required="required"
                @change="updateValue"
            >
            <span class="leading-5 text-slate-700">
                {{ agreementTitle }}
                <button
                    type="button"
                    class="ml-1 font-bold text-amber-700 underline decoration-amber-300 underline-offset-2 hover:text-amber-800"
                    aria-haspopup="dialog"
                    @click.stop.prevent="showTermsModal = true"
                >
                    Read terms
                </button>
            </span>
        </label>

        <TermsModal v-model="showTermsModal" :context="context" />
    </div>
</template>
