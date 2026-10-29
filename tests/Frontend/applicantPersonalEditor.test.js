import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantPersonalEditor.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'ApplicantPersonalEditor.vue' });
assert.deepEqual(errors, []);
const compiled = compileScript(descriptor, { id: 'personal-editor-test', inlineTemplate: true });
const code = compiled.content.replaceAll('from "vue"', `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replaceAll("from 'vue'", `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replaceAll("from '../../support/profileEditorStyles'", `from ${JSON.stringify(new URL('../../resources/js/support/profileEditorStyles.js', import.meta.url).href)}`);
const { default: PersonalEditor } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

function render(overrides = {}) {
    return renderToString(createSSRApp(PersonalEditor, {
        modelValue: { first_name: 'Demo', last_name: 'Applicant', middle_initial: '', suffix: '', birthdate: '2007-01-01', gender: '', citizenship_status: 'filipino', contact_number: '09175550001', account_managed_by: 'learner' },
        photoUrl: '/dashboard/profile/photo',
        applicantName: 'Demo Applicant',
        birthdateMin: '1906-10-29', birthdateMax: '2026-10-29',
        age: 19,
        genderOptions: [{ value: 'female', label: 'Female' }],
        citizenshipOptions: [{ value: 'filipino', label: 'Filipino' }],
        accountManagerOptions: [{ value: 'learner', label: 'Learner' }],
        suffixOptions: ['Jr.', 'II'],
        ...overrides,
    }));
}

test('a saved photo starts as a compact summary, not an expanded upload task', async () => {
    const html = await render();
    assert.match(html, /Photo saved/);
    assert.match(html, /Change photo/);
    assert.match(html, /aria-expanded="false"/);
    assert.doesNotMatch(html, /data-photo-controls[ >]|type="file"|Open camera|Remove photo|300 to 4000/);
    assert.match(source, /h-16 w-16/);
    assert.doesNotMatch(source, /student-card|shadow-|rounded-xl|w-28/);
});

test('a missing required photo exposes the upload and camera controls immediately', async () => {
    const html = await render({ photoUrl: '' });
    assert.match(html, /Required for your profile/);
    assert.match(html, /data-photo-controls/);
    assert.match(html, /type="file"[^>]+aria-label="Upload applicant photo"/);
    assert.match(html, /Open camera/);
    assert.match(html, /Photo changes save immediately/);
    assert.doesNotMatch(html, /data-photo-controls-toggle|Remove photo/);
});

test('replacement requests remain actionable and keep the reviewer explanation visible', async () => {
    const html = await render({ photoReviewStatus: 'needs_replacement', photoReviewNote: 'Please show the whole face.' });
    assert.match(html, /Replacement requested/);
    assert.match(html, /Please show the whole face\./);
    assert.match(html, /data-photo-controls/);
    assert.doesNotMatch(html, /data-photo-controls-toggle/);
    const resubmitted = await render({ photoReviewStatus: 'resubmitted', photoReviewNote: 'Replacement received.' });
    assert.match(resubmitted, /Your replacement photo is awaiting review/);
    assert.match(resubmitted, /Replacement received\./);
    assert.doesNotMatch(resubmitted, /data-photo-controls[ >]/);
});

test('photo operations disable conflicting actions while they are running', async () => {
    for (const busy of [{ uploadingPhoto: true }, { deletingPhoto: true }]) {
        const html = await render({ photoReviewStatus: 'needs_replacement', ...busy });
        assert.match(html, /type="file"[^>]+disabled/);
        assert.match(html, /<button[^>]+disabled[^>]*><i[^>]*><\/i>Open camera/);
        assert.match(html, /<button[^>]+disabled[^>]*><i[^>]*><\/i>Remove photo/);
    }
});

test('required personal details stay visible and optional name fields stay optional', async () => {
    const html = await render();
    for (const id of ['first-name', 'last-name', 'birthdate', 'citizenship', 'contact', 'account-manager']) {
        assert.match(html, new RegExp(`id="profile-${id}"[^>]+required`));
    }
    for (const id of ['middle', 'suffix', 'gender']) {
        const field = html.match(new RegExp(`<(?:input|select) id="profile-${id}"[^>]*>`))?.[0];
        assert.ok(field, `The optional ${id} input must remain available.`);
        assert.doesNotMatch(field, /required/);
    }
    assert.doesNotMatch(html, /<details/);
    assert.match(html, /type="date"[^>]+min="1906-10-29"[^>]+max="2026-10-29"/);
    assert.match(html, /type="tel"[^>]+inputmode="tel"/);
    const minor = await render({ age: 16, isMinor: true });
    assert.match(minor, /16 years old.*Guardian details required/);
});

test('the page keeps existing photo actions, draft sanitizers and section save wiring', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    assert.match(page, /<ApplicantPersonalEditor[^]*?v-model="form"/);
    assert.match(page, /@upload-photo="uploadProfilePhoto"/);
    assert.match(page, /@open-camera="openProfileCamera"/);
    assert.match(page, /@remove-photo="deleteProfilePhoto"/);
    assert.match(page, /@middle-initial-input="handleMiddleInitialInput"/);
    assert.match(page, /@phone-input="handlePhoneInput\('contact_number', \$event\)"/);
    assert.match(page, /@save="finishProfileEditor"/);
});

test('the shared editor header matches the applicant navy and amber treatment without extra labels', () => {
    const shell = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEditModal.vue', import.meta.url), 'utf8');
    assert.match(shell, /data-profile-editor-header[^>]+border-amber-400[^>]+bg-\[#081426\]/);
    assert.match(shell, /font-display text-xl/);
    assert.doesNotMatch(shell, /eyebrow|gradient/);
    assert.match(shell, /No changes to save yet/);
});
