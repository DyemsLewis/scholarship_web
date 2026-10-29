import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { preparationQuery, preparationReturn, withPreparationContext, hasUnsentApplicationAnswers } from '../../resources/js/support/scholarshipPreparationContext.js';

async function loadComponent(name) {
    const source = readFileSync(new URL(`../../resources/js/components/${name}.vue`, import.meta.url), 'utf8');
    const { descriptor, errors } = parse(source, { filename: `${name}.vue` });
    assert.deepEqual(errors, []);
    const compiled = compileScript(descriptor, { id: name, inlineTemplate: true });
    const code = compiled.content.replace(/from (['"])(vue|\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`);
    return (await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`)).default;
}
const Panel = await loadComponent('ScholarshipApplicationRequirements');
const Return = await loadComponent('ScholarshipPreparationReturn');
const requirements = () => ({
    review_method: 'online', review_method_label: 'Online document review', review_method_note: 'Upload required files through the portal.',
    readiness: { required_count: 2, ready_count: 1, items: [{ name: 'School ID', status: 'ready' }, { name: 'Provider essay', status: 'missing' }] },
    optional_documents: ['Award certificate'], post_qualification_documents: ['Original transcript'],
    questions: [{ id: 'reason', prompt: 'Why are you applying?', required: true }, { id: 'other', prompt: 'Other information', required: false }],
    manage_files_href: '/dashboard/documents', upload_policy: { extensions: ['pdf', 'jpg'], max_size_mb: 5 },
});
const decision = { state: 'eligible', title: 'Start with pre-screening', action: { kind: 'link', href: '/dashboard/applications/start/4', label: 'Start pre-screening' } };
const render = (props = {}) => renderToString(createSSRApp(Panel, { requirements: requirements(), preparation: { can_start_application: true }, decision, scholarshipId: 4, ...props }));
const context = { scholarship_id: 4, scholarship_title: 'Learning Grant', return_section: 'application', return_href: '/dashboard/scholarships/4/application' };

test('required, optional and after-qualification files have distinct groups and only required files have readiness badges', async () => {
    const html = await render();
    assert.equal((html.match(/data-required-file/g) ?? []).length, 2);
    assert.match(html, /Saved file/);
    assert.match(html, /Upload needed/);
    assert.match(html, /1 of 2 saved/);
    assert.match(html, /<details[^>]+data-optional-files(?![^>]*open)/);
    assert.match(html, /<details[^>]+data-later-files(?![^>]*open)/);
    assert.match(html, /not required to submit/);
    assert.match(html, /Not needed for the initial submission/);
    assert.doesNotMatch(html, /Files that may be requested later|Approved document|100%/);
});

test('questions are a read-only preview and file guidance remains collapsed', async () => {
    const html = await render();
    assert.match(html, /<details[^>]+data-provider-questions(?![^>]*open)/);
    assert.match(html, /Why are you applying\?/);
    assert.match(html, /Enter your answers only in the application form/);
    assert.match(html, /Required<\/span>/);
    assert.match(html, /Optional<\/span>/);
    assert.match(html, /<details[^>]+data-file-guidance(?![^>]*open)/);
    assert.match(html, /up to 5 MB/);
    assert.doesNotMatch(html, /<input|<textarea|<form/);
});

test('missing files do not disable a server-allowed start or skip the selected-scholarship route', async () => {
    const html = await render();
    assert.match(html, /href="\/dashboard\/applications\/start\/4"/);
    assert.match(html, /Opening the form keeps this scholarship selected/);
    assert.match(html, /provider-specific documents/);
    assert.match(html, /href="\/dashboard\/documents\?preparing_scholarship=4&amp;return_section=application"/);
});

test('profile gates and unavailable intakes cannot enable a stale start action', async () => {
    const html = await render({ preparation: { can_start_application: false }, decision: { ...decision, title: 'Verify your profile' } });
    assert.doesNotMatch(html, /href="\/dashboard\/applications\/start/);
    assert.match(html, /Verify your profile/);
    const profile = await render({ preparation: { can_start_application: false }, decision: { title: 'Verify your profile', action: { kind: 'link', label: 'View supporting evidence', href: '/dashboard/profile?section=verification' } } });
    assert.match(profile, /section=verification&amp;preparing_scholarship=4&amp;return_section=application/);
    const full = await render({ preparation: { can_start_application: false }, decision: { title: 'Intake full', action: { kind: 'save', label: 'Save scholarship' } } });
    assert.match(full, /Save scholarship/);
    assert.doesNotMatch(full, /href="\/dashboard\/applications\/start/);
});

test('no-file programs do not show zero-percent progress or invent initial requirements', async () => {
    const data = { ...requirements(), review_method: 'provider_review', readiness: { items: [], required_count: 0, ready_count: 0 }, optional_documents: [] };
    const html = await render({ requirements: data });
    assert.match(html, /No initial files are needed/);
    assert.match(html, /any required answers/);
    assert.doesNotMatch(html, /0 of 0|data-required-file|data-file-guidance/);
    assert.match(html, /data-provider-questions/);
    assert.match(html, /data-later-files/);
});

test('provider previews never show applicant file availability or live applicant actions even with stale props', async () => {
    const html = await render({ preview: true });
    assert.match(html, /Read-only preview/);
    assert.equal((html.match(/Required file<\/span>/g) ?? []).length, 2);
    assert.doesNotMatch(html, /1 of 2 saved|Saved file<\/span>|href="\/dashboard\/|preparing_scholarship/);
    assert.match(html, /Applicant actions are disabled/);
});

test('existing applications explain that library changes do not replace submitted attachments', async () => {
    const html = await render({ preparation: { has_applied: true, can_start_application: false }, decision: { action: { kind: 'link', label: 'View application', href: '/dashboard/applications/7' } } });
    assert.match(html, /Library changes do not replace files already submitted/);
    assert.match(html, /href="\/dashboard\/applications\/7"/);
    assert.doesNotMatch(html, /href="\/dashboard\/applications\/start/);
});

test('return context accepts canonical positive IDs and known sections, never arbitrary redirect URLs', () => {
    assert.deepEqual(preparationQuery('?preparing_scholarship=4&return_section=eligibility'), { preparing_scholarship: '4', return_section: 'eligibility' });
    assert.deepEqual(preparationQuery('?preparing_scholarship=4&return_section=https://evil.test'), { preparing_scholarship: '4', return_section: 'application' });
    for (const id of ['0', '-1', '01', '1e3', '1.5', 'https://evil.test', '9007199254740992']) assert.deepEqual(preparationQuery(`?preparing_scholarship=${encodeURIComponent(id)}`), {});
    assert.equal(preparationReturn({ ...context, return_href: '//evil.test' }), null);
    assert.equal(preparationReturn({ ...context, return_section: '../../admin' }), null);
    assert.equal(preparationReturn(context).href, '/dashboard/scholarships/4/application');
});

test('context links preserve profile sections, document tabs and hashes without affecting unrelated navigation', () => {
    assert.equal(withPreparationContext('/dashboard/profile?section=verification', 4, 'eligibility'), '/dashboard/profile?section=verification&preparing_scholarship=4&return_section=eligibility');
    assert.equal(withPreparationContext('/dashboard/documents?view=applications#upload-document', 4), '/dashboard/documents?view=applications&preparing_scholarship=4&return_section=application#upload-document');
    for (const href of ['//evil.test', 'https://evil.test', '/dashboard/applications/start/4', '/dashboard/profile/../admin', '#upload-document']) assert.equal(withPreparationContext(href, 4), href);
    assert.equal(withPreparationContext('/dashboard/profile', null), '/dashboard/profile');
});

test('the return banner uses only a validated backend destination and disables navigation while an action runs', async () => {
    const html = await renderToString(createSSRApp(Return, { context }));
    assert.match(html, /Learning Grant/);
    assert.match(html, /href="\/dashboard\/scholarships\/4\/application"/);
    assert.match(html, /nothing is submitted here/);
    const busy = await renderToString(createSSRApp(Return, { context, busy: true }));
    assert.doesNotMatch(busy, /href=/);
    const invalid = await renderToString(createSSRApp(Return, { context: { ...context, return_href: 'https://evil.test' } }));
    assert.doesNotMatch(invalid, /data-preparation-return|href=/);
});

test('unsent answers and notes are protected but immediately saved uploads are not represented as unsaved answers', () => {
    assert.equal(hasUnsentApplicationAnswers({}, '', false), false);
    assert.equal(hasUnsentApplicationAnswers({ q: '  ' }), false);
    assert.equal(hasUnsentApplicationAnswers({ q: 'A reason' }), true);
    assert.equal(hasUnsentApplicationAnswers({ q: 0 }), true);
    assert.equal(hasUnsentApplicationAnswers({}, 'A note'), true);
    assert.equal(hasUnsentApplicationAnswers({}, '', true), true);
    const form = readFileSync(new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url), 'utf8');
    assert.match(form, /useConfirmationDialog/);
    assert.match(form, /beforeunload', protectUnsentApplication/);
    assert.match(form, /Discard answers and leave/);
    assert.match(form, /Uploaded files remain in My Documents/);
    assert.doesNotMatch(form, /localStorage|sessionStorage/);
});

test('profile and document pages request validated context and retain the choice across preparation links', () => {
    for (const name of ['UserProfilePage', 'UserDocumentsPage']) {
        const source = readFileSync(new URL(`../../resources/js/pages/${name}.vue`, import.meta.url), 'utf8');
        assert.match(source, /params: preparationParams/);
        assert.match(source, /response.data.preparation_context/);
        assert.match(source, /<ScholarshipPreparationReturn/);
        assert.match(source, /withPreparationContext/);
    }
});
