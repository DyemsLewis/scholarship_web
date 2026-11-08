import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { deliveryBodyValid, deliveryClass, deliveryIssues, deliveryReplies } from '../../resources/js/support/benefitDeliveryReports.js';

const base = { creating: false, provider: true, kind: 'delivery_proposed', source: {}, period: '', details: 'The actual recorded delivery is supported by this proof.', confirmed: true, files: [{}], record: 4, date: '2026-11-02', followUp: '' };
test('delivery responses distinguish commitments, delivery evidence, and applicant confirmation', () => {
    assert.deepEqual(deliveryReplies.map(([key]) => key), ['correction_planned', 'delivery_proposed', 'information_provided']);
    assert.ok(deliveryIssues.some(([key]) => key === 'delivery_question'));
    assert.match(deliveryClass('awaiting_recipient'), /amber/);
    assert.match(deliveryClass('closed'), /emerald/);
    for (const [, label] of [...deliveryReplies, ...deliveryIssues]) assert.ok(!label.includes('_'));
});
test('actual delivery requires a recorded release, date, proof, and explicit confirmation', () => {
    assert.equal(deliveryBodyValid(base), true);
    for (const change of [{ files: [] }, { record: '' }, { date: '' }, { confirmed: false }, { details: 'short' }]) assert.equal(deliveryBodyValid({ ...base, ...change }), false);
});
test('correction plans require a follow-up date but do not require an invented delivery record', () => {
    assert.equal(deliveryBodyValid({ ...base, kind: 'correction_planned', record: '', date: '', files: [], followUp: '2026-11-04' }), true);
    assert.equal(deliveryBodyValid({ ...base, kind: 'correction_planned' }), false);
});
test('unscheduled recurring reports require an affected period', () => {
    const create = { ...base, creating: true, provider: false, kind: 'not_received', source: { period_required: true } };
    assert.equal(deliveryBodyValid(create), false);
    assert.equal(deliveryBodyValid({ ...create, period: 'First semester' }), true);
    assert.equal(deliveryBodyValid({ ...create, source: null, period: 'First semester' }), false);
});
test('receipt confirmation needs a date while unresolved replies require useful details', () => {
    assert.equal(deliveryBodyValid({ ...base, provider: false, kind: 'receipt_confirmed', details: '' }), true);
    assert.equal(deliveryBodyValid({ ...base, provider: false, kind: 'receipt_confirmed', date: '' }), false);
    assert.equal(deliveryBodyValid({ ...base, provider: false, kind: 'still_unresolved', details: 'No' }), false);
});
test('delivery forms use scoped private modals, retained stale text, and no unilateral resolve request', () => {
    const dialog = readFileSync(new URL('../../resources/js/components/benefit-delivery/BenefitDeliveryReportDialog.vue', import.meta.url), 'utf8');
    for (const required of ['useModalAccessibility', 'useConcernMutation', 'Teleport', 'aria-modal="true"', 'expected_revision', 'request_key', 'Discard your unsent response?', 'Reload report', 'evidence[]']) assert.ok(dialog.includes(required), required);
    const record = readFileSync(new URL('../../resources/js/pages/provider/releases/BenefitReleaseRecordPage.vue', import.meta.url), 'utf8');
    assert.ok(record.includes('BenefitDeliveryReportDialog')); assert.ok(!record.includes('askToResolveIssue')); assert.ok(!record.includes('/resolve`'));
    const queue = readFileSync(new URL('../../resources/js/pages/provider/releases/BenefitDeliveryReportDirectoryPage.vue', import.meta.url), 'utf8');
    assert.ok(queue.includes('staff-table-viewport'));
    assert.match(queue, /<StaffTableIdentity :src="row.applicant.photo_url"/);
    const panel = readFileSync(new URL('../../resources/js/components/benefit-delivery/BenefitDeliveryReportPanel.vue', import.meta.url), 'utf8');
    assert.ok(panel.includes('provider || open || loading || options.length'), 'Keep the dialog mounted while the first report refreshes its previously empty list.');
});
