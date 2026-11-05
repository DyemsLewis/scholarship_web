import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue';
import { parse } from '@vue/compiler-sfc';
import { evidenceDecisionDate, evidenceFileError, evidenceProgress, evidenceReviewDetail, evidenceStatus, evidenceSummary, nextEvidenceAction } from '../../resources/js/support/applicantProfileEvidence.js';
import { applicantProfileOverview } from '../../resources/js/support/applicantProfileOverview.js';

const file = () => new File(['%PDF-1.4 demonstration'], 'income.pdf', { type: 'application/pdf' });
const doc = (type = 'income_record', extra = {}) => ({ id: 4, document_type: type, revision: 2, status: 'submitted', original_name: 'income.pdf', ...extra });
const row = (type, document, extra = {}) => ({ value: type, label: type === 'income_record' ? 'Household income proof' : 'Identity record', required: true, document, ...extra });
const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };

test('only current acceptance counts, while legacy approval and re-review remain distinct', () => {
    assert.equal(evidenceStatus(doc('income_record', { status: 'approved' })).key, 'legacy');
    assert.equal(evidenceStatus(doc('income_record', { status: 'approved', has_current_acceptance: true })).key, 'accepted');
    assert.equal(evidenceStatus(doc('income_record', { has_current_acceptance: true, file_available: false })).key, 'unavailable');
    assert.equal(evidenceStatus(doc('income_record', { review_context: { action: 'claims_changed' } })).key, 'recheck');
    assert.equal(evidenceProgress([row('income_record', doc('income_record', { status: 'approved' }))]).accepted, 0);
});

test('requested required corrections lead before missing files and unrelated optional records', () => {
    const rows = [row('identity_record', null), row('income_record', doc('income_record', { status: 'rejected' })), row('guardian_record', doc('guardian_record', { status: 'rejected' }), { required: false })];
    assert.equal(nextEvidenceAction(rows).value, 'income_record');
    assert.match(evidenceSummary(rows, 'rejected').title, /Replace household income proof/);
    assert.equal(nextEvidenceAction(rows.filter((item) => item.value !== 'income_record')).value, 'identity_record');
});

test('accepted documents do not imply overall verification, and existing verified profiles are preserved', () => {
    const rows = [row('income_record', doc('income_record', { status: 'approved', has_current_acceptance: true }))];
    assert.match(evidenceSummary(rows, 'pending').title, /final verification pending/);
    assert.match(evidenceSummary(rows, 'pending').detail, /before you can apply/);
    rows[0].document.has_current_acceptance = false;
    assert.equal(evidenceSummary(rows, 'approved').title, 'Your profile is verified');
    const overview = applicantProfileOverview({ readiness: { complete: true, verified: false }, verificationStatus: 'pending', evidenceReview: { required_evidence_accepted: true } });
    assert.equal(overview.key, 'verification_pending'); assert.equal(overview.canApply, false);
});

test('profile guidance points to the actual returned or unavailable record', () => {
    const args = { readiness: { complete: true, verified: false }, verificationStatus: 'pending', requirements: [{ value: 'income_record', required: true }], documents: [doc('income_record', { status: 'rejected' })] };
    assert.equal(applicantProfileOverview(args).action.evidence, 'income_record');
    args.documents = [doc('income_record', { file_available: false })];
    assert.equal(applicantProfileOverview(args).action.label, 'Replace unavailable record');
});

test('replacement and changed-claim messages explain re-review without demanding unnecessary uploads', () => {
    assert.match(evidenceReviewDetail(doc('income_record', { review_context: { action: 'file_replaced' } })), /Replacement saved/);
    assert.match(evidenceReviewDetail(doc('income_record', { review_context: { action: 'claims_changed' } })), /only if it is no longer current/);
    assert.equal(evidenceDecisionDate('bad-date'), '');
});

test('client file checks match server limits but never substitute for server validation', () => {
    assert.equal(evidenceFileError(file(), 'income_record'), '');
    assert.match(evidenceFileError({ name: 'photo.svg', size: 10 }, 'recent_school_id'), /supported file/);
    assert.match(evidenceFileError({ name: 'empty.pdf', size: 0 }, 'income_record'), /empty/);
    assert.match(evidenceFileError({ name: 'large.pdf', size: 2 * 1024 * 1024 }, 'academic_record', { configured: true, max_file_size_mb: 1 }), /up to 1 MB/);
    assert.equal(evidenceFileError({ name: 'letter.DOCX', size: 1024 }, 'income_record'), '');
});

