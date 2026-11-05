import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { ref } from 'vue';
import { useWithdrawalAction } from '../../resources/js/composables/useWithdrawalAction.js';
import { useWithdrawalList } from '../../resources/js/composables/useWithdrawalList.js';
import { withdrawalLabel, withdrawalClass, withdrawalDate, withdrawalAgreementRows } from '../../resources/js/support/recipientWithdrawal.js';

const request = () => ({ id: 1, revision: 2, status: 'pending', can_review: true, can_cancel: true, review_url: '/provider/recipient-withdrawals/1/review', cancel_url: '/dashboard/recipient-withdrawals/1/cancel' });
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { resolve, reject, promise }; };
function action(mode = 'review') {
    const intent = ref(mode); const record = ref(request()); const writes = []; const results = [];
    let transport = async () => ({ data: { request: { ...request(), status: 'approved' }, message: 'Saved.' } });
    const state = useWithdrawalAction({ mode: () => intent.value, record: () => record.value, endpoint: () => '/dashboard/applications/4/recipient-withdrawals',
        send: (...args) => { writes.push(args); return transport(...args); }, onSaved: (data) => results.push(data) });
    return { ...state, intent, record, writes, results, transport: (callback) => { transport = callback; } };
}
function valid(state) { state.notes.value = '  A specific explanation for this request.  '; state.outcome.value = 'approved'; state.confirmed.value = true; }

test('withdrawal labels and dates are readable and fixed to Philippine time', () => {
    for (const key of ['pending', 'approved', 'declined', 'cancelled', 'future_internal_status']) assert.ok(!withdrawalLabel(key).includes('_'));
    assert.match(withdrawalDate('2026-11-01T18:00:00Z'), /Nov 2, 2026/);
    assert.equal(withdrawalDate('invalid'), 'Not recorded'); assert.equal(withdrawalDate(null), 'Not recorded');
    assert.match(withdrawalClass('pending'), /amber/); assert.match(withdrawalClass('approved'), /emerald/);
});

test('agreement guidance uses a whitelisted accepted snapshot, not raw objects or new terms', () => {
    assert.deepEqual(withdrawalAgreementRows(null), []);
    assert.deepEqual(withdrawalAgreementRows({ snapshot: { recipient_expectation: { responsibilities: 'Accepted responsibility', exit_or_exception_process: 'Contact the office' }, other_contract_terms: 'Recorded terms', private_key: 'hidden', renewal_policy: { invalid: true } } }),
        [['Recipient responsibilities', 'Accepted responsibility'], ['Exceptions or withdrawal', 'Contact the office'], ['Provider terms', 'Recorded terms']]);
});

test('create requires a trimmed explanation and confirmation, and sends only a request', async () => {
    const state = action('create'); state.notes.value = '          '; state.confirmed.value = true; await state.save(); assert.equal(state.writes.length, 0);
    state.notes.value = '  I need to leave this scholarship.  '; state.confirmed.value = false; await state.save(); assert.equal(state.writes.length, 0);
    state.confirmed.value = true; await state.save();
    assert.deepEqual(state.writes[0], ['post', '/dashboard/applications/4/recipient-withdrawals', { reason: 'I need to leave this scholarship.', confirmed: true }, { portalToast: false }]);
    assert.equal(state.results.length, 1);
});

test('review starts without a default decision and requires a valid outcome, note and acknowledgment', async () => {
    const state = action(); assert.equal(state.outcome.value, ''); state.notes.value = 'Review explanation.'; state.confirmed.value = true;
    await state.save(); assert.equal(state.writes.length, 0);
    state.outcome.value = 'blacklist'; await state.save(); assert.equal(state.writes.length, 0);
    state.outcome.value = 'declined'; state.notes.value = 'short'; await state.save(); assert.equal(state.writes.length, 0);
    valid(state); await state.save();
    assert.deepEqual(state.writes[0], ['patch', request().review_url, { expected_revision: 2, confirmed: true, outcome: 'approved', review_note: 'A specific explanation for this request.' }, { portalToast: false }]);
});

test('oversized notes, invalid revisions and revoked action permissions prevent review', async () => {
    const state = action(); valid(state); state.notes.value = 'a'.repeat(2001); await state.save(); assert.equal(state.writes.length, 0);
    valid(state); state.record.value.revision = undefined; await state.save(); assert.equal(state.writes.length, 0);
    state.record.value = { ...request(), can_review: false }; await state.save(); assert.equal(state.writes.length, 0);
    state.record.value = { ...request(), review_url: null }; await state.save(); assert.equal(state.writes.length, 0);
});

test('cancel sends the current revision without inventing a provider outcome', async () => {
    const state = action('cancel'); state.confirmed.value = true; await state.save();
    assert.deepEqual(state.writes[0], ['patch', request().cancel_url, { expected_revision: 2, confirmed: true }, { portalToast: false }]);
});

test('read-only or resolved records never submit review or cancellation', async () => {
    for (const mode of ['review', 'cancel', 'view']) {
        const state = action(mode); valid(state); state.record.value = { ...request(), status: 'approved' };
        await state.save(); assert.equal(state.writes.length, 0);
    }
    const state = action('cancel'); valid(state); state.record.value.can_cancel = false;
    await state.save(); assert.equal(state.writes.length, 0);
});

test('duplicate clicks, resets and already-saved forms cannot send another action', async () => {
    const state = action(); valid(state); const pending = deferred(); state.transport(() => pending.promise);
    const saving = state.save(); await state.save(); state.reset();
    assert.equal(state.writes.length, 1); assert.equal(state.notes.value.trim(), 'A specific explanation for this request.');
    pending.resolve({ data: { request: { status: 'approved' } } }); await saving; await state.save();
    assert.equal(state.writes.length, 1); assert.equal(state.results.length, 1); assert.equal(state.saving.value, false);
});

