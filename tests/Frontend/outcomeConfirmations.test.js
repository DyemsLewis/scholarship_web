import assert from 'node:assert/strict';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { readFileSync } from 'node:fs';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref } from 'vue';
import { confirmOutcome, registerOutcomeConfirmationPresenter } from '../../resources/js/support/outcomeConfirmation.js';
import { decisionAwardLabel, finalDecisionConsequence, stageDecisionConsequence } from '../../resources/js/support/providerDecisionConfirmation.js';
import { useConfirmationDialog } from '../../resources/js/composables/useConfirmationDialog.js';
import { useApplicantEvidenceDecision } from '../../resources/js/composables/useApplicantEvidenceDecision.js';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { reviewErrorMessage, reviewStatusLabel } from '../../resources/js/support/adminReviewPresentation.js';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const flush = () => new Promise((resolve) => setImmediate(resolve));
const guarded = [
    'components/DecisionReopenDialog.vue', 'components/ProviderDocumentReviewModal.vue',
    'components/ApplicantRecipientMonitoring.vue', 'components/application-detail/RecipientAgreementDialog.vue',
    'components/benefit-delivery/BenefitDeliveryReportDialog.vue', 'components/case-reviews/RecipientCaseReviewDialog.vue',
    'components/case-reviews/RecipientCasePrivacySection.vue', 'components/withdrawals/WithdrawalRequestDialog.vue',
    'components/monitoring-detail/BenefitReceiptResponseModal.vue', 'components/monitoring-detail/SupportOutcomeResponseModal.vue',
    'components/monitoring-detail/MonitoringCheckInRecord.vue',
    'pages/AdminApplicantReviewPage.vue', 'pages/AdminProviderReviewPage.vue', 'pages/AdminProgramReviewPage.vue',
    'pages/AdminMonitoringReviewPage.vue', 'pages/AdminSupportOfficerWorkspacePage.vue',
    'pages/SupportReportQueuePage.vue', 'pages/UserApplicationDetailPage.vue',
    'pages/admin/billing/AdminServiceCloseoutPage.vue', 'pages/admin/billing/AdminServiceDeliveryPage.vue',
    'pages/admin/billing/AdminServiceMeetingPage.vue', 'pages/provider/billing/BillingServiceCloseoutPage.vue',
    'pages/admin/reviews/ApplicantReviewRecordPage.vue', 'pages/admin/reviews/ProviderReviewRecordPage.vue',
    'pages/admin/reviews/ProviderDocumentReviewDialog.vue', 'pages/admin/reviews/ProgramReviewRecordPage.vue',
    'pages/admin/reviews/MonitoringReviewRecordPage.vue', 'pages/admin/support/SupportCaseRecordPage.vue',
    'pages/provider/reviews/ApplicationReviewRecordPage.vue', 'pages/provider/selection/SelectionActivityRecordPage.vue',
    'pages/provider/decisions/FinalDecisionRecordPage.vue',
    'pages/provider/selection/SelectionSessionRecordPage.vue', 'pages/provider/monitoring/MonitoringCheckInRecordPage.vue',
];

