import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref } from 'vue';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const pages = [
    'organization/OrganizationProfileReadinessPage.vue', 'organization/OrganizationPublicProfilePage.vue',
    'organization/OrganizationContactPage.vue', 'organization/OrganizationVerificationPage.vue',
    'team/TeamDirectoryPage.vue', 'team/TeamAccountFormPage.vue', 'ProviderAccountPage.vue',
];
for (const path of [...pages.map((name) => `pages/provider/${name}`), 'components/ProviderActionDialog.vue', 'components/CoverImagePickerModal.vue', 'components/ApplicantPagePreviewModal.vue']) {
    test(`${path} compiles with settings refinements`, () => {
        const source = read(path);
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
        assert.doesNotMatch(descriptor.template.content.replace(/<i\b[^>]*>/g, ''), /text-\[(?:9|10|11)px\]|\btruncate\b/);
        if (path.startsWith('pages/')) assert.match(source, /provider-settings-workspace/);
    });
}

test('organization and team tables reserve space for every action without dropping fixed table height', () => {
    for (const page of ['organization/OrganizationProfileReadinessPage.vue', 'organization/OrganizationVerificationPage.vue', 'team/TeamDirectoryPage.vue']) {
        const source = read(`pages/provider/${page}`);
        for (const token of ['staff-table-viewport', '<colgroup>', 'min-w-[56rem]']) assert.ok(source.includes(token), `${page}: ${token}`);
        assert.doesNotMatch(source, /<th[^>]*w-\[\d+%\]|min-w-\[(?:68|70|76)rem\]/);
    }
    const team = read('pages/provider/team/TeamDirectoryPage.vue');
    for (const token of ['selectedRole', 'requestVersion', 'pagination', 'account.can_manage', 'account.is_current_account', 'ProviderActionDialog', 'confirmationError']) assert.ok(team.includes(token));
    assert.match(team, /!accounts.length && !errorMessage/);
});

test('new rules are provider-scoped, maintain full-width forms and avoid styling embedded previews', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const rules = css.split('/* Provider account and organization refinements never style applicant previews. */')[1].split('/* Keep monitoring')[0];
    for (const match of rules.matchAll(/([^{}]+)\{/g)) for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.provider-settings-workspace/);
    for (const token of ['max-height: 24rem', 'calc(100dvh - 3rem)', 'overscroll-behavior: contain', 'focus-visible']) assert.ok(rules.includes(token));
    assert.doesNotMatch(rules, /@media|gradient/);
    const account = read('pages/provider/ProviderAccountPage.vue');
    assert.doesNotMatch(account, /max-w-md/);
    const publicPage = read('pages/provider/organization/OrganizationPublicProfilePage.vue');
    assert.equal((publicPage.match(/class="settings-page-actions/g) ?? []).length, 1);
    assert.ok(publicPage.indexOf('</section>\n\n        <ApplicantPagePreviewModal') > 0);
    assert.doesNotMatch(read('components/ApplicantPagePreviewModal.vue'), /provider-settings-workspace/);
});

test('dialogs share scroll and focus protections without altering file or role permissions', () => {
    for (const path of ['components/ProviderActionDialog.vue', 'components/CoverImagePickerModal.vue']) {
        const source = read(path);
        for (const token of ['useModalAccessibility', 'tabindex="-1"', 'settings-dialog-body', 'if (!props.busy)', 'role="alert"']) assert.ok(source.includes(token), `${path}: ${token}`);
    }
    const team = read('pages/provider/team/TeamAccountFormPage.vue');
    for (const token of ['settings-program-list', 'canAssignAllPrograms', 'isCustomRole', 'applyRolePreset', 'assigned_program_ids', ':aria-current=']) assert.ok(team.includes(token));
    const verification = read('pages/provider/organization/OrganizationVerificationPage.vue');
    assert.doesNotMatch(verification, /window.confirm/);
    for (const token of ['termsAccepted.value', 'labelFromKey(document.document_type)', 'deleteError', 'requestDelete(document)', 'v-else-if="profile"']) assert.ok(verification.includes(token));
});

function harness(path, exposed, overrides = {}) {
    const source = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const writes = [];
    const window = { location: { pathname: '/provider/team/accounts/88/edit', href: '', search: '' }, scrollTo() {}, clearTimeout() {}, setTimeout() {},
        axios: { get: async () => { throw new Error('Unavailable'); }, patch: async (...args) => { writes.push(args); return { data: {} }; }, post: async (...args) => { writes.push(args); return { data: { user: { provider_logo_url: '/new-logo.png' } } }; }, delete: async (...args) => { writes.push(args); return { data: {} }; } } };
    const deps = { ref, reactive, computed, onMounted() {}, onBeforeUnmount() {}, watch() {}, window, URLSearchParams,
        FormData: class { append() {} }, labelFromKey: (value) => value, limitPhoneNumber: (value) => value, ...overrides };
    return { ...new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps)), window, writes };
}

