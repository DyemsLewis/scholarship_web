<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import LocationMapModal from './LocationMapModal.vue';
import { hasLocationPin } from '../support/locationPins';

const props = defineProps({
    address: { type: String, default: '' },
    latitude: { type: [Number, String], default: null },
    longitude: { type: [Number, String], default: null },
    title: { type: String, default: 'Set location pin' },
    resetOnAddressChange: { type: Boolean, default: true },
    reverseGeocodeOnPick: { type: Boolean, default: true },
    inlinePicker: Boolean,
});
const emit = defineEmits(['update:latitude', 'update:longitude', 'selected', 'picker-open-change']);
const open = ref(false);
const trigger = ref(null);
const pickerPanel = ref(null);
const message = ref('');
const errorMessage = ref('');
let applyingPin = false;
const hasPin = computed(() => hasLocationPin(props.latitude, props.longitude));

async function openPicker() {
    open.value = true;
    if (!props.inlinePicker) return;
    emit('picker-open-change', true);
    await nextTick();
    pickerPanel.value?.scrollIntoView({ block: 'nearest' });
    pickerPanel.value?.focus({ preventScroll: true });
}

async function closePicker() {
    open.value = false;
    if (!props.inlinePicker) return;
    emit('picker-open-change', false);
    await nextTick();
    trigger.value?.focus();
}

function setPin(location) {
    if (!hasLocationPin(location.latitude, location.longitude)) return;
    applyingPin = true;
    emit('update:latitude', Number(location.latitude).toFixed(7));
    emit('update:longitude', Number(location.longitude).toFixed(7));
    errorMessage.value = location.addressLookupFailed ? 'The pin was kept, but its address could not be filled. Complete the written address before saving.' : '';
    message.value = errorMessage.value || 'Map pin selected. Save the form to keep this location.';
    emit('selected', location);
    nextTick(() => { applyingPin = false; });
}

function clearPin() {
    emit('update:latitude', null);
    emit('update:longitude', null);
    message.value = '';
    errorMessage.value = '';
}

watch(() => props.address.trim(), () => { if (props.resetOnAddressChange && !applyingPin) clearPin(); });
</script>

<template>
    <div class="min-w-0 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2">
            <p class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600" aria-live="polite">
                <i class="fa-solid fa-location-dot shrink-0 text-amber-700" aria-hidden="true"></i>
                {{ hasPin ? 'Map pin selected' : 'Pin the exact location so applicants can find it.' }}
            </p>
            <div class="flex max-w-full flex-wrap items-center gap-2 sm:ml-auto sm:justify-end" data-pin-actions>
                <button v-if="hasPin" type="button" class="min-h-10 rounded-sm px-3 text-xs font-bold text-slate-600 hover:bg-slate-50 hover:text-slate-950" @click="clearPin">Clear pin</button>
                <slot name="actions" />
                <button ref="trigger" type="button" :aria-haspopup="inlinePicker ? undefined : 'dialog'" :aria-expanded="open" class="inline-flex min-h-10 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openPicker">
                    <i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>
                    {{ hasPin ? 'Review map pin' : 'Set map pin' }}
                </button>
            </div>
        </div>
        <FeedbackToast v-if="errorMessage &amp;&amp; !open" :message="errorMessage" />
        <div v-if="!inlinePicker || open" ref="pickerPanel" :tabindex="inlinePicker ? -1 : undefined" :data-inline-location-picker="inlinePicker ? '' : undefined" :class="{ contents: !inlinePicker }">
            <LocationMapModal :open="open" :title="title" :address="address" :latitude="latitude" :longitude="longitude" :marker-text="title" :location-message="message" :reverse-geocode-on-pick="reverseGeocodeOnPick" :inline="inlinePicker" picker @picked="setPin" @resolved="setPin" @error="message = $event" @close="closePicker" />
        </div>
    </div>
</template>
