import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { computed, createSSRApp, h, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const modalStub = 'data:text/javascript,' + encodeURIComponent('export function useModalAccessibility(){return {handleKeydown(){}}}');
function compile(path, imports) {
    const { descriptor } = parse(read(path));
    const code = compileScript(descriptor, { id: path, inlineTemplate: true }).content.replace(/from (['"])(.*?)\1/g, (match, quote, name) => {
        const url = name === 'vue' ? import.meta.resolve('vue') : imports[name];
        return url ? `from ${JSON.stringify(url)}` : match;
    });
    return 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
}
const baseUrl = compile('components/ConfirmationDialog.vue', { '../composables/useModalAccessibility': modalStub });
const { default: Confirmation } = await import(baseUrl);
const { default: Provider } = await import(compile('components/ProviderActionDialog.vue', { './ConfirmationDialog.vue': baseUrl }));
const { default: Alert } = await import(compile('components/AlertDialog.vue', { './ConfirmationDialog.vue': baseUrl }));
const { default: Admin } = await import(compile('pages/admin/components/AdminDialog.vue', {
    '../../../composables/useModalAccessibility': modalStub, '../../../components/ConfirmationDialog.vue': baseUrl,
}));
async function render(component, props, slots) {
    const context = {};
    await renderToString(createSSRApp({ render: () => h(component, { open: true, ...props }, slots) }), context);
    return context.teleports.body || '';
}

test('logout, provider actions, alerts, and simple admin confirmations share one visual shell', async () => {
    for (const [component, props] of [[Confirmation, { title: 'Log out?', tone: 'danger' }], [Provider, { title: 'Remove proof?', danger: true }],
        [Alert, { title: 'Check required information' }], [Admin, { title: 'Reopen response?' }]]) {
        const html = await render(component, props);
        for (const marker of ['confirmation-dialog', 'max-w-md', 'border-t-4', 'bg-slate-950/55', 'role="alertdialog"', 'aria-modal="true"']) assert.ok(html.includes(marker), marker);
    }
});

test('provider busy and error feedback survive the shared rendering', async () => {
    const html = await render(Provider, { title: 'Remove proof?', danger: true, busy: true, error: 'The file could not be removed.' });
    assert.match(html, /data-provider-action-dialog/);
    assert.match(html, /border-t-rose-600/);
    assert.match(html, /role="alert"/);
    assert.match(html, /The file could not be removed/);
    assert.match(html, /Saving\.\.\./);
    assert.equal((html.match(/<button[^>]* disabled/g) || []).length, 2);
});

test('validation alerts keep their existing selector and have one acknowledgement button', async () => {
    const html = await render(Alert, { title: 'Check the form', buttonLabel: 'Review details' });
    assert.match(html, /aria-labelledby="form-alert-title"/);
    assert.equal((html.match(/<button\b/g) || []).length, 1);
    assert.match(html, /Review details/);
    assert.doesNotMatch(html, />Cancel</);
});

test('admin confirmation-only actions ignore empty form slots while real forms retain their fields', async () => {
    const html = await render(Admin, { title: 'Reactivate?', confirmationOnly: true }, { default: () => h('textarea', { name: 'reason' }) });
    assert.match(html, /role="alertdialog"/);
    assert.doesNotMatch(html, /<textarea|<form/);
    const form = await render(Admin, { title: 'Suspend?', danger: true }, { default: () => h('textarea', { name: 'reason' }) });
    assert.match(form, /role="dialog"/);
    assert.match(form, /<textarea name="reason"/);
});

test('confirmation events are guarded while busy or disabled', () => {
    const script = parse(read('components/ConfirmationDialog.vue')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = { open: false, busy: true, confirmDisabled: false };
    const events = [];
    const state = new Function('computed','ref','useId','watch','defineProps','defineEmits','defineOptions','useModalAccessibility', script + '\nreturn {cancel,confirm};')(
        computed, ref, () => 'test', () => {}, () => props, () => (event) => events.push(event), () => {}, () => ({handleKeydown(){}}));
    state.confirm(); state.cancel(); assert.deepEqual(events, []);
    props.busy = false; props.confirmDisabled = true; state.confirm(); state.cancel(); assert.deepEqual(events, ['cancel']);
    props.confirmDisabled = false; state.confirm(); assert.deepEqual(events, ['cancel', 'confirm']);
});
