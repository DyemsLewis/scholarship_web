import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useConfirmationDialog } from '../../resources/js/composables/useConfirmationDialog.js';
import { scheduleAcknowledgementPrompt } from '../../resources/js/support/applicationConfirmations.js';

function setup(page, axios, exposed) {
    const source = readFileSync(new URL(`../../resources/js/pages/${page}.vue`, import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const unmount = [];
    const bindings = {
        computed, ref, useConfirmationDialog, scheduleAcknowledgementPrompt,
        window: { location: { search: '' }, axios },
        document: { getElementById: () => ({ dataset: { applicationId: '3' } }) },
        watch() {},
        onMounted() {},
        onBeforeUnmount: (callback) => unmount.push(callback),
        showPortalToast() {},
    };
    const state = new Function(...Object.keys(bindings), script + '\nreturn {' + exposed + '};')(...Object.values(bindings));
    return { ...state, unmount: () => unmount.forEach((callback) => callback()) };
}

const dialogState = 'confirmation, confirmConfirmation, cancelConfirmation';
const endpoint = '/dashboard/applications/3/stage-activities/6/acknowledge';
const schedule = {
    id: 6,
    source: 'stage_session',
    status: 'scheduled',
    type: 'exam',
    title: 'Entrance exam',
    scheduled_label: 'Nov 18, 2026 9:00 AM',
    requires_applicant_acknowledgment: true,
    acknowledge_endpoint: endpoint,
};

test('final application submission preserves answers on cancel and posts once after confirmation', async () => {
    const requests = [];
    const state = setup('UserApplicationsPage', {
        post: async (...args) => { requests.push(args); },
        get: async () => ({ data: { user: {}, stats: {}, scholarships: [], applications: [] } }),
    }, 'submitApplication, profileReadiness, scholarships, selectedScholarshipId, applicationTermsAccepted, applicationAnswers, documentChecklist, notes, ' + dialogState);

    state.profileReadiness.value = { complete: true, verified: true };
    state.scholarships.value = [
        { id: 1, title: 'First scholarship', requirements: 'School ID', application_questions: [{ id: 5, required: true, prompt: 'Why are you applying?' }] },
        { id: 2, title: 'Different scholarship' },
    ];
    state.selectedScholarshipId.value = '1';
    state.documentChecklist.value = ['School ID'];
    state.applicationAnswers.value = { 5: 'My answer' };
    state.notes.value = 'My note';
    state.applicationTermsAccepted.value = true;

    const canceled = state.submitApplication();
    assert.match(state.confirmation.title, /Submit this application/);
    assert.match(state.confirmation.message, /First scholarship/);
    await state.submitApplication();
    assert.deepEqual(requests, []);
    state.cancelConfirmation();
    await canceled;
    assert.equal(state.applicationAnswers.value[5], 'My answer');
    assert.deepEqual(state.documentChecklist.value, ['School ID']);
    assert.equal(state.applicationTermsAccepted.value, true);

    const stale = state.submitApplication();
    state.selectedScholarshipId.value = '2';
    state.confirmConfirmation();
    await stale;
    assert.deepEqual(requests, []);
    state.selectedScholarshipId.value = '1';

    const confirmed = state.submitApplication();
    state.confirmConfirmation();
    state.confirmConfirmation();
    await confirmed;
    assert.deepEqual(requests, [['/dashboard/applications', {
        scholarship_id: 1,
        document_checklist: ['School ID'],
        application_answers: [{ question_id: 5, answer: 'My answer' }],
        notes: 'My note',
        terms_accepted: '1',
    }]]);
});

test('the application Schedule tab only acknowledges the activity that was confirmed', async () => {
    const requests = [];
    const acknowledged = { ...schedule, requires_applicant_acknowledgment: false, applicant_acknowledged: true };
    const state = setup('UserApplicationDetailPage', {
        patch: async (url) => { requests.push(url); return { data: { activity: acknowledged } }; },
    }, 'acknowledgeCurrentActivity, application, ' + dialogState);
    state.application.value = { id: 3, selection_activities: [{ ...schedule }] };

    const canceled = state.acknowledgeCurrentActivity();
    assert.match(state.confirmation.message, /Entrance exam.*Nov 18.*does not mark you as having attended/s);
    assert.deepEqual(requests, []);
    state.cancelConfirmation();
    await canceled;
    assert.equal(state.application.value.selection_activities[0].applicant_acknowledged, undefined);

    const stale = state.acknowledgeCurrentActivity();
    state.application.value.selection_activities[0] = { ...schedule, id: 7 };
    state.confirmConfirmation();
    await stale;
    assert.deepEqual(requests, []);
    state.application.value.selection_activities[0] = { ...schedule };

    const confirmed = state.acknowledgeCurrentActivity();
    state.confirmConfirmation();
    await confirmed;
    assert.deepEqual(requests, [endpoint]);
    assert.equal(state.application.value.selection_activities[0].applicant_acknowledged, true);
    await state.acknowledgeCurrentActivity();
    assert.equal(state.confirmation.open, false);
    assert.deepEqual(requests, [endpoint]);
});

test('the dedicated Activities page cancels pending confirmation on unmount and reloads only after saving', async () => {
    const requests = [];
    const state = setup('UserApplicationActivitiesPage', {
        patch: async (url) => { requests.push(['PATCH', url]); return { data: {} }; },
        get: async (url) => {
            requests.push(['GET', url]);
            return { data: { detail: { content: { active: [{ ...schedule, applicant_acknowledged: true }] } } } };
        },
    }, 'acknowledgeActivity, detail, isLoading, ' + dialogState);
    state.isLoading.value = false;
    state.detail.value = { content: { active: [{ ...schedule }] } };
    const activity = state.detail.value.content.active[0];

    const canceled = state.acknowledgeActivity(activity);
    assert.equal(state.confirmation.open, true);
    await state.acknowledgeActivity(activity);
    state.unmount();
    await canceled;
    assert.deepEqual(requests, []);

    const confirmed = state.acknowledgeActivity(activity);
    state.confirmConfirmation();
    await confirmed;
    assert.deepEqual(requests, [['PATCH', endpoint], ['GET', '/dashboard/applications/3/data']]);
    assert.equal(state.detail.value.content.active[0].applicant_acknowledged, true);
});
