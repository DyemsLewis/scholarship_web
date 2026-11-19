import assert from 'node:assert/strict';
import test from 'node:test';
import { startVisiblePolling } from '../../resources/js/support/visiblePolling.js';

function environment(visibilityState = 'visible') {
    const page = Object.assign(new EventTarget(), { visibilityState });
    const timers = new Map();
    let nextId = 0;
    const browser = Object.assign(new EventTarget(), {
        navigator: { onLine: true },
        setTimeout: (task) => { timers.set(++nextId, task); return nextId; },
        clearTimeout: (id) => timers.delete(id),
    });
    const flush = async () => { await Promise.resolve(); await Promise.resolve(); };
    return {
        page, browser, timers, flush,
        async run() { await timers.values().next().value?.(); },
        async visibility(state) {
            page.visibilityState = state;
            page.dispatchEvent(new Event('visibilitychange'));
            await flush();
        },
        async online(state) {
            browser.navigator.onLine = state;
            browser.dispatchEvent(new Event(state ? 'online' : 'offline'));
            await flush();
        },
    };
}

test('polling pauses in hidden tabs and offline; on return it refreshes and schedules once', async () => {
    const env = environment('hidden');
    let calls = 0;
    const stop = startVisiblePolling(() => { calls++; }, { ...env, interval: 12000 });
    assert.equal(env.timers.size, 0);
    await env.visibility('visible');
    assert.equal(calls, 1);
    assert.equal(env.timers.size, 1);
    await env.run();
    assert.equal(calls, 2);
    await env.online(false);
    assert.equal(env.timers.size, 0);
    await env.visibility('hidden');
    await env.online(true);
    assert.equal(calls, 2);
    await env.visibility('visible');
    assert.equal(calls, 3);
    stop();
    assert.equal(env.timers.size, 0);
    await env.visibility('visible');
    assert.equal(calls, 3);
});

test('repeated wakeups do not overlap a refresh and failures can retry', async () => {
    const env = environment();
    let calls = 0;
    let reject;
    const stop = startVisiblePolling(() => {
        calls++;
        return calls === 1 ? new Promise((_, fail) => { reject = fail; }) : undefined;
    }, { ...env, interval: 30000 });
    const pending = env.run();
    await env.visibility('hidden');
    await env.visibility('visible');
    await env.online(true);
    assert.equal(calls, 1);
    assert.equal(env.timers.size, 0);
    reject(new Error('Network unavailable'));
    await pending;
    assert.equal(env.timers.size, 1);
    await env.run();
    assert.equal(calls, 2);
    stop();
});
