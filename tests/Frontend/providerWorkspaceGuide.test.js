import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const root = new URL('../../resources/js/', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const source = read('components/provider/WorkspaceGuide.vue');

test('guide compiles and uses shared modal focus, scroll locking, a teleport, and non-submit buttons', () => {
    const { descriptor } = parse(source);
    compileScript(descriptor, { id: 'guide-test' });
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'WorkspaceGuide.vue', id: 'guide-test' }).errors, []);
    assert.match(source, /useModalAccessibility/);
    assert.match(source, /<Teleport to="body">/);
    assert.match(source, /role="dialog" aria-modal="true"/);
    assert.match(source, /@keydown="handleKeydown"/);
    for (const button of descriptor.template.content.match(/<button\b[\s\S]*?<\/button>/g)) assert.match(button, /type="button"/);
    assert.doesNotMatch(source, /<form|window\.alert|window\.confirm/);
});

test('auto offer is explicit, permission checked, versioned per account, and failure does not block work', () => {
    assert.match(source, /autoOffer:.*default: false/);
    assert.match(source, /user\.permissions\?\.includes\(workspacePermissions\[props\.workspace\]\)/);
    assert.match(source, /data\?\.auto_offer && !alreadyOpened\(\)/);
    assert.match(source, /provider-guide:\$\{user\.id\}:\$\{props\.workspace\}:\$\{guide\.value\.version\}/);
    assert.match(source, /progressQueue = progressQueue\.then/);
    assert.match(source, /portalToast: false/);
    assert.match(source, /Your workspace is still available/);
    assert.match(source, /onBeforeUnmount/);
    assert.match(source, /saveProgress\(completed \? 'completed' : 'dismissed'\)/);
});

test('Programs dashboard and directory offer once; records and builders remain manual', () => {
    for (const file of ['ProgramCoordinatorDashboardPage', 'ProgramDirectoryPage']) {
        assert.match(read(`pages/provider/programs/${file}.vue`), /<WorkspaceGuide[^>]*workspace="programs" auto-offer/);
    }
    for (const file of ['ProgramFormPage', 'ProgramRecordPage']) {
        assert.match(read(`pages/provider/programs/${file}.vue`), /<WorkspaceGuide workspace="programs" \/>/);
        assert.doesNotMatch(read(`pages/provider/programs/${file}.vue`), /<WorkspaceGuide[^>]*auto-offer/);
    }
    assert.match(read('pages/provider/programs/ProgramDirectoryPage.vue'), /<WorkspaceGuide v-if="!isMonitoringDirectory"/);
});

function controller({ autoOffer = true, data, request, workspace = 'programs', permissions = ['manage_programs'], role = 'provider', providerWorkspaceUrl = '/provider/workspaces/programs' } = {}) {
    const mounted = [], calls = [];
    const props = { workspace, autoOffer };
    const payload = data || { auto_offer: true, guide: { version: 1, steps: [{ title: 'Draft' }] } };
    const window = {
        portalUser: { id: 10, permissions, role, provider_workspace_url: providerWorkspaceUrl },
        sessionStorage: { getItem: () => null, setItem: () => {} },
        axios: { get: async () => request ? request() : { data: payload }, post: async (url, body) => { calls.push(body); } },
    };
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function('window', 'defineProps', 'computed', 'ref', 'useId', 'useModalAccessibility', 'watch', 'onMounted', 'onBeforeUnmount', script + '\nreturn { open, guide, loadError, show, close, allowed };')(
        window, () => props, computed, ref, () => 'test', () => ({ handleKeydown() {} }), () => {}, cb => mounted.push(cb), () => {},
    );
    return { ...state, mounted, calls, props };
}

test('guide is optional, shows once when offered, and manual forms never load it automatically', async () => {
    const state = controller(); await state.mounted[0]();
    assert.equal(state.open.value, true);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(state.calls, [{ version: 1, status: 'seen' }]);
    state.close(true); await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.open.value, false); assert.equal(state.calls.at(-1).status, 'completed');
    const form = controller({ autoOffer: false }); await form.mounted[0]();
    assert.equal(form.guide.value, null); assert.deepEqual(form.calls, []);
    await form.show(); assert.equal(form.open.value, true);
    const manager = controller({ data: { auto_offer: false, guide: { version: 1, steps: [] } } });
    await manager.mounted[0](); assert.equal(manager.open.value, false); assert.deepEqual(manager.calls, []);
});

