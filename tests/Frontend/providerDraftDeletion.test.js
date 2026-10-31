import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, ref } from 'vue';

const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
const expression = source.match(/const canDelete = computed\(\(\) => ([\s\S]*?)\);/);
const handler = source.match(/async function deleteDraft\(\) \{([\s\S]*?)\n\}\n\nasync function loadProgram/);
assert.ok(expression && handler, 'The draft guard and deletion handler must remain available');
const createGuard = new Function('computed', 'programId', 'hasSavedProgram', 'isLoading', 'originalStatus', 'applicationsCount', `return computed(() => ${expression[1]});`);
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const invokeDelete = new AsyncFunction('programId', 'canDelete', 'isSaving', 'requestConfirmation', 'window', 'showBackendError', handler[1]);

function context(overrides = {}) {
    const state = Object.fromEntries(Object.entries({ programId: 42, hasSavedProgram: true, isLoading: false, originalStatus: 'draft', applicationsCount: 0, isSaving: false, ...overrides })
        .map(([key, value]) => [key, ref(value)]));
    state.canDelete = createGuard(computed, state.programId, state.hasSavedProgram, state.isLoading, state.originalStatus, state.applicationsCount);
    return state;
}

async function remove(state, { confirm = true, duringConfirmation, fail = false } = {}) {
    const calls = { confirmations: 0, urls: [], redirects: [], errors: [] };
    const window = { axios: { delete: async (url) => {
        assert.equal(state.isSaving.value, true);
        calls.urls.push(url);
        if (fail) throw new Error('Delete failed');
    } }, location: { assign: (href) => calls.redirects.push(href) } };
    await invokeDelete(state.programId, state.canDelete, state.isSaving, async () => {
        calls.confirmations++;
        duringConfirmation?.(state);
        return confirm;
    }, window, (error) => calls.errors.push(error.message));
    return calls;
}

test('Delete draft remains hidden until a saved draft has loaded successfully', () => {
    for (const state of [context({ programId: null, hasSavedProgram: false }), context({ hasSavedProgram: false }), context({ isLoading: true }), context({ programId: 0 })]) assert.equal(state.canDelete.value, false);
    const state = context({ programId: null, hasSavedProgram: false });
    state.programId.value = 42;
    assert.equal(state.canDelete.value, false);
    state.hasSavedProgram.value = true;
    assert.equal(state.canDelete.value, true);
    assert.match(source, /hasSavedProgram.value = true/);
    assert.match(source, /v-if="canDelete"[\s\S]*?@click="deleteDraft"/);
});

test('programs with applications or a non-draft status cannot be deleted', () => {
    for (const status of ['pending_review', 'published', 'rejected', 'closed']) assert.equal(context({ originalStatus: status }).canDelete.value, false);
    assert.equal(context({ applicationsCount: 1 }).canDelete.value, false);
    assert.equal(context().canDelete.value, true);
});

test('unsaved, failed-load and busy states never open confirmation or send a delete', async () => {
    for (const state of [context({ programId: null, hasSavedProgram: false }), context({ hasSavedProgram: false }), context({ isLoading: true }), context({ isSaving: true })]) {
        const calls = await remove(state);
        assert.equal(calls.confirmations, 0);
        assert.deepEqual(calls.urls, []);
        assert.deepEqual(calls.redirects, []);
    }
});

test('cancelling confirmation leaves a saved draft untouched', async () => {
    const state = context();
    const calls = await remove(state, { confirm: false });
    assert.equal(calls.confirmations, 1);
    assert.deepEqual(calls.urls, []);
    assert.deepEqual(calls.redirects, []);
    assert.equal(state.isSaving.value, false);
});

test('confirmation is rechecked if the record, eligibility or save state changes', async () => {
    for (const duringConfirmation of [(state) => { state.programId.value = 43; }, (state) => { state.originalStatus.value = 'published'; },
        (state) => { state.applicationsCount.value = 1; }, (state) => { state.hasSavedProgram.value = false; }, (state) => { state.isSaving.value = true; }]) {
        const calls = await remove(context(), { duringConfirmation });
        assert.equal(calls.confirmations, 1);
        assert.deepEqual(calls.urls, []);
        assert.deepEqual(calls.redirects, []);
    }
});

test('a confirmed saved draft still uses the existing deletion endpoint and return page', async () => {
    const state = context();
    const calls = await remove(state);
    assert.deepEqual(calls.urls, ['/provider/scholarships/42']);
    assert.deepEqual(calls.redirects, ['/provider/programs/drafts']);
    assert.deepEqual(calls.errors, []);
    assert.equal(state.isSaving.value, false);
});

test('a failed deletion keeps the existing error feedback and releases the busy state', async () => {
    const state = context();
    const calls = await remove(state, { fail: true });
    assert.deepEqual(calls.errors, ['Delete failed']);
    assert.deepEqual(calls.redirects, []);
    assert.equal(state.isSaving.value, false);
});
