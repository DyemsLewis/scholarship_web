import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { clearRegistrationProgress, readRegistrationProgress, saveRegistrationProgress } from '../../resources/js/support/registrationProgress.js';

function storage() {
    const values = new Map();
    return { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key), values };
}
const data = { token: 'a'.repeat(64), email: 'te**@example.test', expiresIn: 600, resendAfter: 60 };

test('verification resumes after reload with the original expiry and resend cooldown', () => {
    const session = storage();
    saveRegistrationProgress(session, 'applicant', data, 100000);
    const saved = readRegistrationProgress(session, 'applicant', 120000);
    assert.equal(saved.token, data.token); assert.equal(saved.email, data.email);
    assert.equal(saved.expiresAt, 700000); assert.equal(saved.resendAt, 160000);
    assert.equal(readRegistrationProgress(session, 'provider', 120000), null);
});

test('passwords, personal details and the verification code are never saved to storage', () => {
    const session = storage();
    saveRegistrationProgress(session, 'applicant', { ...data, password: 'secret', code: '123456', firstName: 'Private' }, 100000);
    const raw = [...session.values.values()].join('');
    for (const value of ['secret', '123456', 'Private', 'password', 'firstName']) assert.ok(!raw.includes(value));
});

test('expired or malformed progress is removed and registration can start again', () => {
    const session = storage(); saveRegistrationProgress(session, 'applicant', data, 100000);
    assert.equal(readRegistrationProgress(session, 'applicant', 700000), null);
    assert.equal(session.values.size, 0);
    session.setItem('findscholarship:registration:applicant', '{broken');
    assert.equal(readRegistrationProgress(session, 'applicant', 100000), null);
    assert.equal(session.values.size, 0);
});

test('resuming an existing account keeps the correct verification mode', () => {
    const session = storage();
    saveRegistrationProgress(session, 'provider', { ...data, resumingAccount: true }, 100000);
    assert.equal(readRegistrationProgress(session, 'provider', 100001).resumingAccount, true);
    clearRegistrationProgress(session, 'provider');
    assert.equal(readRegistrationProgress(session, 'provider', 100002), null);
});

test('blocked browser storage does not prevent registration or verification', () => {
    const session = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } };
    assert.doesNotThrow(() => saveRegistrationProgress(session, 'applicant', data));
    assert.equal(readRegistrationProgress(session, 'applicant'), null);
    assert.doesNotThrow(() => clearRegistrationProgress(session, 'applicant'));
});

test('registration form compiles with recovery state and does not store passwords', () => {
    const source = readFileSync(new URL('../../resources/js/pages/RegisterPage.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const script = compileScript(descriptor, { id: 'registration' });
    assert.deepEqual(compileTemplate({ id: 'registration', filename: 'RegisterPage.vue', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /readRegistrationProgress/);
    assert.match(source, /clearRegistrationProgress/);
    assert.match(source, /form.value.password = ''/);
});

test('provider-only sign-up guidance is visible in the details form before name entry', () => {
    const source = readFileSync(new URL('../../resources/js/pages/RegisterPage.vue', import.meta.url), 'utf8');
    const form = parse(source).descriptor.template.content.match(/<form v-if="registrationStep === 'details'"[^>]*>([\s\S]*?)<\/form>/)[1];
    const notice = form.match(/<section v-if="isProviderRegistration"[^>]*aria-labelledby="provider-registration-program-access"[^>]*>([\s\S]*?)<\/section>/);
    assert.ok(notice && notice.index < form.indexOf('id="first-name"'));
    assert.match(notice[1], /cannot create scholarships by default/);
    assert.match(notice[1], /Program Coordinator/);
    assert.match(notice[1], /once your organization is approved/);
});
