<script setup>
import { computed, ref, watch } from 'vue';
import ProviderVenueAddress from '../../../../components/ProviderVenueAddress.vue';
import { hasLocationPin } from '../../../../support/locationPins.js';
import { organizationLocationWouldReplace } from '../../../../support/programOrganizationLocation.js';
import { copyProgramLocation } from '../../../../support/programScheduleDetails.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    organizationLocation: { type: Object, required: true },
    requestConfirmation: { type: Function, required: true },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const draft = ref(copyProgramLocation());
const pickerOpen = ref(false);
const lookupPending = ref(false);
const canApply = computed(() => open.value && !props.disabled && !pickerOpen.value && !lookupPending.value);
const savedPin = computed(() => hasLocationPin(props.modelValue.latitude, props.modelValue.longitude));
const summary = computed(() => [...new Set([props.modelValue.locationName, props.modelValue.locationAddress].filter(Boolean))].join(' | '));

function show() {
    if (props.disabled) return;
    draft.value = copyProgramLocation(props.modelValue);
    pickerOpen.value = false;
    lookupPending.value = false;
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!canApply.value) return;
    emit('update:modelValue', copyProgramLocation(draft.value));
    close();
}

async function useOrganizationLocation() {
    const editing = draft.value;
    const unavailable = () => !open.value || props.disabled || pickerOpen.value || editing !== draft.value || !props.organizationLocation.locationAddress;
    if (unavailable()) return;
    if (organizationLocationWouldReplace(editing, props.organizationLocation)) {
        const confirmed = await props.requestConfirmation({
            title: 'Replace the program location?',
            message: 'This replaces the venue and map pin in this editor. It does not change the organization profile or formal application venue.',
            confirmLabel: 'Use organization location', cancelLabel: 'Keep current venue',
        });
        if (!confirmed || unavailable()) return;
    }
    Object.assign(editing, copyProgramLocation(props.organizationLocation));
}

watch(() => props.disabled, disabled => { if (disabled) close(); });
watch(() => props.modelValue, close);
</script>

<template>
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="program-location-editor-title" data-program-location>
        <div class="min-w-0">
            <h4 id="program-location-editor-title" class="text-sm font-bold text-slate-950">Program location</h4>
            <p class="mt-1 break-words text-xs text-slate-600">{{ summary || 'No venue or address set' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span data-program-location-status class="rounded-sm px-2 py-1 text-xs font-semibold" :class="savedPin ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'" aria-live="polite">{{ savedPin ? 'Map pin selected' : 'Map pin needed' }}</span>
            <button type="button" :disabled="disabled" aria-haspopup="dialog" :aria-expanded="open" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show">Edit location</button>
        </div>

        <EligibilityOptionsModal :open="open" id="program-location-editor" title="Program location" eyebrow="Schedule" description="Set the public venue and pin that applicants will see. This does not change the formal application venue." apply-label="Apply changes" allow-incomplete :apply-disabled="!canApply" :busy="disabled" @close="close" @apply="apply">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-600">{{ organizationLocation.locationAddress ? 'Reuse the organization address and pin, or enter a different venue.' : 'No organization address is saved. Set the program venue below.' }}</p>
                <button type="button" data-use-organization-location :disabled="!organizationLocation.locationAddress || pickerOpen" class="inline-flex min-h-9 items-center gap-2 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-40" @click="useOrganizationLocation"><i class="fa-solid fa-building-columns" aria-hidden="true"></i>Use organization location</button>
            </div>
            <ProviderVenueAddress v-model="draft.locationAddressDetails" v-model:venue="draft.locationName" v-model:address="draft.locationAddress" v-model:latitude="draft.latitude" v-model:longitude="draft.longitude" :include-venue-in-address="false" :auto-fill-saved-pin="!draft.locationAddress" inline-map @picker-open-change="pickerOpen = $event" @lookup-pending="lookupPending = $event" />
            <p v-if="pickerOpen" class="text-xs text-slate-600" role="status">Use the pin or close the map before applying the location.</p>
        </EligibilityOptionsModal>
    </section>
</template>
