<script setup>
import { computed, ref, watch } from 'vue';
import LocationMapModal from './LocationMapModal.vue';
import { hasLocationPin } from '../support/locationPins';

const props = defineProps({
    address: { type: String, default: '' },
    latitude: { type: [Number, String], default: null },
    longitude: { type: [Number, String], default: null },
    title: { type: String, default: 'Set location pin' },
});
const emit = defineEmits(['update:latitude', 'update:longitude']);
const open = ref(false);
const message = ref('');
const hasPin = computed(() => hasLocationPin(props.latitude, props.longitude));

function setPin(location) {
    if (!hasLocationPin(location.latitude, location.longitude)) return;
    emit('update:latitude', Number(location.latitude).toFixed(7));
    emit('update:longitude', Number(location.longitude).toFixed(7));
    message.value = 'Map pin selected. Save the form to keep this location.';
}

function clearPin() {
    emit('update:latitude', null);
    emit('update:longitude', null);
    message.value = '';
}

watch(() => props.address.trim(), clearPin);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 py-2">
        <p class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600" aria-live="polite">
            <i class="fa-solid fa-location-dot shrink-0 text-amber-700" aria-hidden="true"></i>
            {{ hasPin ? 'Map pin selected' : 'Pin the exact location so applicants can find it.' }}
        </p>
        <div class="flex items-center gap-2">
            <button v-if="hasPin" type="button" class="px-2 py-2 text-xs font-bold text-slate-600 hover:text-slate-950" @click="clearPin">Clear pin</button>
            <button type="button" class="inline-flex min-h-10 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="open = true">
                <i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>
                {{ hasPin ? 'Review map pin' : 'Set map pin' }}
            </button>
        </div>
        <LocationMapModal :open="open" :title="title" :address="address" :latitude="latitude" :longitude="longitude" :marker-text="title" :location-message="message" picker @picked="setPin" @resolved="setPin" @error="message = $event" @close="open = false" />
    </div>
</template>
