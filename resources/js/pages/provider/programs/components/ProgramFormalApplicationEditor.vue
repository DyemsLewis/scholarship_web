<script setup>
import { computed, ref, watch } from 'vue';
import ProviderVenueAddress from '../../../../components/ProviderVenueAddress.vue';
import { copyFormalApplication, formalApplicationIssue, formalApplicationLinkHost, formalApplicationModes } from '../../../../support/programFormalApplication.js';
import { organizationLocationWouldReplace } from '../../../../support/programOrganizationLocation.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    organizationLocation: { type: Object, required: true },
    requestConfirmation: { type: Function, required: true },
    deadlineLabel: { type: String, required: true },
    disabled: Boolean,
    inputClass: { type: String, default: '' },
    textareaClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'edit-schedule']);
const open = ref(false);
const draft = ref(copyFormalApplication());
const issue = computed(() => formalApplicationIssue(props.modelValue));
const modeLabel = computed(() => formalApplicationModes.find(([key]) => key === props.modelValue.handoffMode)?.[1]);
const summaryDetail = computed(() => {
    if (props.modelValue.handoffMode === 'onsite') return props.modelValue.handoffLocationName || props.modelValue.handoffLocationAddress;
    if (props.modelValue.handoffMode === 'online') return formalApplicationLinkHost(props.modelValue.handoffUrl);
    return '';
});
const invalidDraftLink = computed(() => draft.value.handoffMode === 'online' && !!draft.value.handoffUrl.trim() && !formalApplicationLinkHost(draft.value.handoffUrl));
const draftLocation = computed(() => ({
    locationName: draft.value.handoffLocationName,
    locationAddress: draft.value.handoffLocationAddress,
    latitude: draft.value.handoffLatitude,
    longitude: draft.value.handoffLongitude,
}));
const hasOrganizationHandoffAddress = computed(() => Boolean(props.organizationLocation.locationAddress)
    && draftLocation.value.locationAddress === props.organizationLocation.locationAddress
    && !organizationLocationWouldReplace(draftLocation.value, props.organizationLocation));

function changeMode(mode) {
    if (props.disabled || !formalApplicationModes.some(([key]) => key === mode)) return;
    emit('update:modelValue', { ...copyFormalApplication(props.modelValue), handoffMode: mode });
}

function show() {
    if (props.disabled || !modeLabel.value) return;
    draft.value = copyFormalApplication(props.modelValue);
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled || invalidDraftLink.value || draft.value.handoffMode !== props.modelValue.handoffMode) return;
    emit('update:modelValue', copyFormalApplication(draft.value));
    close();
}

async function useOrganizationHandoffLocation() {
    const editing = draft.value;
    const unavailable = () => !open.value || props.disabled || editing !== draft.value
        || editing.handoffMode !== 'onsite' || !props.organizationLocation.locationAddress;
    if (unavailable()) return;
    if (organizationLocationWouldReplace(draftLocation.value, props.organizationLocation)) {
        const confirmed = await props.requestConfirmation({
            title: 'Replace the formal application venue?',
            message: 'This replaces the venue and map pin in this editor. The main program location stays unchanged.',
            confirmLabel: 'Use organization location', cancelLabel: 'Keep current venue',
        });
        if (!confirmed || unavailable()) return;
    }
    Object.assign(editing, {
        handoffAddressDetails: null,
        handoffLocationName: props.organizationLocation.locationName,
        handoffLocationAddress: props.organizationLocation.locationAddress,
        handoffLatitude: props.organizationLocation.latitude,
        handoffLongitude: props.organizationLocation.longitude,
    });
}

watch(() => props.disabled, disabled => { if (disabled) close(); });
watch(() => props.modelValue, close);
</script>