test('closing while the guide loads cannot unexpectedly reopen it; loading failures remain dismissible', async () => {
    let resolve;
    const state = controller({ request: () => new Promise(yes => { resolve = yes; }) });
    const auto = state.mounted[0](); const manual = state.show(); state.close();
    resolve({ data: { auto_offer: true, guide: { version: 1, steps: [] } } }); await auto; await manual;
    await new Promise(yes => setImmediate(yes));
    assert.equal(state.open.value, false); assert.equal(state.calls.at(-1).status, 'dismissed');
    const failed = controller({ autoOffer: false, request: () => { throw new Error('Unavailable'); } });
    await failed.show(); assert.equal(failed.loadError.value, true); assert.equal(failed.open.value, true);
    failed.close(); assert.equal(failed.open.value, false); assert.deepEqual(failed.calls, []);
});

test('operational guides use their own permissions, including for multi-workspace managers', async () => {
    for (const [workspace, permission] of [['reviews', 'verify_applications'], ['selection', 'manage_selection_activities'], ['decisions', 'record_final_decisions'], ['recipients', 'manage_recipients'], ['monitoring', 'manage_monitoring'], ['releases', 'manage_benefit_releases'], ['organization-profile', 'manage_profile'], ['team', 'manage_team'], ['support', 'manage_reports'], ['billing', 'manage_billing']]) {
        const own = controller({ workspace, permissions: [permission] });
        assert.equal(own.allowed.value, true); await own.mounted[0](); assert.equal(own.open.value, true);
        const wrong = controller({ workspace, permissions: ['manage_programs'] });
        assert.equal(wrong.allowed.value, false); await wrong.mounted[0](); assert.equal(wrong.guide.value, null);
    }
    assert.equal(controller({ workspace: 'unknown', permissions: ['manage_programs'] }).allowed.value, false);
});

test('opening a session form while the initial guide loads suppresses the automatic popup', async () => {
    let resolve;
    const state = controller({ workspace: 'selection', permissions: ['manage_selection_activities'], request: () => new Promise(yes => { resolve = yes; }) });
    const loading = state.mounted[0](); state.props.autoOffer = false;
    resolve({ data: { auto_offer: true, guide: { version: 1, steps: [] } } }); await loading;
    assert.equal(state.open.value, false); assert.deepEqual(state.calls, []);
});

test('new queue and dashboard headers offer their guide; working records and forms remain manual', () => {
    for (const [folder, dashboard, directory, workspace] of [
        ['reviews', 'ApplicationReviewerDashboardPage', 'ApplicationReviewDirectoryPage', 'reviews'],
        ['selection', 'SelectionOfficerDashboardPage', 'SelectionActivityDirectoryPage', 'selection'],
        ['decisions', 'DecisionOfficerDashboardPage', 'FinalDecisionDirectoryPage', 'decisions'],
        ['recipients', 'RecipientOfficerDashboardPage', 'RecipientDirectoryPage', 'recipients'],
        ['monitoring', 'MonitoringOfficerDashboardPage', 'MonitoringDirectoryPage', 'monitoring'],
        ['releases', 'BenefitReleaseOfficerDashboardPage', 'BenefitReleaseDirectoryPage', 'releases'],
        ['organization', 'OrganizationProfileDashboardPage', 'OrganizationProfileReadinessPage', 'organization-profile'],
        ['team', 'TeamAdministratorDashboardPage', 'TeamDirectoryPage', 'team'],
        ['support', 'SupportStaffDashboardPage', 'SupportCaseDirectoryPage', 'support'],
        ['billing', 'BillingStaffDashboardPage', 'BillingRequestDirectoryPage', 'billing'],
    ]) {
        assert.match(read(`pages/provider/${folder}/${dashboard}.vue`), new RegExp(`<WorkspaceGuide workspace="${workspace}" auto-offer`));
        assert.match(read(`pages/provider/${folder}/${directory}.vue`), new RegExp(`<WorkspaceGuide workspace="${workspace}"`));
    }
    assert.match(read('pages/provider/selection/SelectionActivityDirectoryPage.vue'), /:auto-offer="!showSessionModal && pageQuery.get\('create_session'\) !== '1'"/);
    for (const file of ['reviews/ApplicationReviewRecordPage', 'selection/SelectionActivityRecordPage', 'selection/SelectionSessionRecordPage', 'selection/SelectionSessionFormPage', 'decisions/FinalDecisionRecordPage', 'recipients/RecipientRecordPage', 'monitoring/MonitoringPlanPage', 'monitoring/MonitoringCheckInRecordPage', 'releases/BenefitReleaseSchedulePage', 'releases/BenefitReleaseRecordPage', 'organization/OrganizationPublicProfilePage', 'organization/OrganizationContactPage', 'organization/OrganizationVerificationPage', 'team/TeamAccountFormPage', 'support/SupportCaseRecordPage', 'support/SupportPlatformReportPage', 'billing/BillingServiceDetailPage', 'billing/BillingServiceIntakePage', 'billing/BillingServiceReviewPage', 'billing/components/BillingServiceWorkspaceShell']) {
        const text = read(`pages/provider/${file}.vue`);
        assert.match(text, /<WorkspaceGuide workspace="(reviews|selection|decisions|recipients|monitoring|releases|organization-profile|team|support|billing)" \/>/);
        assert.doesNotMatch(text, /<WorkspaceGuide[^>]*auto-offer/);
        const { descriptor } = parse(text);
        assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: file, id: 'guide-page-test' }).errors, []);
    }
});

