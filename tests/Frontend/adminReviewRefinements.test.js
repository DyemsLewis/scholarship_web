import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { confirmOutcome, afterConfirmation } from './helpers/acceptedOutcome.js';
import { profileVerificationLabel } from '../../resources/js/support/applicantProfileLabels.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { reviewErrorMessage, reviewRequirementItems, reviewQueueStatus, reviewStatusClass, reviewStatusLabel } from '../../resources/js/support/adminReviewPresentation.js';
import { evidenceRows, evidenceReviewDate } from '../../resources/js/support/applicantEvidenceReview.js';

const read = (name) => readFileSync(new URL(`../../resources/js/pages/admin/reviews/${name}.vue`, import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
function harness(name, exposed, payload, pathname = '/admin/workspaces/reviews/applicants/39', search = '') {
    const script = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const writes = [], gets = [];
    const window = { location: { pathname, search, hash: '' }, history: { replaceState() {} }, axios: {
        get: async (...args) => { gets.push(args); return { data: payload }; },
        patch: async (...args) => { writes.push(args); return { data: payload }; },
        post: async (...args) => { writes.push(args); return { data: payload }; },
    } };
    const deps = { staffQueueReturn, confirmOutcome, profileVerificationLabel, reviewStatusLabel, ref, computed, useAdminResource, reviewErrorMessage, reviewRequirementItems, onMounted() {}, window, URLSearchParams,
        useAdminReviewNavigation: () => ({ next: ref(null), loading: ref(false), error: ref(''), backUrl: '/admin/workspaces/reviews/applicants', load() {} }),
        applicantProfileDetails: () => [], profileEducationSummary: () => '', evidenceRows, evidenceReviewDate };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), window, writes, gets };
}
const applicant = { name: 'Mira Cruz', applicant_verification_status: 'pending', grading_scale: 'percentage', gwa: 91,
    review_workflow: { token: 'current-review', ready: true },
    can_review_evidence: true, evidence_review: { required_evidence_accepted: true, required_count: 1, accepted_count: 1 },
    profile_photo_url: '/admin/applicants/39/profile-photo', verification_documents: [{ id: 1, document_type: 'academic_record', original_name: 'current-report.pdf', view_url: '/proof' }],
    profile_evidence_requirements: [{ value: 'academic_record', label: 'Academic record', required: true }] };

