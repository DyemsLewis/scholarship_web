<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import FeedbackAlertModal from './FeedbackAlertModal.vue';
import LocationPinField from './LocationPinField.vue';
import { citiesForLocation, findLocationOption, findPhilippineRegion, philippineRegionOptions, provincesForRegion } from '../support/philippineLocations';
import { emptyVenueAddress, lookupVenuePinAddress, venueAddressFromMap, venueAddressText } from '../support/venueAddress';

const props = defineProps({
    modelValue: { type: Object, default: null },
    address: { type: String, default: '' },
    venue: { type: String, default: '' },
    latitude: { type: [String, Number], default: null },
    longitude: { type: [String, Number], default: null },
    includeVenueInAddress: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue', 'update:address', 'update:venue', 'update:latitude', 'update:longitude']);
const id = useId();
const fields = computed(() => ({ ...emptyVenueAddress(), venue: props.venue, ...props.modelValue }));
const required = computed(() => Boolean(props.modelValue) || !props.address);
const provinces = ref([]), cities = ref([]), loading = ref(false), error = ref('');
const useManualLocation = ref(false);
const pinValues = ref({});
const pinMessage = ref('');
const fieldClass = 'mt-1.5 h-11 w-full min-w-0 rounded border border-slate-300 bg-white px-3 text-sm font-normal text-slate-900 disabled:bg-slate-50';
let version = 0;
let pinRevision = 0, pinController = null;

function cancelPinLookup() {
    pinRevision++;
    pinController?.abort();
    pinMessage.value = '';
}

function change(key, value) {
    cancelPinLookup();
    pinValues.value = {};
    const next = { ...fields.value, [key]: value, latitude: null, longitude: null };
    if (key === 'region') Object.assign(next, { province: '', city: '', barangay: '' });
    if (key === 'province') Object.assign(next, { city: '', barangay: '' });
    if (key === 'city') next.barangay = '';
    emit('update:modelValue', next);
    emit('update:address', venueAddressText(next, props.includeVenueInAddress));
    emit('update:venue', next.venue);
    emit('update:latitude', null); emit('update:longitude', null);
}

function pin(key, value) {
    if (value === null) cancelPinLookup();
    pinValues.value = { ...pinValues.value, [key]: value };
    if (props.modelValue || value !== null) emit('update:modelValue', { ...fields.value, ...pinValues.value });
    emit('update:' + key, value);
}

async function fillFromPin(location) {
    cancelPinLookup();
    const revision = pinRevision;
    const controller = pinController = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    pinMessage.value = 'Pin selected. Looking up its address...';
    try {
        const address = await lookupVenuePinAddress(location, controller.signal);
        await nextTick();
        if (revision !== pinRevision || controller.signal.aborted
            || Number(props.latitude) !== Number(location.latitude) || Number(props.longitude) !== Number(location.longitude)) return;
        const resolved = venueAddressFromMap(address);
        if (!resolved) throw new Error('Address not recognized.');
        const next = { ...fields.value, ...resolved, latitude: Number(location.latitude).toFixed(7), longitude: Number(location.longitude).toFixed(7) };
        pinValues.value = { latitude: next.latitude, longitude: next.longitude };
        emit('update:modelValue', next);
        emit('update:address', venueAddressText(next, props.includeVenueInAddress));
        pinMessage.value = 'Available address fields filled from the confirmed pin. Review them and complete any missing details before saving.';
    } catch {
        if (revision === pinRevision) pinMessage.value = 'Pin selected. The address could not be filled automatically; select the region, province, and city yourself before saving.';
    } finally {
        clearTimeout(timeout);
        if (pinController === controller) pinController = null;
    }
}

async function loadOptions() {
    const current = ++version;
    const region = findPhilippineRegion(fields.value.region);
    provinces.value = []; cities.value = []; error.value = ''; useManualLocation.value = false;
    if (!region) { loading.value = false; return; }
    loading.value = true;
    try {
        const options = await provincesForRegion(region.code);
        if (current !== version) return;
        provinces.value = options;
        const province = findLocationOption(options, fields.value.province);
        const cityOptions = province ? await citiesForLocation(region.code, province.code) : [];
        if (current === version) cities.value = cityOptions;
    } catch {
        if (current === version) { useManualLocation.value = true; error.value = 'Location lists could not load. Enter the province and city, or try again.'; }
    } finally { if (current === version) loading.value = false; }
}
watch(() => [fields.value.region, fields.value.province], (current, previous) => {
    if (useManualLocation.value && previous && current[0] === previous[0]) return;
    loadOptions();
}, { immediate: true });
onBeforeUnmount(() => { version++; cancelPinLookup(); });
</script>

<template>
    <fieldset class="min-w-0 space-y-4" data-provider-venue-address>
        <legend class="mb-3 text-sm font-bold text-slate-950">Venue and address</legend>
        <p v-if="address && !modelValue" class="border-l-2 border-slate-300 pl-3 text-xs leading-5 text-slate-600" data-legacy-venue>Saved address: {{ address }}. Leave these fields unchanged to keep it, or complete them to replace it.</p>
        <label class="block text-xs font-bold text-slate-700" :for="`${id}-venue`">Venue / building / room<input :id="`${id}-venue`" :value="fields.venue" :required="required" maxlength="120" :class="fieldClass" placeholder="Example: Student Services Office, Room 201" @input="change('venue', $event.target.value)"></label>
        <div class="grid min-w-0 gap-4 sm:grid-cols-3">
            <label class="min-w-0 text-xs font-bold text-slate-700" :for="`${id}-region`">Region<select :id="`${id}-region`" :value="fields.region" :required="required" :class="fieldClass" @change="change('region', $event.target.value)"><option value="">Select region</option><option v-for="option in philippineRegionOptions" :key="option.code" :value="option.value">{{ option.label }}</option></select></label>
            <label class="min-w-0 text-xs font-bold text-slate-700" :for="`${id}-province`">Province<input v-if="useManualLocation" :id="`${id}-province`" :value="fields.province" :required="required" maxlength="100" :class="fieldClass" @input="change('province', $event.target.value)"><select v-else :id="`${id}-province`" :value="fields.province" :required="required" :disabled="!fields.region || loading" :class="fieldClass" @change="change('province', $event.target.value)"><option value="">{{ loading ? 'Loading...' : 'Select province' }}</option><option v-if="fields.province && !provinces.some(o=>o.value===fields.province)" :value="fields.province">{{ fields.province }}</option><option v-for="option in provinces" :key="option.code" :value="option.value">{{ option.label }}</option></select></label>
            <label class="min-w-0 text-xs font-bold text-slate-700" :for="`${id}-city`">City / municipality<input v-if="useManualLocation" :id="`${id}-city`" :value="fields.city" :required="required" maxlength="100" :class="fieldClass" @input="change('city', $event.target.value)"><select v-else :id="`${id}-city`" :value="fields.city" :required="required" :disabled="!fields.province || loading" :class="fieldClass" @change="change('city', $event.target.value)"><option value="">{{ loading ? 'Loading...' : 'Select city / municipality' }}</option><option v-if="fields.city && !cities.some(o=>o.value===fields.city)" :value="fields.city">{{ fields.city }}</option><option v-for="option in cities" :key="option.code" :value="option.value">{{ option.label }}</option></select></label>
        </div>
        <FeedbackAlertModal v-model="error" title="Venue location lists unavailable" retry-label="Try again" :busy="loading" @retry="loadOptions" />
        <label class="block text-xs font-bold text-slate-700" :for="`${id}-barangay`">Barangay <span class="font-normal text-slate-500">(optional)</span><input :id="`${id}-barangay`" :value="fields.barangay" maxlength="100" :class="fieldClass" @input="change('barangay', $event.target.value)"></label>
        <label class="block text-xs font-bold text-slate-700" :for="`${id}-street`">Street / address details <span class="font-normal text-slate-500">(optional)</span><input :id="`${id}-street`" :value="fields.street" maxlength="160" :class="fieldClass" placeholder="Street and building number" @input="change('street', $event.target.value)"></label>
        <LocationPinField :address="venueAddressText(modelValue) || address || venue" :latitude="modelValue ? fields.latitude : latitude" :longitude="modelValue ? fields.longitude : longitude" :reset-on-address-change="false" :reverse-geocode-on-pick="false" title="Venue location" @update:latitude="pin('latitude', $event)" @update:longitude="pin('longitude', $event)" @selected="fillFromPin" />
        <p v-if="pinMessage" class="text-xs leading-5 text-slate-600" role="status" aria-live="polite" data-pin-address-status>{{ pinMessage }}</p>
    </fieldset>
</template>