<template>
    <section class="mt-7 border-t border-slate-200 pt-6" aria-labelledby="formal-application-editor-title" data-program-formal-application>
        <h4 id="formal-application-editor-title" class="text-sm font-bold text-slate-950">How qualified applicants continue</h4>
        <fieldset class="mt-3 grid gap-2 sm:grid-cols-3" :disabled="disabled">
            <legend class="sr-only">Formal application method</legend>
            <label v-for="([key, label]) in formalApplicationModes" :key="key" :class="['flex cursor-pointer items-center gap-2 rounded-sm border px-3 py-2.5 text-xs font-bold focus-within:ring-2 focus-within:ring-slate-500', modelValue.handoffMode === key ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 text-slate-700']">
                <input name="formal-application-method" type="radio" :value="key" :checked="modelValue.handoffMode === key" class="sr-only" @change="changeMode(key)">{{ label }}
            </label>
        </fieldset>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-950">Formal application details</p>
                <p class="mt-1 break-words text-xs text-slate-500">{{ summaryDetail || modeLabel || 'Choose a method to set the instructions.' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-sm px-2 py-1 text-xs font-semibold" :class="issue ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-800'" aria-live="polite">{{ issue ? 'Needs setup' : 'Ready' }}</span>
                <button type="button" :disabled="disabled || !modeLabel" aria-haspopup="dialog" :aria-expanded="open" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show">Edit details</button>
            </div>
        </div>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-slate-50 px-4 py-3">
            <p class="text-sm text-slate-600"><span class="font-bold text-slate-950">Formal application deadline:</span> {{ deadlineLabel }}</p>
            <button type="button" class="rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="emit('edit-schedule')">Edit schedule</button>
        </div>

        <EligibilityOptionsModal :open="open" id="program-formal-application" title="Formal application details" eyebrow="Application handoff" :description="modeLabel" apply-label="Apply changes" allow-incomplete :apply-disabled="invalidDraftLink" :busy="disabled" @close="close" @apply="apply">
            <label class="block text-sm font-semibold text-slate-700">
                Instructions <span class="text-xs text-amber-700">Required</span>
                <textarea v-model="draft.handoffInstructions" rows="4" maxlength="3000" :class="[textareaClass, 'mt-1.5']" :placeholder="draft.handoffMode === 'provider_contact' ? 'Explain how and when you will contact qualified applicants.' : 'Explain what qualified applicants should do next.'"></textarea>
            </label>
            <p v-if="draft.handoffMode === 'provider_contact'" class="text-xs text-slate-500">This choice does not schedule an appointment.</p>
            <label v-if="draft.handoffMode === 'online'" class="block text-sm font-semibold text-slate-700">
                Official link <span class="text-xs text-amber-700">Required</span>
                <input v-model="draft.handoffUrl" type="url" maxlength="2048" :aria-invalid="invalidDraftLink || undefined" :class="[inputClass, 'mt-1.5']" placeholder="https://">
                <span v-if="invalidDraftLink" class="mt-1 block text-xs text-rose-700" role="status">Enter a valid link starting with http:// or https://.</span>
            </label>
            <div v-if="draft.handoffMode === 'onsite'" class="space-y-4 border-t border-slate-200 pt-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-600">{{ organizationLocation.locationAddress ? 'Reuse the organization address and pin, or enter a different venue.' : 'No organization address is saved. Enter the formal application venue below.' }}</p>
                    <button type="button" data-use-organization-handoff-location :disabled="!organizationLocation.locationAddress || disabled" class="inline-flex items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-40" @click="useOrganizationHandoffLocation"><i class="fa-solid fa-building-columns text-[10px]" aria-hidden="true"></i>Use organization location</button>
                </div>
                <ProviderVenueAddress v-model="draft.handoffAddressDetails" v-model:venue="draft.handoffLocationName" v-model:address="draft.handoffLocationAddress" v-model:latitude="draft.handoffLatitude" v-model:longitude="draft.handoffLongitude" :include-venue-in-address="false" :auto-fill-saved-pin="!hasOrganizationHandoffAddress" />
            </div>
        </EligibilityOptionsModal>
    </section>
</template>
