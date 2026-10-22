import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/components/application-detail/ApplicationDecisionBanner.vue',
    '../../resources/js/components/application-detail/ApplicantApplicationShell.vue',
    '../../resources/js/components/application-detail/ApplicationProgressRecord.vue',
    '../../resources/js/components/application-detail/ApplicationSubmissionSnapshot.vue',
    '../../resources/js/components/application-detail/ApplicationDocumentList.vue',
    '../../resources/js/components/application-detail/ApplicationCorrectionDialog.vue',
    '../../resources/js/components/application-detail/ApplicationActivityRecord.vue',
    '../../resources/js/components/application-detail/FormalApplicationHandoff.vue',
    '../../resources/js/components/application-detail/ApplicationOutcomeRecord.vue',
    '../../resources/js/components/application-detail/RecipientAgreementDialog.vue',
    '../../resources/js/components/application-detail/ApplicationHistoryTimeline.vue',
    '../../resources/js/components/application-detail/ApplicationHistoryEventDialog.vue',
    '../../resources/js/pages/UserApplicationOverviewPage.vue',
    '../../resources/js/pages/UserApplicationSubmissionPage.vue',
    '../../resources/js/pages/UserApplicationActivitiesPage.vue',
    '../../resources/js/pages/UserApplicationOutcomePage.vue',
    '../../resources/js/pages/UserApplicationHistoryPage.vue',
];

for (const relativePath of components) {
    const component = relativePath.split('/').at(-1);

    test(`${component} compiles as a Vue component`, () => {
        const filename = new URL(relativePath, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });

        assert.deepEqual(errors, []);

        const script = descriptor.scriptSetup
            ? compileScript(descriptor, { id: component })
            : null;
        const template = compileTemplate({
            id: component,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: {
                bindingMetadata: script?.bindings ?? {},
            },
        });

        assert.deepEqual(template.errors, []);
    });
}

test('the overview keeps one primary action and delegates detail views to focused routes', () => {
    const overview = readFileSync(new URL('../../resources/js/pages/UserApplicationOverviewPage.vue', import.meta.url), 'utf8');
    const shell = readFileSync(new URL('../../resources/js/components/application-detail/ApplicantApplicationShell.vue', import.meta.url), 'utf8');

    assert.match(overview, /view: 'summary'/);
    assert.match(shell, /ApplicationDecisionBanner/);
    assert.match(shell, /v-if="detail\.view === 'summary'"/);
    assert.match(shell, /applicationVisual/);
    assert.match(shell, /sectionVisuals/);
    assert.match(shell, /application-documents\.jpg/);
    assert.match(shell, /filipino-students-workshop\.jpg/);
    assert.match(shell, /lg:min-h-\[15rem\]/);
    assert.match(shell, /lg:grid-cols-\[minmax\(0,1\.35fr\)_minmax\(22rem,0\.65fr\)\]/);
    assert.match(shell, /detail\.page\.title/);
    assert.ok(shell.indexOf('detail.page.title') > shell.indexOf('</header>'));
    assert.match(shell, /v-if="detail\.view !== 'summary'"/);
    assert.match(shell, /aria-labelledby="application-section-title"/);
    assert.match(shell, /border border-slate-300 bg-white px-5 py-4 shadow-sm/);
    assert.match(shell, /detail\.navigation/);
    assert.doesNotMatch(shell, /Your scholarship journey/);
    assert.doesNotMatch(overview, /dss_breakdown|rubric_scores|review_notes/);
});

test('the shared guide clearly distinguishes applicant action from provider waiting', () => {
    const decision = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationDecisionBanner.vue', import.meta.url), 'utf8');

    assert.match(decision, /Your next step/);
    assert.match(decision, /What happens next/);
    assert.match(decision, /Waiting on/);
    assert.match(decision, /decision\.due_label/);
});

test('the submission page uses the scoped contract for files and correction responses', () => {
    const submission = readFileSync(new URL('../../resources/js/pages/UserApplicationSubmissionPage.vue', import.meta.url), 'utf8');
    const snapshot = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationSubmissionSnapshot.vue', import.meta.url), 'utf8');
    const documents = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationDocumentList.vue', import.meta.url), 'utf8');

    assert.match(submission, /view: 'submission'/);
    assert.match(submission, /orderedDocuments/);
    assert.match(submission, /actions\.upload_endpoint/);
    assert.match(submission, /actions\.correction_endpoint/);
    assert.match(submission, /ApplicationSubmissionSnapshot/);
    assert.match(submission, /ApplicationDocumentList/);
    assert.match(snapshot, /<details/);
    assert.match(snapshot, /Review submitted information/);
    assert.match(documents, /attentionCount/);
    assert.match(documents, /Start with the highlighted requirement/);
    assert.match(documents, /sm:grid-cols-\[minmax\(0,1fr\)_10rem_auto\]/);
    assert.match(documents, /min-w-28/);
    assert.doesNotMatch(submission, /dss_breakdown|rubric_scores|review_notes/);
});