const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
const actions = source.slice(source.indexOf('function applyAcademicResponse'), source.indexOf('async function loadProfile'));
function harness(initial = []) {
    const state = {
        user: ref({ gwa: 91, grading_scale: 'percentage', applicant_verification_status: 'pending' }), savedProfileReadiness: ref(null),
        form: ref({ gwa: 95, grading_scale: 'percentage' }), savedFormSnapshot: ref(JSON.stringify({ gwa: 91, grading_scale: 'percentage' })),
        verificationDocuments: ref(initial), academicOcr: ref({ configured: false }), verificationDocumentTermsAccepted: ref(true),
        uploadingVerificationDocumentType: ref(''), deletingVerificationDocumentId: ref(null), rescanningAcademicDocumentId: ref(null),
        evidenceActionPending: ref(false), evidenceRefreshRequired: ref(false), evidenceRefreshing: ref(false), evidenceSuccess: ref(''),
        errorMessage: ref(''), preparedDocumentsCount: ref(0),
    };
    state.verificationDocumentRows = computed(() => state.verificationDocuments.value.map((document) => row(document.document_type, document)));
    state.profileEditorBusy = computed(() => state.evidenceActionPending.value || state.evidenceRefreshing.value || !!state.uploadingVerificationDocumentType.value || state.deletingVerificationDocumentId.value !== null || state.rescanningAcademicDocumentId.value !== null);
    let respond = async () => ({ data: { user: { gwa: 91, grading_scale: 'percentage' }, verification_documents: [], message: 'Saved for review.' } });
    let confirm = async () => true;
    const writes = [], confirmations = [];
    const window = { axios: Object.fromEntries(['get', 'post', 'delete'].map((method) => [method, async (...args) => { writes.push([method, ...args]); return respond(...args); }])) };
    const deps = { ...state, window, evidenceFileError, FormData, nextTick: async () => {}, preparationParams: {}, showPortalToast() {}, markFormSaved() {}, focusProfileAlert: async () => {},
        requestConfirmation: async (options) => { confirmations.push(options); return confirm(options); }, document: { querySelector: () => ({ scrollIntoView() {}, focus() {} }) } };
    const result = new Function(...Object.keys(deps), `${actions}\nreturn { applyAcademicResponse, uploadVerificationDocument, retryAcademicRecordScan, deleteVerificationDocument, refreshEvidence };`)(...Object.values(deps));
    return { ...state, ...result, writes, confirmations, respond: (fn) => { respond = fn; }, confirm: (fn) => { confirm = fn; } };
}
const event = (chosen = file()) => ({ target: { files: [chosen], value: 'selected-file' } });

test('upload submits the selected revision and a successful replacement does not erase an academic draft', async () => {
    const state = harness([doc()]); const input = event();
    await state.uploadVerificationDocument('income_record', input);
    assert.equal(state.writes[0][2].get('expected_revision'), '2');
    assert.equal(state.form.value.gwa, 95); assert.equal(JSON.parse(state.savedFormSnapshot.value).gwa, 91);
    assert.equal(state.evidenceSuccess.value, 'Saved for review.'); assert.equal(input.target.value, '');
});

test('accepted replacement can be cancelled without an upload or losing the current record', async () => {
    const state = harness([doc('income_record', { has_current_acceptance: true })]); state.confirm(async () => false);
    const input = event(); await state.uploadVerificationDocument('income_record', input);
    assert.equal(state.writes.length, 0); assert.equal(state.verificationDocuments.value.length, 1);
    assert.match(state.confirmations[0].message, /existing applications are not restarted/);
    assert.equal(state.evidenceActionPending.value, false); assert.equal(input.target.value, '');
});

test('new uploads use revision zero and invalid files or missing consent do not reach the API', async () => {
    const state = harness(); state.verificationDocumentTermsAccepted.value = false;
    await state.uploadVerificationDocument('income_record', event()); assert.equal(state.writes.length, 0);
    state.verificationDocumentTermsAccepted.value = true;
    await state.uploadVerificationDocument('income_record', event(new File(['bad'], 'bad.exe'))); assert.equal(state.writes.length, 0);
    await state.uploadVerificationDocument('income_record', event()); assert.equal(state.writes[0][2].get('expected_revision'), '0');
});

