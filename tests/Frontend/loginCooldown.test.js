import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, ref } from 'vue';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/pages/LoginPage.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);

function loginForm(post) {
    let now = Date.parse('2026-11-14T00:00:00Z');
    let nextTimer = 0;
    let cleanup;
    const intervals = new Map();
    const window = {
        axios: { post },
        setInterval(callback) { intervals.set(++nextTimer, callback); return nextTimer; },
        clearInterval(id) { intervals.delete(id); },
        setTimeout() { return ++nextTimer; },
        clearTimeout() {},
    };
    const script = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const dependencies = { ref, computed, window, Date: { now: () => now, parse: Date.parse }, onMounted() {}, onBeforeUnmount(callback) { cleanup = callback; } };
    const state = new Function(...Object.keys(dependencies), script + '; return { formElement, isSubmitting, cooldownSeconds, submitLabel, submitForm };')(...Object.values(dependencies));
    state.formElement.value = { reportValidity: () => true };
    return {
        ...state, intervals,
        advance(ms) { now += ms; for (const callback of intervals.values()) callback(); },
        unmount() { cleanup(); },
    };
}

test('the login button compiles with its cooldown and submission guards', () => {
    const script = compileScript(descriptor, { id: 'login' });
    assert.deepEqual(compileTemplate({ id: 'login', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /:disabled="isSubmitting \|\| cooldownSeconds > 0"/);
    assert.match(source, /\{\{ submitLabel \}\}/);
});

test('an account lockout counts down, prevents repeat submissions, and automatically unlocks', async () => {
    let requests = 0;
    const page = loginForm(async () => {
        requests++;
        throw { response: { status: 429, data: { retry_after: 65 } } };
    });
    await page.submitForm();
    assert.equal(page.submitLabel.value, 'Try again in 1:05');
    assert.equal(page.isSubmitting.value, false);
    await page.submitForm();
    assert.equal(requests, 1);
    page.advance(1000);
    assert.equal(page.submitLabel.value, 'Try again in 1:04');
    page.advance(64000);
    assert.equal(page.submitLabel.value, 'Log in to portal');
    assert.equal(page.intervals.size, 0);
    await page.submitForm();
    assert.equal(requests, 2);
    page.unmount();
    assert.equal(page.intervals.size, 0);
});

for (const [name, response, expected] of [
    ['IP throttle header', { headers: { 'retry-after': '42' } }, 42],
    ['HTTP-date header', { headers: { 'retry-after': 'Sat, 14 Nov 2026 00:00:30 GMT' } }, 30],
    ['missing timing', {}, 60],
    ['invalid timing', { data: { retry_after: 'invalid' } }, 60],
    ['fractional timing', { data: { retry_after: 1.5 } }, 2],
]) {
    test(`${name} uses a valid server-based countdown`, async () => {
        const page = loginForm(async () => { throw { response: { status: 429, ...response } }; });
        await page.submitForm();
        assert.equal(page.cooldownSeconds.value, expected);
        page.advance(expected * 1000 + 5000);
        assert.equal(page.cooldownSeconds.value, 0);
        page.unmount();
    });
}

test('ordinary invalid credentials do not start a countdown', async () => {
    const page = loginForm(async () => { throw { response: { status: 422, data: { message: 'Incorrect credentials.' } } }; });
    await page.submitForm();
    assert.equal(page.cooldownSeconds.value, 0);
    assert.equal(page.submitLabel.value, 'Log in to portal');
    assert.equal(page.intervals.size, 0);
});

test('a pending sign-in cannot be submitted twice', async () => {
    let finish;
    let requests = 0;
    const page = loginForm(() => { requests++; return new Promise((resolve) => { finish = resolve; }); });
    const pending = page.submitForm();
    assert.equal(page.submitLabel.value, 'Signing in...');
    await page.submitForm();
    assert.equal(requests, 1);
    finish({ data: { redirect: '/dashboard' } });
    await pending;
    page.unmount();
});
