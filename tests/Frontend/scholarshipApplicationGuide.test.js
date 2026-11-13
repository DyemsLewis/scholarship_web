import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { preparationAction, preparationRequirementSummary, preparationSteps } from '../../resources/js/support/scholarshipPreparation.js';

const source = readFileSync(new URL('../../resources/js/components/ScholarshipApplicationGuide.vue', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url), 'utf8');
const applicationForm = readFileSync(new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url), 'utf8');
async function loadComponent(name) {
    const filename = `${name}.vue`;
    const { descriptor, errors } = parse(readFileSync(new URL(`../../resources/js/components/${filename}`, import.meta.url), 'utf8'), { filename });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: name, inlineTemplate: true });
    const code = script.content.replace(/from (['"])(vue|\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`);
    return (await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`)).default;
}
const Guide = await loadComponent('ScholarshipApplicationGuide');
const DetailAction = await loadComponent('ScholarshipDetailAction');
const link = (label, href, section) => ({ kind: 'link', label, href, ...(section ? { section } : {}) });
const start = link('Start pre-screening', '/dashboard/applications/start/1');
function fixture(overrides = {}) {
    return {
        mode: 'live', state: 'eligible', can_start_application: true, has_applied: false,
        next_step: 'submit', next_action: start,
        steps: [
            { key: 'profile', title: 'Complete and verify your profile', status: 'verified', status_label: 'Verified', tone: 'positive', description: 'Your profile is complete and verified.', required_before: 'start', blocks_start: false, action: link('View supporting evidence', '/dashboard/profile?section=verification'), details: { missing_fields: [], missing_evidence: [] } },
            { key: 'eligibility', title: 'Check program eligibility', status: 'ready', status_label: 'No required mismatches', tone: 'positive', description: 'Matching does not guarantee selection.', required_before: 'start', blocks_start: false, action: link('View eligibility comparison', '/dashboard/scholarships/1/eligibility', 'eligibility') },
            { key: 'requirements', title: 'Prepare application requirements', status: 'saved', status_label: 'Files saved', tone: 'neutral', description: 'Saved does not mean attached or approved.', required_before: 'submission', blocks_start: false, action: link('View application requirements', '/dashboard/scholarships/1/application', 'application'), details: { required_file_count: 2, saved_file_count: 2, missing_file_count: 0, question_count: 2, required_question_count: 1 } },
            { key: 'submit', title: 'Review and submit for pre-screening', status: 'in_application', status_label: 'In application form', tone: 'neutral', description: 'Confirm files, answers and terms in the application form.', required_before: 'submission', blocks_start: false, action: start },
        ],
        submission: { note: 'Starting does not submit an application or reserve a place. Requirements are checked again when you submit.' },
        ...overrides,
    };
}
const render = (preparation = fixture()) => renderToString(createSSRApp({ render: () => h(Guide, { preparation, scholarshipId: 1 }, preparation ? {
    action: () => h(DetailAction, {
        decision: { state: preparation.state, title: preparation.has_applied ? 'Continue from your application' : 'Application status', action: preparation.can_start_application && preparation.state === 'eligible' ? start : preparation.next_action },
        preparation, scholarshipId: 1, section: 'application', preview: preparation.mode === 'preview',
    }),
} : {}) }));

test('the overview links to the guide while the application section owns the instructions and checklist', () => {
    assert.match(page, /:preparation="detail.preparation"/);
    const overview = page.slice(page.indexOf('data-scholarship-section-content'), page.indexOf('<ScholarshipEligibilityPanel'));
    assert.match(overview, /See how to apply/);
    assert.doesNotMatch(overview, /<ScholarshipApplicationGuide/);
    assert.match(page, /v-else-if="isApplicationView"[\s\S]*?<ScholarshipApplicationGuide[\s\S]*?<template #action>[\s\S]*?<ScholarshipDetailAction[\s\S]*?<ScholarshipApplicationRequirements/);
    assert.match(page, /@navigate="openPreviewSection"/);
    assert.match(page, /<ScholarshipDetailAction v-bind="actionProps" @save="toggleSave"/);
});

test('the four preparation rows stay ordered, do not mutate the payload, and do not invent missing rows', () => {
    const guide = fixture();
    guide.steps.reverse();
    const before = JSON.stringify(guide);
    assert.deepEqual(preparationSteps(guide).map((step) => step.key), ['profile', 'eligibility', 'requirements', 'submit']);
    assert.equal(JSON.stringify(guide), before);
    assert.deepEqual(preparationSteps(null), []);
    assert.deepEqual(preparationSteps({ steps: [{ key: 'unrelated' }] }), []);
});

test('the guide renders one primary action and does not duplicate files or normal submission steps in readiness', async () => {
    const html = await render();
    assert.deepEqual([...html.matchAll(/data-preparation-step="([^"]+)"/g)].map((match) => match[1]), ['profile', 'eligibility']);
    assert.equal((html.match(/data-scholarship-primary-action/g) ?? []).length, 1);
    assert.equal((html.match(/href="\/dashboard\/applications\/start\/1"/g) ?? []).length, 1);
    assert.match(html, /How to apply/);
    assert.match(html, /Submission is not a scholarship award/);
    assert.match(source, /data-guide-status/);
    assert.match(source, /data-guide-action/);
    assert.doesNotMatch(html, /in_application|preferences_differ|% complete|100%|type="checkbox"/);
});

test('profile details and missing evidence remain collapsed and waiting is not described as another upload task', async () => {
    const guide = fixture({ state: 'profile_unverified', can_start_application: false, next_step: 'profile' });
    guide.steps[0] = { ...guide.steps[0], status: 'evidence_missing', status_label: 'Evidence needed', description: 'Upload the missing supporting records.', tone: 'attention', blocks_start: true, details: { missing_fields: [{ key: 'school', label: 'School' }], missing_evidence: [{ key: 'recent_school_id', label: 'Recent school ID' }] } };
    let html = await render(guide);
    assert.match(html, /See what is missing \(2\)/);
    assert.match(html, /<details[^>]+data-guide-missing(?![^>]*open)/);
    assert.match(html, /Recent school ID/);
    assert.match(html, /Needed before starting/);
    assert.doesNotMatch(html, /href="\/dashboard\/applications\/start/);

    guide.steps[0] = { ...guide.steps[0], status: 'pending', status_label: 'Waiting for review', description: 'Your evidence is awaiting review. You can prepare requirements while you wait.', details: {} };
    html = await render(guide);
    assert.match(html, /Waiting for review/);
    assert.doesNotMatch(html, /data-guide-missing|Upload the missing/);
});

test('preferences and manual review stay distinct without hiding the allowed application action', async () => {
    for (const [status, label] of [['preferences_differ', 'Preferences differ'], ['needs_review', 'Provider review needed']]) {
        const guide = fixture();
        guide.steps[1] = { ...guide.steps[1], status, status_label: label, tone: 'attention' };
        const html = await render(guide);
        assert.ok(html.includes(label));
        assert.match(html, /href="\/dashboard\/applications\/start\/1"/);
        assert.doesNotMatch(html, /Needed before starting/);
    }
});

test('required mismatches remain blocking even if a stale payload includes a start link', async () => {
    const guide = fixture({ state: 'blocked', can_start_application: false, next_step: 'eligibility' });
    guide.steps[1] = { ...guide.steps[1], status: 'blocked', status_label: 'Required rules not met', tone: 'blocked', blocks_start: true };
    const html = await render(guide);
    assert.match(html, /Required rules not met/);
    assert.match(html, /href="\/dashboard\/scholarships\/1\/eligibility"/);
    assert.doesNotMatch(html, /href="\/dashboard\/applications\/start/);
});

test('requirements show file and question counts without implying approval or inventing work for zero requirements', async () => {
    assert.equal(preparationRequirementSummary({ required_file_count: 2, saved_file_count: 1, question_count: 2, required_question_count: 1 }), '1 of 2 required files saved | 2 questions in the form (1 required)');
    assert.equal(preparationRequirementSummary({ required_file_count: 1, saved_file_count: null, question_count: 1, required_question_count: 0 }, true), '1 required file | 1 question in the form (optional)');
    assert.equal(preparationRequirementSummary({ required_file_count: -5, saved_file_count: NaN, question_count: '2' }), '');
    const guide = fixture();
    guide.steps[2] = { ...guide.steps[2], status: 'not_required', status_label: 'No initial files', details: { required_file_count: 0, question_count: 0, required_question_count: 0 } };
    const html = await render(guide);
    assert.match(html, /No initial files are required/);
    assert.doesNotMatch(html, /data-guide-requirement-summary/);
});

test('unavailable intakes show no next-step highlight or usable start link and preserve the save action', async () => {
    for (const state of ['upcoming', 'closed', 'full']) {
        const guide = fixture({ state, can_start_application: false, next_step: null, next_action: { kind: 'save', label: 'Save scholarship' } });
        const html = await render(guide);
        assert.match(html, /data-guide-intake-notice/);
        assert.match(html, /Save scholarship/);
        assert.doesNotMatch(html, /data-guide-next|href="\/dashboard\/applications\/start/);
        const saved = await render({ ...guide, next_action: link('View saved scholarships', '/dashboard/scholarships?view=saved') });
        assert.match(saved, /href="\/dashboard\/scholarships\?view=saved"/);
    }
});

test('an existing application gets a record handoff rather than another preparation checklist', async () => {
    const html = await render(fixture({ state: 'applied', has_applied: true, can_start_application: false, next_action: link('View application', '/dashboard/applications/9') }));
    assert.match(html, /Continue from your application/);
    assert.match(html, /href="\/dashboard\/applications\/9"/);
    assert.doesNotMatch(html, /data-application-instruction=|data-preparation-step=|data-guide-submission-note/);
});

test('previews can only open authorized read-only preview sections, never live applicant actions', async () => {
    const guide = fixture({ mode: 'preview', can_start_application: false, next_step: 'submit' });
    guide.steps[1].action = link('View eligibility comparison', '/provider/previews/programs/1/eligibility?scenario=eligible', 'eligibility');
    guide.steps[2].action = link('View application requirements', '/provider/previews/programs/1/application?scenario=eligible', 'application');
    guide.steps[0].details = { missing_fields: [{ label: 'Provider private data' }] };
    const html = await render(guide);
    assert.match(html, /Read-only preview/);
    assert.match(html, /href="\/provider\/previews\/programs\/1\/eligibility/);
    assert.doesNotMatch(html, /href="\/dashboard\/|Provider private data|data-guide-missing/);
    assert.match(html, /Applicant action:/);
    assert.match(source, /emit\('navigate', event, action.section\)/);
});

test('general previews do not manufacture completed progress or claim that live actions are enabled', async () => {
    const guide = fixture({ mode: 'preview', next_step: null, can_start_application: false });
    guide.steps = guide.steps.map((step) => ({ ...step, status: 'not_compared', status_label: 'Applicant-specific', tone: 'neutral', details: {} }));
    const html = await render(guide);
    assert.equal((html.match(/Applicant-specific/g) ?? []).length, 2);
    assert.doesNotMatch(html, /data-guide-next|href="\/dashboard\/|Needed before starting|Files saved/);
});

test('submission checks stay behind a disclosure and no client checklist writes are introduced', async () => {
    const html = await render();
    assert.match(html, /<details[^>]+data-guide-submission-note(?![^>]*open)/);
    assert.match(html, /Starting does not submit an application or reserve a place/);
    assert.doesNotMatch(source, /axios|localStorage|sessionStorage|fetch\(|\.post\(|\.put\(/);
});

test('missing contract data degrades to guidance text instead of fabricating a start action', async () => {
    const html = await render(null);
    assert.match(html, /Application guidance is unavailable/);
    assert.doesNotMatch(html, /data-guide-next|data-preparation-step=|href=/);
});

test('actions reject disabled, external, malformed and unauthorized preview links', () => {
    for (const href of ['javascript:alert(1)', 'https://example.test', '//example.test', '/\\example.test', '/dashboard\n/profile']) assert.equal(preparationAction(link('Go', href)), null);
    assert.equal(preparationAction({ ...start, disabled: true }, { canStart: true }), null);
    assert.equal(preparationAction(start), null);
    assert.equal(preparationAction(start, { canStart: true }), start);
    assert.equal(preparationAction(link('Profile', '/dashboard/profile'), { preview: true }), null);
    assert.equal(preparationAction(link('Preview', '/provider/previews/programs/1/application', 'profile'), { preview: true }), null);
    assert.equal(preparationAction({ kind: 'save', label: 'Save' }, { preview: true }), null);
});

test('how to apply explains the real controls and distinguishes starting from sending and tracking', async () => {
    const html = await render();
    assert.equal((html.match(/data-application-instruction=/g) ?? []).length, 4);
    for (const label of ['Program', 'Eligibility', 'Requirements', 'Confirm']) assert.ok(applicationForm.includes(`label: '${label}'`));
    for (const label of ['Start pre-screening', 'Submit application']) {
        assert.ok(applicationForm.includes(label));
        assert.ok(html.includes(label));
    }
    for (const instruction of ['Continue to Eligibility', 'Continue to Requirements', 'Continue to Confirm', 'document upload terms', 'accept the terms']) assert.ok(html.includes(instruction));
    assert.match(html, /Open the form for this scholarship/);
    assert.match(html, /then select <strong[^>]*>Submit application<\/strong>/);
    assert.match(html, /Track updates in Applications/);
    assert.ok(html.indexOf('data-application-instructions') < html.indexOf('data-guide-readiness'));
});

test('main instructions are scan-friendly and control-by-control tips stay secondary', async () => {
    const html = await render();
    const instructions = html.slice(html.indexOf('data-application-instructions'), html.indexOf('</ol>', html.indexOf('data-application-instructions')));
    const words = instructions.replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean);
    assert.ok(words.length < 90, `Four main steps should stay concise, got ${words.length} words.`);
    assert.doesNotMatch(instructions, /Continue to Eligibility|document upload terms/);
    assert.match(html, /<details[^>]+data-guide-submission-note(?![^>]*open)/);
    assert.match(html, /Form tips and submission checks/);
    assert.match(page, /class="grid grid-cols-1 items-start gap-4" data-scholarship-how-to-apply/);
});

test('readiness checks are secondary and collapsed unless a required check needs attention', async () => {
    const html = await render();
    assert.match(html, /<details[^>]+data-guide-readiness(?![^>]*\bopen)[^>]*>/);
    assert.match(html, /Application readiness/);
    const blocked = fixture({ state: 'profile_unverified', can_start_application: false, next_step: 'profile' });
    blocked.steps[0].blocks_start = true;
    assert.match(await render(blocked), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    assert.doesNotMatch(await render({ ...blocked, mode: 'preview' }), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    const email = fixture();
    email.steps[3].status = 'email_unverified';
    email.steps[3].action = link('Review email verification', '/dashboard');
    assert.match(await render(email), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    const emailHtml = await render(email);
    assert.match(emailHtml, /Verify your email before submitting/);
    assert.match(emailHtml, /href="\/dashboard\/applications\/start\/1"/);
});

test('form instructions do not invent document uploads or questions for profile-review programs', async () => {
    const guide = fixture();
    guide.steps[2].details = { required_file_count: 0, question_count: 0 };
    const html = await render(guide);
    const instructions = html.slice(html.indexOf('data-application-instruction="complete"'), html.indexOf('data-application-instruction="send"'));
    assert.match(instructions, /No initial files are required/);
    assert.doesNotMatch(instructions, /upload terms|using Upload|Answer any required provider questions/);
    const preview = fixture({ mode: 'preview' });
    preview.steps[2].details = {};
    assert.match(await render(preview), /Check which files the provider requests/);
    assert.doesNotMatch(await render(preview), /data-scholarship-primary-action/);
});

test('missing files do not hide an authorized form link and unavailable or stale actions cannot enable it', async () => {
    const guide = fixture({ next_step: 'requirements', next_action: link('Requirements', '/dashboard/scholarships/1/application', 'application') });
    guide.steps[2].details.missing_file_count = 2;
    assert.match(await render(guide), /href="\/dashboard\/applications\/start\/1"/);
    for (const overrides of [{ can_start_application: false }, { state: 'full' }, { state: 'closed' }, { state: 'upcoming' }, { mode: 'preview' }]) assert.doesNotMatch(await render({ ...guide, ...overrides }), /href="\/dashboard\/applications\/start/);
    assert.doesNotMatch(await render(fixture({ state: 'applied', has_applied: true })), /data-application-instruction=|data-guide-readiness/);
});