test('stale conflicts keep drafts, block further writes and require confirmation again after reload', async () => {
    const state = action(); valid(state); state.transport(async () => { throw { response: { status: 409 } }; });
    await state.save(); await state.save(); assert.equal(state.writes.length, 1); assert.equal(state.stale.value, true);
    assert.match(state.error.value, /Reload the current record/); assert.equal(state.notes.value.trim(), 'A specific explanation for this request.');
    state.record.value = { ...request(), revision: 3 }; state.refreshed();
    assert.equal(state.confirmed.value, false); assert.equal(state.canSave.value, false); assert.equal(state.stale.value, false);
    state.record.value = { ...request(), status: 'cancelled' }; state.confirmed.value = true;
    assert.equal(state.canSave.value, false);
});

test('changed support state and lost access require reload rather than repeating a decision', async () => {
    for (const response of [{ status: 403 }, { status: 422, data: { errors: { withdrawal: ['Support ended.'] } } }]) {
        const state = action(); valid(state); state.transport(async () => { throw { response }; }); await state.save();
        assert.equal(state.stale.value, true); assert.equal(state.canSave.value, false);
    }
});

test('validation and network errors retain the explanation for deliberate retry', async () => {
    const state = action(); valid(state);
    state.transport(async () => { throw { response: { status: 422, data: { errors: { review_note: ['Provide a clear explanation.'] } } } }; });
    await state.save(); assert.equal(state.error.value, 'Provide a clear explanation.'); assert.equal(state.canSave.value, true);
    state.transport(async () => { throw new Error('Offline'); }); await state.save();
    assert.match(state.error.value, /explanation has been kept/); assert.equal(state.notes.value.trim(), 'A specific explanation for this request.');
});

test('list loading uses explicit server permissions and pagination', async () => {
    const reads = [];
    const state = useWithdrawalList({ endpoint: () => '/queue', params: () => ({ status: 'pending', program_id: 3 }), fetcher: async (...args) => {
        reads.push(args); return { data: { can_request: true, requests: { data: [request()], current_page: 2, last_page: 3, total: 45, from: 21, to: 40 }, programs: [{ id: 3, title: 'Program' }] } };
    } });
    await state.load(2); assert.equal(state.canRequest.value, true); assert.equal(state.pagination.value.current_page, 2);
    assert.equal(state.rows.value.length, 1); assert.equal(state.programs.value[0].id, 3);
    assert.deepEqual(reads[0], ['/queue', { params: { status: 'pending', program_id: 3, page: 2 }, portalToast: false }]);
});

test('late filter responses do not overwrite a newer queue result', async () => {
    const first = deferred(); const second = deferred(); let index = 0;
    const state = useWithdrawalList({ endpoint: () => '/queue', fetcher: () => ++index === 1 ? first.promise : second.promise });
    const old = state.load(); const current = state.load();
    second.resolve({ data: { requests: { data: [{ id: 2 }] } } }); await current;
    first.resolve({ data: { requests: { data: [{ id: 1 }] } } }); await old;
    assert.equal(state.rows.value[0].id, 2); assert.equal(state.loading.value, false);
});

test('failed reload clears actionable stale rows and applicant permission', async () => {
    let fails = false;
    const state = useWithdrawalList({ endpoint: () => '/queue', fetcher: async () => {
        if (fails) throw new Error('Offline');
        return { data: { can_request: true, requests: { data: [request()] } } };
    } });
    await state.load(); fails = true; await state.load();
    assert.equal(state.rows.value.length, 0); assert.equal(state.canRequest.value, false); assert.match(state.error.value, /could not be loaded/);
});

test('unmount invalidation ignores unfinished reads', async () => {
    const pending = deferred(); const state = useWithdrawalList({ endpoint: () => '/queue', fetcher: () => pending.promise });
    const read = state.load(); state.invalidate(); pending.resolve({ data: { requests: { data: [request()] } } });
    await read; assert.equal(state.rows.value.length, 0);
});

test('shared dialog has bounded scrolling, focus management, explicit discard and busy guards', () => {
    const source = readFileSync(new URL('../../resources/js/components/withdrawals/WithdrawalRequestDialog.vue', import.meta.url), 'utf8');
    for (const pattern of [/Teleport to="body"/, /useModalAccessibility/, /100dvh/, /overflow-y-auto/, /aria-modal="true"/, /:disabled="busy/, /Discard changes/, /Keep editing/, /Reload current request/, /Request history/, /Accepted terms and exit guidance/, /:form="formId"/]) assert.match(source, pattern);
    assert.ok(!source.includes('window.confirm'));
});

test('applicant action is in the real outcome page and officer navigation uses the dedicated queue', () => {
    const read = (relative) => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');
    assert.match(read('resources/js/pages/UserApplicationOutcomePage.vue'), /WithdrawalRequestPanel v-if="outcome.withdrawal\?\.history_url"/);
    assert.match(read('resources/js/pages/provider/ProviderSidebar.vue'), /label: 'Withdrawal requests'/);
    const queue = read('resources/js/pages/provider/recipients/WithdrawalDirectoryPage.vue');
    assert.match(queue, /profile_photo_url/); assert.match(queue, /staff-table-viewport/); assert.match(queue, /WithdrawalRequestDialog/);
    assert.match(queue, /initialId/); assert.match(queue, /read_url/);
    const record = read('resources/js/pages/provider/recipients/RecipientRecordPage.vue');
    assert.match(record, /pendingWithdrawal/); assert.match(record, /Review the withdrawal request first/);
});
