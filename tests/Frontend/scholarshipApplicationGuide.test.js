import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { preparationAction, preparationRequirementSummary, preparationSteps } from '../../resources/js/support/scholarshipPreparation.js';

const source = readFileSync(new URL('../../resources/js/components/ScholarshipApplicationGuide.vue', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url), 'utf8');
const applicationForm = readFileSync(new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'ScholarshipApplicationGuide.vue' });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'scholarship-application-guide-test', inlineTemplate: true });
const code = script.content.replace(/from (['"])(vue|\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`);
const { default: Guide } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
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
const render = (preparation = fixture(), overrides = {}) => renderToString(createSSRApp(Guide, { preparation, ...overrides }));

test('the overview replaces the old readiness brief with one guide before the program overview', () => {
    assert.match(page, /:preparation="detail.preparation"/);
    assert.ok(page.indexOf('<ScholarshipApplicationGuide') < page.indexOf('data-scholarship-overview'));
    assert.doesNotMatch(page, /v-for="item in content.readiness"|function readinessSection/);
    assert.match(page, /@navigate="openPreviewSection"/);
    assert.match(page, /preparation.next_action = \{ \.\.\.detail.value.decision.action \}/);
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

test('the guide renders a full-width numbered list with one next step and aligned status and action columns', async () => {
    const html = await render();
    assert.equal((html.match(/data-preparation-step=/g) ?? []).length, 4);
    assert.equal((html.match(/data-guide-next="true"/g) ?? []).length, 1);
    assert.equal((html.match(/aria-current="step"/g) ?? []).length, 1);
    assert.match(html, /How to apply/);
    assert.match(html, /Submitting starts pre-screening, not a scholarship award/);
    assert.match(source, /xl:grid-cols-\[2rem_minmax\(0,1fr\)_11.5rem_13rem\]/);
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
    assert.match(html, /No initial files/);
    assert.equal((html.match(/Complete before submitting/g) ?? []).length, 1);
    assert.doesNotMatch(html, /data-guide-requirement-summary/);
});

test('unavailable intakes show no next-step highlight or usable start link and preserve the save action', async () => {
    for (const state of ['upcoming', 'closed', 'full']) {
        const guide = fixture({ state, can_start_application: false, next_step: null, next_action: { kind: 'save', label: 'Save scholarship' } });
        const html = await render(guide);
        assert.match(html, /data-guide-intake-notice/);
        assert.match(html, /Save scholarship/);
        assert.doesNotMatch(html, /data-guide-next|href="\/dashboard\/applications\/start/);
        assert.match(html, state === 'upcoming' ? /must wait until applications open/ : /not accepting applications/);
        const saved = await render({ ...guide, next_action: link('View saved scholarships', '/dashboard/scholarships?view=saved') });
        assert.match(saved, /href="\/dashboard\/scholarships\?view=saved"/);
    }
});

test('an existing application gets a record handoff rather than another preparation checklist', async () => {
    const html = await render(fixture({ state: 'applied', has_applied: true, can_start_application: false, next_action: link('View application', '/dashboard/applications/9') }));
    assert.match(html, /Continue with your application/);
    assert.match(html, /href="\/dashboard\/applications\/9"/);
    assert.match(html, /do not need to submit another application/);
    assert.doesNotMatch(html, /data-preparation-step=|data-guide-next|data-guide-submission-note/);
});

test('previews can only open authorized read-only preview sections, never live applicant actions', async () => {
    const guide = fixture({ mode: 'preview', can_start_application: false, next_step: 'submit' });
    guide.steps[1].action = link('View eligibility comparison', '/provider/previews/programs/1/eligibility?scenario=eligible', 'eligibility');
    guide.steps[2].action = link('View application requirements', '/provider/previews/programs/1/application?scenario=eligible', 'application');
    guide.steps[0].details = { missing_fields: [{ label: 'Provider private data' }] };
    const html = await render(guide);
    assert.match(html, /Read-only preview/);
    assert.match(html, /Example next step/);
    assert.match(html, /href="\/provider\/previews\/programs\/1\/eligibility/);
    assert.doesNotMatch(html, /href="\/dashboard\/|Provider private data|data-guide-missing/);
    assert.match(html, /Applicant-only action/);
    assert.match(source, /emit\('navigate', event, action.section\)/);
});

test('general previews do not manufacture completed progress or claim that live actions are enabled', async () => {
    const guide = fixture({ mode: 'preview', next_step: null, can_start_application: false });
    guide.steps = guide.steps.map((step) => ({ ...step, status: 'not_compared', status_label: 'Applicant-specific', tone: 'neutral', details: {} }));
    const html = await render(guide);
    assert.equal((html.match(/Applicant-specific/g) ?? []).length, 4);
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
    for (const label of ['Program', 'Eligibility', 'Requirements', 'Confirm']) {
        assert.ok(applicationForm.includes(`label: '${label}'`));
        assert.ok(html.includes(label));
    }
    for (const label of ['Start pre-screening', 'Submit application']) {
        assert.ok(applicationForm.includes(label));
        assert.ok(html.includes(label));
    }
    for (const instruction of ['Continue to Eligibility', 'Continue to Requirements', 'Continue to Confirm', 'document upload terms', 'accept the application terms']) assert.ok(html.includes(instruction));
    assert.match(html, /data-guide-start/);
    assert.match(html, /This scholarship will already be selected/);
    assert.match(html, /This final button sends your application to the provider/);
    assert.match(html, /Open Applications in the sidebar/);
    assert.match(html, /only if the provider advances you and sends instructions/);
    assert.ok(html.indexOf('data-application-instructions') < html.indexOf('data-guide-readiness'));
});

test('readiness checks are secondary and collapsed unless a required check needs attention', async () => {
    const html = await render();
    assert.match(html, /<details[^>]+data-guide-readiness(?![^>]*\bopen)[^>]*>/);
    assert.match(html, /Before you start/);
    const blocked = fixture({ state: 'profile_unverified', can_start_application: false, next_step: 'profile' });
    blocked.steps[0].blocks_start = true;
    assert.match(await render(blocked), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    assert.doesNotMatch(await render({ ...blocked, mode: 'preview' }), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    const email = fixture();
    email.steps[3].status = 'email_unverified';
    email.steps[3].action = link('Review email verification', '/dashboard');
    assert.match(await render(email), /<details[^>]+data-guide-readiness[^>]*\bopen[^>]*>/);
    assert.doesNotMatch(await render(email), /data-guide-start/);
});

test('form instructions do not invent document uploads or questions for profile-review programs', async () => {
    const guide = fixture();
    guide.steps[2].details = { required_file_count: 0, question_count: 0 };
    const html = await render(guide);
    const instructions = html.slice(html.indexOf('data-application-instruction="complete"'), html.indexOf('data-application-instruction="send"'));
    assert.match(instructions, /no initial files are required/);
    assert.match(instructions, /There are no provider questions to answer/);
    assert.doesNotMatch(instructions, /upload terms|using Upload|Answer the provider questions/);
    const preview = fixture({ mode: 'preview' });
    preview.steps[2].details = {};
    assert.match(await render(preview), /check which files the provider requests/);
    assert.doesNotMatch(await render(preview), /data-guide-start/);
});

test('missing files do not hide an authorized form link and unavailable or stale actions cannot enable it', async () => {
    const guide = fixture({ next_step: 'requirements', next_action: link('Requirements', '/dashboard/scholarships/1/application', 'application') });
    guide.steps[2].details.missing_file_count = 2;
    assert.match(await render(guide), /data-guide-start/);
    for (const overrides of [{ can_start_application: false }, { state: 'full' }, { state: 'closed' }, { state: 'upcoming' }, { mode: 'preview' }]) assert.doesNotMatch(await render({ ...guide, ...overrides }), /data-guide-start/);
    guide.steps[3].action = link('Unrelated', '/dashboard/applications');
    assert.doesNotMatch(await render(guide), /data-guide-start/);
    assert.doesNotMatch(await render(fixture({ state: 'applied', has_applied: true })), /data-application-instruction=|data-guide-readiness/);
});
