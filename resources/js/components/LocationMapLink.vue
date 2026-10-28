<script setup>
import { computed, ref } from 'vue';
import LocationMapModal from './LocationMapModal.vue';
import { hasLocationPin } from '../support/locationPins';

const props = defineProps({
    address: { type: String, default: '' },
    latitude: { type: [Number, String], default: null },
    longitude: { type: [Number, String], default: null },
    title: { type: String, default: 'Location' },
});
const open = ref(false);
const hasPin = computed(() => hasLocationPin(props.latitude, props.longitude));
</script>

<template>
    <button v-if="hasPin || address" type="button" class="mt-2 inline-flex min-h-9 items-center gap-2 text-xs font-bold text-slate-700 hover:text-slate-950" @click="open = true">
        <i class="fa-solid fa-location-dot shrink-0 text-amber-700" aria-hidden="true"></i>
        View map
    </button>
    <LocationMapModal :open="open" :title="title" :address="address" :latitude="latitude" :longitude="longitude" :marker-text="title" :auto-geocode="!hasPin" @close="open = false" />
</template>
