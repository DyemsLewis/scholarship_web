import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';

const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
for (const file of ['components/case-reviews/RecipientCaseReviewDialog.vue', 'components/case-reviews/RecipientCaseReviewLauncher.vue', 'components/case-reviews/RecipientCaseReviewPanel.vue', 'pages/admin/reviews/RecipientCaseReviewDirectoryPage.vue']) {
    test(file + ' compiles with focused case-review controls', () => {
        const { descriptor, errors } = parse(read(file), { filename: file }); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const template = compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}
test('review forms do not preselect a finding and require proof, reasons, and acknowledgment', () => {
    const dialog = read('components/case-reviews/RecipientCaseReviewDialog.vue');
    for (const token of ["finding = ref('')", "recommendation = ref('')", 'termsReviewed.value && responsesReviewed.value', 'cited.value.length > 0 || files.value.length > 0', 'expected_revision', 'request_key', 'No finding selected', 'Discard your unsent statement?', 'Reload case', 'useModalAccessibility', 'useConcernMutation', 'aria-modal="true"']) assert.ok(dialog.includes(token), token);
    assert.ok(dialog.includes('independent_reviewer_required'));
    assert.ok(dialog.includes('Late appeals remain available'));
});
test('delivery review recommendations cannot masquerade as applicant penalties or receipt confirmation', () => {
    const dialog = read('components/case-reviews/RecipientCaseReviewDialog.vue');
    assert.match(dialog, /case_type === 'benefit_delivery' \? \[\['no_support_change'/);
    assert.ok(!dialog.includes("['receipt_confirmed'"));
    assert.ok(!dialog.includes('/support-decision'));
});
test('new review access stays in the relevant workspace with photos and one review panel', () => {
    const queue = read('components/case-reviews/RecipientCaseReviewPanel.vue');
    assert.ok(queue.includes('staff-table-viewport'));
    assert.match(queue, /<StaffTableIdentity :src="row.applicant.photo_url"/);
    for (const file of ['pages/UserApplicationOutcomePage.vue', 'pages/provider/recipients/RecipientRecordPage.vue', 'components/monitoring-detail/MonitoringBenefits.vue']) {
        assert.equal((read(file).match(/<RecipientCaseReviewPanel /g) || []).length, 1, file);
    }
    const provider = read('pages/provider/recipients/RecipientRecordPage.vue'); assert.ok(provider.includes('eligible_agreement_reviews')); assert.ok(provider.includes('agreement_case_review_id'));
});
