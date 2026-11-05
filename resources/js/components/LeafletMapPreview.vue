<script setup>
import { nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { loadLeaflet } from '../support/leafletLoader.js';
import { isPhilippineLandPoint, loadPhilippineLand, PHILIPPINE_LAND_MESSAGE } from '../support/philippineLand.js';

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
    secondaryLatitude: {
        type: [Number, String],
        default: '',
    },
    secondaryLongitude: {
        type: [Number, String],
        default: '',
    },
    secondaryMarkerText: {
        type: String,
        default: '',
    },
    distanceLabel: {
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
    autoGeocodeDelay: {
        type: Number,
        default: 850,
    },
    geocodeZoom: {
        type: Number,
        default: 15,
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

const emit = defineEmits(['resolved', 'picked', 'error', 'selection-state']);

const mapElement = ref(null);
const statusMessage = ref('Loading map...');
// Leaflet objects must not be wrapped in Vue's deep reactive proxies.
const mapInstance = shallowRef(null);
const markerInstance = shallowRef(null);
const secondaryMarkerInstance = shallowRef(null);
const routeLineInstance = shallowRef(null);
let isMounted = false;
let pendingPickedCoordinates = null;
let pendingGeocodedCoordinates = null;
let automaticGeocodeTimer = null;
let geocodeController = null;
let reverseController = null;
let selectionRevision = 0;
let acceptedCoordinates = null;
let resizeTimer = null;

function selectionState(pending = false) {
    emit('selection-state', { pending, valid: Boolean(acceptedCoordinates) });
}

function reportError(message) {
    if (!isMounted) return;
    statusMessage.value = message;
    emit('error', message);
}

async function isAllowed(coordinates) {
    try {
        return isPhilippineLandPoint(await loadPhilippineLand(), coordinates?.latitude, coordinates?.longitude);
    } catch {
        throw new Error('Location checks could not load. Check your connection and reopen the map.');
    }
}

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

function secondaryCoordinates() {
    const latitude = numberOrNull(props.secondaryLatitude);
    const longitude = numberOrNull(props.secondaryLongitude);

    return latitude === null || longitude === null ? null : { latitude, longitude };
}

function coordinatesMatch(first, second) {
    if (!first || !second) {
        return false;
    }

    return Math.abs(first.latitude - second.latitude) < 0.000001
        && Math.abs(first.longitude - second.longitude) < 0.000001;
}

function markerLabel() {
    return props.markerText || props.title || props.address || 'Selected location';
}

function secondaryMarkerLabel() {
    return props.secondaryMarkerText || 'Your saved location';
}

function philippineSearchQuery(query) {
    return /\bphilippines\b/i.test(query) ? query : `${query}, Philippines`;
}

async function geocodeAddress(address = props.address) {
    const query = String(address ?? '').trim();

    if (!query) {
        statusMessage.value = 'Add an address to preview the map.';
        return null;
    }

    statusMessage.value = 'Searching address on OpenStreetMap...';
    geocodeController?.abort();
    geocodeController = new AbortController();
    const activeController = geocodeController;
    const timeout = setTimeout(() => activeController.abort(), 12000);
    if (props.picker) selectionState(true);

    try {
        const params = new URLSearchParams({
            format: 'jsonv2',
            limit: '1',
            addressdetails: '1',
            countrycodes: 'ph',
            q: philippineSearchQuery(query),
        });
        const response = await fetch(`https://nominatim.openstreetmap.org/search?${params.toString()}`, {
            referrerPolicy: 'strict-origin',
            headers: {
                Accept: 'application/json',
            },
            signal: activeController.signal,
        });

        if (!response.ok) {
            throw new Error('Unable to search address.');
        }

        const results = await response.json();
        const firstResult = results[0];

        if (!isMounted || activeController.signal.aborted || query !== props.address.trim()) {
            return null;
        }

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

        if (props.picker && !await isAllowed(coordinates)) {
            reportError('The address search did not find a land location in the Philippines. Place the pin manually.');
            return null;
        }
        if (!isMounted || activeController.signal.aborted || query !== props.address.trim()) return null;
        if (props.picker) acceptedCoordinates = coordinates;

        pendingGeocodedCoordinates = coordinates;
        emit('resolved', coordinates);

        return coordinates;
    } catch (error) {
        if (error?.name === 'AbortError') {
            if (geocodeController === activeController) reportError('Address search timed out. You can still place the pin manually.');
            return null;
        }

        statusMessage.value = 'Map search is unavailable. Check internet connection and try again.';
        emit('error', statusMessage.value);
        return null;
    } finally {
        clearTimeout(timeout);
        if (geocodeController === activeController) {
            geocodeController = null;
            if (isMounted && props.picker) selectionState();
        }
    }
}

async function reverseGeocodeCoordinates(coordinates) {
    statusMessage.value = 'Finding address from selected pin...';
    reverseController?.abort();
    const controller = reverseController = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);

    try {
        const params = new URLSearchParams({
            format: 'jsonv2',
            addressdetails: '1',
            lat: String(coordinates.latitude),
            lon: String(coordinates.longitude),
            'accept-language': 'en',
        });
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?${params.toString()}`, {
            referrerPolicy: 'strict-origin',
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
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
    } catch {
        return null;
    } finally {
        clearTimeout(timeout);
        if (reverseController === controller) reverseController = null;
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

function updateSecondaryMarker(coordinates) {
    const position = [coordinates.latitude, coordinates.longitude];

    if (!secondaryMarkerInstance.value) {
        secondaryMarkerInstance.value = window.L.circleMarker(position, {
            radius: 8,
            color: '#0f172a',
            fillColor: '#38bdf8',
            fillOpacity: 0.95,
            weight: 2,
        }).addTo(mapInstance.value);
    } else {
        secondaryMarkerInstance.value.setLatLng(position);
    }

    const popupContent = document.createElement('span');
    popupContent.textContent = secondaryMarkerLabel();
    secondaryMarkerInstance.value.bindPopup(popupContent);
}

function removeSecondaryLayers() {
    if (secondaryMarkerInstance.value) {
        secondaryMarkerInstance.value.remove();
        secondaryMarkerInstance.value = null;
    }

    if (routeLineInstance.value) {
        routeLineInstance.value.remove();
        routeLineInstance.value = null;
    }
}

function updateRouteLine(primaryCoordinates, userCoordinates) {
    if (!userCoordinates || props.picker) {
        removeSecondaryLayers();
        return;
    }

    updateSecondaryMarker(userCoordinates);

    const routePoints = [
        [primaryCoordinates.latitude, primaryCoordinates.longitude],
        [userCoordinates.latitude, userCoordinates.longitude],
    ];

    if (!routeLineInstance.value) {
        routeLineInstance.value = window.L.polyline(routePoints, {
            color: '#0f172a',
            dashArray: '6 6',
            opacity: 0.7,
            weight: 3,
        }).addTo(mapInstance.value);
    } else {
        routeLineInstance.value.setLatLngs(routePoints);
    }

    mapInstance.value.fitBounds(window.L.latLngBounds(routePoints), {
        padding: [36, 36],
        maxZoom: 14,
    });
}

async function renderMap(coordinates = currentCoordinates(), preserveView = false, zoom = 15) {
    if (!isMounted || !mapElement.value) {
        return;
    }

    try {
        await loadLeaflet();
    } catch {
        reportError('The map could not load. Check your connection, then close and reopen the map.');
        return;
    }
    await nextTick();
    if (!isMounted || !mapElement.value?.isConnected) return;

    if (!mapInstance.value) {
        mapInstance.value = window.L.map(mapElement.value, {
            scrollWheelZoom: false,
            maxBounds: props.picker ? [[3, 115], [23, 130]] : undefined,
            maxBoundsViscosity: 1,
            minZoom: props.picker ? 5 : 0,
            worldCopyJump: false,
        });

        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            // Identify the website without disclosing private page paths or tokens.
            referrerPolicy: 'strict-origin',
            maxZoom: 19,
        }).addTo(mapInstance.value);
        if (props.picker) mapInstance.value.attributionControl.addAttribution('<a href="/maps/SOURCES.txt" target="_blank" rel="noopener noreferrer">Land checks: geoBoundaries / GSHHG</a>');
    }

    enablePickerEvents();

    if (!coordinates) {
        markerInstance.value?.remove();
        markerInstance.value = null;
        const fallback = defaultCoordinates();
        mapInstance.value.setView([fallback.latitude, fallback.longitude], props.defaultZoom);
        removeSecondaryLayers();
        statusMessage.value = props.picker
            ? 'Click the map to set a pin, or search an address first.'
            : props.address ? 'Use address search to preview this location.' : 'Add an address to preview the map.';

        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            mapInstance.value?.invalidateSize();
        }, 100);

        return;
    }

    const position = [coordinates.latitude, coordinates.longitude];

    if (!preserveView) {
        mapInstance.value.setView(position, zoom);
    }

    updateMarker(coordinates);
    updateRouteLine(coordinates, secondaryCoordinates());
    statusMessage.value = props.picker ? 'Pin set. You can drag it to adjust the location.' : '';

    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        mapInstance.value?.invalidateSize();
    }, 100);
}

async function setPickedCoordinates(coordinates) {
    const revision = ++selectionRevision;
    const search = geocodeController;
    geocodeController = null;
    search?.abort();
    reverseController?.abort();
    selectionState(true);
    try {
        const allowed = await isAllowed(coordinates);
        if (!isMounted || revision !== selectionRevision) return;
        if (!allowed) {
            await renderMap(acceptedCoordinates, true);
            reportError(PHILIPPINE_LAND_MESSAGE + (acceptedCoordinates ? ' Your previous pin has been kept.' : ''));
            return;
        }
        // Land validation is independent of the optional address lookup.
        const location = props.reverseGeocodeOnPick ? await reverseGeocodeCoordinates(coordinates) : null;
        if (!isMounted || revision !== selectionRevision) return;
        acceptedCoordinates = coordinates;
        pendingPickedCoordinates = coordinates;
        await renderMap(coordinates, true);
        if (!isMounted || revision !== selectionRevision) return;
        emit('picked', location || coordinates);
        statusMessage.value = location?.displayName
            ? 'Pin set. Address fields were filled from this map point.'
            : 'Land location selected. Address lookup is unavailable; enter the address yourself before saving.';
    } catch (error) {
        if (!isMounted || revision !== selectionRevision) return;
        await renderMap(acceptedCoordinates, true);
        reportError(error.message);
    } finally {
        if (isMounted && revision === selectionRevision) selectionState();
    }
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
    const savedCoordinates = currentCoordinates();
    const coordinates = savedCoordinates || await geocodeAddress();

    if (!coordinates) {
        if (props.picker && !mapInstance.value) {
            const message = statusMessage.value;
            await renderMap(null);
            statusMessage.value = message;
        }
        return;
    }

    await renderMap(coordinates, false, savedCoordinates ? 15 : props.geocodeZoom);
}

function scheduleAutomaticGeocode() {
    window.clearTimeout(automaticGeocodeTimer);
    geocodeController?.abort();

    const query = props.address.trim();

    if (!query) {
        renderMap(null);
        return;
    }

    automaticGeocodeTimer = window.setTimeout(async () => {
        if (!isMounted || currentCoordinates()) {
            return;
        }

        const coordinates = await geocodeAddress(query);

        if (coordinates) {
            await renderMap(coordinates, false, props.geocodeZoom);
        }
    }, Math.max(250, props.autoGeocodeDelay));
}

watch(
    () => [props.latitude, props.longitude, props.secondaryLatitude, props.secondaryLongitude],
    async () => {
        const coordinates = currentCoordinates();
        const preserveView = props.picker && coordinatesMatch(coordinates, pendingPickedCoordinates);
        const useGeocodeZoom = coordinatesMatch(coordinates, pendingGeocodedCoordinates);

        if (preserveView) {
            pendingPickedCoordinates = null;
        }

        if (useGeocodeZoom) {
            pendingGeocodedCoordinates = null;
        }

        if (props.picker && !coordinatesMatch(coordinates, acceptedCoordinates)) {
            const revision = ++selectionRevision;
            acceptedCoordinates = null;
            selectionState(Boolean(coordinates));
            try {
                const allowed = coordinates && await isAllowed(coordinates);
                if (!isMounted || revision !== selectionRevision) return;
                if (allowed) acceptedCoordinates = coordinates;
            } catch (error) {
                reportError(error.message);
            }
            if (!isMounted || revision !== selectionRevision) return;
            selectionState();
        }
        await renderMap(coordinates, preserveView, useGeocodeZoom ? props.geocodeZoom : 15);
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

watch(
    () => props.address,
    (address, previousAddress) => {
        if (!props.autoGeocode || address.trim() === previousAddress.trim()) {
            return;
        }

        scheduleAutomaticGeocode();
    },
);

onMounted(async () => {
    isMounted = true;

    if (props.picker && currentCoordinates()) {
        selectionState(true);
        try {
            if (await isAllowed(currentCoordinates())) acceptedCoordinates = currentCoordinates();
        } catch (error) {
            reportError(error.message);
        }
        if (!isMounted) return;
        selectionState();
    }

    if (currentCoordinates()) {
        await renderMap();
        if (props.picker && !acceptedCoordinates) reportError(PHILIPPINE_LAND_MESSAGE);
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
    selectionRevision += 1;
    clearTimeout(resizeTimer);
    window.clearTimeout(automaticGeocodeTimer);
    geocodeController?.abort();
    reverseController?.abort();
    mapInstance.value?.off('click', handleMapClick);
    markerInstance.value?.off('dragend', handleMarkerDragEnd);
    removeSecondaryLayers();
    mapInstance.value?.remove();
    mapInstance.value = null;
    markerInstance.value = null;
});
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <div class="relative">
            <div
                ref="mapElement"
                class="w-full bg-slate-100"
                :style="{ minHeight: height }"
                :aria-label="title"
            ></div>
            <div
                v-if="distanceLabel"
                class="pointer-events-none absolute left-3 top-3 rounded-md border border-slate-200 bg-white/95 px-3 py-2 text-xs shadow-sm"
            >
                <p class="font-semibold uppercase tracking-[0.12em] text-slate-500">
                    Distance
                </p>
                <p class="mt-0.5 font-bold text-slate-950">
                    {{ distanceLabel }}
                </p>
            </div>
        </div>
        <p v-if="statusMessage" role="status" aria-live="polite" class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-600">
            {{ statusMessage }}
        </p>
        <p v-if="picker" class="border-t border-slate-200 bg-white px-4 py-3 text-xs font-semibold text-slate-500">
            Select a land location in the Philippines. Pins on the sea, mapped lakes, or other countries are not accepted.
        </p>
    </div>
</template>
