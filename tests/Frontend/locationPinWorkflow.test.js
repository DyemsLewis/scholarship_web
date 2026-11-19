import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, nextTick, reactive, ref, shallowRef, watch } from 'vue';
import { parse } from '@vue/compiler-sfc';
import { hasLocationPin } from '../../resources/js/support/locationPins.js';
import { findLocationOption } from '../../resources/js/support/philippineLocations.js';
import { venueAddressFromMap } from '../../resources/js/support/venueAddress.js';

const pin = { latitude: 14.3017, longitude: 120.9573 };
const address = { country_code: 'ph', region: 'Calabarzon', state: 'Cavite', city: 'Dasmariñas', quarter: 'Sampaloc 1', suburb: 'Sampaloc', road: 'RDU Lane' };
const response = (body) => ({ ok: true, json: async () => body });
const reverseResult = { address, display_name: 'RDU Lane, Sampaloc 1, Dasmariñas, Cavite, Philippines' };
const searchResult = [{ ...reverseResult, lat: String(pin.latitude), lon: String(pin.longitude) }];
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

function map(overrides = {}) {
    const source = readFileSync(new URL('../../resources/js/components/LeafletMapPreview.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = reactive({ picker: true, reverseGeocodeOnPick: true, address: 'Public venue', latitude: '', longitude: '', autoGeocode: false, ...overrides });
    const events = [], mounts = [], unmounts = [];
    const state = new Function('nextTick', 'onMounted', 'onUnmounted', 'ref', 'shallowRef', 'watch', 'defineProps', 'defineEmits', 'loadLeaflet', 'isPhilippineLandPoint', 'loadPhilippineLand', 'PHILIPPINE_LAND_MESSAGE',
        script + ';renderMap=async()=>{};isMounted=true;return {setPickedCoordinates,previewAddress,statusMessage};')(
        nextTick, fn => mounts.push(fn), fn => unmounts.push(fn), ref, shallowRef, watch, () => props, () => (...args) => events.push(args),
        async () => {}, (_, latitude) => latitude !== 0, async () => ({}), 'Choose Philippine land.',
    );
    return { ...state, props, events, mount: mounts[0], unmount: unmounts[0] };
}

async function withFetch(fetch, run) {
    const previousFetch = globalThis.fetch, previousWindow = globalThis.window;
    globalThis.fetch = fetch;
    globalThis.window = { clearTimeout, setTimeout };
    try { await run(); }
    finally { globalThis.fetch = previousFetch; globalThis.window = previousWindow; }
}

test('a new pin cannot be confirmed before its address lookup finishes', async () => {
    let finish;
    await withFetch(() => new Promise(resolve => { finish = resolve; }), async () => {
        const state = map();
        const pending = state.setPickedCoordinates(pin);
        await settle();
        assert.equal(state.events.at(-1)[1].pending, true);
        assert.equal(state.events.some(([event]) => event === 'picked'), false);
        finish(response(reverseResult)); await pending;
        assert.deepEqual(state.events.find(([event]) => event === 'picked')[1].address, address);
        assert.deepEqual(state.events.at(-1), ['selection-state', { pending: false, valid: true }]);
    });
});

test('reopening a saved pin prepares its address before confirmation too', async () => {
    await withFetch(async () => response(reverseResult), async () => {
        const state = map(pin); await state.mount();
        assert.equal(state.events.find(([event]) => event === 'picked')[1].address.city, 'Dasmariñas');
        assert.equal(state.events.at(-1)[1].pending, false);
    });
});

test('an unavailable address lookup keeps a valid pin and explicitly requests manual completion', async () => {
    await withFetch(async () => { throw new Error('offline'); }, async () => {
        const state = map(); await state.setPickedCoordinates(pin);
        assert.deepEqual(state.events.find(([event]) => event === 'picked')[1], { ...pin, addressLookupFailed: true });
        assert.match(state.statusMessage.value, /complete the address fields manually/);
        assert.equal(state.events.at(-1)[1].valid, true);
    });
});

test('search address again actually searches even when there is already a pin', async () => {
    let calls = 0;
    await withFetch(async url => { calls++; assert.match(url, /\/search\?/); return response(searchResult); }, async () => {
        const state = map(pin); await state.previewAddress(true);
        assert.equal(calls, 1);
        assert.equal(state.events.find(([event]) => event === 'resolved')[1].address.city, 'Dasmariñas');
    });
});

test('a slow address search cannot replace a newer manually picked pin', async () => {
    let finishSearch;
    await withFetch(url => url.includes('/search?') ? new Promise(resolve => { finishSearch = resolve; }) : Promise.resolve(response(reverseResult)), async () => {
        const state = map();
        const pending = state.previewAddress(true);
        await state.setPickedCoordinates(pin);
        finishSearch(response(searchResult)); await pending;
        assert.equal(state.events.some(([event]) => event === 'resolved'), false);
        assert.equal(state.events.filter(([event]) => event === 'picked').length, 1);
    });
});

test('a slow pin lookup cannot replace a newer address-search result', async () => {
    let finishReverse;
    await withFetch(url => url.includes('/reverse?') ? new Promise(resolve => { finishReverse = resolve; }) : Promise.resolve(response(searchResult)), async () => {
        const state = map(); const pending = state.setPickedCoordinates(pin);
        await settle();
        await state.previewAddress(true);
        finishReverse(response(reverseResult)); await pending;
        assert.equal(state.events.some(([event]) => event === 'picked'), false);
        assert.equal(state.events.filter(([event]) => event === 'resolved').length, 1);
    });
});

test('closing the map while looking up an address does not publish a late selection', async () => {
    let finish;
    await withFetch(() => new Promise(resolve => { finish = resolve; }), async () => {
        const state = map(); const pending = state.setPickedCoordinates(pin);
        await settle(); state.unmount();
        finish(response(reverseResult)); await pending;
        assert.equal(state.events.some(([event]) => event === 'picked'), false);
    });
});

test('address autofill from confirming an organization pin does not immediately clear that pin', async () => {
    const source = readFileSync(new URL('../../resources/js/components/LocationPinField.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = reactive({ address: 'Old office', latitude: null, longitude: null, resetOnAddressChange: true });
    const emit = (event, value) => {
        if (event === 'selected') props.address = value.displayName;
        else props[event.replace('update:', '')] = value;
    };
    const state = new Function('computed', 'nextTick', 'ref', 'watch', 'defineProps', 'defineEmits', 'hasLocationPin', script + ';return {setPin};')(
        computed, nextTick, ref, watch, () => props, () => emit, hasLocationPin,
    );
    state.setPin({ ...pin, displayName: reverseResult.display_name }); await nextTick(); await nextTick();
    assert.equal(Number(props.latitude), pin.latitude);
    assert.equal(Number(props.longitude), pin.longitude);
    props.address = 'A different office'; await nextTick();
    assert.equal(props.latitude, null); assert.equal(props.longitude, null);
});

test('applicant profile address-search and picked-pin confirmations fill the same separate fields', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const handlers = source.slice(source.indexOf('function handleProfileLocationResolved('), source.indexOf('function handleProfileLocationError('));
    const form = ref({ region: '', province: '', city: '', barangay: '', address: '' });
    const message = ref('');
    const state = new Function('form', 'locationMessage', 'venueAddressFromMap', 'findLocationOption', 'loadApplicantLocationHierarchy', handlers + ';return {handleProfileLocationResolved,handleProfileLocationPicked};')(
        form, message, venueAddressFromMap, findLocationOption, () => {},
    );
    state.handleProfileLocationResolved({ ...pin, address, displayName: reverseResult.display_name });
    assert.equal(form.value.region, 'Region IV-A'); assert.equal(form.value.province, 'Cavite');
    assert.equal(form.value.barangay, 'Sampaloc 1'); assert.equal(form.value.address, 'RDU Lane');
    assert.equal(Number(form.value.latitude), pin.latitude);
    state.handleProfileLocationPicked({ ...pin, addressLookupFailed: true });
    assert.match(message.value, /could not be filled/);
    assert.equal(form.value.address, 'RDU Lane');
});

test('rescheduling an older appointment supplies its saved pin even without structured address details', () => {
    const source = readFileSync(new URL('../../resources/js/components/RecipientCheckInAppointment.vue', import.meta.url), 'utf8');
    const handler = source.slice(source.indexOf('function open(action)'), source.indexOf('function close()'));
    const form = ref({});
    const open = new Function('error', 'form', 'appointment', 'attendance', 'mode', handler + ';return open;')(
        ref(''), form, ref({ ...pin, location: 'Existing office', address_details: null }), ref(''), ref(''),
    );
    open('reschedule');
    assert.equal(form.value.latitude, pin.latitude); assert.equal(form.value.longitude, pin.longitude);
    assert.equal(form.value.address_details, null);
    assert.match(source, /v-model:latitude="form.latitude" v-model:longitude="form.longitude"/);
});
