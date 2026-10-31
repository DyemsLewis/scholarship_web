import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';

const page = readFileSync(new URL('../../resources/js/pages/UserDocumentsPage.vue', import.meta.url), 'utf8');
const submission = readFileSync(new URL('../../resources/js/pages/UserApplicationSubmissionPage.vue', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../resources/js/components/application-detail/ApplicationDocumentList.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'ApplicationDocumentList.vue' });
assert.deepEqual(errors, []);
const compiled = compileScript(descriptor, { id: 'document-refinements', inlineTemplate: true });
const code = compiled.content.replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const { default: DocumentList } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const file = { name: 'school-record.pdf', view_url: '/dashboard/student-documents/1/view', uploaded_at: 'Oct 30, 2026' };
const documents = [
    { requirement: 'Report card', required: true, file, status: 'pending', status_label: 'Waiting for review', can_replace: true },
    { requirement: 'Enrollment certificate', required: true, file, status: 'accepted', status_label: 'Accepted', can_replace: false },
    { requirement: 'School ID', required: true, file: null, status: 'missing', status_label: 'Missing', can_replace: true, needs_attention: true },
];
const render = (overrides = {}, terms = true) => renderToString(createSSRApp({
    render: () => h(DocumentList, { documents, summary: { required: 3, uploaded: 2, accepted: 1 }, canEdit: true, ...overrides },
        terms ? { terms: () => h('label', 'Document upload consent') } : undefined),
}));
const buttonTags = (html) => [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
    .map((match) => ({ attributes: match[1], text: match[2].replace(/<[^>]*>/g, '').trim() }));
const isDisabled = (button) => /(?:^|\s)disabled(?:\s|=|$)/.test(button.attributes);

test('the document library compiles without changing its existing page structure', () => {
    const result = parse(page, { filename: 'UserDocumentsPage.vue' });
    assert.deepEqual(result.errors, []);
    const script = compileScript(result.descriptor, { id: 'document-library-refinements' });
    const template = compileTemplate({ id: 'document-library-refinements', filename: 'UserDocumentsPage.vue',
        source: result.descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
    assert.deepEqual(template.errors, []);
    for (const component of ['ApplicantPageHeader', 'PrivacyNoticeCard', 'FilePreviewModal', 'ScholarshipPreparationReturn']) assert.ok(page.includes(component));
});

test('prepared and other saved rows share reserved status and action tracks', () => {
    assert.match(page, /const preparedRowColumns = 'lg:grid-cols-\[minmax\(0,1fr\)_9rem_14rem\]'/);
    assert.equal((page.match(/:class="preparedRowColumns"/g) ?? []).length, 2);
    assert.equal((page.match(/data-library-document-row/g) ?? []).length, 2);
    assert.doesNotMatch(page, /9rem_auto/);
    assert.match(page, /md:grid-cols-\[minmax\(0,1fr\)_9rem\] xl:grid-cols-\[minmax\(0,1fr\)_9rem_12rem_9rem\]/);
    assert.match(page, /:class="applicationRowColumns"/);
    assert.match(page, /statusBadgeBase = 'student-badge min-w-\[7\.5rem\] justify-center text-center'/);
});

test('common files use saved counts rather than a mandatory completion target', () => {
    assert.match(page, /Upload only what a scholarship requests\. You do not need every document below\./);
    assert.match(page, /commonDocumentReadyCount === 1 \? 'file' : 'files'/);
    assert.doesNotMatch(page, /\{\{ commonDocumentReadyCount \}\} of \{\{ documentOptions.length \}\} ready/);
    assert.match(page, /Required files uploaded/);
});

test('all library upload controls explain consent and keep the existing consent guards', () => {
    assert.match(page, /id="prepared-upload-consent"[^>]*role="status"[^>]*aria-live="polite"/);
    assert.match(page, /Accept the upload terms above to enable Upload and Replace/);
    assert.equal((page.match(/aria-describedby="prepared-upload-consent prepared-upload-formats"/g) ?? []).length, 2);
    assert.equal((page.match(/if \(!preparedDocumentTermsAccepted.value\)/g) ?? []).length, 2);
    assert.equal((page.match(/:disabled="!preparedDocumentTermsAccepted/g) ?? []).length, 2);
    assert.match(page, /focus-within:ring-2/);
});

test('application headings and rows share columns and the same attention-border inset', async () => {
    assert.match(source, /const fileColumns = 'md:grid-cols-\[minmax\(0,1fr\)_10rem_12rem\]'/);
    assert.equal((source.match(/:class="fileColumns"/g) ?? []).length, 2);
    const html = await render();
    assert.equal((html.match(/data-application-file-row/g) ?? []).length, 3);
    assert.match(html, /border-l-4 border-transparent[^>]+data-application-file-header/);
    for (const label of ['Provider status', 'Actions', 'Waiting for review', 'Accepted', 'Missing']) assert.ok(html.includes(label));
    assert.match(html, /bg-amber-50\/40/);
});

test('upload consent disables only uploads while keeping file previews available', async () => {
    const html = await render({ uploadEnabled: false });
    const buttons = buttonTags(html);
    assert.equal(buttons.filter((button) => /View/.test(button.text) && !isDisabled(button)).length, 2);
    const uploads = buttons.filter((button) => /Upload|Replace/.test(button.text));
    assert.equal(uploads.length, 2);
    assert.ok(uploads.every((button) => isDisabled(button) && /aria-describedby="application-upload-consent"/.test(button.attributes)));
    assert.match(html, /You can still view your submitted files/);
    assert.match(submission, /:upload-enabled="documentTermsAccepted"/);
    assert.match(submission, /if \(!documentTermsAccepted.value\)/);
});

test('consent and busy states do not bypass per-document edit permissions', async () => {
    const enabled = buttonTags(await render({ uploadEnabled: true }));
    assert.equal(enabled.filter((button) => /Upload|Replace/.test(button.text) && !isDisabled(button)).length, 2);
    const busy = buttonTags(await render({ uploadEnabled: true, uploadingRequirement: 'School ID' }));
    assert.ok(busy.filter((button) => /Uploading|Replace/.test(button.text)).every(isDisabled));
    const readOnly = await render({ canEdit: false, uploadEnabled: true });
    assert.doesNotMatch(readOnly, /application-upload-consent|Document upload consent/);
    assert.ok(buttonTags(readOnly).every((button) => button.text === 'View'));
});

test('read-only empty and feedback states stay intact without introducing upload actions', async () => {
    assert.match(await render({ documents: [], canEdit: false }), /No application files required/);
    const feedback = await render({ documents: [{ ...documents[2], feedback: 'Please provide a clearer copy.' }], uploadEnabled: false });
    assert.match(feedback, /Provider feedback:/);
    assert.match(feedback, /Please provide a clearer copy/);
    const noTerms = await render({ uploadEnabled: true }, false);
    assert.doesNotMatch(noTerms, /aria-describedby="application-upload-consent"/);
});
