<script setup>
import ConfirmationDialog from './ConfirmationDialog.vue';

const message = defineModel({ type: String, default: '' });
const props = defineProps({ title: { type: String, required: true }, retryLabel: { type: String, default: '' }, busy: Boolean });
const emit = defineEmits(['retry']);
function close() { message.value = ''; }
function confirm() {
    close();
    if (props.retryLabel) emit('retry');
}
</script>

<template>
    <ConfirmationDialog :open="Boolean(message)" :title="title" :message="message" eyebrow="Please review" :confirm-label="retryLabel || 'Got it'" :show-cancel="Boolean(retryLabel)" cancel-label="Close" :busy="busy" @click.stop @cancel="close" @confirm="confirm" />
</template>