test('public profile logo replacement preserves unsaved text fields', async () => {
    const state = harness('pages/provider/organization/OrganizationPublicProfilePage.vue', 'applyProfile, form, profile, uploadLogo, isLoading');
    state.applyProfile({ name: 'Saved organization', logo_url: '/old.png', values: { provider_name: 'Saved organization', provider_description: 'Saved text' } });
    state.isLoading.value = false;
    state.form.provider_description = 'Unsaved changes in progress';
    await state.uploadLogo({ target: { files: [{}] } });
    assert.equal(state.profile.value.logo_url, '/new-logo.png');
    assert.equal(state.form.provider_description, 'Unsaved changes in progress');
    assert.equal(state.writes.length, 1);
});

test('background upload errors stay inside the picker and preserve profile text', async () => {
    const state = harness('pages/provider/organization/OrganizationPublicProfilePage.vue', 'applyProfile, form, updateCover, coverError, errorMessage');
    state.applyProfile({ name: 'Organization', values: {} });
    state.form.provider_description = 'In-progress description';
    state.window.axios.post = async () => { throw { response: { data: { errors: { cover_file: ['Choose a wider image.'] } } } }; };
    await state.updateCover('upload', { file: {} });
    assert.equal(state.coverError.value, 'Choose a wider image.');
    assert.equal(state.errorMessage.value, '');
    assert.equal(state.form.provider_description, 'In-progress description');
});

test('failed initial loads cannot save blank organization or team forms', async () => {
    for (const [path, action] of [
        ['organization/OrganizationPublicProfilePage.vue', 'saveProfile'], ['organization/OrganizationContactPage.vue', 'saveContact'],
        ['organization/OrganizationVerificationPage.vue', 'saveLegalDetails'], ['team/TeamAccountFormPage.vue', 'saveAccount'],
    ]) {
        const state = harness(`pages/provider/${path}`, `${action}, isLoading`);
        state.isLoading.value = false;
        await state[action]();
        assert.equal(state.writes.length, 0, path);
    }
});

function validTeamForm() {
    const state = harness('pages/provider/team/TeamAccountFormPage.vue', 'form, step, saveAccount, hasLoaded, isLoading');
    Object.assign(state.form, { first_name: 'Demo', middle_initial: 'A', last_name: 'Member', email: 'member@example.test', username: 'member', contact_number: '09175550001', account_title: 'application_reviewer', permissions: ['manage_reviews'] });
    state.hasLoaded.value = true;
    state.isLoading.value = false;
    state.step.value = 3;
    return state;
}

test('team validation returns to account details even when first name was already filled', async () => {
    const state = validTeamForm();
    state.form.last_name = '';
    await state.saveAccount();
    assert.equal(state.step.value, 1);
    assert.equal(state.writes.length, 0);
});

test('an existing role outside the actor catalog remains a custom role without adding permissions', () => {
    const state = harness('pages/provider/team/TeamAccountFormPage.vue', 'applyConfiguration, applyAccount, form');
    state.applyConfiguration({ available_roles: [{ value: 'custom', label: 'Custom role' }], can_assign_all_programs: true });
    state.applyAccount({ team_role: 'manager', permissions: ['manage_programs'], program_access_mode: 'selected', assigned_program_ids: [11] });
    assert.equal(state.form.account_title, 'custom');
    assert.deepEqual([...state.form.permissions], ['manage_programs']);
    assert.deepEqual([...state.form.assigned_program_ids], [11]);
});

test('server validation opens the correct team form step and preserves entered values', async () => {
    for (const [field, expected] of [['username', 1], ['permissions.0', 2], ['assigned_program_ids.0', 3]]) {
        const state = validTeamForm();
        state.window.axios.patch = async () => { throw { response: { data: { message: 'Check the highlighted field.', errors: { [field]: ['Invalid choice'] } } } }; };
        await state.saveAccount();
        assert.equal(state.step.value, expected, field);
        assert.equal(state.form.first_name, 'Demo');
    }
});

test('failed team status changes keep errors and target inside the dialog', async () => {
    const state = harness('pages/provider/team/TeamDirectoryPage.vue', 'requestStatusChange, confirmStatusChange, confirmation, confirmationError, errorMessage');
    state.requestStatusChange({ id: 88, name: 'Staff member', can_manage: true, is_current_account: false, account_status: 'active' });
    state.window.axios.patch = async () => { throw { response: { data: { message: 'This account is protected.' } } }; };
    await state.confirmStatusChange();
    assert.equal(state.confirmation.value.account.id, 88);
    assert.equal(state.confirmationError.value, 'This account is protected.');
    assert.equal(state.errorMessage.value, '');
});

test('protected and current team accounts cannot open a status-change action', () => {
    const state = harness('pages/provider/team/TeamDirectoryPage.vue', 'requestStatusChange, confirmation');
    for (const account of [{ can_manage: false }, { can_manage: true, is_current_account: true }]) {
        state.requestStatusChange(account);
        assert.equal(state.confirmation.value, null);
    }
});

test('proof deletion errors stay in the confirmation and do not discard its target', async () => {
    const state = harness('pages/provider/organization/OrganizationVerificationPage.vue', 'requestDelete, deleteDocument, deleteTarget, deleteError, errorMessage');
    state.requestDelete({ id: 11, original_name: 'registration.pdf' });
    state.window.axios.delete = async () => { throw { response: { data: { message: 'The file could not be removed.' } } }; };
    await state.deleteDocument();
    assert.equal(state.deleteTarget.value.id, 11);
    assert.equal(state.deleteError.value, 'The file could not be removed.');
    assert.equal(state.errorMessage.value, '');
});
