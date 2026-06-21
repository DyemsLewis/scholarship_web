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
    picker: {
        type: Boolean,
        default: false,
    },
    reverseGeocodeOnPick: {
        type: Boolean,
        default: true,
    },
    defaultLatitude: {
        type: [Number, String],
        default: 12.8797,
    },
    defaultLongitude: {
        type: [Number, String],
        default: 121.7740,
    },
    defaultZoom: {
        type: Number,
        default: 6,
    },
});

const emit = defineEmits(['resolved', 'picked', 'error']);

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

async function reverseGeocodeCoordinates(coordinates) {
    statusMessage.value = 'Finding address from selected pin...';

    try {
        const params = new URLSearchParams({
            format: 'jsonv2',
            addressdetails: '1',
            lat: String(coordinates.latitude),
            lon: String(coordinates.longitude),
            'accept-language': 'en',
        });
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to find address from pin.');
        }

        const result = await response.json();

        return {
            ...coordinates,
            displayName: result.display_name ?? '',
            address: result.address ?? {},
        };
    } catch (error) {
        statusMessage.value = 'Pin set, but address lookup is unavailable. Check internet connection and try again.';
        emit('error', statusMessage.value);
        return null;
    }
}

function defaultCoordinates() {
    return {
        latitude: numberOrNull(props.defaultLatitude) ?? 12.8797,
        longitude: numberOrNull(props.defaultLongitude) ?? 121.7740,
    };
}

function enablePickerEvents() {
    if (!mapInstance.value || !props.picker) {
        return;
    }

    mapInstance.value.off('click', handleMapClick);
    mapInstance.value.on('click', handleMapClick);
}

function updateMarker(coordinates) {
    const position = [coordinates.latitude, coordinates.longitude];

    if (!markerInstance.value) {
        markerInstance.value = window.L.marker(position, {
            draggable: props.picker,
        }).addTo(mapInstance.value);
    } else {
        markerInstance.value.setLatLng(position);
    }

    if (props.picker) {
        markerInstance.value.dragging?.enable();
        markerInstance.value.off('dragend', handleMarkerDragEnd);
        markerInstance.value.on('dragend', handleMarkerDragEnd);
    } else {
        markerInstance.value.dragging?.disable();
    }

    const popupContent = document.createElement('span');
    popupContent.textContent = markerLabel();
    markerInstance.value.bindPopup(popupContent);
}

async function renderMap(coordinates = currentCoordinates()) {
    if (!isMounted || !mapElement.value) {
        return;
    }

    await ensureLeaflet();
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

    enablePickerEvents();

    if (!coordinates) {
        const fallback = defaultCoordinates();
        mapInstance.value.setView([fallback.latitude, fallback.longitude], props.defaultZoom);
        statusMessage.value = props.picker
            ? 'Click the map to set a pin, or search an address first.'
            : props.address ? 'Use address search to preview this location.' : 'Add an address to preview the map.';

        setTimeout(() => {
            mapInstance.value?.invalidateSize();
        }, 100);

        return;
    }

    const position = [coordinates.latitude, coordinates.longitude];
    mapInstance.value.setView(position, 15);
    updateMarker(coordinates);
    statusMessage.value = props.picker ? 'Pin set. You can drag it to adjust the location.' : '';

    setTimeout(() => {
        mapInstance.value?.invalidateSize();
    }, 100);
}

async function setPickedCoordinates(coordinates) {
    await renderMap(coordinates);

    if (!props.reverseGeocodeOnPick) {
        emit('picked', coordinates);
        statusMessage.value = 'Pin set. Save to keep this map point.';
        return;
    }

    const location = await reverseGeocodeCoordinates(coordinates);

    if (location) {
        emit('picked', location);
        statusMessage.value = location.displayName
            ? 'Pin set. Address fields were filled from this map point.'
            : 'Pin set. Save to keep this map point.';
        return;
    }

    emit('picked', coordinates);
}

async function handleMapClick(event) {
    await setPickedCoordinates({
        latitude: Number(event.latlng.lat),
        longitude: Number(event.latlng.lng),
    });
}

async function handleMarkerDragEnd(event) {
    const position = event.target.getLatLng();

    await setPickedCoordinates({
        latitude: Number(position.lat),
        longitude: Number(position.lng),
    });
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

    if (props.picker) {
        await renderMap();
        return;
    }

    statusMessage.value = props.address ? 'Use address search to preview this location.' : 'Add an address to preview the map.';
});

onUnmounted(() => {
    isMounted = false;
    mapInstance.value?.off('click', handleMapClick);
    markerInstance.value?.off('dragend', handleMarkerDragEnd);
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
        <p v-if="picker" class="border-t border-slate-200 bg-white px-4 py-3 text-xs font-semibold text-slate-500">
            Tip: click the map to set a pin, then drag the marker for a more exact location.
        </p>
    </div>
</template>
