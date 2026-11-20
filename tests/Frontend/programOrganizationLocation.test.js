import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
import { copyFormalApplication, formalApplicationIssue, formalApplicationLinkHost, formalApplicationModes } from '../../resources/js/support/programFormalApplication.js';
import { organizationProgramLocation, organizationLocationWouldReplace } from '../../resources/js/support/programOrganizationLocation.js';

const organization = { provider_name: 'Learning Trust', provider_address: 'Office, Antipolo City', provider_latitude: '14.5861000', provider_longitude: '121.1756000' };
const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
const editor = readFileSync(new URL('../../resources/js/pages/provider/programs/components/ProgramFormalApplicationEditor.vue', import.meta.url), 'utf8');

function formWithHandoff(overrides = {}, user = organization) {
    const form = copyFormalApplication({ handoffMode: 'onsite', ...overrides });
    const prompts = [];
    const events = [];
    let confirm = false;
    const props = reactive({
        modelValue: form, organizationLocation: organizationProgramLocation(user), disabled: false,
        requestConfirmation: async options => { prompts.push(options); return confirm; },
    });
    const deps = {
        computed, ref, watch, copyFormalApplication, formalApplicationIssue, formalApplicationLinkHost, formalApplicationModes,
        organizationLocationWouldReplace, defineProps: () => props, defineEmits: () => (...args) => events.push(args),
    };
    const script = parse(editor).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(deps), script + '\nreturn { show, close, apply, open, draft, changeMode, useOrganizationHandoffLocation, hasOrganizationHandoffAddress };')(...Object.values(deps));
    return { ...state, form, props, prompts, events, confirm: () => { confirm = true; } };
}

test('organization address and valid coordinates are copied as one independent value', () => {
    const result = organizationProgramLocation(organization);
    assert.deepEqual(result, { locationName: 'Learning Trust', locationAddress: 'Office, Antipolo City', latitude: '14.5861000', longitude: '121.1756000' });
    result.locationAddress = 'Different program venue';
    assert.equal(organization.provider_address, 'Office, Antipolo City');
});

test('missing, partial or invalid organization pins cannot leave stale program coordinates', () => {
    for (const overrides of [{ provider_latitude: null }, { provider_longitude: '' }, { provider_latitude: 91 }, { provider_address: '' }]) {
        const result = organizationProgramLocation({ ...organization, ...overrides });
        assert.equal(result.latitude, '');
        assert.equal(result.longitude, '');
    }
    assert.equal(organizationProgramLocation().locationAddress, '');
});

test('different venues and replaced pins need confirmation, matching or empty forms do not', () => {
    const source = organizationProgramLocation(organization);
    assert.equal(organizationLocationWouldReplace({}, source), false);
    assert.equal(organizationLocationWouldReplace({ ...source, latitude: 14.5861, longitude: 121.1756 }, source), false);
    assert.equal(organizationLocationWouldReplace({ ...source, locationAddress: 'Different office' }, source), true);
    assert.equal(organizationLocationWouldReplace({ ...source, locationName: 'Exam center' }, source), true);
    assert.equal(organizationLocationWouldReplace({ ...source, latitude: 14.6 }, source), true);
    assert.equal(organizationLocationWouldReplace(source, { ...source, latitude: '', longitude: '' }), true);
});

test('program form uses the existing organization payload and invalidates a pin only on manual address edits', () => {
    assert.match(source, /organizationProgramLocation\(portalUser\)/);
    assert.match(source, /&& programHasLocationPin.value/);
    assert.match(source, /<ProgramLocationEditor v-model="programLocation" :organization-location="organizationLocation"/);
    const locationEditor = readFileSync(new URL('../../resources/js/pages/provider/programs/components/ProgramLocationEditor.vue', import.meta.url), 'utf8');
    assert.match(locationEditor, /cancelLabel: 'Keep current venue'/);
    assert.match(locationEditor, /v-model="draft.locationAddressDetails"/);
    assert.match(locationEditor, /Object.assign\(editing, copyProgramLocation\(props.organizationLocation\)\)/);
});

