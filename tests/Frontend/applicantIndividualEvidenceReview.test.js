import assert from 'node:assert/strict';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { confirmOutcome, afterConfirmation } from './helpers/acceptedOutcome.js';
import { profileVerificationLabel } from '../../resources/js/support/applicantProfileLabels.js';
import { readFileSync } from 'node:fs';
import { computed, ref } from 'vue';
import { parse } from '@vue/compiler-sfc';
import { useApplicantEvidenceDecision } from '../../resources/js/composables/useApplicantEvidenceDecision.js';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { evidenceRows, evidenceState, evidenceProfileClaims, evidenceLabel, evidenceEventLabel, evidenceReviewDate } from '../../resources/js/support/applicantEvidenceReview.js';
import { reviewErrorMessage } from '../../resources/js/support/adminReviewPresentation.js';

const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const record = { id: 8, document_type: 'academic_record', revision: 2, review_url: '/admin/applicant-verification-documents/8/review', file_available: true };
function decisionHarness() {
    const document = ref({ ...record }); const allowed = ref(true); const writes = []; const results = [];
    let request = async () => ({ data: { document: { ...record, revision: 3 }, user: {}, evidence_review: {} } });
    const state = useApplicantEvidenceDecision({ document: () => document.value, allowed: () => allowed.value,
        submit: (...args) => { writes.push(args); return request(...args); }, onSaved: (payload) => results.push(payload) });
    return { ...state, document, allowed, writes, results, request: (callback) => { request = callback; } };
}

test('evidence states never mistake an uploaded, legacy, or missing file for current acceptance', () => {
    assert.equal(evidenceState(null).key, 'missing');
    assert.equal(evidenceState({ status: 'submitted' }).key, 'pending');
    assert.equal(evidenceState({ status: 'approved', legacy_approval: true }).key, 'pending');
    assert.equal(evidenceState({ status: 'approved', has_current_acceptance: true }).key, 'accepted');
    assert.equal(evidenceState({ has_current_acceptance: true, file_available: false }).key, 'unavailable');
    assert.equal(evidenceState({ status: 'rejected' }).key, 'replacement');
});

test('queue shows required missing evidence, hides absent optional evidence and retains retired files read-only', () => {
    const rows = evidenceRows({ profile_evidence_requirements: [{ value: 'identity_record', label: 'Identity', required: true }, { value: 'guardian_record', required: false }],
        verification_documents: [{ document_type: 'no_current_scholarship_proof', label: 'Earlier scholarship proof' }] });
    assert.equal(rows.length, 2); assert.equal(rows[0].state.key, 'missing'); assert.equal(rows[0].required, true);
    assert.equal(rows[1].retired, true); assert.equal(rows[1].required, false);
});

test('comparison includes only centrally mapped profile claims and readable education values', () => {
    const claims = evidenceProfileClaims({ name: 'Test Learner', school: 'Example School', education_level: 'senior_high_school', gwa: 91, grading_scale: 'percentage', email: 'private@example.test' },
        { claim_fields: ['first_name', 'education_level', 'school', 'gwa'] });
    assert.deepEqual(claims.map((item) => item.key), ['official_name', 'school', 'education_level', 'gwa']);
    assert.equal(claims.find((item) => item.key === 'education_level').value, 'Senior High School');
    assert.equal(claims.find((item) => item.key === 'gwa').value, '91.00%');
    assert.ok(!claims.some((item) => item.key === 'email'));
});

test('acceptance requires an explicit outcome and comparison, and submits the current revision once', async () => {
    const state = decisionHarness(); await state.save(); assert.equal(state.writes.length, 0);
    state.outcome.value = 'approved'; await state.save(); assert.equal(state.canSave.value, false);
    state.compared.value = true; state.notes.value = '  Compared with the report card.  ';
    assert.equal(state.canSave.value, true); await state.save(); await state.save();
    assert.equal(state.writes.length, 1);
    assert.deepEqual(state.writes[0], [record.review_url, { status: 'approved', expected_revision: 2, review_notes: 'Compared with the report card.' }, { portalToast: false }]);
    assert.equal(state.results.length, 1);
});

test('replacement requires a trimmed reason but permits asking for a missing file', async () => {
    const state = decisionHarness(); state.document.value.file_available = false;
    state.outcome.value = 'approved'; state.compared.value = true; assert.equal(state.canSave.value, false);
    state.outcome.value = 'rejected'; state.notes.value = '   '; await state.save(); assert.equal(state.writes.length, 0);
    state.notes.value = 'Upload a readable current record.'; await state.save();
    assert.equal(state.writes[0][1].status, 'rejected');
});

test('read-only roles and invalid revisions cannot submit decisions', async () => {
    const state = decisionHarness(); state.outcome.value = 'approved'; state.compared.value = true;
    state.allowed.value = false; await state.save(); assert.equal(state.writes.length, 0);
    state.allowed.value = true; state.document.value.revision = undefined; await state.save(); assert.equal(state.writes.length, 0);
    state.document.value.revision = 2; state.document.value.review_url = ''; await state.save(); assert.equal(state.writes.length, 0);
});

