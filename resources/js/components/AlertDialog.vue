<script setup>
import ConfirmationDialog from './ConfirmationDialog.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Check required information' },
    message: { type: String, default: '' },
    buttonLabel: { type: String, default: 'OK' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <ConfirmationDialog :open="open" :title="title" :message="message" :confirm-label="buttonLabel" :show-cancel="false" title-id="form-alert-title" message-id="form-alert-message" eyebrow="Please review" @confirm="emit('close')" @cancel="emit('close')" />
</template>
