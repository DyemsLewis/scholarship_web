<script setup>
import { computed, ref } from 'vue';
import TermsModal from './TermsModal.vue';
import { getTermsContent } from '../support/termsContent';

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
const selectedContent = computed(() => getTermsContent(props.context));
const agreementLabels = {
    account: 'I agree to the Terms and Privacy Notice.',
    application: 'I confirm this application can be submitted for provider review.',
    document: 'I confirm I am allowed to upload this document.',
    providerDocument: 'I confirm I am authorized to upload this provider proof.',
    scholarship: 'I confirm this scholarship information is accurate and authorized.',
    acceptance: 'I understand and confirm my scholarship response.',
};
const agreementTitle = computed(() => agreementLabels[props.context] ?? selectedContent.value.title);

function updateValue(event) {
    emit('update:modelValue', event.target.checked);
}
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
        <label class="flex cursor-pointer items-start gap-3">
            <input
                type="checkbox"
                class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-200"
                :checked="modelValue"
                :required="required"
                @change="updateValue"
            >
            <span>
                <span class="block font-bold text-slate-950">
                    {{ agreementTitle }}
                    <button
                        type="button"
                        class="ml-1 text-amber-700 underline decoration-amber-300 underline-offset-2 hover:text-amber-800"
                        @click.stop.prevent="showTermsModal = true"
                    >
                        Read terms
                    </button>
                </span>
                <span class="mt-1 block leading-5 text-slate-600">
                    {{ selectedContent.summary }}
                </span>
            </span>
        </label>

        <details class="mt-2 pl-7">
            <summary class="cursor-pointer text-xs font-bold text-slate-600">
                What this means
            </summary>
            <ul class="mt-2 space-y-1 text-xs leading-5 text-slate-500">
                <li v-for="detail in selectedContent.details" :key="detail">
                    {{ detail }}
                </li>
            </ul>
        </details>

        <TermsModal v-model="showTermsModal" :context="context" />
    </div>
</template>
