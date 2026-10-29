import assert from 'node:assert/strict';
import test from 'node:test';
import { lockPageScroll } from '../../resources/js/support/pageScrollLock.js';

function page({ scrollbar = true, initial = {}, gutter = 'auto' } = {}) {
    const properties = new Map(Object.entries(initial).map(([key, value]) => [key, { value, priority: 'important' }]));
    const style = {
        getPropertyValue: (key) => properties.get(key)?.value ?? '',
        getPropertyPriority: (key) => properties.get(key)?.priority ?? '',
        setProperty: (key, value, priority = '') => properties.set(key, { value, priority }),
        removeProperty: (key) => properties.delete(key),
    };
    const doc = { documentElement: { clientWidth: scrollbar ? 1425 : 1440, style }, body: Object.freeze({ style: Object.freeze({ overflowX: 'clip' }) }) };
    const view = { innerWidth: 1440, getComputedStyle: () => ({ scrollbarGutter: gutter }) };
    return { doc, view, style };
}

test('a modal locks the viewport without creating a body scroll container', () => {
    const { doc, view, style } = page();
    const release = lockPageScroll(doc, view);
    assert.equal(style.getPropertyValue('overflow-y'), 'hidden');
    assert.equal(style.getPropertyValue('scrollbar-gutter'), 'stable');
    assert.deepEqual(doc.body.style, { overflowX: 'clip' });
    release();
    assert.equal(style.getPropertyValue('overflow-y'), '');
    assert.equal(style.getPropertyValue('scrollbar-gutter'), '');
});

test('short pages and overlay scrollbars do not gain an artificial gutter', () => {
    const { doc, view, style } = page({ scrollbar: false });
    const release = lockPageScroll(doc, view);
    assert.equal(style.getPropertyValue('scrollbar-gutter'), '');
    release();
});

test('nested dialogs cannot release the parent scroll lock', () => {
    const { doc, view, style } = page();
    const outer = lockPageScroll(doc, view);
    const inner = lockPageScroll(doc, view);
    inner();
    inner();
    assert.equal(style.getPropertyValue('overflow-y'), 'hidden');
    outer();
    assert.equal(style.getPropertyValue('overflow-y'), '');
});

test('closing a parent first still leaves the remaining dialog locked', () => {
    const { doc, view, style } = page();
    const outer = lockPageScroll(doc, view);
    const inner = lockPageScroll(doc, view);
    outer();
    assert.equal(style.getPropertyValue('overflow-y'), 'hidden');
    inner();
    assert.equal(style.getPropertyValue('overflow-y'), '');
});

test('original inline viewport styles and priorities are restored exactly', () => {
    const { doc, view, style } = page({ initial: { 'overflow-y': 'scroll', 'scrollbar-gutter': 'auto' } });
    const release = lockPageScroll(doc, view);
    release();
    assert.equal(style.getPropertyValue('overflow-y'), 'scroll');
    assert.equal(style.getPropertyPriority('overflow-y'), 'important');
    assert.equal(style.getPropertyValue('scrollbar-gutter'), 'auto');
    assert.equal(style.getPropertyPriority('scrollbar-gutter'), 'important');
});

test('existing balanced gutters and separate documents keep their own state', () => {
    const first = page({ gutter: 'stable both-edges' });
    const second = page({ scrollbar: false });
    const releaseFirst = lockPageScroll(first.doc, first.view);
    const releaseSecond = lockPageScroll(second.doc, second.view);
    assert.equal(first.style.getPropertyValue('scrollbar-gutter'), 'stable both-edges');
    releaseFirst();
    assert.equal(second.style.getPropertyValue('overflow-y'), 'hidden');
    releaseSecond();
});
