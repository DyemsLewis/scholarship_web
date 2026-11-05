<script setup>
import ConfirmationDialog from './ConfirmationDialog.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirm change' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirm' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    danger: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <ConfirmationDialog data-provider-action-dialog :open="open" :title="title" :message="message" :confirm-label="confirmLabel" :busy="busy" :error="error" :tone="danger ? 'danger' : 'warning'" eyebrow="Confirm change" @confirm="emit('confirm')" @cancel="emit('cancel')" />
</template>
