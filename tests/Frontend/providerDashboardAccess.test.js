import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/pages/provider/ProviderManagerDashboardPage.vue', import.meta.url), 'utf8');
const program = { id: 11, title: 'Education award', category: 'Financial assistance', status: 'published', applications_count: 1, deadline: '2026-12-01' };

async function renderDashboard(permissions, primaryAction, programs = [], userDetails = {}) {
    const overview = { organization_name: 'Education foundation', primary_action: primaryAction, summary: { programs: programs.length, published_programs: programs.length, applications: 0, attention: 0, active_team: 1 }, priority_action: null, attention_workspaces: [] };
    const fixture = source.replace('const isLoading = ref(true);', 'const isLoading = ref(false);')
        .replace('const user = ref(null);', `const user = ref(${JSON.stringify(permissions === null ? null : { permissions, ...userDetails })});`)
        .replace('const scholarships = ref([]);', `const scholarships = ref(${JSON.stringify(programs)});`)
        .replace(/const overview = ref\(\{[\s\S]*?\n\}\);/, `const overview = ref(${JSON.stringify(overview)});`);
    const { descriptor, errors } = parse(fixture);
    assert.deepEqual(errors, []);
    let code = compileScript(descriptor, { id: 'dashboard-access', inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
        .replace(/from (['"])\.\.\/\.\.\/support\/workspaceDashboard.js\1/g, `from ${JSON.stringify(new URL('../../resources/js/support/workspaceDashboard.js', import.meta.url).href)}`)
        .replace(/import (\w+) from ['"][^'"]+\.vue['"];?/g, (_, name) => name === 'WorkspaceDashboardHeader'
            ? `const ${name} = { props: ['workspace'], render() { return this.$slots.actions?.(); } };`
            : `const ${name} = { render: () => null };`);
    const { default: Dashboard } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
    return renderToString(createSSRApp(Dashboard));
}

test('governance owners see the coordinator setup action, not forbidden program links', async () => {
    const html = await renderDashboard(['manage_profile', 'manage_team'], { label: 'Review team access', href: '/provider/workspaces/team' }, [], { is_organization_owner: true });
    assert.match(html, /href="\/provider\/workspaces\/team"/);
    assert.match(html, /Review team access/);
    assert.match(html, /representative account manages the organization and team, but cannot create scholarships/);
    assert.match(html, /href="\/provider\/team\/accounts\/create\?role=program_coordinator"/);
    assert.match(html, /An admin must approve the organization/);
    assert.doesNotMatch(html, /href="\/provider\/programs|program-snapshot-title|No scholarship program has been created/);
});

test('program managers keep directory, create and individual record access', async () => {
    const html = await renderDashboard(['manage_programs'], { label: 'Review programs', href: '/provider/programs' }, [program]);
    assert.match(html, /href="\/provider\/programs"/);
    assert.match(html, /href="\/provider\/programs\/11"/);
    assert.match(html, /Education award/);
    assert.doesNotMatch(html, /provider-program-setup-title/);
    const empty = await renderDashboard(['manage_programs'], { label: 'Review programs', href: '/provider/programs' });
    assert.match(empty, /href="\/provider\/programs\/create"/);
    const solo = await renderDashboard(['manage_programs', 'manage_team'], null, [], { is_organization_owner: true });
    assert.doesNotMatch(solo, /provider-program-setup-title/);
});

test('approved representatives keep the role guidance without the approval warning, and staff do not see it', async () => {
    const approved = await renderDashboard(['manage_profile', 'manage_team'], null, [], { is_organization_owner: true, can_post_scholarships: true });
    assert.match(approved, /Add Program Coordinator/);
    assert.doesNotMatch(approved, /An admin must approve the organization/);
    const staff = await renderDashboard(['manage_team'], null);
    assert.doesNotMatch(staff, /provider-program-setup-title/);
});

test('mixed operational staff retain accessible records but cannot enter the program builder or management directory', async () => {
    const html = await renderDashboard(['verify_applications', 'manage_selection_activities'], { label: 'Open workspace', href: '/provider/workspaces/reviews/all' }, [program]);
    assert.match(html, /href="\/provider\/programs\/11"/);
    assert.match(html, /href="\/provider\/workspaces\/reviews\/all"/);
    assert.doesNotMatch(html, /href="\/provider\/programs"|href="\/provider\/programs\/create"/);
});

test('no program action is displayed before the authenticated dashboard contract loads', async () => {
    const html = await renderDashboard(null, null);
    assert.doesNotMatch(html, /href="\/provider\/programs/);
    assert.match(source, /v-if="overview.primary_action" :href="overview.primary_action.href"/);
});
