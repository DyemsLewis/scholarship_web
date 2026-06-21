<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    address: {
        type: String,
        default: '',
    },
    latitude: {
        type: [Number, String],
        default: '',
    },
    longitude: {
        type: [Number, String],
        default: '',
    },
    title: {
        type: String,
        default: 'Map preview',
    },
    markerText: {
        type: String,
        default: '',
    },
    height: {
        type: String,
        default: '20rem',
    },
    autoGeocode: {
        type: Boolean,
        default: false,
    },
    geocodeTrigger: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['resolved', 'error']);

const mapElement = ref(null);
const statusMessage = ref('Loading map...');
const mapInstance = ref(null);
const markerInstance = ref(null);
let isMounted = false;

function numberOrNull(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function currentCoordinates() {
    const latitude = numberOrNull(props.latitude);
    const longitude = numberOrNull(props.longitude);

    return latitude === null || longitude === null ? null : { latitude, longitude };
}

function markerLabel() {
    return props.markerText || props.title || props.address || 'Selected location';
}

function loadScript(src, id) {
    return new Promise((resolve, reject) => {
        const existingScript = document.getElementById(id);

        if (existingScript) {
            if (window.L) {
                resolve();
                return;
            }

            existingScript.addEventListener('load', resolve, { once: true });
            existingScript.addEventListener('error', reject, { once: true });
            return;
        }

        const script = document.createElement('script');
        script.id = id;
        script.src = src;
        script.async = true;
        script.addEventListener('load', resolve, { once: true });
        script.addEventListener('error', reject, { once: true });
        document.head.appendChild(script);
    });
}

async function ensureLeaflet() {
    if (!document.getElementById('leaflet-cdn-css')) {
        const stylesheet = document.createElement('link');
        stylesheet.id = 'leaflet-cdn-css';
        stylesheet.rel = 'stylesheet';
        stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(stylesheet);
    }

    if (!window.L) {
        await loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'leaflet-cdn-js');
    }
}

async function geocodeAddress() {
    const query = props.address.trim();

    if (!query) {
        statusMessage.value = 'Add an address to preview the map.';
        return null;
    }

    statusMessage.value = 'Searching address on OpenStreetMap...';

    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to search address.');
        }

        const results = await response.json();
        const firstResult = results[0];

        if (!firstResult) {
            statusMessage.value = 'No map match found for this address.';
            emit('error', statusMessage.value);
            return null;
        }

        const coordinates = {
            latitude: Number(firstResult.lat),
            longitude: Number(firstResult.lon),
            displayName: firstResult.display_name,
        };

        emit('resolved', coordinates);

        return coordinates;
    } catch (error) {
        statusMessage.value = 'Map search is unavailable. Check internet connection and try again.';
        emit('error', statusMessage.value);
        return null;
    }
}

async function renderMap(coordinates = currentCoordinates()) {
    if (!isMounted || !mapElement.value) {
        return;
    }

    await ensureLeaflet();

    if (!coordinates) {
        statusMessage.value = props.address ? 'Use address search to preview this location.' : 'Add an address to preview the map.';
        return;
    }

    await nextTick();

    if (!mapInstance.value) {
        mapInstance.value = window.L.map(mapElement.value, {
            scrollWheelZoom: false,
        });

        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(mapInstance.value);
    }

    const position = [coordinates.latitude, coordinates.longitude];
    mapInstance.value.setView(position, 15);

    if (!markerInstance.value) {
        markerInstance.value = window.L.marker(position).addTo(mapInstance.value);
    } else {
        markerInstance.value.setLatLng(position);
    }

    const popupContent = document.createElement('span');
    popupContent.textContent = markerLabel();
    markerInstance.value.bindPopup(popupContent);
    statusMessage.value = '';

    setTimeout(() => {
        mapInstance.value?.invalidateSize();
    }, 100);
}

async function previewAddress() {
    const coordinates = currentCoordinates() || await geocodeAddress();
    await renderMap(coordinates);
}

watch(
    () => [props.latitude, props.longitude],
    () => {
        renderMap();
    },
);

watch(
    () => props.geocodeTrigger,
    () => {
        if (props.geocodeTrigger > 0) {
            previewAddress();
        }
    },
);

onMounted(async () => {
    isMounted = true;

    if (currentCoordinates()) {
        await renderMap();
        return;
    }

    if (props.autoGeocode) {
        await previewAddress();
        return;
    }

    statusMessage.value = props.address ? 'Use address search to preview this location.' : 'Add an address to preview the map.';
});

onUnmounted(() => {
    isMounted = false;
    mapInstance.value?.remove();
    mapInstance.value = null;
    markerInstance.value = null;
});
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <div
            ref="mapElement"
            class="w-full bg-slate-100"
            :style="{ minHeight: height }"
            :aria-label="title"
        ></div>
        <p v-if="statusMessage" class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-600">
            {{ statusMessage }}
        </p>
    </div>
</template>