test('a stale conflict keeps the note and blocks retry until current evidence is reloaded', async () => {
    const state = decisionHarness(); state.outcome.value = 'rejected'; state.notes.value = 'A specific correction.';
    state.request(async () => { throw { response: { status: 409 } }; });
    await state.save(); await state.save();
    assert.equal(state.writes.length, 1); assert.equal(state.stale.value, true);
    assert.equal(state.notes.value, 'A specific correction.'); assert.match(state.error.value, /Reload the record/);
    state.reset(); assert.equal(state.stale.value, false); assert.equal(state.outcome.value, ''); assert.equal(state.compared.value, false);
});

test('validation and connection errors retain the note and allow a deliberate retry', async () => {
    const state = decisionHarness(); state.outcome.value = 'rejected'; state.notes.value = 'Keep this explanation.';
    state.request(async () => { throw { response: { status: 422, data: { errors: { review_notes: ['Explain the requested correction.'] } } } }; });
    await state.save(); assert.equal(state.error.value, 'Explain the requested correction.');
    assert.equal(state.notes.value, 'Keep this explanation.'); assert.equal(state.canSave.value, true);
    state.request(async () => { throw new Error('Offline'); }); await state.save(); assert.match(state.error.value, /note has been kept/);
});

test('overlapping clicks and resets cannot replace a decision in flight', async () => {
    const state = decisionHarness(); const pending = deferred(); state.request(() => pending.promise);
    state.outcome.value = 'approved'; state.compared.value = true; const request = state.save(); await afterConfirmation();
    await state.save(); state.reset(); assert.equal(state.outcome.value, 'approved'); assert.equal(state.writes.length, 1);
    pending.resolve({ data: { document: { id: 8 } } }); await request; assert.equal(state.saving.value, false);
});

test('history and fallback labels hide internal underscores and handle invalid dates', () => {
    assert.equal(evidenceEventLabel('claims_changed'), 'Profile details changed');
    assert.equal(evidenceEventLabel('new_event'), 'New Event');
    assert.equal(evidenceLabel({}, 'income_record'), 'Income Record');
    assert.equal(evidenceReviewDate('not-a-date'), 'Not recorded');
});

