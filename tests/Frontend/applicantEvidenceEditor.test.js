import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEvidenceList.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'ApplicantProfileEvidenceList.vue' });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'evidence-list-test', inlineTemplate: true });
const code = script.content.replace(/from (['"])(vue|\.\.\/\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`);
const { default: EvidenceList } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

const row = (value, required, document = null) => ({ value, label: value === 'academic_record' ? 'Academic record' : 'Identity record', required, document, icon: 'fa-solid fa-file-lines', description: 'Choose a clear copy.', supports: 'Saved profile information', context: ['Saved school'] });
const file = (status, overrides = {}) => ({ id: 1, status, original_name: 'profile.pdf', size: 1024, ...overrides });
const render = (groups, overrides = {}) => renderToString(createSSRApp(EvidenceList, { groups, scanLabel: () => 'Scan needs attention', resultLabel: () => '91.20%', ...overrides }));

test('required evidence stays expanded and counts distinguish saved files from accepted proof', async () => {
    const html = await render([{ id: 'required', label: 'Required files', rows: [row('identity_record', true, file('approved')), row('academic_record', true, file('submitted')), row('recent_school_id', true)] }]);
    assert.match(html, /<section[^>]+data-evidence-group="required"/);
    assert.match(html, /2 of 3 required saved/);
    assert.match(html, /1 accepted/);
    assert.match(html, />Accepted</);
    assert.match(html, />Awaiting review</);
    assert.match(html, />Not uploaded</);
});

test('optional records start collapsed without adding to required completion', async () => {
    const html = await render([{ id: 'required', label: 'Required files', rows: [row('identity_record', true)] }, { id: 'optional', label: 'Optional records', rows: [row('guardian_record', false, file('approved'))] }]);
    assert.match(html, /<details[^>]+data-evidence-group="optional"(?![^>]* open)/);
    assert.match(html, /0 of 1 required saved/);
    assert.match(html, /1 saved/);
    assert.match(html, /Optional records/);
});

test('a returned optional file opens its group and keeps the review note outside hidden guidance', async () => {
    const html = await render([{ id: 'optional', label: 'Optional records', rows: [row('guardian_record', false, file('rejected', { review_notes: 'Please include both sides.' }))] }]);
    assert.match(html, /<details[^>]+data-evidence-group="optional"[^>]+open/);
    assert.match(html, /Needs replacement/);
    assert.ok(html.indexOf('Please include both sides.') < html.indexOf('data-evidence-help'));
});

test('file guidance is progressively disclosed and uses the server-compatible file policy', async () => {
    const html = await render([{ id: 'required', label: 'Required files', rows: [row('recent_school_id', true)] }]);
    assert.match(html, /accept=".pdf,.jpg,.jpeg,.png"/);
    assert.match(html, /PDF, JPG or PNG; up to 5 MB/);
    assert.match(html, /<details[^>]+data-evidence-help(?![^>]* open)/);
    assert.match(html, /What to upload[^]*?Choose a clear copy[^]*?Supports:[^]*?Saved in your profile:/);
    assert.doesNotMatch(html, /Required to verify your profile/);
});

test('academic scan problems remain visible and allow a protected retry', async () => {
    const groups = [{ id: 'required', label: 'Required files', rows: [row('academic_record', true, file('submitted', { ocr_status: 'failed' }))] }];
    let html = await render(groups, { ocr: { configured: true, max_file_size_mb: 1 } });
    assert.match(html, /Scan needs attention/);
    assert.match(html, /Retry scan/);
    assert.match(html, /PDF, JPG or PNG; up to 1 MB/);
    html = await render(groups, { ocr: { configured: true }, scanningId: 1 });
    assert.match(html, /<button[^>]+disabled[^>]*>Scanning/);
    assert.match(html, /type="file"[^>]+disabled/);
});

test('uploads and removals disable conflicting file actions without changing review status', async () => {
    const groups = [{ id: 'required', label: 'Required files', rows: [row('identity_record', true, file('approved'))] }];
    for (const working of [{ uploadingType: 'identity_record' }, { deletingId: 1 }]) {
        const html = await render(groups, working);
        assert.match(html, /type="file"[^>]+disabled/);
        assert.match(html, /<button[^>]+disabled[^>]+aria-label="Remove Identity record"/);
        assert.match(html, />Accepted</);
    }
});

test('the editor places manual results with the academic file and keeps older records secondary', () => {
    const editor = readFileSync(new URL('../../resources/js/components/profile/ApplicantEvidenceEditor.vue', import.meta.url), 'utf8');
    assert.match(editor, /v-if="manualResultAllowed" #academic-result/);
    assert.match(source, /<slot v-if="row.value === 'academic_record'" name="academic-result"/);
    assert.match(editor, /id="fallback-academic-result"[^>]+max="form.grading_scale === 'grade_point' \? 5 : 100"/);
    assert.match(editor, /\['percentage', 'grade_point'\].includes\(form.grading_scale\)/);
    assert.match(editor, /<details[^>]+data-older-evidence/);
    assert.match(editor, /not counted toward required evidence/);
    assert.match(editor, /v-model="consent" context="document" :modal-layer="2700"/);
    assert.match(editor, /data-profile-evidence-terms/);
    assert.match(editor, /Only authorized reviewers can access these files/);
    assert.doesNotMatch(editor, /student-card|shadow-|<footer/);
});

test('consent and API failures focus the actionable explanation without changing save ownership', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const shell = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEditModal.vue', import.meta.url), 'utf8');
    assert.match(page, /v-model:consent="verificationDocumentTermsAccepted"/);
    assert.match(page, /const consent = document.querySelector\('\[data-profile-evidence-terms\]/);
    assert.match(page, /consent\?\.focus\(\{ preventScroll: true \}\)/);
    assert.match(page, /Unable to upload this record[^]*?await focusProfileAlert\(\)/);
    assert.match(shell, /Academic result not saved. Files are saved immediately/);
});
