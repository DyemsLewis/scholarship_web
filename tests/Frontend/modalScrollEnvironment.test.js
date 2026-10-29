import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { installModalScrollEnvironment } from '../../resources/js/support/modalScrollEnvironment.js';
import { lockPageScroll } from '../../resources/js/support/pageScrollLock.js';

function environment() {
    const styles = new Map();
    const style = {
        getPropertyValue: (key) => styles.get(key)?.value ?? '', getPropertyPriority: () => '',
        setProperty: (key, value) => styles.set(key, { value }), removeProperty: (key) => styles.delete(key),
    };
    const doc = {
        documentElement: { style, clientWidth: 1425 }, body: {}, dialogs: [],
        querySelectorAll: () => doc.dialogs.filter((dialog) => dialog.modal && ['dialog', 'alertdialog'].includes(dialog.role)),
    };
    const view = { innerWidth: 1440, getComputedStyle: (element) => ({ scrollbarGutter: 'auto', visibility: element.visibility ?? 'visible' }) };
    let observer;
    class Observer {
        constructor(callback) { this.callback = callback; observer = this; }
        observe(target, options) { this.target = target; this.options = options; }
        disconnect() { this.disconnected = true; }
    }
    const stop = installModalScrollEnvironment(doc, view, Observer);
    const dialog = (options = {}) => ({ role: 'dialog', modal: true, hidden: false, getClientRects: () => [1], ...options });
    return { doc, view, style, dialog, stop, observer, sync: () => observer.callback() };
}

test('inline modal insertion locks the viewport and removal restores it', () => {
    const env = environment();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
    env.doc.dialogs.push(env.dialog()); env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), 'hidden');
    env.doc.dialogs = []; env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
    env.stop();
    assert.equal(env.observer.disconnected, true);
});

test('closing one nested modal cannot unlock another modal', () => {
    const env = environment();
    env.doc.dialogs = [env.dialog(), env.dialog({ role: 'alertdialog' })]; env.sync();
    env.doc.dialogs.pop(); env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), 'hidden');
    env.stop();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
});

test('global fallback and component leases cooperate without releasing each other', () => {
    const env = environment();
    const release = lockPageScroll(env.doc, env.view);
    env.doc.dialogs = [env.dialog()]; env.sync();
    release();
    assert.equal(env.style.getPropertyValue('overflow-y'), 'hidden');
    env.doc.dialogs = []; env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
    env.stop();
});

test('hidden dialogs and non-modal popovers do not lock the page', () => {
    const env = environment();
    env.doc.dialogs = [env.dialog({ hidden: true }), env.dialog({ getClientRects: () => [] }), env.dialog({ visibility: 'hidden' }), env.dialog({ modal: false })];
    env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
    env.doc.dialogs[0].hidden = false; env.sync();
    assert.equal(env.style.getPropertyValue('overflow-y'), 'hidden');
    env.stop();
    assert.equal(env.style.getPropertyValue('overflow-y'), '');
});

test('fallback observes modal visibility changes without observing the viewport styles it owns', () => {
    const env = environment();
    assert.equal(env.observer.target, env.doc.body);
    assert.ok(env.observer.options.childList && env.observer.options.subtree);
    assert.deepEqual(env.observer.options.attributeFilter, ['aria-modal', 'role', 'hidden', 'style', 'class']);
    env.stop();
    const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    assert.match(app, /installModalScrollEnvironment\(\)/);
    assert.match(app, /import.meta.hot.dispose\(cleanupModalEnvironment\)/);
});

const components = [
    'ApplicantPagePreviewModal', 'ApplicantProfileProofModal', 'ApplicantReportModal', 'StaffFilePreviewHost',
    'monitoring-detail/MonitoringAdjustmentModal', 'monitoring-detail/BenefitReceiptResponseModal', 'monitoring-detail/SupportOutcomeResponseModal',
];
for (const file of components) {
    test(`${file} compiles with a shared viewport lock`, () => {
        const source = readFileSync(new URL(`../../resources/js/components/${file}.vue`, import.meta.url), 'utf8');
        assert.match(source, /usePageScrollLock/);
        const { descriptor, errors } = parse(source, { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const template = compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('active applicant, provider and admin code cannot reintroduce body scroll locks', () => {
    function audit(directory) {
        for (const entry of readdirSync(directory, { withFileTypes: true })) {
            if (entry.name === 'legacy') continue;
            const filename = path.join(directory, entry.name);
            if (entry.isDirectory()) audit(filename);
            else if (/\.(?:js|vue)$/.test(filename)) {
                const source = readFileSync(filename, 'utf8');
                assert.doesNotMatch(source, /document\.body\.(?:classList\.(?:add|remove|toggle)\([^)]*overflow-hidden|style\.overflow(?:[XY])?\s*=)/, filename);
            }
        }
    }
    audit(fileURLToPath(new URL('../../resources/js/', import.meta.url)));
});
