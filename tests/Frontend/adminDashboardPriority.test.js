import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { normalizeAdminDashboard } from '../../resources/js/support/adminDashboard.js';

test('admin dashboard preserves a bounded, explicit next action', () => {
    const dashboard = normalizeAdminDashboard({ priority: {
        title: ' Profile review ', reason: ' Oldest submitted evidence ', owner: 'Review Officer', state: 'actionable',
        action: { label: 'Open review', url: '/admin/workspaces/reviews/applicants/record' },
    } });
    assert.equal(dashboard.priority.title, 'Profile review');
    assert.equal(dashboard.priority.state, 'actionable');
    assert.ok(dashboard.priority.action.url.startsWith('/admin/'));
    assert.equal(normalizeAdminDashboard({}).priority, null);
});

test('priority rejects unsafe links and does not infer urgent work for unknown states', () => {
    for (const url of ['javascript:alert(1)', '//outside.test/path', 'https://outside.test/path']) {
        const dashboard = normalizeAdminDashboard({ priority: { title: 'Reference', reason: 'Inspect records', state: 'urgent', action: { label: 'Open', url } } });
        assert.equal(dashboard.priority.state, 'reference');
        assert.equal(dashboard.priority.action, null);
    }
});

test('priority component compiles and remains navigation-only', () => {
    const filename = new URL('../../resources/js/pages/admin/components/AdminDashboardPriority.vue', import.meta.url);
    const source = readFileSync(filename, 'utf8');
    const { descriptor, errors } = parse(source);
    const script = compileScript(descriptor, { id: 'admin-priority' });
    const template = compileTemplate({ id: 'admin-priority', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
    assert.deepEqual(errors, []);
    assert.deepEqual(template.errors, []);
    assert.match(source, /data-admin-priority/);
    assert.doesNotMatch(source, /axios|v-html|<form|<button/);
});
