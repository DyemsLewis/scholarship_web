import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, createSSRApp, reactive, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { normalizeWorkspaceDashboard } from '../../resources/js/support/workspaceDashboard.js';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const paths = [
    'pages/admin/governance/SuperAdminAreaPage.vue', 'pages/admin/governance/SuperAdminWorkspacePage.vue',
    'pages/admin/accounts/AccountDirectoryPage.vue', 'pages/admin/accounts/AccountAttentionPage.vue',
    'pages/admin/accounts/AccountDirectoryTable.vue', 'pages/admin/accounts/AccountManagerDashboardPage.vue',
    'pages/admin/accounts/AccountRecordPage.vue', 'pages/admin/accounts/AccountCreatePage.vue',
    'pages/AdminAccountFormPage.vue', 'pages/AdminProfilePage.vue', 'components/workspace-dashboard/WorkspaceDashboardFrame.vue',
];
for (const path of paths) {
    test(`${path} compiles with account/governance refinements`, () => {
        const { descriptor, errors } = parse(read(path), { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

function harness(path, exposed, options = {}) {
    const script = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const writes = [];
    const mounted = [];
    const watchers = [];
    const window = {
        location: { pathname: options.pathname ?? '/admin/accounts/2/edit', search: options.search ?? '', href: 'http://localhost/admin/workspaces/accounts/attention' },
        portalUser: { id: 1, is_managed_account: false }, history: { replaceState() {} }, scrollTo() {},
        axios: {
            get: async () => { throw new Error('Offline'); },
            patch: async (...args) => { writes.push(args); return { data: { user: { id: 2, role: 'admin' } } }; },
            post: async (...args) => { writes.push(args); return { data: { user: { id: 2, role: 'admin' } } }; },
        },
    };
    const deps = { staffQueueReturn,
        computed, reactive, ref, useAdminResource, normalizeWorkspaceDashboard,
        nextTick: async () => {}, onMounted: (callback) => mounted.push(callback), onBeforeUnmount() {},
        watch: (...args) => watchers.push(args), window, document: { getElementById: () => ({ dataset: { area: options.area ?? 'team' } }) },
        URL, URLSearchParams, clearTimeout, setTimeout, limitPhoneNumber: (value) => value,
        useConfirmationDialog: () => ({ confirmation: ref({}), requestConfirmation: async () => true, confirmConfirmation() {}, cancelConfirmation() {} }),
        formatFileSize: () => '', showPortalToast() {}, labelFromKey: (value) => value,
    };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), window, writes, mounted, watchers };
}

const accountPayload = { id: 2, role: 'admin', first_name: 'Mira', middle_initial: '', last_name: 'Cruz', email: 'mira@example.test', username: 'mira.cruz', contact_number: '09175550111', is_managed_account: true, account_title: 'Account manager', permissions: ['manage_accounts'], account_status: 'active' };
async function loadedForm() {
    const state = harness('pages/AdminAccountFormPage.vue', 'loadAccount, saveAccount, form, formElement, isSaving, accountAction, activeFormSection, activeWorkspaceSection, errorMessage, validationErrors, formAvailable');
    state.window.axios.get = async () => ({ data: { user: { ...accountPayload } } });
    await state.loadAccount();
    return state;
}

test('failed edit loads cannot expose a blank form or execute account actions', async () => {
    const state = harness('pages/AdminAccountFormPage.vue', 'loadAccount, loadError, formAvailable, saveAccount, updateAccountStatus, forcePasswordReset, verifyEmail, resendVerificationEmail, updateApplicantVerification');
    await state.loadAccount();
    for (const action of [() => state.saveAccount(), () => state.updateAccountStatus('suspended'), () => state.forcePasswordReset(), () => state.verifyEmail(), () => state.resendVerificationEmail(), () => state.updateApplicantVerification('approved')]) await action();
    assert.equal(state.formAvailable.value, false);
    assert.equal(state.loadError.value, 'Unable to load account.');
    assert.equal(state.writes.length, 0);
    assert.match(read('pages/AdminAccountFormPage.vue'), /v-if="formAvailable && activeWorkspaceSection === 'details'"/);
});

test('edit load retry recovers the account without weakening the availability guard', async () => {
    const state = await loadedForm();
    assert.equal(state.formAvailable.value, true);
    assert.equal(state.form.value.firstName, 'Mira');
});

test('Add admin account preselects staff access only for a primary admin', async () => {
    const state = harness('pages/AdminAccountFormPage.vue', 'applyRequestedRole, form, resetForm', { pathname: '/admin/accounts/create', search: '?role=admin' });
    state.applyRequestedRole();
    assert.equal(state.form.value.role, 'admin');
    assert.equal(state.form.value.accountTitle, 'Account manager');
    assert.deepEqual([...state.form.value.permissions], ['manage_accounts']);
    state.resetForm();
    assert.equal(state.form.value.role, 'admin');

    const managed = harness('pages/AdminAccountFormPage.vue', 'applyRequestedRole, form', { pathname: '/admin/accounts/create', search: '?role=admin' });
    managed.window.portalUser.is_managed_account = true;
    managed.applyRequestedRole();
    assert.equal(managed.form.value.role, 'applicant');
    assert.deepEqual([...managed.form.value.permissions], []);
});

test('blank initials are sent as null and duplicate edit saves are suppressed after async validation', async () => {
    const state = await loadedForm();
    await Promise.all([state.saveAccount(), state.saveAccount()]);
    assert.equal(state.writes.length, 1);
    assert.equal(state.writes[0][1].middle_initial, null);
    assert.deepEqual([...state.writes[0][1].permissions], ['manage_accounts']);
});

test('server errors open the matching step, retain drafts, and expose field feedback', async () => {
    for (const [field, section] of [['username', 'identity'], ['permissions.0', 'access'], ['account_title', 'access'], ['password', 'signin']]) {
        const state = await loadedForm();
        state.form.value.firstName = 'Unsaved identity';
        state.window.axios.patch = async () => { throw { response: { data: { message: 'Validation failed.', errors: { [field]: ['Review this value.'] } } } }; };
        await state.saveAccount();
        assert.equal(state.activeFormSection.value, section, field);
        assert.equal(state.form.value.firstName, 'Unsaved identity');
        assert.equal(state.errorMessage.value, 'Review this value.');
        assert.equal(state.validationErrors.value[field][0], 'Review this value.');
    }
});

test('network failures preserve the current step instead of silently dropping the save error', async () => {
    const state = await loadedForm();
    state.activeFormSection.value = 'signin';
    state.window.axios.patch = async () => { throw new Error('Offline'); };
    await state.saveAccount();
    assert.equal(state.activeFormSection.value, 'signin');
    assert.match(state.errorMessage.value, /could not be saved/);
    assert.equal(state.form.value.email, 'mira@example.test');
});

test('server validation focuses the rejected field only after the saving fieldset is re-enabled', async () => {
    const state = await loadedForm();
    let focused = false;
    state.formElement.value = {
        querySelectorAll: () => [],
        querySelector: () => ({ setCustomValidity() {}, focus() { focused = !state.isSaving.value; } }),
    };
    state.window.axios.patch = async () => { throw { response: { data: { errors: { username: ['Already in use.'] } } } }; };
    await state.saveAccount();
    assert.equal(focused, true);
    assert.equal(state.isSaving.value, false);
});

test('native invalid fields on another step are revealed before focus/reportValidity', async () => {
    const state = harness('pages/AdminAccountFormPage.vue', 'focusFirstInvalidField, activeFormSection');
    let reported = false, focused = false;
    const invalid = { disabled: false, checkValidity: () => false, closest: () => ({ dataset: { formSection: 'identity' } }), reportValidity: () => { reported = true; }, focus: () => { focused = true; } };
    state.activeFormSection.value = 'signin';
    assert.equal(await state.focusFirstInvalidField({ querySelectorAll: () => [invalid] }), false);
    assert.equal(state.activeFormSection.value, 'identity');
    assert.ok(reported && focused);
    assert.match(read('pages/AdminAccountFormPage.vue'), /novalidate/);
});

test('account forms label initials optional without removing other required identity fields', () => {
    const staff = read('pages/AdminAccountFormPage.vue');
    const input = staff.match(/<input\s+id="admin-middle-initial"[\s\S]*?>/)[0];
    assert.doesNotMatch(input, /\brequired\b/);
    assert.match(staff, /M\.I\. <span[^>]*>\(optional\)/);
    assert.doesNotMatch(staff, /max-w-xl/);
    assert.match(staff, /:aria-invalid="Boolean\(fieldError\('username'\)\)"/);
    const create = read('pages/admin/accounts/AccountCreatePage.vue');
    assert.doesNotMatch(create.match(/<input v-model="form.middle_initial"[^>]*>/)[0], /\brequired\b/);
    assert.match(create, /:disabled="saving"/);
    assert.match(create, /trim\(\).toUpperCase\(\) \|\| null/);
});

test('account record actions require a loaded record and failed loads remain retryable', async () => {
    const state = harness('pages/admin/accounts/AccountRecordPage.vue', 'loadAccount, resendVerification, requirePasswordReset, updateStatus, openStatusDialog, statusDialogOpen, errorMessage', { pathname: '/admin/workspaces/accounts/39' });
    await state.loadAccount();
    state.resendVerification(); state.requirePasswordReset(); await state.updateStatus(); state.openStatusDialog();
    assert.equal(state.writes.length, 0);
    assert.equal(state.statusDialogOpen.value, false);
    assert.match(state.errorMessage.value, /could not be loaded/);
    const source = read('pages/admin/accounts/AccountRecordPage.vue');
    assert.match(source, /@retry="loadAccount"/);
    assert.match(source, /account.role === 'provider' \? 'object-contain p-1'/);
    assert.doesNotMatch(source, /\btruncate\b/);
});

test('account queues invalidate before debouncing and retain protected pagination and distinct error states', () => {
    for (const page of ['AccountDirectoryPage', 'AccountAttentionPage']) {
        const value = read(`pages/admin/accounts/${page}.vue`);
        for (const token of ['useAdminResource', 'resource.invalidate()', "{ flush: 'sync' }", 'onBeforeUnmount(() => clearTimeout(searchTimer))', ':loading="loading" :error="errorMessage"', '@retry="resource.retry"', 'loading || pagination.current_page', 'params: query, signal']) assert.ok(value.includes(token), `${page}: ${token}`);
        assert.doesNotMatch(value, /\.axios\.(post|patch|delete|put)\(/);
    }
});

test('governance failures show retry feedback instead of reassuring empty tables or provisional zeros', () => {
    for (const page of ['SuperAdminWorkspacePage', 'SuperAdminAreaPage']) {
        const value = read(`pages/admin/governance/${page}.vue`);
        for (const token of ['useAdminResource', 'v-if="isLoading || errorMessage"', '<template v-else>', '<AdminLoadState', '@retry=', 'signal']) assert.ok(value.includes(token));
        assert.doesNotMatch(value, /fa-shield-check/);
    }
    const team = read('pages/admin/governance/SuperAdminAreaPage.vue');
    assert.match(team, /table-fixed min-w-\[56rem\]/);
    assert.match(team, /<colgroup><col><col class="w-44"/);
    assert.match(team, /<col class="w-20">/);
    assert.doesNotMatch(team, /min-w-\[72rem\]|\btruncate\b/);
});

test('account dashboard offers retry feedback while the shared frame retains its default error presentation', () => {
    const value = read('pages/admin/accounts/AccountManagerDashboardPage.vue');
    assert.match(value, /<template #error>/);
    assert.match(value, /@retry="loadDashboard"/);
    assert.match(value, /title: 'Account dashboard'/);
    const frame = read('components/workspace-dashboard/WorkspaceDashboardFrame.vue');
    assert.match(frame, /<slot name="error">/);
    assert.match(frame, /role="alert"/);
    assert.match(frame, /v-if="!errorMessage && !loading"/);
});

test('account tables retain complete escaped identities, photos/logos, and explicit email status', async () => {
    const compile = (path, imports = {}) => {
        const { descriptor } = parse(read(path));
        const code = compileScript(descriptor, { id: path, inlineTemplate: true }).content.replace(/from (['"])(.*?)\1/g, (match, quote, specifier) => {
            const resolved = specifier === 'vue' ? import.meta.resolve('vue') : imports[specifier];
            return resolved ? `from ${JSON.stringify(resolved)}` : match;
        });
        return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
    };
    const child = compile('pages/admin/components/AdminEmptyState.vue');
    const Table = (await import(compile('pages/admin/accounts/AccountDirectoryTable.vue', {
        '../components/AdminEmptyState.vue': child,
        '../../../components/StaffTableIdentity.vue': compile('components/StaffTableIdentity.vue'),
    }))).default;
    const email = `${'very.long.account.'.repeat(8)}@example.test`;
    const html = await renderToString(createSSRApp(Table, { accounts: [{ id: 39, name: 'Mira <script>name</script>', email, role: 'applicant', avatar_url: '/private-photo', email_verified: true, account_status: 'active' }, { id: 11, name: 'Provider', role: 'provider', avatar_url: '/logo.png', email_verified: false, account_status: 'active' }] }));
    for (const token of [email, '&lt;script&gt;', 'src="/private-photo"', 'src="/logo.png"', 'object-contain p-1', 'Email verified', 'Email unverified', 'table-fixed', '<colgroup>', 'scope="col"', 'href="/admin/workspaces/accounts/39"']) assert.ok(html.includes(token), token);
    assert.doesNotMatch(html, /<script>|\btruncate\b/);
});
