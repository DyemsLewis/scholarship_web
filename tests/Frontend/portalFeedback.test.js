import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { parse as parseJs } from '@babel/parser';
import { computed, effectScope, nextTick, reactive, ref, shallowRef, watch } from 'vue';
import { confirmChange, registerOutcomeConfirmationPresenter } from '../../resources/js/support/outcomeConfirmation.js';
import { useConfirmationDialog } from '../../resources/js/composables/useConfirmationDialog.js';

const read = (file) => readFileSync(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8');
const script = (file) => parse(read(file)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
const run = (source, deps, exposed) => new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps));
const settle = () => new Promise((resolve) => setImmediate(resolve));
function handler(file, name, deps) {
    const source = parse(read(file)).descriptor.scriptSetup.content;
    const fn = parseJs(source, { sourceType: 'module' }).program.body.find((node) => node.type === 'FunctionDeclaration' && node.id.name === name);
    return run(source.slice(fn.start, fn.end), { confirmChange, ...deps }, name)[name];
}

test('feedback toasts report results without clearing stale guards, and expose read-only recovery', async () => {
    const props = reactive({ message: '', type: 'error', title: '', retryLabel: 'Reload record', fallback: false });
    const notifications = [], emitted = [], scope = effectScope();
    scope.run(() => run(script('components/FeedbackToast.vue'), {
        watch, defineProps: () => props, defineEmits: () => (...args) => emitted.push(args),
        showPortalToast: (notice) => notifications.push(notice),
    }, 'retry'));
    try {
        assert.equal(notifications.length, 0);
        props.message = 'The record changed. Reload before saving.';
        await nextTick();
        assert.equal(notifications.length, 1);
        assert.equal(notifications[0].type, 'error');
        assert.equal(notifications[0].actionLabel, 'Reload record');
        notifications[0].action();
        assert.deepEqual(emitted, [['retry']]);
        assert.equal(props.message, 'The record changed. Reload before saving.');
        props.type = 'success'; props.message = 'Changes saved.';
        await nextTick();
        assert.equal(notifications[1].type, 'success');
        assert.equal(notifications[1].title, 'Changes saved');
    } finally { scope.stop(); }
});

test('duplicate server and page notices keep Retry, and toasts remain above confirmation dialogs', () => {
    let timer;
    const state = run(script('components/GlobalToastHost.vue'), {
        reactive, shallowRef, onMounted() {}, onBeforeUnmount() {},
        window: { clearTimeout() {}, setTimeout: (callback) => { timer = callback; return 1; } },
    }, 'handleToast, runAction, toast');
    let retried = 0;
    state.handleToast({ detail: { message: 'Please reload.', type: 'error' } });
    state.handleToast({ detail: { message: 'Please reload.', type: 'error', actionLabel: 'Reload current record', action: () => retried++ } });
    assert.equal(state.toast.actionLabel, 'Reload current record');
    state.runAction();
    assert.equal(retried, 1); assert.equal(state.toast.show, false);
    timer();
    assert.match(read('components/ToastMessage.vue'), /z-\[5000\]/);
    assert.match(read('components/ToastMessage.vue'), /:role="type === 'error' \? 'alert' : 'status'"/);
    assert.doesNotMatch(read('components/ToastMessage.vue'), /v-html/);
});

test('data confirmation fails closed and rechecks permissions after the modal', async () => {
    assert.equal(await confirmChange(), false);
    const dialog = useConfirmationDialog(), unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    let permitted = true;
    try {
        const pending = confirmChange({ title: 'Save profile?' }, () => permitted);
        assert.equal(dialog.confirmation.open, true);
        assert.equal(dialog.confirmation.cancelLabel, 'Cancel');
        permitted = false; dialog.confirmConfirmation();
        assert.equal(await pending, false);
    } finally { unregister(); }
});

test('provider evaluation cancellation retains the draft and never records an outcome', async () => {
    const dialog = useConfirmationDialog(), unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const writes = [], stage = { can_edit: true, save_url: '/evaluation' }, payload = { score: 88, notes: 'Keep this draft.' };
    const deps = { actionBusy: ref(false), evaluationError: ref(''), application: ref({}), editingEvaluation: ref(stage), successMessage: ref(''),
        firstError: (_, fallback) => fallback, window: { axios: { post: async (...args) => { writes.push(args); return { data: { application: {}, message: 'Saved' } }; } } } };
    const save = handler('pages/provider/selection/SelectionActivityRecordPage.vue', 'saveEvaluation', deps);
    try {
        const cancelled = save({ stage, payload }); dialog.cancelConfirmation(); await cancelled;
        assert.equal(writes.length, 0); assert.deepEqual(deps.editingEvaluation.value, stage); assert.equal(payload.notes, 'Keep this draft.');
        const accepted = save({ stage, payload }); dialog.confirmConfirmation(); await accepted;
        assert.equal(writes.length, 1); assert.equal(writes[0][0], '/evaluation');
        assert.equal(deps.actionBusy.value, false);
    } finally { unregister(); }
});

test('admin save and applicant saved-list actions make no changes when cancelled', async () => {
    const dialog = useConfirmationDialog(), unregister = registerOutcomeConfirmationPresenter(dialog.requestConfirmation);
    const writes = [], window = { axios: { patch: async (...args) => { writes.push(args); return { data: { user: {} } }; }, post: async (...args) => { writes.push(args); return { data: { scholarship: { is_saved: true } } }; } } };
    const admin = { isSaving: ref(false), isLoading: ref(false), user: ref({ id: 1 }), form: reactive({ first_name: 'Retained draft' }), errorMessage: ref(''), successMessage: ref(''), validationErrors: ref({}), window, applyUser() {} };
    const detail = ref({ saved: { endpoint: '/saved/1', is_saved: false } });
    const saveProfile = handler('pages/AdminProfilePage.vue', 'saveProfile', admin);
    const toggleSave = handler('pages/UserScholarshipDetailPage.vue', 'toggleSave', { detail, isPreview: false, isSaving: ref(false), errorMessage: ref(''), decision: ref({ state: 'open' }), window });
    try {
        let pending = saveProfile(); dialog.cancelConfirmation(); await pending;
        assert.equal(admin.form.first_name, 'Retained draft'); assert.equal(writes.length, 0);
        pending = toggleSave(); dialog.cancelConfirmation(); await pending;
        assert.equal(detail.value.saved.is_saved, false); assert.equal(writes.length, 0);
        pending = toggleSave(); dialog.confirmConfirmation(); await pending;
        assert.equal(detail.value.saved.is_saved, true); assert.equal(writes.length, 1);
    } finally { unregister(); }
});

test('all active Vue pages compile after feedback replacements', () => {
    for (const file of readdirSync(new URL('../../resources/js/', import.meta.url), { recursive: true }).filter((file) => file.endsWith('.vue') && !file.startsWith('legacy'))) {
        const { descriptor, errors } = parse(read(file), { filename: file });
        assert.deepEqual(errors, [], file);
        const bindings = compileScript(descriptor, { id: file }).bindings;
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: bindings } }).errors, [], file);
    }
});
