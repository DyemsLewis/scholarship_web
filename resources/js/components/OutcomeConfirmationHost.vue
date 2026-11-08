<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import ConfirmationDialog from './ConfirmationDialog.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog.js';
import { registerOutcomeConfirmationPresenter } from '../support/outcomeConfirmation.js';

const { confirmation, requestConfirmation, confirmConfirmation, cancelConfirmation } = useConfirmationDialog();
const unregister = registerOutcomeConfirmationPresenter(requestConfirmation);
onMounted(() => window.addEventListener('pagehide', cancelConfirmation));
onBeforeUnmount(() => {
    cancelConfirmation();
    unregister();
    window.removeEventListener('pagehide', cancelConfirmation);
});
</script>

<template>
    <ConfirmationDialog data-outcome-confirmation :layer="4000" v-bind="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
</template>
