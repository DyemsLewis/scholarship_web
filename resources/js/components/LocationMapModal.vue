<script setup>
import { computed, ref } from 'vue';
import LeafletMapPreview from './LeafletMapPreview.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    eyebrow: { type: String, default: 'Location map' },
    title: { type: String, default: 'Map preview' },
    address: { type: String, default: '' },
    note: { type: String, default: '' },
    latitude: { type: [Number, String], default: '' },
    longitude: { type: [Number, String], default: '' },
    markerText: { type: String, default: '' },
    secondaryLatitude: { type: [Number, String], default: '' },
    secondaryLongitude: { type: [Number, String], default: '' },
    secondaryMarkerText: { type: String, default: '' },
    distanceLabel: { type: String, default: '' },
    picker: { type: Boolean, default: false },
    autoGeocode: { type: Boolean, default: true },
    autoGeocodeDelay: { type: Number, default: 850 },
    geocodeZoom: { type: Number, default: 15 },
    locationMessage: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Use this location' },
    googleMapsQuery: { type: String, default: '' },
});

const emit = defineEmits(['close', 'resolved', 'picked', 'error']);
const retryCount = ref(0);
const mapGeocodeTrigger = computed(() => retryCount.value);
const googleMapsUrl = computed(() => {
    const rawLatitude = String(props.latitude ?? '').trim();
    const rawLongitude = String(props.longitude ?? '').trim();
    const latitude = Number(props.latitude);
    const longitude = Number(props.longitude);
    const hasPinnedCoordinates = rawLatitude !== ''
        && rawLongitude !== ''
        && Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90
        && latitude <= 90
        && longitude >= -180
        && longitude <= 180;
    const query = hasPinnedCoordinates
        ? `${latitude},${longitude}`
        : String(props.googleMapsQuery || props.address || '').trim();

    return query
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`
        : '';
});

function close() {
    emit('close');
}

function retryAddressSearch() {
    retryCount.value += 1;
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2600] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
            role="dialog"
            aria-modal="true"
            aria-labelledby="location-map-modal-title"
            tabindex="-1"
            @click.self="close"
            @keydown.esc="close"
        >
            <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ eyebrow }}</p>
                        <h2 id="location-map-modal-title" class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">{{ title }}</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ address || 'No complete address was provided.' }}</p>
                        <p v-if="note" class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold leading-5 text-slate-600">{{ note }}</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-50" aria-label="Close map" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-3 sm:p-4">
                    <LeafletMapPreview
                        :address="address"
                        :latitude="latitude"
                        :longitude="longitude"
                        :title="title"
                        :marker-text="markerText || title"
                        :secondary-latitude="secondaryLatitude"
                        :secondary-longitude="secondaryLongitude"
                        :secondary-marker-text="secondaryMarkerText"
                        :distance-label="distanceLabel"
                        :picker="picker"
                        :auto-geocode="autoGeocode"
                        :auto-geocode-delay="autoGeocodeDelay"
                        :geocode-zoom="geocodeZoom"
                        :geocode-trigger="mapGeocodeTrigger"
                        height="min(60vh, 34rem)"
                        @resolved="emit('resolved', $event)"
                        @picked="emit('picked', $event)"
                        @error="emit('error', $event)"
                    />
                </div>

                <footer class="flex flex-col gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <p class="text-xs font-semibold leading-5" :class="locationMessage ? 'text-slate-700' : 'text-slate-500'">
                        {{ locationMessage || (picker ? 'Click the map to set a pin, then drag it for a more exact location.' : 'The location is shown inside the portal.') }}
                    </p>
                    <div class="flex flex-wrap justify-end gap-2 sm:shrink-0">
                        <a
                            v-if="googleMapsUrl"
                            :href="googleMapsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100"
                        >
                            <i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-amber-700" aria-hidden="true"></i>
                            Open in Google Maps
                        </a>
                        <button v-if="picker && address" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="retryAddressSearch">Search address again</button>
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="close">{{ picker ? confirmLabel : 'Close map' }}</button>
                    </div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
