import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { createStaffQueueState, staffQueueLink, staffQueueReturn } from '../../resources/js/support/staffQueueState.js';

function harness(path, exposed, options = {}) {
    const source = readFileSync(new URL(`../../resources/js/${path}.vue`, import.meta.url), 'utf8');
    const script = parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const gets = [], watchers = [];
    const pathname = options.pathname || '/admin/workspaces/records/activity';
    const search = options.search || '';
    const window = { location: { pathname, search, origin: 'https://portal.example', href: `https://portal.example${pathname}${search}` }, history: { replaceState() {} }, axios: {
        get: async (...args) => { gets.push(args); return { data: options.payload || {} }; },
    } };
    const document = { getElementById: () => ({ dataset: { area: options.area || 'team', entryId: '5' } }) };
    const deps = { window, document, computed, ref, useAdminResource, createStaffQueueState, staffQueueLink, staffQueueReturn,
        URL, URLSearchParams, onMounted() {}, onBeforeUnmount() {}, watch: (...args) => watchers.push(args), clearTimeout, setTimeout };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), gets, window, watchers };
}

test('manager totals show actionable work separately from waiting steps, without a false stable-area claim', async () => {
    const s = harness('pages/AdminPortalManagerWorkspacePage', 'loadOverview, metrics', { payload: { summary: { total_attention: 2, waiting_total: 9, reference_events: 25, month_revenue: 0 } } });
    await s.loadOverview();
    assert.equal(s.metrics.value[0].label, 'Team action'); assert.equal(s.metrics.value[0].value, 2);
    assert.equal(s.metrics.value[2].label, 'Waiting steps'); assert.equal(s.metrics.value[2].value, 9);
    assert.ok(s.metrics.value.every(item => item.label !== 'Stable areas'));
});

test('manager service preview keeps closed Finance follow-ups and removes duplicate source rows', async () => {
    const s = harness('pages/admin/portal/PortalManagerAreaPage', 'loadArea, serviceItems, serviceDescription', { area: 'services', payload: {
        summary: { actionable: 7, waiting_on_provider: 4 },
        queues: { actionable: { items: [{ id: 4, finance_follow_up: true, state: 'completed' }, { id: 7 }] }, provider_waiting: { items: [{ id: 7 }, { id: 9 }] } },
    } });
    await s.loadArea(); assert.deepEqual(s.serviceItems.value.map(item => item.id), [4, 7, 9]);
    assert.equal(s.serviceItems.value[0].finance_follow_up, true);
    assert.match(s.serviceDescription.value, /3 of 11/);
});

test('Super Admin metrics distinguish available staff from active account status', async () => {
    const s = harness('pages/admin/governance/SuperAdminWorkspacePage', 'loadGovernance, metrics, waitingByOwner', { payload: {
        summary: { managed_admins: 4, active_admins: 4, available_admins: 2, covered_roles: 2, total_roles: 7, operational_attention: 3, waiting_total: 8 },
        operational_areas: [{ owner: 'Finance officer', count: 1, waiting: 4 }],
    } });
    await s.loadGovernance(); assert.equal(s.metrics.value[0].value, 2);
    assert.equal(s.metrics.value[0].label, 'Available admin staff');
    assert.equal(s.waitingByOwner.value['finance officer'], 4);
});

test('governance role links load only the requested team members through a read-only GET', async () => {
    const s = harness('pages/admin/governance/SuperAdminAreaPage', 'loadArea, selectedRole', { pathname: '/admin/governance/team', search: '?role=finance_officer' });
    await s.loadArea();
    assert.equal(s.selectedRole.value, 'finance_officer');
    assert.deepEqual(s.gets[0][1].params, { role: 'finance_officer' });
    assert.equal(s.gets[0][0], '/admin/governance/team/data');
    const invalid = harness('pages/admin/governance/SuperAdminAreaPage', 'loadArea, selectedRole', { search: '?role=not_a_role' });
    await invalid.loadArea(); assert.equal(invalid.selectedRole.value, ''); assert.equal(invalid.gets[0][1].params, undefined);
});

test('related audit filters persist into the detail-page return link and can be cleared independently', async () => {
    const s = harness('pages/admin/records/RecordsActivityPage', 'loadActivity, recordLink, related', {
        search: '?record_type=service&record_id=12&period=all&category=operations&search=refund&page=2',
        payload: { items: [], pagination: { current_page: 2, last_page: 3, total: 40 } },
    });
    await s.loadActivity(2);
    assert.equal(s.gets[0][1].params.record_type, 'service'); assert.equal(s.gets[0][1].params.record_id, '12');
    const link = new URL(s.recordLink('/admin/workspaces/records/activity/5'), s.window.location.origin);
    const back = new URL(link.searchParams.get('return_to'), s.window.location.origin);
    assert.equal(back.searchParams.get('record_id'), '12'); assert.equal(back.searchParams.get('period'), 'all');
    assert.equal(back.searchParams.get('category'), 'operations'); assert.equal(back.searchParams.get('search'), 'refund');
    assert.equal(back.searchParams.get('page'), '2');
    s.related.value = null; await s.loadActivity();
    assert.equal(s.gets[1][1].params.record_id, undefined); assert.equal(s.gets[1][1].params.search, 'refund');
});

test('audit details accept only an internal Records return path and never turn history into a current decision', () => {
    const valid = harness('pages/admin/records/RecordsActivityRecordPage', 'backUrl', {
        search: '?return_to=' + encodeURIComponent('/admin/workspaces/records/activity?record_type=service&record_id=12&period=all'),
    });
    assert.match(valid.backUrl, /record_type=service/);
    const invalid = harness('pages/admin/records/RecordsActivityRecordPage', 'backUrl', { search: '?return_to=https://external.example' });
    assert.equal(invalid.backUrl, '/admin/workspaces/records/activity');
    const source = readFileSync(new URL('../../resources/js/pages/admin/records/RecordsActivityRecordPage.vue', import.meta.url), 'utf8');
    assert.match(source, /recorded event, not the current decision/); assert.match(source, /entry.related_trails/);
    assert.doesNotMatch(source, /axios\.(post|patch|delete)/);
});
