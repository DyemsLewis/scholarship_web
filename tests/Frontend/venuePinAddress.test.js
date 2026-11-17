import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { parse } from '@vue/compiler-sfc';
import * as locations from '../../resources/js/support/philippineLocations.js';
import { emptyVenueAddress, lookupVenuePinAddress, venueAddressFromMap, venueAddressText } from '../../resources/js/support/venueAddress.js';

const address = { country_code: 'ph', region: 'CALABARZON', state: 'Rizal', city: 'Antipolo City', suburb: 'San Isidro', road: 'Learning Road', house_number: '12' };
const pin = { latitude: 14.5861, longitude: 121.1756 };

test('map address fills separate fields without putting the whole display address into a dropdown', () => {
    assert.deepEqual(venueAddressFromMap(address), { region: 'Region IV-A', province: 'Rizal', city: 'Antipolo City', barangay: 'San Isidro', street: '12 Learning Road' });
    assert.equal(venueAddressFromMap({ ...address, region: 'Metro Manila', city: 'Manila' }).province, 'Metro Manila');
    assert.equal(venueAddressFromMap({ ...address, country_code: 'us' }), null);
    assert.equal(venueAddressFromMap({ country_code: 'ph', state: 'Unknown province' }), null);
    assert.equal(venueAddressFromMap({ country_code: 'ph', region: 'CALABARZON' }).city, '');
});

function editor(lookup) {
    const source = readFileSync(new URL('../../resources/js/components/ProviderVenueAddress.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = reactive({ modelValue: { ...emptyVenueAddress(), ...pin }, venue: 'Scholarship office', address: '', ...pin, includeVenueInAddress: false });
    props.modelValue.venue = props.venue;
    const events = [];
    const emit = (event, value) => { events.push([event, value]); props[{ 'update:modelValue': 'modelValue', 'update:address': 'address', 'update:latitude': 'latitude', 'update:longitude': 'longitude', 'update:venue': 'venue' }[event]] = value; };
    const keys = ['computed', 'nextTick', 'onBeforeUnmount', 'ref', 'useId', 'watch', 'defineProps', 'defineEmits', 'citiesForLocation', 'findLocationOption', 'findPhilippineRegion', 'philippineRegionOptions', 'provincesForRegion', 'emptyVenueAddress', 'lookupVenuePinAddress', 'venueAddressFromMap', 'venueAddressText'];
    return { props, events, ...new Function(...keys, script + ';return {fillFromPin,change,pin,pinMessage};')(
        computed, nextTick, () => {}, ref, () => 'test-venue', watch, () => props, () => emit,
        async () => [], locations.findLocationOption, locations.findPhilippineRegion, locations.philippineRegionOptions, async () => [], emptyVenueAddress, lookup, venueAddressFromMap, venueAddressText,
    ) };
}

test('confirmed pin fills address fields and preserves the venue and selected coordinates', async () => {
    const state = editor(async () => address);
    await state.fillFromPin(pin);
    assert.equal(state.props.modelValue.region, 'Region IV-A');
    assert.equal(state.props.modelValue.city, 'Antipolo City');
    assert.equal(state.props.modelValue.venue, 'Scholarship office');
    assert.equal(state.props.modelValue.latitude, '14.5861000');
    assert.match(state.pinMessage.value, /Available address fields filled/);
});

test('lookup failure preserves the pin and explicitly requests manual address selection', async () => {
    const state = editor(async () => { throw new Error('offline'); });
    await state.fillFromPin(pin);
    assert.equal(state.props.modelValue.latitude, pin.latitude);
    assert.match(state.pinMessage.value, /could not be filled automatically/);
    assert.doesNotMatch(state.pinMessage.value, /fields filled/);
});

test('late lookup cannot overwrite a manual address edit or a cleared pin', async () => {
    for (const action of [state => state.change('city', 'User city'), state => state.pin('latitude', null)]) {
        let finish;
        const state = editor(() => new Promise(resolve => { finish = resolve; }));
        const pending = state.fillFromPin(pin);
        action(state);
        const saved = JSON.stringify(state.props.modelValue);
        finish(address); await pending;
        assert.equal(JSON.stringify(state.props.modelValue), saved);
    }
});

test('existing map address details avoid another reverse lookup', async () => {
    assert.equal(await lookupVenuePinAddress({ ...pin, address }), address);
});
