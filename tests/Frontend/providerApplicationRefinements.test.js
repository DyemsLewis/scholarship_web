import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const pages = [
    'reviews/ApplicationReviewDirectoryPage.vue', 'reviews/ApplicationReviewRecordPage.vue',
    'reviews/components/EligibilityCriteriaTable.vue', 'reviews/components/EligibilityReviewPanel.vue',
    'selection/SelectionActivityDirectoryPage.vue', 'selection/SelectionActivityRecordPage.vue',
    'selection/SelectionSessionFormPage.vue', 'selection/SelectionSessionRecordPage.vue',
    'selection/components/SelectionSessionFormModal.vue',
    'decisions/FinalDecisionDirectoryPage.vue', 'decisions/FinalDecisionRecordPage.vue',
    'recipients/RecipientDirectoryPage.vue', 'recipients/RecipientRecordPage.vue',
];
for (const page of pages) {
    test(`${page} compiles with application refinements`, () => {
        const source = read(`pages/provider/${page}`);
        const { descriptor, errors } = parse(source, { filename: page });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: page });
        const template = compileTemplate({ id: page, filename: page, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
        assert.doesNotMatch(descriptor.template.content.replace(/<i\b[^>]*>/g, ''), /text-\[(?:9|10|11)px\]|\btruncate\b/);
        if (!page.includes('/components/')) assert.match(source, /provider-application-workspace/);
    });
}

const summary = read('components/workspace-dashboard/WorkspaceTextSummary.vue');
const descriptor = parse(summary).descriptor;
const script = compileScript(descriptor, { id: 'workspace-text-summary', inlineTemplate: true }).content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const Summary = (await import(`data:text/javascript;base64,${Buffer.from(script).toString('base64')}`)).default;
const render = (props) => renderToString(createSSRApp(Summary, props));

test('short notes stay immediately visible without an extra control', async () => {
    const text = 'Bring the original enrollment record.';
    const html = await render({ text });
    assert.ok(html.includes(text));
    assert.doesNotMatch(html, /<details|<summary/);
    assert.match(html, /application-text whitespace-pre-line/);
    for (const text of [null, undefined, '']) assert.doesNotMatch(await render({ text }), /<details/);
});