test('team help does not auto-open over status confirmations and cannot grant profile or team permissions', () => {
    assert.match(read('pages/provider/team/TeamDirectoryPage.vue'), /<WorkspaceGuide workspace="team" :auto-offer="!confirmation"/);
    assert.match(read('pages/provider/organization/OrganizationProfileReadinessPage.vue'), /<WorkspaceGuide workspace="organization-profile" auto-offer/);
    assert.equal(controller({ workspace: 'organization-profile', permissions: ['manage_team'] }).allowed.value, false);
    assert.equal(controller({ workspace: 'team', permissions: ['manage_profile'] }).allowed.value, false);
});

test('manager introduction is limited to the real main dashboard, not a title or permission count', async () => {
    assert.match(read('pages/provider/ProviderManagerDashboardPage.vue'), /<WorkspaceGuide workspace="manager" auto-offer/);
    for (const permissions of [[], ['manage_reports', 'manage_billing']]) {
        const state = controller({ workspace: 'manager', permissions, providerWorkspaceUrl: null });
        assert.equal(state.allowed.value, true); await state.mounted[0](); assert.equal(state.open.value, true);
    }
    for (const role of ['admin', 'applicant']) {
        const state = controller({ workspace: 'manager', role, providerWorkspaceUrl: null });
        assert.equal(state.allowed.value, false); await state.mounted[0](); assert.equal(state.guide.value, null);
    }
    assert.equal(controller({ workspace: 'manager', permissions: ['manage_reports', 'manage_billing'], providerWorkspaceUrl: '/provider/workspaces/support' }).allowed.value, false);
    assert.match(read('pages/provider/billing/BillingServiceCatalogPage.vue'), /<WorkspaceGuide workspace="billing" auto-offer/);
});

test('monitoring plans keep their own guide and delivery dialogs suppress automatic release guides', () => {
    assert.match(read('pages/provider/programs/ProgramDirectoryPage.vue'), /<WorkspaceGuide v-if="isMonitoringDirectory" workspace="monitoring" auto-offer/);
    const delivery = read('pages/provider/releases/BenefitDeliveryReportDirectoryPage.vue');
    assert.match(delivery, /<WorkspaceGuide workspace="releases" :auto-offer="!selected && !opening"/);
    const { descriptor } = parse(delivery);
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'delivery-guide', id: 'delivery-guide-test' }).errors, []);
});

test('guide auto-offers do not interrupt score breakdowns, concern dialogs, or direct withdrawal review', () => {
    assert.match(read('pages/provider/decisions/FinalDecisionDirectoryPage.vue'), /<WorkspaceGuide workspace="decisions" :auto-offer="!breakdownRecord"/);
    assert.match(read('pages/provider/recipients/AgreementConcernDirectoryPage.vue'), /<WorkspaceGuide workspace="recipients" :auto-offer="!selected && !opening"/);
    assert.match(read('pages/provider/recipients/WithdrawalDirectoryPage.vue'), /<WorkspaceGuide workspace="recipients" :auto-offer="!initialId && !selected && !opening"/);
    for (const file of ['AgreementConcernDirectoryPage', 'WithdrawalDirectoryPage']) {
        const { descriptor } = parse(read(`pages/provider/recipients/${file}.vue`));
        assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: file, id: 'guide-queue-test' }).errors, []);
    }
});
