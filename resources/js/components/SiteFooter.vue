<script setup>
import PortalFooter from './PortalFooter.vue';

defineProps({
    variant: {
        type: String,
        default: 'light',
        validator: (value) => ['light', 'dark'].includes(value),
    },
});
</script>

<template>
    <PortalFooter
        context="public"
        panel-name="Scholarship Portal"
        message="Helping Filipino learners discover suitable scholarships and connect with verified providers."
        :variant="variant"
        standalone
    />
</template>
