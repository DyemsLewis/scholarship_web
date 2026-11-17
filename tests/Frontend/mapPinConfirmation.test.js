import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { parse } from '@vue/compiler-sfc';
import { hasLocationPin } from '../../resources/js/support/locationPins.js';

function picker(overrides = {}) {
    const source = readFileSync(new URL('../../resources/js/components/LocationMapModal.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const props = reactive({ open: true, picker: true, latitude: '', longitude: '', address: '', ...overrides });
    const events = [];
    const script = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function('computed', 'ref', 'watch', 'defineProps', 'defineEmits', 'useModalAccessibility', 'hasLocationPin',
        script + ';return {stageLocation,confirmSelection,close,selection,draftLocation};')(
        computed, ref, watch, () => props, () => (...args) => events.push(args), () => ({ handleKeydown() {} }), hasLocationPin,
    );
    return { ...state, props, events };
}

test('validating and previewing a new pin does not modify the caller until confirmation', () => {
    const state = picker();
    const location = { latitude: 14.676, longitude: 121.0437 };
    state.stageLocation(location, 'picked');
    assert.deepEqual(state.events, []);
    state.selection.value = { pending: true, valid: false };
    state.confirmSelection();assert.deepEqual(state.events, []);
    state.selection.value = { pending: false, valid: true };
    state.confirmSelection();assert.deepEqual(state.events, [['picked', location], ['close']]);
});

test('cancelling a map keeps the saved pin and discards the unconfirmed candidate', async () => {
    const state = picker({ latitude: '14.676', longitude: '121.0437' });
    state.stageLocation({ latitude: 14.5861, longitude: 121.1756 }, 'picked');
    state.close();assert.deepEqual(state.events, [['close']]);
    state.props.open = false;await nextTick();state.props.open = true;await nextTick();
    assert.deepEqual(state.draftLocation.value, { latitude: '14.676', longitude: '121.0437' });
});

test('address-search results require confirmation and invalid candidates cannot be applied', () => {
    const state = picker();
    state.stageLocation({ latitude: 91, longitude: 121 }, 'resolved');
    state.selection.value = { pending: false, valid: true };state.confirmSelection();assert.deepEqual(state.events, []);
    const result = { latitude: 14.676, longitude: 121.0437, displayName: 'Quezon City' };
    state.stageLocation(result, 'resolved');assert.deepEqual(state.events, []);
    state.confirmSelection();assert.deepEqual(state.events, [['resolved', result], ['close']]);
});

test('read-only maps continue to forward location resolution without a picker confirmation', () => {
    const state = picker({ picker: false });
    const result = { latitude: 14.676, longitude: 121.0437 };
    state.stageLocation(result, 'resolved');state.confirmSelection();
    assert.deepEqual(state.events, [['resolved', result], ['close']]);
});
