import test from 'node:test';
import assert from 'node:assert/strict';
import { installOpaqueRecordNavigation } from '../../resources/js/support/opaqueRecordNavigation.js';

function harness(href, prefix = '/provider/programs/12', opaque = '/provider/r/tamper-resistant-token') {
    const browser = { location: new URL(href), history: { state: { saved: true } } };
    for (const name of ['pushState', 'replaceState']) browser.history[name] = (state, unused, url) => {
        browser.history.state = state;
        if (url != null) browser.location = new URL(url, browser.location.href);
    };
    const page = { querySelector: (selector) => ({ content: selector.includes('opaque') ? opaque : prefix }) };
    installOpaqueRecordNavigation(browser, page);
    return browser;
}

test('opaque navigation keeps page code on its logical path without exposing IDs in browser history', () => {
    const browser = harness('https://portal.test/provider/r/tamper-resistant-token/edit/basics?from=demo#details');
    assert.equal(browser.portalPathname(), '/provider/programs/12/edit/basics');
    browser.history.pushState({ step: 'benefits' }, '', '/provider/programs/12/edit/benefits?from=demo#benefits');
    assert.equal(browser.location.pathname, '/provider/r/tamper-resistant-token/edit/benefits');
    assert.equal(browser.portalPathname(), '/provider/programs/12/edit/benefits');
    assert.equal(browser.location.search, '?from=demo');
    assert.equal(browser.location.hash, '#benefits');
    assert.deepEqual(browser.history.state, { step: 'benefits' });
});

test('legacy direct renders are canonicalized without dropping query or fragment', () => {
    const browser = harness('https://portal.test/provider/programs/12/edit/basics?edit=1#item');
    assert.equal(browser.location.href, 'https://portal.test/provider/r/tamper-resistant-token/edit/basics?edit=1#item');
    assert.equal(browser.portalPathname(), '/provider/programs/12/edit/basics');
    assert.deepEqual(browser.history.state, { saved: true });
});

test('another record and external origin cannot accidentally reuse the current token', () => {
    const browser = harness('https://portal.test/provider/r/tamper-resistant-token/edit');
    browser.history.replaceState({}, '', '/provider/programs/123/edit');
    assert.equal(browser.location.pathname, '/provider/programs/123/edit');
    browser.history.replaceState({}, '', 'https://other.test/provider/programs/12/edit');
    assert.equal(browser.location.origin, 'https://other.test');
});

test('normal non-record pages keep their current pathname and history behavior', () => {
    const browser = harness('https://portal.test/provider/programs', '', '');
    assert.equal(browser.portalPathname(), '/provider/programs');
    browser.history.replaceState({}, '', '/provider/account');
    assert.equal(browser.portalPathname(), '/provider/account');
});

test('a newly saved draft can adopt server-issued record context before the wizard changes URL', () => {
    const browser = harness('https://portal.test/provider/programs/create', '', '');
    browser.portalSetRecordContext({ prefix: '/provider/programs/12', opaque_prefix: '/provider/r/new-record-token' });
    browser.history.replaceState({}, '', '/provider/programs/12/edit/benefits');
    assert.equal(browser.location.pathname, '/provider/r/new-record-token/edit/benefits');
    assert.equal(browser.portalPathname(), '/provider/programs/12/edit/benefits');
});