function pageHarness(payload) {
    payload = { review_workflow: { token: 'current-review', ready: true }, ...payload };
    const source = readFileSync(new URL('../../resources/js/pages/admin/reviews/ApplicantReviewRecordPage.vue', import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const window = { location: { pathname: '/admin/workspaces/reviews/applicants/39' }, axios: { get: async () => ({ data: { applicant: payload } }) } };
    const deps = { staffQueueReturn, confirmOutcome, profileVerificationLabel, ref, computed, useAdminResource, reviewErrorMessage, evidenceRows, evidenceReviewDate, onMounted() {}, window, applicantProfileDetails: () => [], profileEducationSummary: () => '', useAdminReviewNavigation: () => ({ next: ref(null), loading: ref(false), error: ref(''), backUrl: '/admin/workspaces/reviews/applicants', load() {} }) };
    return new Function(...Object.keys(deps), `${script}\nreturn { loadRecord, applicant, canSave, notes, decision, evidenceSaved, selectedDocumentId, saveDecision, reloadEvidence, recordStale, window };`)(...Object.values(deps));
}

test('page uses server evidence readiness, not upload counts or legacy approval, to allow final verification', async () => {
    const state = pageHarness({ can_review_evidence: true, gwa: 91, grading_scale: 'percentage', applicant_verification_status: 'approved', verification_documents: [record], evidence_review: { required_evidence_accepted: false } });
    await state.loadRecord(); assert.equal(state.canSave.value, false);
    state.applicant.value.evidence_review.required_evidence_accepted = true; assert.equal(state.canSave.value, true);
    state.applicant.value.can_review_evidence = false; assert.equal(state.canSave.value, false);
});

test('a document decision updates current evidence and profile state without overwriting final-decision drafts', async () => {
    const state = pageHarness({ can_review_evidence: true, gwa: 91, verification_documents: [record], evidence_review: { required_evidence_accepted: false } });
    await state.loadRecord(); state.notes.value = 'Unsaved final explanation'; state.selectedDocumentId.value = 8;
    state.evidenceSaved({ document: { id: 8, has_current_acceptance: true, revision: 3 }, user: { gwa: 91, applicant_verification_status: 'pending' }, evidence_review: { required_evidence_accepted: true }, review_workflow: { token: 'updated-review', ready: true }, message: 'Accepted.' });
    assert.equal(state.applicant.value.verification_documents[0].revision, 3);
    assert.equal(state.applicant.value.verification_oversight.status, 'pending');
    assert.equal(state.canSave.value, true); assert.equal(state.notes.value, 'Unsaved final explanation'); assert.equal(state.applicant.value.gwa, 91);
    assert.equal(state.selectedDocumentId.value, null);
});

test('final approval detects remotely changed evidence and requires reload without discarding the draft', async () => {
    const state = pageHarness({ can_review_evidence: true, gwa: 91, grading_scale: 'percentage', verification_documents: [record], evidence_review: { required_evidence_accepted: true } });
    await state.loadRecord(); state.notes.value = 'Keep my final review note.';
    state.window.axios.patch = async () => { throw { response: { status: 422, data: { errors: { evidence_reviews: ['A required file changed.'] } } } }; };
    await state.saveDecision(); assert.equal(state.recordStale.value, true); assert.equal(state.canSave.value, false);
    await state.reloadEvidence(); assert.equal(state.recordStale.value, false); assert.equal(state.canSave.value, true);
    assert.equal(state.notes.value, 'Keep my final review note.');
});

test('an unsubmitted profile has a valid outcome selected but cannot be verified, and correction does not send academic edits', async () => {
    const payload = { can_review_evidence: true, applicant_verification_status: 'unsubmitted', evidence_review: { required_evidence_accepted: false } };
    const state = pageHarness(payload); await state.loadRecord();
    assert.equal(state.decision.value, 'approved'); assert.equal(state.canSave.value, false);
    state.decision.value = 'unsubmitted'; assert.equal(state.canSave.value, false);
    state.decision.value = 'rejected'; state.notes.value = 'Please add supporting evidence.';
    let sent;
    state.window.axios.patch = async (url, body) => { sent = body; return { data: { applicant: payload } }; };
    await state.saveDecision();
    assert.deepEqual(sent, { verification_status: 'rejected', verification_notes: 'Please add supporting evidence.', expected_review_token: 'current-review' });
});

test('review and history use accessible bounded dialogs and collapsed secondary information', () => {
    const read = (name) => readFileSync(new URL(`../../resources/js/pages/admin/reviews/${name}.vue`, import.meta.url), 'utf8');
    const dialog = read('ApplicantEvidenceReviewDialog'); const history = read('ApplicantEvidenceHistory'); const page = read('ApplicantReviewRecordPage');
    assert.match(dialog, /Teleport to="body"/); assert.match(dialog, /useModalAccessibility/); assert.match(dialog, /aria-modal="true"/);
    assert.match(dialog, /overflow-y-auto/); assert.match(dialog, /100dvh/); assert.match(dialog, /expected_revision|useApplicantEvidenceDecision/);
    assert.match(dialog, /claim.value \|\| 'Not provided'/); assert.match(dialog, /Reload current record/);
    assert.match(history, /<details/); assert.match(history, /@toggle="toggle"/); assert.match(history, /params: \{ page: page.value \}/);
    assert.match(page, /View complete applicant profile/); assert.match(page, /All required evidence is accepted/);
});

test('grade controls belong only to the academic evidence decision, not the final profile form', async () => {
    const dialog = readFileSync(new URL('../../resources/js/pages/admin/reviews/ApplicantEvidenceReviewDialog.vue', import.meta.url), 'utf8');
    assert.match(dialog, /v-if="academic && outcome === 'approved'"[^]*?data-academic-results/);
    assert.match(dialog, /Academic results/);
    assert.match(dialog, /extraValid: \(\) => academicValid.value/);
    const state = pageHarness({ can_review_evidence: true, gwa: 92, grading_scale: 'percentage', verification_documents: [record], evidence_review: { required_evidence_accepted: true } });
    await state.loadRecord();
    let sent;
    state.window.axios.patch = async (url, body) => { sent = body; return { data: { applicant: state.applicant.value } }; };
    await state.saveDecision();
    assert.deepEqual(sent, { verification_status: 'approved', verification_notes: null, expected_review_token: 'current-review' });
    const page = readFileSync(new URL('../../resources/js/pages/admin/reviews/ApplicantReviewRecordPage.vue', import.meta.url), 'utf8');
    assert.doesNotMatch(page, /v-model="gradingScale"|v-model="academicResult"|Confirm the academic result/);
});

test('document-specific validation gates acceptance but never blocks a replacement request', async () => {
    const valid = ref(false), writes = [];
    const state = useApplicantEvidenceDecision({ document: () => record, allowed: () => true,
        extraValid: () => valid.value, extraPayload: () => ({ academic_grading_scale: 'percentage', academic_result: 92 }),
        submit: async (...args) => { writes.push(args); return { data: {} }; }, onSaved: () => {} });
    state.outcome.value = 'approved'; state.compared.value = true;
    assert.equal(state.canSave.value, false); await state.save(); assert.equal(writes.length, 0);
    valid.value = true; await state.save();
    assert.equal(writes[0][1].academic_result, 92);
    state.reset(); valid.value = false; state.outcome.value = 'rejected'; state.notes.value = 'Upload a readable grade record.';
    assert.equal(state.canSave.value, true); await state.save();
    assert.ok(!('academic_result' in writes[1][1]));
});
