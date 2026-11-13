import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, reactive, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';

const read = (name) => readFileSync(new URL(`../../resources/js/components/admin/${name}.vue`, import.meta.url), 'utf8');
const coordination = { token: 'a'.repeat(64), can_request_billing: true, active_review: null, history: [] };
const record = { id: 8, can_review: true, coordination };
function harness(name, exposed, options = {}) {
    const props = reactive({ open: true, purchaseId: 8, coordination: { ...coordination, active_review: { state: 'with_billing' } }, disabled: false, ...options });
    const emitted = [], writes = [], gets = [], watchers = [];
    const window = { axios: {
        get: async (...args) => { gets.push(args); return { data: { record: structuredClone(record) } }; },
        post: async (...args) => { writes.push(args); return { data: { record: structuredClone(record), message: 'Financial review saved.' } }; },
        patch: async (...args) => { writes.push(args); return { data: { coordination: { ...coordination, active_review: { state: 'with_finance' } } } }; },
    } };
    const script = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { computed, ref, useAdminResource, defineProps: () => props, defineEmits: () => (...args) => emitted.push(args), watch: (...args) => watchers.push(args), window };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), props, emitted, writes, gets, watchers, window };
}

for (const name of ['FinanceRecordReviewDialog', 'BillingFinanceFollowUp']) {
    test(`${name} compiles with shared admin modal and existing panel styles`, () => {
        const { descriptor, errors } = parse(read(name)); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('finance modal cannot write while unloaded, failed, read-only, or outside note limits', async () => {
    const s = harness('FinanceRecordReviewDialog', 'loadRecord, save, note, canSave, record');
    s.note.value = 'An otherwise valid note.'; await s.save(); assert.equal(s.writes.length, 0);
    s.window.axios.get = async () => { throw new Error('Offline'); };
    await s.loadRecord(); await s.save(); assert.equal(s.writes.length, 0);
    s.window.axios.get = async () => ({ data: { record: structuredClone(record) } });
    await s.loadRecord();
    for (const value of ['', ' short ', 'x'.repeat(1501)]) {
        s.note.value = value; assert.equal(s.canSave.value, false); await s.save();
    }
    s.record.value.can_review = false; s.note.value = 'A proper note for a read-only viewer.';
    await s.save(); assert.equal(s.writes.length, 0);
});

test('recording a check sends only a trimmed note, review action and source snapshot', async () => {
    const s = harness('FinanceRecordReviewDialog', 'loadRecord, save, note');
    await s.loadRecord(); s.note.value = '  The payment and service record were compared.  ';
    await s.save();
    assert.equal(s.writes[0][0], '/admin/workspaces/finance/records/8/review');
    assert.deepEqual(s.writes[0][1], { action: 'record_check', note: 'The payment and service record were compared.', expected_finance_token: coordination.token });
    assert.equal(s.emitted.find(([event]) => event === 'updated')[1], 'Financial review saved.');
    assert.ok(s.emitted.some(([event]) => event === 'close'));
});

test('pending internal question cannot be referred twice from the finance modal', async () => {
    const s = harness('FinanceRecordReviewDialog', 'loadRecord, save, note, action, record');
    await s.loadRecord(); s.note.value = 'Please check the original reference.'; s.action.value = 'request_billing';
    s.record.value.coordination.can_request_billing = false;
    await s.save(); assert.equal(s.writes.length, 0);
    s.record.value.coordination.can_request_billing = true;
    await s.save(); assert.equal(s.writes[0][1].action, 'request_billing');
});

test('finance save is single-flight and keeps the note on a stale-record rejection', async () => {
    const s = harness('FinanceRecordReviewDialog', 'loadRecord, save, close, note, saving, actionError');
    await s.loadRecord(); s.note.value = 'Keep this internal review note after an error.';
    let reject;
    s.window.axios.post = (...args) => { s.writes.push(args); return new Promise((_, no) => { reject = no; }); };
    const pending = s.save(); await s.save(); s.close();
    assert.equal(s.writes.length, 1); assert.equal(s.emitted.length, 0);
    reject({ response: { data: { message: 'This financial record changed. Reload it.' } } }); await pending;
    assert.equal(s.note.value, 'Keep this internal review note after an error.');
    assert.equal(s.actionError.value, 'This financial record changed. Reload it.');
    assert.equal(s.saving.value, false);
});

test('billing only returns an active question using the current source snapshot', async () => {
    const s = harness('BillingFinanceFollowUp', 'show, submit, note, open');
    s.show(); s.note.value = '  The external refund is still being processed.  '; await s.submit();
    assert.deepEqual(s.writes[0][1], { note: 'The external refund is still being processed.', expected_finance_token: coordination.token });
    assert.equal(s.writes[0][0], '/admin/workspaces/billing/requests/8/finance-response');
    assert.equal(s.emitted[0][1].active_review.state, 'with_finance');
    assert.equal(s.open.value, false);
    s.props.coordination.active_review.state = 'with_finance'; s.show(); await s.submit();
    assert.equal(s.writes.length, 1);
});

test('billing drafts survive errors and disabled delivery actions block concurrent response forms', async () => {
    const s = harness('BillingFinanceFollowUp', 'show, submit, note, error, open');
    s.props.disabled = true; s.show(); assert.equal(s.open.value, false);
    s.props.disabled = false; s.show(); s.note.value = 'Checked the source payment references.';
    s.window.axios.patch = async () => { throw { response: { data: { message: 'Source details changed.' } } }; };
    await s.submit(); assert.equal(s.note.value, 'Checked the source payment references.');
    assert.equal(s.error.value, 'Source details changed.'); assert.equal(s.open.value, true);
});

test('payment, service, refund, and financial check wording do not imply actual money movement', () => {
    assert.match(read('FinanceRecordReviewDialog'), /do not receive, send, or refund money/);
    assert.match(read('FinanceRecordReviewDialog'), /outstanding payment or refund issues remain visible/);
    assert.match(read('BillingFinanceFollowUp'), /Payment, delivery, and refund records are separate/);
});
