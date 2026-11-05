import assert from 'node:assert/strict';
import test from 'node:test';
import { ref } from 'vue';
import { readFileSync } from 'node:fs';
import { concernDate, concernLabel, concernClass, evidenceError, responseKinds } from '../../resources/js/support/agreementConcerns.js';
import { useConcernCollection } from '../../resources/js/composables/useConcernCollection.js';
import { useConcernMutation } from '../../resources/js/composables/useConcernMutation.js';

const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
test('concern labels never expose machine keys and use a limited neutral palette', () => {
    for (const status of ['draft', 'awaiting_response', 'under_review', 'appeal_pending', 'cancelled', 'unknown_value']) assert.ok(!concernLabel(status).includes('_'));
    assert.match(concernClass('awaiting_response'), /amber/);
    assert.match(concernClass('under_review'), /slate/);
    assert.match(concernDate('2026-11-01T18:00:00Z'), /Nov 2, 2026/);
    assert.deepEqual(responseKinds.map(([key]) => key), ['explanation', 'correction_request', 'exception_request']);
});
test('client evidence checks bound count size and file type without claiming to verify content', () => {
    const file = { name: 'enrollment.pdf', type: 'application/pdf', size: 5120 };
    assert.equal(evidenceError([file]), '');
    assert.ok(evidenceError([file, file, file, file]));
    assert.ok(evidenceError([{ ...file, name: 'script.svg', type: 'image/svg+xml' }]));
    assert.ok(evidenceError([{ ...file, size: 5 * 1024 * 1024 + 1 }]));
    assert.ok(evidenceError([{ ...file, type: 'text/html' }]));
});
test('a pending mutation cannot be double submitted and a stale failure disables another write', async () => {
    const pending = deferred(); let calls = 0;
    const state = useConcernMutation(async () => { calls++; return pending.promise; });
    const first = state.run('post', '/issue', { confirmed: true });
    assert.equal(await state.run('post', '/issue', {}), null); assert.equal(calls, 1);
    pending.reject({ response: { status: 409 } }); await first;
    assert.equal(state.stale.value, true); assert.match(state.error.value, /kept/);
    assert.equal(await state.run('post', '/issue', {}), null); assert.equal(calls, 1);
    state.reset(); assert.equal(state.stale.value, false); assert.equal(state.error.value, '');
});
test('validation errors preserve the ability to correct and retry without pretending the request succeeded', async () => {
    const state = useConcernMutation(async () => { throw { response: { status: 422, data: { errors: { evidence: ['Add a supporting file.'] } } } }; });
    assert.equal(await state.run('post', '/issue', {}), null);
    assert.equal(state.stale.value, false); assert.equal(state.error.value, 'Add a supporting file.');
    assert.equal(state.busy.value, false);
});
test('invalidation suppresses a late mutation response after a dialog closes', async () => {
    const pending = deferred(); const state = useConcernMutation(() => pending.promise);
    const result = state.run('post', '/response', {}); state.reset();
    pending.resolve({ data: { case: { id: 4 } } }); assert.equal(await result, null); assert.equal(state.busy.value, false);
});
test('the latest list response wins and access or load errors clear stale actionable data', async () => {
    const requests = [], endpoint = ref('/one');
    const state = useConcernCollection({ endpoint: () => endpoint.value, fetcher: () => { const d = deferred(); requests.push(d); return d.promise; } });
    const old = state.load(); endpoint.value = '/two'; const latest = state.load();
    requests[1].resolve({ data: { cases: { data: [{ id: 2 }], total: 1 }, create_url: '/create', options: [{ key: 'term' }] } }); await latest;
    requests[0].resolve({ data: { cases: { data: [{ id: 1 }] }, create_url: '/wrong' } }); await old;
    assert.deepEqual(state.rows.value, [{ id: 2 }]); assert.equal(state.createUrl.value, '/create');
    const failure = state.load(); assert.equal(state.createUrl.value, null);
    requests[2].reject({ response: { status: 403 } }); await failure;
    assert.deepEqual(state.rows.value, []); assert.deepEqual(state.options.value, []); assert.equal(state.createUrl.value, null);
});
test('an unmounted list cannot be repopulated by a late response', async () => {
    const pending = deferred(); const state = useConcernCollection({ endpoint: () => '/one', fetcher: () => pending.promise });
    const result = state.load(); state.invalidate(); pending.resolve({ data: { cases: { data: [{ id: 1 }] }, create_url: '/create' } }); await result;
    assert.deepEqual(state.rows.value, []); assert.equal(state.createUrl.value, null);
});
test('dialog and integration retain explicit confirmation, modal accessibility, and evidence preview rather than external pages', () => {
    const source = readFileSync(new URL('../../resources/js/components/agreement-concerns/AgreementConcernDialog.vue', import.meta.url), 'utf8');
    for (const value of ['useModalAccessibility', '<Teleport to="body">', 'aria-modal="true"', 'confirmed.value = false', 'expected_revision', 'crypto.randomUUID()', 'Reload current concern', 'Keep editing']) assert.ok(source.includes(value), value);
    assert.ok(!source.includes('value="confirmed"'));
    const files = readFileSync(new URL('../../resources/js/components/agreement-concerns/ConcernEvidenceList.vue', import.meta.url), 'utf8');
    assert.ok(files.includes('FilePreviewModal')); assert.ok(!files.includes('target="_blank"'));
    const sidebar = readFileSync(new URL('../../resources/js/pages/provider/ProviderSidebar.vue', import.meta.url), 'utf8');
    assert.ok(sidebar.includes("label: 'Agreement concerns'"));
});
