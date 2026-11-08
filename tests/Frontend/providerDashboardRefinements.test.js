import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const root = new URL('../../resources/js/', import.meta.url);
const source = (path) => readFileSync(new URL(path, root), 'utf8');
const load = async (name) => {
    const { descriptor } = parse(source(`components/workspace-dashboard/${name}.vue`));
    const compiled = compileScript(descriptor, { id: name, inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
        .replace(/from (['"])\.\.\/\.\.\/support\/workspaceDashboard.js\1/g, `from ${JSON.stringify(new URL('support/workspaceDashboard.js', root).href)}`);
    return (await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)).default;
};
const Header = await load('WorkspaceDashboardHeader');
const Metrics = await load('WorkspaceMetricStrip');
const Items = await load('WorkspaceDashboardItemList');
const Section = await load('WorkspaceDashboardSection');
const render = (component, props) => renderToString(createSSRApp(component, props));
const longTitle = 'A complete scholarship and applicant record title that must remain readable '.repeat(4);

test('provider dashboard headers retain the title, description, icon and original primary destination', async () => {
    const html = await render(Header, {
        workspace: { key: 'reviews', title: longTitle, eyebrow: 'Application reviews', description: 'Review submitted evidence.', icon: 'fa-solid fa-file-circle-check' },
        primaryAction: { label: 'Open review queue', url: '/provider/workspaces/reviews/assigned', icon: 'fa-solid fa-arrow-right' },
    });
    for (const text of [longTitle, 'Review submitted evidence.', 'Application reviews', 'reviews-dashboard-title', 'fa-file-circle-check', 'href="/provider/workspaces/reviews/assigned"']) assert.ok(html.includes(text), text);
    assert.match(html, /workspace-dashboard-heading/);
    assert.match(html, /workspace-dashboard-icon/);
    assert.match(html, /workspace-dashboard-action/);
    assert.doesNotMatch(await render(Header, { workspace: { title: 'Dashboard' } }), /<a\b/);
});

test('summary loading hides provisional zero counts and preserves accessible status feedback', async () => {
    const metrics = [{ key: 'review', label: 'Applications awaiting evidence verification', value: 1294, detail: 'Across all assigned scholarships', icon: 'fa-solid fa-file-circle-check', url: '/provider/workspaces/reviews', tone: 'attention' }];
    const loading = await render(Metrics, { metrics, loading: true });
    assert.match(loading, /aria-busy="true"/);
    assert.match(loading, /role="status"/);
    assert.doesNotMatch(loading, /1294|<li\b/);
    const html = await render(Metrics, { metrics });
    for (const text of [metrics[0].label, metrics[0].detail, '1294', 'href="/provider/workspaces/reviews"', 'workspace-dashboard-label workspace-dashboard-text', 'text-amber-700']) assert.ok(html.includes(text), text);
    assert.match(html, /repeat\(1, minmax\(0, 1fr\)\)/);
    const staticMetric = await render(Metrics, { metrics: [{ key: 'all', label: 'Programs', value: 0 }] });
    assert.doesNotMatch(staticMetric, /<a\b/);
    assert.match(staticMetric, />0<\/p>/);
});

test('dashboard records keep photos, full names, statuses, dates and contextual navigation', async () => {
    const item = { id: 1, title: `${longTitle}<img onerror=alert(1)>`, subtitle: longTitle, image_url: '/applicant-photo.png', status: { tone: 'attention', label: 'Needs evidence review' }, meta: ['First semester - 2026-2027', 'Due Nov 18, 2026'], action: { label: 'Review', url: '/provider/applications/7' } };
    const html = await render(Items, { items: [item] });
    for (const text of ['/applicant-photo.png', longTitle, 'Needs evidence review', 'First semester - 2026-2027', 'Due Nov 18, 2026', 'href="/provider/applications/7"', 'aria-label="Review: ', 'workspace-dashboard-status', 'workspace-dashboard-text']) assert.ok(html.includes(text), text);
    assert.match(html, /&lt;img onerror=alert\(1\)&gt;/);
    assert.doesNotMatch(html, /<img onerror=/);
    const noImage = await render(Items, { items: [{ ...item, image_url: null }], fallbackIcon: 'fa-solid fa-award' });
    assert.match(noImage, /fa-solid fa-award/);
    assert.doesNotMatch(noImage, /<img\b/);
});

test('dashboard section empty and loading states retain distinct, useful feedback', async () => {
    const section = { key: 'priority', title: 'Priority work', eyebrow: 'Current focus', description: 'Only assigned work appears here.', icon: 'fa-solid fa-list-check', items: [], empty_message: 'No assigned work needs attention.', action: { label: 'Open queue', url: '/provider/workspaces/reviews', icon: 'fa-solid fa-arrow-right' } };
    const html = await render(Section, { section });
    for (const text of [section.title, section.description, section.empty_message, 'priority-title', 'workspace-dashboard-section-header', 'href="/provider/workspaces/reviews"']) assert.ok(html.includes(text), text);
    const loading = await render(Section, { section, loading: true });
    assert.match(loading, /Loading work items/);
    assert.doesNotMatch(loading, /No assigned work needs attention/);
});

test('organization logo rows fit the entire mark while applicant photos retain their crop', async () => {
    const item = { id: 1, title: 'Application support', subtitle: 'Education organization', image_url: '/organization-logo.png',
        image_fit: 'contain', status: { label: 'Confirmed' }, meta: [], action: { label: 'Open', url: '/admin/workspaces/finance' } };
    const logo = await render(Items, { items: [item] });
    assert.match(logo, /alt="Education organization logo"/);
    assert.match(logo, /object-contain p-1/);
    assert.doesNotMatch(logo, /object-cover/);
    const photo = await render(Items, { items: [{ ...item, image_fit: undefined, image_url: '/applicant-photo.png' }] });
    assert.match(photo, /object-cover/);
    assert.doesNotMatch(photo, /object-contain/);
});

test('all twelve active provider dashboards opt into refinements without opting admin into provider styles', () => {
    const pages = readdirSync(new URL('pages/provider/', root), { recursive: true })
        .filter((file) => file.endsWith('DashboardPage.vue'));
    assert.equal(pages.length, 12);
    for (const file of pages) {
        const value = source(`pages/provider/${file.replaceAll('\\', '/')}`);
        assert.match(value, /class="provider-dashboard/);
        const { descriptor, errors } = parse(value);
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const result = compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(result.errors, []);
        assert.doesNotMatch(value, /\.axios\.(post|patch|put|delete)\(/);
    }
    for (const file of readdirSync(new URL('pages/admin/', root), { recursive: true }).filter((path) => path.endsWith('.vue'))) {
        assert.doesNotMatch(source(`pages/admin/${file.replaceAll('\\', '/')}`), /class="provider-dashboard/);
    }
});

test('manager and program summaries share components without changing their data endpoints', () => {
    for (const [page, endpoint] of [['ProviderManagerDashboardPage', '/provider/dashboard/data'], ['programs/ProgramCoordinatorDashboardPage', '/provider/programs/dashboard/data']]) {
        const value = source(`pages/provider/${page}.vue`);
        assert.ok(value.includes(endpoint));
        assert.match(value, /<WorkspaceDashboardHeader/);
        assert.match(value, /<WorkspaceMetricStrip :metrics="metrics"/);
        assert.doesNotMatch(value, /truncate|w-\[6%\]/);
    }
    const manager = source('pages/provider/ProviderManagerDashboardPage.vue');
    assert.match(manager, /<StaffTableIdentity kind="program" :src="program.logo_url"/);
    assert.match(manager, /staff-table-viewport/);
    assert.match(manager, /<col class="w-20">/);
    assert.match(manager, /recentPrograms = computed\(\(\) => scholarships.value.slice\(0, 5\)\)/);
    const programs = source('pages/provider/programs/ProgramCoordinatorDashboardPage.vue');
    assert.match(programs, /<WorkspaceMetricStrip :metrics="metrics" :loading="isLoading"/);
});

test('program priority descriptions handle no work, one program and multiple programs', () => {
    const programs = source('pages/provider/programs/ProgramCoordinatorDashboardPage.vue');
    const body = programs.match(/const focusMessage = computed\(\(\) => \{([\s\S]*?)\n\}\);/)[1];
    const message = new Function('summary', body);
    assert.equal(message({ value: { needs_action: 0 } }), 'No program drafts or requested changes need attention.');
    assert.equal(message({ value: { needs_action: 1 } }), '1 program needs your attention.');
    assert.equal(message({ value: { needs_action: 2 } }), '2 programs need your attention.');
});

test('provider refinements are scoped, expose complete text and preserve keyboard focus', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const refinements = css.slice(css.indexOf('/* Shared dashboard components also serve admin pages;')).replace(/\/\*[\s\S]*?\*\//g, '');
    const selectors = [...refinements.matchAll(/([^{}]+)\{/g)].map((match) => match[1].trim());
    assert.ok(selectors.length > 10);
    for (const selector of selectors) {
        for (const part of selector.split(/,\s*\n/)) assert.match(part.trim(), /^\.provider-(dashboard|page-header)/);
    }
    assert.match(refinements, /\.workspace-dashboard-text\s*\{[^}]*white-space: normal;[^}]*overflow-wrap: anywhere;/);
    assert.match(refinements, /\.workspace-dashboard-label\s*\{[^}]*font-size: 0.75rem;/);
    assert.match(refinements, /\.workspace-dashboard-action,[\s\S]*?min-height: 2.5rem;/);
    assert.match(refinements, /:focus-visible[\s\S]*outline: 2px solid/);
    const header = source('components/ProviderPageHeader.vue');
    assert.match(header, /block w-4 text-center text-sm leading-none/);
    assert.doesNotMatch(header, /line-clamp|<p[^>]*text-\[0.65rem\]/);
});
