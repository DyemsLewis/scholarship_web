import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { confirmOutcome } from './helpers/acceptedOutcome.js';
import { reviewStatusLabel } from '../../resources/js/support/adminReviewPresentation.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { reviewErrorMessage } from '../../resources/js/support/adminReviewPresentation.js';
import { providerProofUploadType } from '../../resources/js/support/providerVerification.js';

const read = name => readFileSync(new URL(`../../resources/js/pages/admin/reviews/${name}.vue`, import.meta.url), 'utf8');
const proof = { id: 2, revision: 1, review_url: '/admin/provider-verification-documents/2/review', file_available: true };
const provider = { verification_status: 'pending', verification_documents: [proof], document_review: { total: 1, accepted: 0, can_approve: false, token: 'original-token' } };
test('historical organization proof types map to valid replacement upload options', () => {
    assert.equal(providerProofUploadType('authority_letter'), 'authorization_letter');
    assert.equal(providerProofUploadType('representative_id'), 'valid_id');
    assert.equal(providerProofUploadType('organization_registration'), 'organization_registration');
    assert.equal(providerProofUploadType('unknown_legacy_type'), 'other');
});
function harness(name, props, exposed) {
    const writes = [], gets = [], events = [];
    const window = { location: { pathname: '/admin/workspaces/reviews/providers/8' }, axios: {
        get: async (...args) => { gets.push(args); return { data: { provider: structuredClone(provider) } }; },
        patch: async (...args) => { writes.push(args); return { data: { provider: structuredClone(provider), message: 'Saved' } }; },
    } };
    const deps = { staffQueueReturn, confirmOutcome, reviewStatusLabel, computed, ref, useAdminResource, reviewErrorMessage, window, onMounted() {},
        defineProps: () => props, defineEmits: () => (...args) => events.push(args), watch() {}, useId: () => 'test',
        useModalAccessibility: () => ({ handleKeydown() {} }) };
    const script = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    return { ...new Function(...Object.keys(deps), `${script}\nreturn {${exposed}};`)(...Object.values(deps)), writes, gets, events, window };
}

for (const name of ['ProviderReviewRecordPage', 'ProviderDocumentReviewDialog']) {
    test(`${name} compiles with individual document review`, () => {
        const { descriptor, errors } = parse(read(name), { filename: name });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('the final account decision requires all reviews and uses their current token', async () => {
    const state = harness('ProviderReviewRecordPage', {}, 'loadRecord, provider, saveDecision, canSave, reviewDocument');
    await state.loadRecord(); assert.equal(state.canSave.value, false);
    await state.saveDecision(); assert.equal(state.writes.length, 0);
    state.provider.value.document_review = { can_approve: true, token: 'current-token' };
    assert.equal(state.canSave.value, true);
    state.reviewDocument.value = proof; assert.equal(state.canSave.value, false);
    state.reviewDocument.value = null; await state.saveDecision();
    assert.equal(state.writes[0][1].expected_review_token, 'current-token');
});

test('refreshing file reviews preserves the draft final decision and review note', async () => {
    const state = harness('ProviderReviewRecordPage', {}, 'loadRecord, refreshReviews, notes, decision, provider');
    await state.loadRecord(); state.notes.value = 'Draft organization feedback'; state.decision.value = 'rejected';
    state.window.axios.get = async () => ({ data: { provider: { ...provider, document_review: { token: 'new-token' } } } });
    await state.refreshReviews({ message: 'Document accepted' });
    assert.equal(state.notes.value, 'Draft organization feedback'); assert.equal(state.decision.value, 'rejected');
    assert.equal(state.provider.value.document_review.token, 'new-token');
});

test('accepting a file requires a comparison and sends its revision', async () => {
    const state = harness('ProviderDocumentReviewDialog', { document: proof, provider }, 'outcome, compared, canSave, save');
    state.outcome.value = 'approved'; assert.equal(state.canSave.value, false);
    state.compared.value = true; assert.equal(state.canSave.value, true);
    await state.save();
    assert.deepEqual(state.writes[0][1], { status: 'approved', expected_revision: 1, review_notes: null, compared: true });
    assert.equal(state.events[0][0], 'saved');
});

test('missing files can be returned for replacement but not accepted', () => {
    const state = harness('ProviderDocumentReviewDialog', { document: { ...proof, file_available: false }, provider }, 'outcome, compared, notes, canSave');
    state.outcome.value = 'approved'; state.compared.value = true; assert.equal(state.canSave.value, false);
    state.outcome.value = 'rejected'; state.notes.value = '   '; assert.equal(state.canSave.value, false);
    state.notes.value = 'Please upload the missing file.'; assert.equal(state.canSave.value, true);
});

test('stale reviews cannot be submitted again and failed requests preserve notes', async () => {
    const state = harness('ProviderDocumentReviewDialog', { document: proof, provider }, 'outcome, notes, canSave, stale, error, save');
    state.outcome.value = 'rejected'; state.notes.value = 'The stamp is unreadable.';
    state.window.axios.patch = async () => { throw { response: { status: 409 } }; };
    await state.save(); assert.equal(state.stale.value, true); assert.equal(state.canSave.value, false);
    assert.equal(state.notes.value, 'The stamp is unreadable.'); assert.match(state.error.value, /Reload/);
    assert.deepEqual(state.events, []);
});