test('application sections do not deep-clone Vue reactive response proxies', () => {
    const sectionPages = [
        'UserApplicationSubmissionPage.vue',
        'UserApplicationActivitiesPage.vue',
        'UserApplicationOutcomePage.vue',
    ];

    for (const page of sectionPages) {
        const source = readFileSync(new URL(`../../resources/js/pages/${page}`, import.meta.url), 'utf8');

        assert.doesNotMatch(source, /structuredClone\s*\(/);
        assert.match(source, /action: sourceDecision\.action \? \{ \.\.\.sourceDecision\.action \} : null/);
    }
});

test('the activities page keeps provider handoff and published schedules together', () => {
    const activities = readFileSync(new URL('../../resources/js/pages/UserApplicationActivitiesPage.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationActivityRecord.vue', import.meta.url), 'utf8');

    assert.match(activities, /view: 'activities'/);
    assert.match(activities, /primaryActivity/);
    assert.match(activities, /additionalActivities/);
    assert.match(activities, /FormalApplicationHandoff/);
    assert.match(activities, /ApplicationActivityRecord/);
    assert.match(activities, /acknowledge_endpoint/);
    assert.match(record, /Verification/);
    assert.match(record, /verification_label/);
    assert.doesNotMatch(activities, /assignment\.notes|review_notes/);
});

test('the outcome page separates the final result, agreement, and recipient support handoff', () => {
    const outcome = readFileSync(new URL('../../resources/js/pages/UserApplicationOutcomePage.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationOutcomeRecord.vue', import.meta.url), 'utf8');

    assert.match(outcome, /view: 'outcome'/);
    assert.match(outcome, /ApplicationOutcomeRecord/);
    assert.match(outcome, /RecipientAgreementDialog/);
    assert.match(outcome, /response_endpoint/);
    assert.match(outcome, /Read the complete terms/);
    assert.match(record, /<details v-if="award\.benefits/);
    assert.doesNotMatch(outcome, /Open recipient support/);
    assert.doesNotMatch(outcome, /dss_breakdown|rubric_scores|review_notes/);
});

test('the history page shows applicant-safe milestones and opens supporting details in a dialog', () => {
    const history = readFileSync(new URL('../../resources/js/pages/UserApplicationHistoryPage.vue', import.meta.url), 'utf8');
    const timeline = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationHistoryTimeline.vue', import.meta.url), 'utf8');

    assert.match(history, /view: 'history'/);
    assert.match(history, /ApplicationHistoryTimeline/);
    assert.match(history, /ApplicationHistoryEventDialog/);
    assert.match(history, /latestEvent/);
    assert.match(history, /earlierEvents/);
    assert.match(history, /compact/);
    assert.match(history, /v-else-if="!latestEvent"/);
    assert.match(timeline, /event\.category_label/);
    assert.match(timeline, /!compact/);
    assert.doesNotMatch(history, /decision_reason|review_notes|dss_breakdown|rubric_scores/);
});

test('shared application states remain distinct and narrow screens keep content contained', () => {
    const shell = readFileSync(new URL('../../resources/js/components/application-detail/ApplicantApplicationShell.vue', import.meta.url), 'utf8');
    const decision = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationDecisionBanner.vue', import.meta.url), 'utf8');
    const progress = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationProgressRecord.vue', import.meta.url), 'utf8');
    const correctionDialog = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationCorrectionDialog.vue', import.meta.url), 'utf8');
    const historyDialog = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationHistoryEventDialog.vue', import.meta.url), 'utf8');

    assert.match(shell, /overflow-x-auto/);
    assert.match(shell, /min-w-\[42rem\]/);
    assert.match(decision, /tone === 'negative'/);
    assert.match(decision, /border-rose-300/);
    assert.match(progress, /step\.status === 'not_passed'/);
    assert.match(correctionDialog, /max-h-\[calc\(100vh-2rem\)\]/);
    assert.match(historyDialog, /overflow-y-auto/);
});
