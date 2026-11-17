import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { ref } from 'vue';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { evidenceError } from '../../resources/js/support/agreementConcerns.js';

const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
for (const file of ['components/monitoring-detail/ReceiptProofUpload.vue', 'components/monitoring-detail/BenefitReceiptResponseModal.vue', 'components/monitoring-detail/BenefitReleaseRecord.vue', 'components/ApplicantRecipientMonitoring.vue', 'components/benefit-delivery/BenefitDeliveryReportDialog.vue', 'pages/provider/releases/BenefitReleaseRecordPage.vue']) {
    test(`${file} compiles with receipt proof`, () => {
        const { descriptor, errors } = parse(read(file));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

function receiptForm(fail = false) {
    const writes = [], prompts = [], events = [], toasts = [];
    const props = { open: true, release: { record_id: 7, title: 'Learning materials' } };
    const dependencies = {
        ref, defineProps: () => props, defineEmits: () => (...event) => events.push(event), watch() {},
        useModalAccessibility: () => ({ handleKeydown() {} }), evidenceError,
        confirmOutcome: async (options, guard) => { prompts.push(options); return guard(); },
        showPortalToast: (value) => toasts.push(value),
        window: { axios: { post: async (...args) => { writes.push(args); if (fail) throw { response: { data: { errors: { evidence: ['Upload failed. Please retry.'] } } } }; return { data: { message: 'Receipt saved.' } }; } } },
    };
    const script = parse(read('components/monitoring-detail/BenefitReceiptResponseModal.vue')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(dependencies), script + '\nreturn { form, submit, isSaving };')(...Object.values(dependencies));
    return { ...state, writes, prompts, events, toasts };
}

test('checking received does not send or confirm anything until applicant proof is selected', async () => {
    const state = receiptForm();
    state.form.value.acknowledged = true;
    await state.submit();
    assert.equal(state.writes.length, 0);
    assert.equal(state.prompts.length, 0);
    assert.match(state.toasts[0].message, /Upload proof/);
});

test('receipt submits the applicants selected proof in the same multipart request', async () => {
    const state = receiptForm();
    state.form.value.acknowledged = true;
    state.form.value.evidence = new File(['image fixture'], 'received-item.png', { type: 'image/png' });
    await state.submit();
    assert.equal(state.writes.length, 1);
    assert.equal(state.writes[0][0], '/dashboard/benefit-release-records/7/response');
    assert.equal(state.writes[0][1].get('evidence').name, 'received-item.png');
    assert.equal(state.writes[0][1].get('response_type'), 'confirmed');
    assert.deepEqual(state.events.map(([event]) => event), ['saved', 'close']);
});

test('a failed upload keeps the form and proof and never emits a successful confirmation', async () => {
    const state = receiptForm(true);
    state.form.value.acknowledged = true;
    const file = new File(['image fixture'], 'received-item.png', { type: 'image/png' });
    state.form.value.evidence = file;
    await state.submit();
    assert.equal(state.form.value.evidence, file);
    assert.equal(state.isSaving.value, false);
    assert.deepEqual(state.events, []);
    assert.equal(state.toasts.at(-1).message, 'Upload failed. Please retry.');
});

test('proof input restricts size and type and releases temporary image previews', () => {
    const upload = read('components/monitoring-detail/ReceiptProofUpload.vue');
    assert.match(upload, /evidenceError\(\[file\], 1\)/);
    assert.match(upload, /URL\.revokeObjectURL/);
    assert.match(upload, /accept="\.pdf,\.jpg,\.jpeg,\.png"/);
    assert.match(upload, /:required="required"/);
    assert.match(upload, /Hide full account numbers/);
    const record = read('pages/provider/releases/BenefitReleaseRecordPage.vue');
    assert.match(record, /View applicant receipt proof/);
    assert.match(record, /<FilePreviewModal :file="receiptPreview"/);
});
