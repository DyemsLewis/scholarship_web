import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
import { hasLocationPin } from '../../resources/js/support/locationPins.js';
import { organizationLocationWouldReplace } from '../../resources/js/support/programOrganizationLocation.js';
import { copyProgramLocation, copyProgramPublicContact } from '../../resources/js/support/programScheduleDetails.js';

const read = file => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const components = 'pages/provider/programs/components/';
const editors = ['ProgramLocationEditor', 'ProgramPublicContactEditor'];

function setup(name, initial) {
    const { descriptor } = parse(read(components + name + '.vue'));
    const props = reactive(initial);
    const events = [];
    const deps = {
        computed, ref, watch, hasLocationPin, organizationLocationWouldReplace, copyProgramLocation, copyProgramPublicContact,
        defineProps: () => props, defineEmits: () => (...args) => events.push(args),
    };
    const script = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const locationState = name === 'ProgramLocationEditor' ? ', useOrganizationLocation, pickerOpen, lookupPending' : '';
    const editor = new Function(...Object.keys(deps), script + `\nreturn { show, close, apply, open, draft${locationState} };`)(...Object.values(deps));
    return { ...editor, props, events };
}

test('Schedule keeps the timeline and two summary rows outside the modal editors', () => {
    const page = read('pages/provider/programs/ProgramFormPage.vue');
    assert.match(page, /data-program-timeline/);
    assert.match(page, /<ProgramLocationEditor v-model="programLocation"/);
    assert.match(page, /<ProgramPublicContactEditor v-model="publicContact"/);
    for (const name of editors) {
        const file = components + name + '.vue';
        const { descriptor, errors } = parse(read(file), { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
        const [summary, modal] = descriptor.template.content.split('<EligibilityOptionsModal');
        assert.doesNotMatch(summary, /<input|<ProviderVenueAddress/);
        assert.match(modal, /apply-label="Apply changes"/);
    }
});

test('the map stays in the location dialog and retains the existing pin confirmation', () => {
    const location = read(components + 'ProgramLocationEditor.vue');
    const address = read('components/ProviderVenueAddress.vue');
    const picker = read('components/LocationPinField.vue');
    const map = read('components/LocationMapModal.vue');
    assert.match(location, /<ProviderVenueAddress[^>]+inline-map/);
    assert.match(address, /:inline-picker="inlineMap"/);
    assert.match(picker, /:inline="inlinePicker"/);
    assert.match(map, /<Teleport to="body" :disabled="inline">/);
    assert.match(map, /:role="inline \? 'region' : 'dialog'"/);
    assert.match(map, /useModalAccessibility\(\(\) => props.open && !props.inline/);
    assert.match(map, /selection.value.pending \|\| !selection.value.valid/);
});

test('location changes, including an organization copy, are staged independently until Apply', async () => {
    const venue = { locationName: 'Program office', locationAddress: 'Old address', locationAddressDetails: { city: 'Antipolo', venue: 'Program office' }, latitude: 14.58, longitude: 121.17 };
    let confirm = false;
    const prompts = [];
    const state = setup('ProgramLocationEditor', {
        modelValue: venue,
        organizationLocation: { locationName: 'Organization office', locationAddress: 'New address', latitude: 14.62, longitude: 121.14 },
        requestConfirmation: async options => { prompts.push(options); return confirm; },
    });
    state.show();
    state.draft.value.locationAddressDetails.city = 'Temporary change';
    assert.equal(venue.locationAddressDetails.city, 'Antipolo');
    state.close();
    state.show();
    await state.useOrganizationLocation();
    assert.equal(prompts[0].title, 'Replace the program location?');
    assert.equal(state.draft.value.locationAddress, 'Old address');
    confirm = true;
    await state.useOrganizationLocation();
    assert.equal(venue.locationAddress, 'Old address');
    assert.equal(state.draft.value.locationAddress, 'New address');

    state.pickerOpen.value = true;
    state.apply();
    state.pickerOpen.value = false;
    state.lookupPending.value = true;
    state.apply();
    assert.deepEqual(state.events, []);
    state.lookupPending.value = false;
    state.apply();
    assert.deepEqual(state.events[0], ['update:modelValue', {
        locationName: 'Organization office', locationAddress: 'New address', locationAddressDetails: null, latitude: 14.62, longitude: 121.14,
    }]);
    assert.equal(state.open.value, false);
});

test('public contact Cancel discards changes and Apply returns only contact fields', () => {
    const initial = { contactEmail: 'office@example.test', formalApplication: 'Leave unchanged', deadline: '2027-06-01' };
    const state = setup('ProgramPublicContactEditor', { modelValue: initial });
    state.show();
    state.draft.value.contactEmail = 'discard@example.test';
    state.close();
    state.show();
    assert.equal(state.draft.value.contactEmail, 'office@example.test');
    state.draft.value.contactPerson = 'Program Staff';
    state.draft.value.officialProgramUrl = 'https://example.test/scholarship';
    state.apply();
    assert.equal(state.events[0][1].contactPerson, 'Program Staff');
    assert.equal(state.events[0][1].officialProgramUrl, 'https://example.test/scholarship');
    assert.equal(Object.hasOwn(state.events[0][1], 'deadline'), false);
    assert.equal(Object.hasOwn(initial, 'contactPerson'), false);
    assert.match(read(components + 'ProgramPublicContactEditor.vue'), /v-model="draft.contactPerson" v-capitalize-name/);
});