for (const path of [...guarded, 'components/OutcomeConfirmationHost.vue', 'components/ConfirmationDialog.vue']) {
    test(`${path} compiles with outcome confirmation`, () => {
        const { descriptor, errors } = parse(read(path), { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        assert.deepEqual(compileTemplate({ id: path, filename: path, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
        if (guarded.includes(path)) assert.match(descriptor.scriptSetup.content, /await confirmOutcome\(/);
    });
}

test('missing presenter and invalid form both fail closed without saving', async () => {
    assert.equal(await confirmOutcome({ outcome: 'passed' }), false);
    let prompts = 0;
    const unregister = registerOutcomeConfirmationPresenter(() => { prompts++; return true; });
    try {
        assert.equal(await confirmOutcome({}, () => false), false);
        assert.equal(prompts, 0);
    } finally { unregister(); }
});

test('summary names the record, formats machine keys, explains effects, and uses the shared style', async () => {
    let options;
    const unregister = registerOutcomeConfirmationPresenter((value) => { options = value; return true; });
    try {
        assert.equal(await confirmOutcome({ record: 'Mira / Exam', outcome: 'not_passed', danger: true,
            consequence: 'This closes the application at this stage.' }), true);
        assert.match(options.message, /Record: Mira \/ Exam/);
        assert.match(options.message, /Outcome: Not Passed/);
        assert.match(options.message, /closes the application/);
        assert.equal(options.tone, 'danger');
        assert.equal(options.cancelLabel, 'Go back');
        assert.equal(options.confirmLabel, 'Record outcome');
        assert.doesNotMatch(read('components/ConfirmationDialog.vue'), /v-html/);
    } finally { unregister(); }
});

test('invalidated permission or stale form after opening cannot save on confirm', async () => {
    const dialog = useConfirmationDialog();
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    let valid = true;
    try {
        const pending = confirmOutcome({ outcome: 'approved' }, () => valid);
        assert.equal(dialog.confirmation.open, true);
        valid = false;
        dialog.confirmConfirmation();
        assert.equal(await pending, false);
        assert.equal(dialog.confirmation.open, false);
    } finally { unregister(); }
});

test('overlapping prompts cancel the earlier request instead of saving two outcomes', async () => {
    const dialog = useConfirmationDialog();
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    try {
        const earlier = confirmOutcome({ record: 'Earlier', outcome: 'passed' });
        const latest = confirmOutcome({ record: 'Latest', outcome: 'not_passed' });
        assert.match(dialog.confirmation.message, /Latest/);
        dialog.confirmConfirmation();
        assert.equal(await earlier, false);
        assert.equal(await latest, true);
    } finally { unregister(); }
});

test('Cancel retains evidence outcome, comparison, notes and does not send a mutation', async () => {
    const dialog = useConfirmationDialog();
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const writes = [];
    const state = useApplicantEvidenceDecision({
        document: () => ({ review_url: '/evidence/1', revision: 2, file_available: true, original_name: 'Grades.pdf' }),
        allowed: () => true, submit: async (...args) => { writes.push(args); return { data: {} }; }, onSaved() {},
    });
    try {
        state.outcome.value = 'approved'; state.notes.value = 'Keep this comparison note.'; state.compared.value = true;
        const pending = state.save();
        assert.equal(dialog.confirmation.open, true);
        assert.equal(state.saving.value, false);
        assert.equal(writes.length, 0);
        dialog.cancelConfirmation(); await pending;
        assert.equal(writes.length, 0);
        assert.equal(state.notes.value, 'Keep this comparison note.');
        assert.equal(state.outcome.value, 'approved'); assert.equal(state.compared.value, true);
        const retry = state.save(); dialog.confirmConfirmation(); await retry;
        assert.equal(writes.length, 1);
        assert.equal(writes[0][1].expected_revision, 2);
    } finally { unregister(); }
});

function harness(path, deps, exposed) {
    const script = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const bindings = { staffQueueReturn, ref, computed, confirmOutcome, onMounted() {}, useAdminReviewNavigation: () => ({ next: ref(null), loading: ref(false), error: ref(''), backUrl: '/admin/workspaces/reviews/programs', load() {} }), ...deps };
    return new Function(...Object.keys(bindings), `${script}\nreturn {${exposed}};`)(...Object.values(bindings));
}

test('final outcome starts blank; the amount reviewed on promotion is the amount sent after confirmation', async () => {
    const dialog = useConfirmationDialog(), writes = [];
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const application = {
        final_outcome: 'waitlisted', workflow: { current_stage: 'decision', is_closed: false },
        applicant: { name: 'Mira Cruz' }, scholarship: { title: 'Learning Grant', slots_available: 3, award_amount: 8000 },
    };
    const window = { location: { search: '' }, axios: {
        get: async () => ({ data: { application } }),
        patch: async (...args) => { writes.push(args); return { data: { application: { ...application, final_outcome: 'selected' } } }; },
    } };
    const state = harness('pages/provider/decisions/FinalDecisionRecordPage.vue', {
        window, document: { getElementById: () => ({ dataset: { applicationId: 2 } }) },
        decisionReasonOptions: [], decisionAwardLabel, finalDecisionConsequence,
    }, 'loadApplication, askToRecordOutcome, decisionForm');
    try {
        await state.loadApplication();
        assert.equal(state.decisionForm.value.outcome, '');
        await state.askToRecordOutcome();
        assert.equal(dialog.confirmation.open, false);
        Object.assign(state.decisionForm.value, { outcome: 'selected', awarded_amount: 4500, notes: 'Confirmed from the program record.' });
        const cancel = state.askToRecordOutcome();
        assert.match(dialog.confirmation.message, /Mira Cruz.*\n.*Promote To Selected.*\n.*Learning Grant.*\n.*PHP 4,500\.00/);
        dialog.cancelConfirmation(); await cancel;
        assert.equal(writes.length, 0);
        assert.equal(state.decisionForm.value.awarded_amount, 4500);
        const save = state.askToRecordOutcome();
        state.decisionForm.value.awarded_amount = 1200;
        dialog.confirmConfirmation(); await save;
        assert.equal(writes.length, 1);
        assert.match(writes[0][0], /\/final-outcome$/);
        assert.equal(writes[0][1].awarded_amount, 4500);
        assert.equal(writes[0][1].expected_outcome, 'waitlisted');
    } finally { unregister(); }
});

test('stage result confirmation names the configured next stage', () => {
    const workflow = { steps: [{ key: 'screening', label: 'Pre-screening' }, { key: 'exam', label: 'Exam' }] };
    assert.equal(stageDecisionConsequence(workflow, 'screening', true), 'The applicant will move to Exam and will be notified.');
    assert.match(stageDecisionConsequence(workflow, 'screening', false), /closes the application/);
});

test('admin publication decision has no request before confirmation and Cancel keeps the draft', async () => {
    const dialog = useConfirmationDialog(), writes = [];
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const program = { title: 'Learning Grant', status: 'pending_review', review_workflow: { ready: true, token: 'current-review' } };
    const window = { location: { pathname: '/admin/workspaces/reviews/programs/4' }, axios: {
        get: async () => ({ data: { scholarship: program } }),
        patch: async (...args) => { writes.push(args); return { data: { scholarship: { ...program, status: 'published' } } }; },
    } };
    const state = harness('pages/admin/reviews/ProgramReviewRecordPage.vue', { window, useAdminResource, reviewStatusLabel, reviewErrorMessage }, 'loadRecord, saveDecision, notes, decision');
    try {
        await state.loadRecord(); state.notes.value = 'Completed all publication checks.';
        const cancelled = state.saveDecision(); assert.equal(writes.length, 0);
        dialog.cancelConfirmation(); await cancelled;
        assert.equal(writes.length, 0); assert.equal(state.notes.value, 'Completed all publication checks.');
        const confirmed = state.saveDecision(); dialog.confirmConfirmation(); await confirmed;
        assert.equal(writes.length, 1); assert.equal(writes[0][1].status, 'published');
    } finally { unregister(); }
});

test('attendance restores its original select value on Cancel and saves only after Confirm', async () => {
    const dialog = useConfirmationDialog(), writes = [];
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const assignment = { id: 2, attendance: { status: 'present' }, application: { applicant: { name: 'Mira Cruz' } } };
    const window = { location: { search: '' }, axios: {
        patch: async (...args) => { writes.push(args); return { data: { message: 'Attendance recorded.' } }; },
        get: async () => ({ data: { session: { status: 'scheduled', assignments: [assignment] } } }),
    } };
    const state = harness('pages/provider/selection/SelectionSessionRecordPage.vue', {
        window, document: { getElementById: () => ({ dataset: { sessionId: 1, programId: 1 } }) },
    }, 'session, saveAttendance');
    state.session.value = { status: 'scheduled', assignments: [assignment] };
    try {
        const select = { value: 'absent' };
        const cancel = state.saveAttendance(assignment, 'absent', select);
        assert.equal(select.value, 'present'); assert.equal(writes.length, 0);
        dialog.cancelConfirmation(); await cancel;
        assert.equal(writes.length, 0); assert.equal(assignment.attendance.status, 'present');
        const accept = state.saveAttendance(assignment, 'absent', select);
        dialog.confirmConfirmation(); await accept;
        assert.equal(writes.length, 1); assert.equal(writes[0][1].attendance_status, 'absent');
    } finally { unregister(); }
});

test('exam result confirmation preserves notes on Cancel and records the chosen result once', async () => {
    const dialog = useConfirmationDialog(), writes = [];
    const unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const application = { applicant: { name: 'Mira Cruz' }, scholarship: { title: 'Learning Grant' }, workflow_stage: 'exam' };
    const window = { location: { search: '' }, axios: {
        patch: async (...args) => { writes.push(args); return { data: { application: { ...application, workflow_stage: 'interview' } } }; },
    } };
    const state = harness('pages/provider/selection/SelectionActivityRecordPage.vue', {
        window, document: { getElementById: () => ({ dataset: { applicationId: 4 } }) }, decisionReasonOptions: [], stageDecisionConsequence,
    }, 'application, readiness, resultForm, recordResult');
    state.application.value = application; state.readiness.value = { can_record_result: true };
    state.resultForm.value.notes = 'Scoring proof compared.';
    try {
        await state.recordResult(); assert.equal(dialog.confirmation.open, false);
        state.resultForm.value.result = 'passed';
        const cancel = state.recordResult();
        assert.equal(dialog.confirmation.title, 'Confirm exam result');
        assert.match(dialog.confirmation.message, /Outcome: Passed/);
        dialog.cancelConfirmation(); await cancel;
        assert.equal(writes.length, 0); assert.equal(state.application.value.workflow_stage, 'exam');
        assert.equal(state.resultForm.value.notes, 'Scoring proof compared.');
        const accept = state.recordResult(); dialog.confirmConfirmation(); await accept;
        assert.equal(writes.length, 1); assert.equal(writes[0][1].result, 'passed');
        assert.equal(state.application.value.workflow_stage, 'interview');
    } finally { unregister(); }
});

test('service revision confirmation preserves the existing completed-service reopening workflow', async () => {
    const writes = [], purchase = ref({ status: 'paid', fulfillment_status: 'completed' });
    const unregister = registerOutcomeConfirmationPresenter(async () => true);
    const window = { axios: { post: async (...args) => { writes.push(args); return true; } } };
    const state = harness('pages/provider/billing/BillingServiceCloseoutPage.vue', {
        reactive, window, useProviderServiceWorkspace: () => ({ purchaseId: 4, purchase, isSaving: ref(false), runAction: async (action) => action() }),
    }, 'requestRevision, revisionReason');
    try {
        state.revisionReason.value = 'Please correct the final report.';
        await state.requestRevision();
        assert.equal(writes.length, 1); assert.equal(writes[0][0], '/provider/billing/4/reopen');
        assert.equal(state.revisionReason.value, '');
    } finally { unregister(); }
});

test('global host uses the accessible logout-style dialog above nested outcome forms', () => {
    const host = read('components/OutcomeConfirmationHost.vue');
    assert.match(host, /ConfirmationDialog/); assert.match(host, /:layer="4000"/);
    assert.match(host, /pagehide.*cancelConfirmation/); assert.match(host, /onBeforeUnmount/);
    assert.match(read('app.js'), /mountOutcomeConfirmationHost\(\)/);
    assert.match(read('app.js'), /config.idPrefix = 'outcome-'/);
    assert.match(read('components/DecisionReopenDialog.vue'), /useModalAccessibility/);
    assert.match(read('pages/provider/decisions/FinalDecisionRecordPage.vue'), /await confirmOutcome/);
    for (const path of ['pages/provider/releases/BenefitReleaseRecordPage.vue', 'pages/provider/recipients/RecipientRecordPage.vue']) {
        assert.match(read(path), /requestConfirmation|ConfirmationDialog/);
        assert.doesNotMatch(read(path), /await confirmOutcome/);
    }
});
