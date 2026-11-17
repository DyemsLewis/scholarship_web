import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { registerPortalPwa } from '../../resources/js/support/portalPwa.js';

const root = new URL('../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const worker = read('public/service-worker.js');
const offline = read('public/offline.html');
const manifest = JSON.parse(read('public/manifest.webmanifest'));

function harness({ scope = 'https://portal.test/', network, cached, keys = [], storageFailure = false } = {}) {
    const listeners = {}, requests = [], writes = [], deleted = [], calls = [];
    const offlineUrl = new URL('offline.html', scope).href;
    const response = new Response(offline, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    Object.defineProperty(response, 'url', { value: offlineUrl });
    const cache = { put: async (...args) => writes.push(args), match: async key => { calls.push(['match', key]); return cached?.clone() || undefined; } };
    class RequestStub {
        constructor(value, options = {}) { if (typeof value === 'string') this.url = value; else Object.assign(this, value); Object.assign(this, options); }
    }
    runInNewContext(worker, {
        URL, Request: RequestStub, Response, caches: {
            open: async key => { if (storageFailure) throw new Error('Storage unavailable'); calls.push(['open', key]); return cache; },
            keys: async () => keys, delete: async key => { deleted.push(key); return true; },
        },
        fetch: async request => { requests.push(request); return network ? network(request) : response.clone(); },
        self: { registration: { scope }, addEventListener: (key, callback) => { listeners[key] = callback; }, skipWaiting: async () => calls.push(['skipWaiting']), clients: { claim: async () => calls.push(['claim']) } },
    });
    return { requests, writes, deleted, calls, offlineUrl,
        async lifecycle(name) { let pending; listeners[name]({ waitUntil: value => { pending = value; } }); await pending; },
        async request(request) { let pending; listeners.fetch({ request, respondWith: value => { pending = value; } }); return pending ? await pending : null; },
    };
}

test('manifest identity and all PNG icon dimensions are valid and stable', () => {
    assert.equal(manifest.name, 'FindScholarship'); assert.equal(manifest.id, './'); assert.equal(manifest.scope, './');
    assert.equal(manifest.start_url, './'); assert.equal(manifest.display, 'standalone');
    assert.match(manifest.theme_color, /^#[0-9a-f]{6}$/i);
    for (const icon of manifest.icons) {
        const png = readFileSync(new URL(`public/${icon.src.replace(/^\.\//, '')}`, root));
        assert.equal(png.subarray(1, 4).toString(), 'PNG');
        assert.equal(`${png.readUInt32BE(16)}x${png.readUInt32BE(20)}`, icon.sizes);
        assert.equal(icon.type, 'image/png');
    }
    assert.ok(manifest.icons.some(icon => icon.sizes === '192x192' && icon.purpose === 'any'));
    assert.ok(manifest.icons.some(icon => icon.sizes === '512x512' && icon.purpose === 'any'));
    assert.ok(manifest.icons.some(icon => icon.sizes === '512x512' && icon.purpose === 'maskable'));
});

test('registration is same-origin, supports subdirectory hosting and bypasses the HTTP worker cache', async () => {
    const calls = [], registration = {};
    const browser = { isSecureContext: true, location: { origin: 'https://portal.test' }, appBaseUrl: 'https://portal.test/scholarship', navigator: { serviceWorker: { register: async (...args) => { calls.push(args); return registration; } } } };
    assert.equal(await registerPortalPwa(browser), registration);
    assert.deepEqual(calls, [['https://portal.test/scholarship/service-worker.js', { scope: 'https://portal.test/scholarship/', updateViaCache: 'none' }]]);
    browser.appBaseUrl = 'https://other.test'; assert.equal(await registerPortalPwa(browser), null); assert.equal(calls.length, 1);
    browser.isSecureContext = false; assert.equal(await registerPortalPwa(browser), null);
    assert.equal(await registerPortalPwa({ isSecureContext: true, navigator: {} }), null);
    browser.isSecureContext = true; browser.appBaseUrl = ''; browser.navigator.serviceWorker.register = async () => { throw new Error('Blocked'); };
    assert.equal(await registerPortalPwa(browser), null);
});

test('worker install stores only the anonymous public offline notice', async () => {
    const state = harness({ network: request => {
        const response = new Response(offline, { headers: { 'Content-Type': 'text/html' } });
        Object.defineProperty(response, 'url', { value: request.url }); return response;
    } });
    await state.lifecycle('install');
    assert.equal(state.writes.length, 1); assert.equal(state.writes[0][0], state.offlineUrl);
    assert.equal(state.requests[0].credentials, 'omit'); assert.equal(state.requests[0].redirect, 'error');
    assert.equal(state.requests[0].cache, 'reload');
    assert.ok(state.calls.some(([key]) => key === 'skipWaiting'));
});

test('a redirected, missing, or non-HTML notice cannot activate the worker', async () => {
    for (const [status, type, url] of [[404, 'text/html', 'https://portal.test/offline.html'], [200, 'application/json', 'https://portal.test/offline.html'], [200, 'text/html', 'https://portal.test/login']]) {
        const state = harness({ network: () => {
            const response = new Response('Unavailable', { status, headers: { 'Content-Type': type } });
            Object.defineProperty(response, 'url', { value: url }); return response;
        } });
        await assert.rejects(state.lifecycle('install'), /offline notice is unavailable/); assert.equal(state.writes.length, 0);
    }
});

test('activation removes only old offline notices belonging to this application scope', async () => {
    const old = 'findscholarship-offline:%2F:v0', current = 'findscholarship-offline:%2F:v1';
    const state = harness({ keys: [old, current, 'another-app-cache', 'findscholarship-offline:%2Fother%2F:v0'] });
    await state.lifecycle('activate'); assert.deepEqual(state.deleted, [old]);
    assert.ok(state.calls.some(([key]) => key === 'claim'));
});

test('private navigations always use the network and are never written into Cache Storage', async () => {
    for (const pathname of ['/dashboard/profile', '/provider/programs', '/admin/workspaces/reviews', '/login', '/dashboard/documents/7/download']) {
        const state = harness({ network: () => new Response('Private online content') });
        const result = await state.request({ url: 'https://portal.test'+pathname, method: 'GET', mode: 'navigate', credentials: 'include' });
        assert.equal(await result.text(), 'Private online content'); assert.equal(state.requests[0].cache, 'no-store');
        assert.equal(state.requests[0].credentials, 'include'); assert.equal(state.writes.length, 0); assert.equal(state.calls.length, 0);
    }
});

test('API calls, uploads, submissions, external requests and other scopes are never intercepted or queued', async () => {
    const state = harness({ scope: 'https://portal.test/app/' });
    for (const request of [
        { url: 'https://portal.test/app/login', method: 'POST', mode: 'navigate' },
        { url: 'https://portal.test/app/review', method: 'PATCH', mode: 'cors' },
        { url: 'https://portal.test/app/upload', method: 'POST', mode: 'cors' },
        { url: 'https://portal.test/app/documents/data', method: 'GET', mode: 'cors' },
        { url: 'https://portal.test/app/proof.png', method: 'GET', mode: 'no-cors' },
        { url: 'https://other.test/app/', method: 'GET', mode: 'navigate' },
        { url: 'https://portal.test/other/', method: 'GET', mode: 'navigate' },
    ]) assert.equal(await state.request(request), null);
    assert.equal(state.requests.length, 0); assert.equal(state.writes.length, 0);
    assert.doesNotMatch(worker, /addEventListener\(['"](?:sync|periodicsync|push|message)['"]|cache\.addAll|cache\.put\(request/);
});

test('offline navigation receives only the public notice and preserves authentication/server errors online', async () => {
    const state = harness({ cached: new Response(offline), network: () => { throw new Error('Offline'); } });
    const response = await state.request({ url: 'https://portal.test/admin/private', method: 'GET', mode: 'navigate' });
    assert.match(await response.text(), /Reconnect to continue/); assert.equal(state.writes.length, 0);
    for (const status of [401, 403, 404, 422, 429, 500, 503]) {
        const online = harness({ network: () => new Response('Server result', { status }) });
        const result = await online.request({ url: 'https://portal.test/private', method: 'GET', mode: 'navigate' });
        assert.equal(result.status, status); assert.equal(await result.text(), 'Server result'); assert.equal(online.calls.length, 0);
    }
});

test('missing offline storage still returns a generic no-store connection notice', async () => {
    const state = harness({ storageFailure: true, network: () => { throw new Error('Offline'); } });
    const result = await state.request({ url: 'https://portal.test/dashboard/profile', method: 'GET', mode: 'navigate' });
    assert.equal(result.status, 503); assert.equal(result.headers.get('Cache-Control'), 'no-store');
    assert.match(await result.text(), /not have been saved/);
});

test('offline page is self-contained, has no scripts/forms, and does not promise offline submissions', () => {
    assert.doesNotMatch(offline, /<script|<form|<link|<img|https?:\/\//);
    assert.match(offline, /href=""/); assert.match(offline, /No application or review action is queued/);
    assert.match(offline, /not stored for offline access/); assert.match(offline, /data-offline-notice/);
    const head = read('resources/views/layouts/app.blade.php');
    assert.match(head, /rel="manifest"/); assert.match(head, /apple-touch-icon/);
    assert.match(read('resources/js/app.js'), /if \(!import.meta.env.DEV\)/);
    assert.match(read('public/.htaccess'), /AddType application\/manifest\+json \.webmanifest/);
});
