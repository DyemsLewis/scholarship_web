import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const path = '../../resources/js/components/admin/AccountSupportHandoffs.vue';
const source = readFileSync(new URL(path, import.meta.url), 'utf8');
const task = { id: 4, status: 'pending', token: 'b'.repeat(64), instruction: 'Check the sign-in issue.' };

function harness(overrides = {}) {
    const props = { report: { id: 9, can_refer_account: true, support_token: 'a'.repeat(64), handoff_tasks: [{ value: 'email', label: 'Email' }] }, accountId: null, handoffs: [], disabled: false, ...overrides };
    const emits = [], writes = [];
    const window = { axios: {
        post: async (...args) => { writes.push(args); return { data: { message: 'Task sent.', support_case: { id: 9 } } }; },
        patch: async (...args) => { writes.push(args); return { data: { message: 'Task returned.', account_handoffs: [] } }; },
    } };
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { ref, computed, defineProps: () => props, defineEmits: () => (...args) => emits.push(args), window };
    const state = new Function(...Object.keys(deps), `${script}\nreturn { open, close, submit, valid, canRefer, note, task, activeDialog, error, busy };`)(...Object.values(deps));
    return { ...state, props, emits, writes, window };
}

test('handoff component compiles using the existing admin dialog and panel styles', () => {
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'handoffs' });
    assert.deepEqual(compileTemplate({ id: 'handoffs', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /full report and attachments are not shared/);
    assert.match(source, /does not close the support case or approve the profile/);
});

test('referral validates and trims the explicit instruction and sends the case snapshot', async () => {
    const state = harness();
    state.open('refer');
    assert.equal(state.task.value, 'email');
    for (const note of [' ', 'short', 'x'.repeat(601)]) {
        state.note.value = note;
        await state.submit();
        assert.equal(state.writes.length, 0);
    }
    state.note.value = '  Check the reported sign-in issue.  ';
    await state.submit();
    assert.deepEqual(state.writes[0][1], { task: 'email', instruction: 'Check the reported sign-in issue.', expected_support_token: 'a'.repeat(64) });
    assert.equal(state.activeDialog.value, '');
    assert.deepEqual(state.emits.filter(([event]) => event === 'busy'), [['busy', true], ['busy', false]]);
});

test('pending handoffs prevent duplicate referrals and disabled controls cannot act', async () => {
    const state = harness({ handoffs: [task] });
    assert.equal(state.canRefer.value, false);
    state.open('refer');
    assert.equal(state.activeDialog.value, '');
    state.props.disabled = true;
    state.open('cancel', task);
    assert.equal(state.activeDialog.value, '');
    await state.submit();
    assert.equal(state.writes.length, 0);
});

test('account manager returns only the result and task snapshot to the scoped account endpoint', async () => {
    const state = harness({ report: null, accountId: 12, handoffs: [task] });
    state.open('complete', task);
    state.note.value = 'Account access checked; email verification is still required.';
    await state.submit();
    assert.equal(state.writes[0][0], '/admin/workspaces/accounts/12/support-handoffs/4');
    assert.deepEqual(state.writes[0][1], { result: state.note.value, expected_handoff_token: task.token });
    assert.equal(state.emits.find(([event]) => event === 'updated')[1].account_handoffs.length, 0);
});

test('cancellation uses its own scoped endpoint and cannot mutate a completed task', async () => {
    const state = harness({ handoffs: [task] });
    state.open('cancel', task);
    state.note.value = 'The reporter confirmed the issue no longer happens.';
    await state.submit();
    assert.equal(state.writes[0][0], '/admin/workspaces/support/cases/9/account-handoffs/4/cancel');
    state.open('cancel', { ...task, status: 'completed' });
    assert.equal(state.activeDialog.value, '');
});

test('stale errors preserve the draft and repeated submits and dialog-close are blocked while saving', async () => {
    const state = harness();
    state.open('refer'); state.note.value = 'Keep this instruction if the request fails.';
    let reject;
    state.window.axios.post = (...args) => { state.writes.push(args); return new Promise((_, no) => { reject = no; }); };
    const pending = state.submit();
    await state.submit(); state.close();
    assert.equal(state.writes.length, 1);
    assert.equal(state.activeDialog.value, 'refer');
    reject({ response: { status: 409, data: { message: 'This case changed. Reload it.' } } });
    await pending;
    assert.equal(state.note.value, 'Keep this instruction if the request fails.');
    assert.equal(state.error.value, 'This case changed. Reload it.');
    assert.equal(state.busy.value, false);
});