test('concurrent uploads and scans are blocked even while a confirmation is open', async () => {
    const state = harness([doc('income_record', { has_current_acceptance: true })]); const pending = deferred(); state.confirm(() => pending.promise);
    const request = state.uploadVerificationDocument('income_record', event());
    await state.uploadVerificationDocument('identity_record', event()); await state.retryAcademicRecordScan(doc('academic_record'));
    assert.equal(state.writes.length, 0); assert.equal(state.confirmations.length, 1);
    pending.resolve(false); await request; assert.equal(state.profileEditorBusy.value, false);
});

test('stale writes require refresh and preserve current files and manual draft values', async () => {
    const state = harness([doc()]); state.respond(async () => { throw { response: { status: 409, data: { message: 'Record changed.' } } }; });
    await state.uploadVerificationDocument('income_record', event());
    assert.equal(state.evidenceRefreshRequired.value, true); assert.equal(state.verificationDocuments.value.length, 1);
    await state.deleteVerificationDocument(doc()); assert.equal(state.writes.length, 1);
    state.respond(async () => ({ data: { user: { gwa: 92 }, verification_documents: [doc('income_record', { revision: 3, has_current_acceptance: true })] } }));
    await state.refreshEvidence();
    assert.equal(state.evidenceRefreshRequired.value, false); assert.equal(state.verificationDocuments.value[0].revision, 3);
    assert.equal(state.form.value.gwa, 95); assert.equal(JSON.parse(state.savedFormSnapshot.value).gwa, 91);
});

test('failed refresh stays guarded and validation errors name the specific file problem', async () => {
    const state = harness([doc()]); state.respond(async () => { throw { response: { status: 422, data: { message: 'Invalid', errors: { document_file: ['Choose a clear PDF.'] } } } }; });
    await state.uploadVerificationDocument('income_record', event()); assert.equal(state.errorMessage.value, 'Choose a clear PDF.');
    state.evidenceRefreshRequired.value = true;
    await state.refreshEvidence(); assert.equal(state.evidenceRefreshRequired.value, true); assert.equal(state.evidenceRefreshing.value, false);
});

test('deletion sends the current revision while an academic rescan explicitly refreshes saved grade values', async () => {
    const state = harness([doc()]); await state.deleteVerificationDocument(doc());
    assert.deepEqual(state.writes[0][2].data, { expected_revision: 2 }); assert.equal(state.form.value.gwa, 95);
    await state.retryAcademicRecordScan(doc('academic_record')); assert.deepEqual(state.writes[1][2], { expected_revision: 2 });
    assert.equal(state.form.value.gwa, 91); assert.equal(JSON.parse(state.savedFormSnapshot.value).gwa, 91);
});

test('notification focus waits for asynchronously loaded rows and does not jump on every status refresh', async () => {
    const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEvidenceList.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = reactive({ focusType: 'income_record', groups: [], ocr: {} });
    let mounted, focused = 0;
    const target = { dataset: { evidenceType: 'income_record' }, isConnected: true, closest: () => null, scrollIntoView() {}, focus() { focused++; } };
    const deps = { ref, computed, nextTick, watch, evidenceProgress, evidenceStatus, evidenceFilePolicy: () => ({}),
        onMounted: (callback) => { mounted = callback; }, defineProps: () => props, defineEmits() {}, requestAnimationFrame: (callback) => callback(0) };
    const scope = effectScope();
    const state = scope.run(() => new Function(...Object.keys(deps), `${script}\nreturn { root };`)(...Object.values(deps)));
    state.root.value = { querySelectorAll: () => props.groups.length ? [target] : [] };
    await mounted(); assert.equal(focused, 0);
    props.groups = [{ rows: [row('income_record', doc())] }];
    await nextTick(); await new Promise((resolve) => setTimeout(resolve, 0)); assert.equal(focused, 1);
    props.groups = [{ rows: [row('income_record', doc('income_record', { status: 'approved' }))] }];
    await nextTick(); assert.equal(focused, 1);
    scope.stop();
});