test('long notes use native keyboard-accessible disclosure and retain the entire escaped text', async () => {
    const text = 'A complete provider note with details needed for this record. '.repeat(16)+'<script>alert(1)</script>';
    const html = await render({ text, label: 'review note' });
    assert.match(html, /<details/);
    assert.match(html, /<summary/);
    assert.match(html, /Read full review note/);
    assert.match(html, /Hide review note/);
    assert.ok(html.includes(text.replace('<script>', '&lt;script&gt;').replace('</script>', '&lt;/script&gt;')));
    assert.doesNotMatch(html, /<script>|<button/);
    assert.match(html, /class="line-clamp-2 group-open:hidden"/);
    assert.doesNotMatch(html, /class="block line-clamp-2/);
    assert.doesNotMatch(summary, /axios|fetch\(|window\.location/);
});

test('verification notes can be disclosed without putting a preview in the table row', async () => {
    const text = 'Bring the original registration.';
    const html = await render({ text, label: 'review note', disclosureOnly: true });
    assert.match(html, /<details[^>]*>.*<summary[^>]*>.*Read review note.*<\/summary>.*Bring the original registration\.<\/p>.*<\/details>/s);
    assert.doesNotMatch(html, /line-clamp-2|<details[^>]*\bopen\b/);
    assert.doesNotMatch(await render({ text: ' ', disclosureOnly: true }), /<details/);
});

test('all application directories reserve action columns while keeping photos and original queues', () => {
    const records = [
        ['reviews/ApplicationReviewDirectoryPage.vue', '/provider/workspaces/reviews/data'],
        ['selection/SelectionActivityDirectoryPage.vue', '/provider/workspaces/selection/sessions/data'],
        ['decisions/FinalDecisionDirectoryPage.vue', '/provider/workspaces/decisions/data'],
        ['recipients/RecipientDirectoryPage.vue', '/provider/workspaces/recipients/data'],
    ];
    for (const [page, endpoint] of records) {
        const source = read(`pages/provider/${page}`);
        assert.ok(source.includes(endpoint), endpoint);
        assert.match(source, /<colgroup>/);
        assert.match(source, /min-w-\[56rem\]/);
        assert.doesNotMatch(source, /min-w-\[(?:68|70|72)rem\]|<th[^>]*w-\[\d+%\]/);
        for (const token of ['profile_photo_url', 'activeQueue', 'selectedProgram', 'pagination', 'isLoading', 'errorMessage']) assert.ok(source.includes(token), `${page}: ${token}`);
    }
});

test('review modals and session form use bounded scrolling and shared focus/viewport protection', () => {
    const review = read('pages/provider/reviews/ApplicationReviewRecordPage.vue');
    for (const [id, handler] of [['review-document-title', 'handleDocumentKeydown'], ['review-correction-title', 'handleCorrectionKeydown'], ['review-photo-title', 'handlePhotoKeydown']]) {
        assert.ok(review.includes(`aria-labelledby="${id}"`));
        assert.ok(review.includes(`@keydown="${handler}"`));
    }
    assert.equal((review.match(/useModalAccessibility\(/g) ?? []).length, 3);
    assert.match(review, /application-dialog-body/);
    for (const token of ['saveDocumentReview', 'requestCorrection', "reviewPhoto('request_replacement')", 'canEditReview', 'actionBusy']) assert.ok(review.includes(token));
    const session = read('pages/provider/selection/components/SelectionSessionFormModal.vue');
    assert.match(session, /useModalAccessibility\(\(\) => props.open, dialogPanel, closeModal\)/);
    assert.match(session, /application-dialog-body flex-1/);
    assert.match(session, /<footer class="flex shrink-0/);
    assert.doesNotMatch(session, /sticky bottom-0/);
    for (const token of ['sessionScheduleError', 'assignedCount', 'continueToDelivery', 'usesVenue', 'usesMeetingLink', 'form.proof_required', ':disabled="isSaving"']) assert.ok(session.includes(token));
});

test('decision readiness, reopening, capacity, recipient acceptance and outcome permissions remain', () => {
    const selection = read('pages/provider/selection/SelectionActivityRecordPage.vue');
    for (const token of ['v-if="canRecordResult"', 'readiness.attendance_status', 'readiness.proof_count', 'recordResult', 'DecisionReopenDialog']) assert.ok(selection.includes(token));
    const decision = read('pages/provider/decisions/FinalDecisionRecordPage.vue');
    for (const token of ['capacity.remaining', 'recordOutcome', 'DecisionReopenDialog', 'actionBusy', 'rejectionReasons', 'decisionForm.awarded_amount']) assert.ok(decision.includes(token), token);
    assert.doesNotMatch(decision, /block max-w-(?:xl|sm)/);
    const recipient = read('pages/provider/recipients/RecipientRecordPage.vue');
    for (const token of ['v-if="!agreementAccepted"', 'support.renewal_eligible', 'supportForm.confirmed', 'askToSaveSupport', 'askToResolveRequest', 'openResponse']) assert.ok(recipient.includes(token));
});

test('cohort lists are bounded and all new presentation rules are provider-only', () => {
    const session = read('pages/provider/selection/SelectionSessionRecordPage.vue');
    assert.match(session, /application-candidate-list/);
    assert.match(session, /v-model="selectedCandidates"/);
    assert.match(session, /profile_photo_url/);
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const part = css.split('/* Refinements stay inside the operational provider application screens. */')[1].split('/* Provider program refinements')[0];
    for (const match of part.matchAll(/([^{}]+)\{/g)) for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.provider-application-workspace/);
    assert.match(part, /max-height: 20rem/);
    assert.match(part, /max-height: calc\(100dvh - 3rem\)/);
    assert.match(part, /overflow-y: auto/);
    assert.match(part, /:focus-visible/);
});