for (const name of ['ReviewOverviewPage', 'ReviewQueuePage', 'ProviderReviewRecordPage', 'ProgramReviewRecordPage', 'ApplicantReviewRecordPage', 'ApplicantEvidenceReviewDialog', 'ApplicantEvidenceHistory', 'SelectionActivityRecordPage', 'MonitoringReviewRecordPage']) {
    test(`${name} compiles with review refinements`, () => {
        const { descriptor, errors } = parse(read(name), { filename: name });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('queue badges distinguish oversight from release or session status', () => {
    for (const queue of ['benefits', 'selection-activities', 'monitoring']) {
        assert.equal(reviewQueueStatus({ status: 'completed', oversight_status: 'attention' }, queue), 'attention');
    }
    assert.equal(reviewQueueStatus({ status: 'pending_review' }, 'programs'), 'pending_review');
    assert.equal(reviewQueueStatus({ verification_status: 'approved', status: 'pending' }, 'applicants'), 'approved');
    assert.equal(reviewStatusLabel('approved', 'applicants'), 'Evidence reviewed');
    assert.equal(reviewStatusLabel('rejected', 'applicants'), 'Needs replacement');
    assert.equal(reviewStatusLabel('rejected', 'programs'), 'Returned to provider');
    assert.equal(reviewStatusLabel('pending_review'), 'In review');
    assert.equal(reviewStatusLabel('not_started'), 'Not Started');
    assert.match(reviewStatusClass('attention'), /amber/);
    assert.match(reviewStatusClass('documented'), /emerald/);
});

test('field validation feedback takes priority over a generic response summary', () => {
    assert.equal(reviewErrorMessage({ response: { data: { message: 'Invalid fields.', errors: { notes: ['Explain what needs correction.'] } } } }, 'Fallback'), 'Explain what needs correction.');
    assert.equal(reviewErrorMessage(new Error('Offline'), 'Try again.'), 'Try again.');
});

test('text and array requirements render as whole file names, not one badge per character', () => {
    assert.deepEqual(reviewRequirementItems('Enrollment certificate\r\n Report card \n\nRecent school ID'), ['Enrollment certificate', 'Report card', 'Recent school ID']);
    assert.deepEqual(reviewRequirementItems(['Enrollment certificate', '', null, ' Report card ']), ['Enrollment certificate', 'Report card']);
    assert.deepEqual(reviewRequirementItems(null), []);
    assert.deepEqual(reviewRequirementItems('Income proof, if applicable'), ['Income proof, if applicable']);
    assert.match(read('ProgramReviewRecordPage'), /v-for="\(requirement, index\) in requirements"/);
});

for (const [name, key, fn, path] of [
    ['ProviderReviewRecordPage', 'provider', 'loadRecord', '/admin/workspaces/reviews/providers/11'],
    ['ProgramReviewRecordPage', 'scholarship', 'loadRecord', '/admin/workspaces/reviews/programs/4'],
    ['ApplicantReviewRecordPage', 'applicant', 'loadRecord', '/admin/workspaces/reviews/applicants/39'],
    ['SelectionActivityRecordPage', 'session', 'loadSession', '/admin/workspaces/reviews/selection-activities/1'],
    ['MonitoringReviewRecordPage', 'record', 'loadRecord', '/admin/workspaces/reviews/monitoring/14'],
]) {
    test(`${name} fails safely and recovers through retry without writing`, async () => {
        const state = harness(name, `${fn}, loading, ${name.includes('Selection') || name.includes('Monitoring') ? 'errorMessage' : 'loadError'}`, { [key]: { id: 1 } }, path);
        state.window.axios.get = async () => { throw new Error('Offline'); };
        await state[fn]();
        assert.equal(state.loading.value, false);
        assert.match((state.loadError || state.errorMessage).value, /could not be loaded/);
        state.window.axios.get = async () => ({ data: { [key]: { id: 1 } } });
        await state[fn]();
        assert.equal((state.loadError || state.errorMessage).value, '');
        assert.equal(state.writes.length, 0);
        assert.match(read(name), /AdminLoadState/);
        assert.match(read(name), /@retry="load(Session|Record)"/);
    });
}

test('provider review guards missing proof and reasons and retains counters after success', async () => {
    const provider = { verification_status: 'pending', verification_documents: [], programs_count: 3, team_members_count: 7 };
    const state = harness('ProviderReviewRecordPage', 'loadRecord, saveDecision, decision, notes, provider, canSave', { provider }, '/admin/workspaces/reviews/providers/11');
    await state.saveDecision(); assert.equal(state.writes.length, 0);
    await state.loadRecord(); await state.saveDecision(); assert.equal(state.writes.length, 0);
    state.decision.value = 'rejected'; assert.equal(state.canSave.value, false);
    state.notes.value = 'Please replace the registration proof.';
    state.window.axios.patch = async (...args) => { state.writes.push(args); return { data: { provider: { ...provider, verification_status: 'rejected', verification_notes: state.notes.value, programs_count: 0, team_members_count: 0 } } }; };
    await state.saveDecision();
    assert.equal(state.provider.value.programs_count, 3);
    assert.equal(state.provider.value.team_members_count, 7);
    assert.equal(state.writes[0][2].portalToast, false);
});

for (const [name, payload, path] of [
    ['ProviderReviewRecordPage', { provider: { verification_status: 'pending', verification_documents: [{ id: 1 }], document_review: { can_approve: true, token: 'current-review' } } }, '/admin/workspaces/reviews/providers/11'],
    ['ProgramReviewRecordPage', { scholarship: { status: 'pending_review', review_workflow: { ready: true, token: 'current-review' } } }, '/admin/workspaces/reviews/programs/4'],
    ['ApplicantReviewRecordPage', { applicant: structuredClone(applicant) }, '/admin/workspaces/reviews/applicants/39'],
]) {
    test(`${name} blocks duplicate saves, keeps drafts and shows field errors`, async () => {
        const state = harness(name, 'loadRecord, saveDecision, notes, saving, errorMessage', payload, path);
        await state.loadRecord(); state.notes.value = 'Unsaved review explanation';
        const pending = deferred(); state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
        const request = state.saveDecision(); await afterConfirmation(); await state.saveDecision(); await state.loadRecord();
        assert.equal(state.writes.length, 1); assert.equal(state.gets.length, 1);
        assert.equal(state.saving.value, true);
        pending.reject({ response: { data: { message: 'Invalid', errors: { note: ['Specific review feedback'] } } } }); await request;
        assert.equal(state.notes.value, 'Unsaved review explanation');
        assert.equal(state.errorMessage.value, 'Specific review feedback');
        assert.equal(state.saving.value, false);
    });
}

test('draft and closed programs remain read-only and zero slots are not shown as unlimited', async () => {
    const state = harness('ProgramReviewRecordPage', 'loadRecord, saveDecision, canSave', { scholarship: { status: 'draft' } }, '/admin/workspaces/reviews/programs/4');
    for (const status of ['draft', 'closed']) {
        state.window.axios.get = async () => ({ data: { scholarship: { status } } });
        await state.loadRecord(); await state.saveDecision(); assert.equal(state.canSave.value, false);
    }
    assert.equal(state.writes.length, 0);
    assert.ok(read('ProgramReviewRecordPage').includes("program.slots_available ?? 'Not limited'"));
});

test('final profile verification requires all evidence and conditional review notes without editing grades', async () => {
    const state = harness('ApplicantReviewRecordPage', 'loadRecord, applicant, decision, notes, canSave, noteRequired', { applicant: structuredClone(applicant) });
    await state.loadRecord(); assert.equal(state.canSave.value, true);
    state.applicant.value.verification_documents = []; assert.equal(state.canSave.value, false);
    state.decision.value = 'rejected'; assert.equal(state.canSave.value, false);
    state.notes.value = 'Please upload the missing evidence.'; assert.equal(state.canSave.value, true);
    state.decision.value = 'pending'; state.notes.value = ''; assert.equal(state.noteRequired.value, false);
    state.applicant.value.applicant_verification_status = 'approved'; assert.equal(state.noteRequired.value, true);
    assert.equal(state.canSave.value, false);
});

test('photo reviews cannot overlap a profile decision and do not erase unsaved final notes', async () => {
    const state = harness('ApplicantReviewRecordPage', 'loadRecord, saveDecision, reviewPhoto, photoReason, photoError, notes, applicant', { applicant: structuredClone(applicant) });
    await state.loadRecord(); state.notes.value = 'Draft profile note';
    await state.reviewPhoto('request_replacement'); assert.equal(state.writes.length, 0);
    const pending = deferred(); state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
    const request = state.reviewPhoto('approve'); await afterConfirmation(); await state.saveDecision(); await state.reviewPhoto('approve');
    assert.equal(state.writes.length, 1);
    pending.resolve({ data: { applicant: { ...applicant, gwa: 91 }, photo_review_status: 'approved', reason: null } }); await request;
    assert.equal(state.applicant.value.profile_photo_review_status, 'approved');
    assert.equal(state.applicant.value.gwa, 91); assert.equal(state.notes.value, 'Draft profile note');
    state.window.axios.patch = async () => { throw { response: { data: { message: 'Photo review unavailable' } } }; };
    state.photoReason.value = 'Please use a clearer photo.'; await state.reviewPhoto('request_replacement');
    assert.equal(state.photoError.value, 'Photo review unavailable'); assert.equal(state.photoReason.value, 'Please use a clearer photo.');
});

test('a profile save in progress also blocks photo review', async () => {
    const state = harness('ApplicantReviewRecordPage', 'loadRecord, saveDecision, reviewPhoto', { applicant: structuredClone(applicant) });
    await state.loadRecord(); const pending = deferred();
    state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
    const request = state.saveDecision(); await afterConfirmation(); await state.reviewPhoto('approve'); assert.equal(state.writes.length, 1);
    pending.resolve({ data: { applicant: { ...applicant, applicant_verification_status: 'approved' } } }); await request;
});

test('monitoring retry and review preserve the exact case without touching provider decisions', async () => {
    const state = harness('MonitoringReviewRecordPage', 'loadRecord, saveReview, openReview, reviewOpen, notes, outcome', { record: { id: 14, case_id: 23 } }, '/admin/workspaces/reviews/monitoring/14', '?case=23');
    state.openReview(); assert.equal(state.reviewOpen.value, false); await state.saveReview(); assert.equal(state.writes.length, 0);
    await state.loadRecord(); assert.equal(state.gets[0][1].params.case, '23');
    state.openReview(); state.outcome.value = 'follow_up_required'; await state.saveReview(); assert.equal(state.writes.length, 0);
    state.notes.value = 'Please clarify the uploaded proof.'; await state.saveReview();
    assert.equal(state.writes[0][0], '/admin/monitoring/14/oversight-reviews');
    assert.equal(state.writes[0][2].params.case, '23');
    assert.deepEqual(state.writes[0][1], { outcome: 'follow_up_required', notes: 'Please clarify the uploaded proof.' });
    assert.equal(state.reviewOpen.value, false);
});

test('review tables retain whole filenames, modal previews and dedicated action widths', () => {
    for (const name of ['ReviewQueuePage', 'ProviderReviewRecordPage', 'ApplicantReviewRecordPage', 'SelectionActivityRecordPage', 'MonitoringReviewRecordPage']) {
        const source = read(name);
        assert.match(source, /table-fixed/); assert.match(source, /<colgroup>/);
        if (name === 'ProviderReviewRecordPage') {
            assert.match(source, /ProviderDocumentReviewDialog/);
            assert.match(source, /data-review-provider-document/);
        } else {
            assert.match(source, /data-file-preview="true"/); assert.match(source, /h-10 w-10/);
        }
        assert.doesNotMatch(source, /truncate/);
        assert.match(source, /admin-review-workspace/);
    }
    assert.match(read('ApplicantReviewRecordPage'), /OCR results are not verified values/);
    assert.match(read('ApplicantReviewRecordPage'), /show-data-source-notice/);
    assert.doesNotMatch(read('SelectionActivityRecordPage'), /axios\.(post|patch|delete|put)/);
    assert.doesNotMatch(read('SelectionActivityRecordPage'), /meeting_url/);
});