test('the onsite handoff can copy the organization address and pin without touching the program location', async () => {
    const state = formWithHandoff();
    state.show();
    assert.equal(state.hasOrganizationHandoffAddress.value, false);
    await state.useOrganizationHandoffLocation();
    assert.deepEqual(state.prompts, []);
    assert.equal(state.draft.value.handoffLocationAddress, 'Office, Antipolo City');
    assert.equal(state.form.handoffLocationAddress, '');
    assert.deepEqual(state.events, []);
    assert.equal(state.hasOrganizationHandoffAddress.value, true);
    state.apply();
    const handoff = state.events[0][1];
    assert.equal(handoff.handoffLocationName, 'Learning Trust');
    assert.equal(handoff.handoffLatitude, '14.5861000');
    assert.equal(handoff.handoffLongitude, '121.1756000');
    assert.doesNotMatch(Object.keys(handoff).join(' '), /\blocation(Name|Address)\b/);
    assert.match(editor, /v-if="draft.handoffMode === 'onsite'"[^>]*>\s*<div[\s\S]*?data-use-organization-handoff-location[^>]+@click="useOrganizationHandoffLocation"/);
    assert.match(editor, /v-model="draft.handoffAddressDetails"[^>]+:auto-fill-saved-pin="!hasOrganizationHandoffAddress"/);
});

test('handoff copy asks before replacing a venue, clears a stale pin if needed, and ignores other modes', async () => {
    const state = formWithHandoff({ handoffLocationName: 'Old venue', handoffLocationAddress: 'Old address', handoffLatitude: '14.6', handoffLongitude: '121.2', handoffAddressDetails: { city: 'Antipolo' } }, { ...organization, provider_latitude: null });
    state.show();
    await state.useOrganizationHandoffLocation();
    assert.equal(state.prompts[0].title, 'Replace the formal application venue?');
    assert.equal(state.draft.value.handoffLocationAddress, 'Old address');
    assert.equal(state.draft.value.handoffLongitude, '121.2');
    assert.deepEqual(state.draft.value.handoffAddressDetails, { city: 'Antipolo' });
    state.confirm();
    await state.useOrganizationHandoffLocation();
    assert.equal(state.draft.value.handoffLatitude, '');
    assert.equal(state.draft.value.handoffLongitude, '');
    assert.equal(state.draft.value.handoffAddressDetails, null);
    state.close();
    state.show();
    assert.equal(state.draft.value.handoffLocationAddress, 'Old address');
    assert.deepEqual(state.events, []);
    const online = formWithHandoff({ handoffMode: 'online' });
    online.show();
    await online.useOrganizationHandoffLocation();
    assert.equal(online.draft.value.handoffLocationName, '');
    assert.deepEqual(online.prompts, []);
});

test('formal setup uses an isolated modal, retains dates outside it, and validates the active method', () => {
    const file = 'ProgramFormalApplicationEditor.vue';
    const { descriptor, errors } = parse(editor, { filename: file });
    assert.deepEqual(errors, []);
    const compiled = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: compiled.bindings } }).errors, []);
    const [summary, dialog] = descriptor.template.content.split('<EligibilityOptionsModal');
    assert.match(summary, /Edit schedule/);
    assert.doesNotMatch(summary, /<textarea|<ProviderVenueAddress/);
    assert.match(dialog, /apply-label="Apply changes" allow-incomplete/);
    assert.match(source, /<ProgramFormalApplicationEditor v-model="formalApplication"/);
    assert.match(source, /&& !handoffIssue.value/);
    assert.ok(!Object.hasOwn(copyFormalApplication({ handoffDeadline: '2027-06-03' }), 'handoffDeadline'));
    assert.match(formalApplicationIssue({ handoffMode: 'onsite', handoffInstructions: 'Bring original records.' }), /venue address/);
    assert.equal(formalApplicationIssue({ handoffMode: 'provider_contact', handoffInstructions: 'Wait for our email.' }), '');
    assert.equal(formalApplicationLinkHost('https:example.org'), '');
});

test('modal edits do not change the form before Apply and malformed links cannot be applied', () => {
    const state = formWithHandoff({ handoffMode: 'online', handoffInstructions: 'Use our form.' });
    state.show();
    state.draft.value.handoffInstructions = 'Text to discard';
    state.close();
    state.show();
    assert.equal(state.draft.value.handoffInstructions, 'Use our form.');
    state.draft.value.handoffUrl = 'ftp://example.org';
    state.apply();
    assert.equal(state.open.value, true);
    assert.deepEqual(state.events, []);
    state.draft.value.handoffUrl = 'https://example.org/form';
    state.apply();
    assert.equal(state.events[0][1].handoffUrl, 'https://example.org/form');
    assert.equal(state.form.handoffUrl, '');
    assert.equal(state.open.value, false);
});
