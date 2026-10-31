import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { parse as parseTemplate } from '@vue/compiler-dom';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const summarySource = read('components/ApplicantTextSummary.vue');
const dashboardSource = read('components/ApplicantDashboardOverview.vue');
const providerSource = read('pages/UserProvidersPage.vue');
const compile = (source, id, childUrl) => {
    const { descriptor, errors } = parse(source, { filename: `${id}.vue` });
    assert.deepEqual(errors, []);
    const code = compileScript(descriptor, { id, inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
        .replace(/from (['"])\.\/ApplicantTextSummary\.vue\1/g, `from ${JSON.stringify(childUrl)}`);
    return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
};
const summaryUrl = compile(summarySource, 'applicant-text-summary');
const { default: Summary } = await import(summaryUrl);
const { default: Dashboard } = await import(compile(dashboardSource, 'applicant-final-dashboard', summaryUrl));
const render = (component, props) => renderToString(createSSRApp(component, props));
const benefits = 'Tuition and transport support, school materials and mentoring. '.repeat(12);
const dashboard = {
    mode: 'applying', profile: { complete: true, percent: 100 },
    summary: { applications: 1, active_applications: 1, actions: 1, upcoming: 1 },
    primary_action: { kind: 'document', tone: 'urgent', title: 'Replace the requested file', description: 'Read the complete provider instruction before uploading. '.repeat(8),
        context: 'Education scholarship', due_label: 'Due Nov 18, 2026', href: '/dashboard/applications/1/submission', cta: 'Review files' },
    applications: [{ id: 1, href: '/dashboard/applications/1', title: 'Education scholarship with a long public title', provider: 'Education foundation', image_url: '/program-logo.png',
        next_step: 'Review the provider instruction', progress_label: 'Pre-screening review', progress_percent: 20, status_label: 'Changes requested', status_tone: 'attention' }],
    upcoming: [{ key: 'exam', href: '/dashboard/applications/1/activities', kind: 'schedule', title: 'Examination session', context: 'Education scholarship', detail: 'Main campus', date_label: 'Nov 15, 2026 9:00 AM' }],
    recommendation: { href: '/dashboard/scholarships/2', title: 'Suggested scholarship', provider: 'Education foundation', image_url: '/recommended-logo.png',
        benefit_summary: benefits, deadline_label: 'Applications close Dec 27, 2026', match_score: 95 },
};

test('compact summaries preserve the complete text and safely escape supplied markup', async () => {
    const html = await render(Summary, { text: `${benefits}\n<script>alert(1)</script>` });
    assert.ok(html.includes(benefits));
    assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
    assert.match(html, /line-clamp-2/);
    assert.match(html, /whitespace-pre-line break-words/);
    assert.doesNotMatch(html, /data-summary-toggle|<script>/);
});

test('summary controls depend on actual overflow, support keyboard semantics and clean up observers', () => {
    assert.match(summarySource, /scrollHeight > paragraph.value.clientHeight \+ 1/);
    assert.match(summarySource, /v-if="overflowing \|\| expanded"/);
    assert.match(dashboardSource, /:label="`benefit summary for \$\{recommendation.title\}`"/);
    assert.match(providerSource, /:label="`benefit summary for \$\{program.title\}`"/);
    for (const value of [':aria-controls="textId"', ':aria-expanded="expanded"', 'type="button"', 'Read full ${label}', 'Collapse ${label}', 'student-button-compact', 'watch(() => props.text', 'document.fonts?.ready', 'new ResizeObserver(measure)', 'observer?.disconnect()', "window.removeEventListener('resize', measure)"]) assert.ok(summarySource.includes(value), value);
    assert.doesNotMatch(summarySource, /slice\(|substring\(|axios|fetch\(|localStorage|scrollIntoView/);
});

test('benefit disclosures never nest an interactive control inside a navigation link', () => {
    for (const source of [dashboardSource, providerSource]) {
        const { descriptor } = parse(source);
        let summaries = 0;
        const walk = (node, ancestors = []) => {
            if (node.tag === 'ApplicantTextSummary') {
                summaries++;
                assert.ok(!ancestors.includes('a'), 'The disclosure must not navigate when expanded');
            }
            for (const child of node.children ?? []) walk(child, [...ancestors, node.tag]);
        };
        walk(parseTemplate(descriptor.template.content));
        assert.equal(summaries, 1);
    }
});

test('dashboard urgency, exact dates, application links, stage progress and right-aligned counts remain intact', async () => {
    const html = await render(Dashboard, { dashboard });
    for (const value of [dashboard.primary_action.description, dashboard.primary_action.due_label, dashboard.applications[0].title,
        dashboard.applications[0].next_step, dashboard.upcoming[0].date_label, '20%', 'Changes requested', 'Main campus']) assert.ok(html.includes(value));
    for (const href of [dashboard.primary_action.href, dashboard.applications[0].href, dashboard.upcoming[0].href]) assert.ok(html.includes(`href="${href}"`));
    assert.equal((html.match(/data-dashboard-summary-value/g) ?? []).length, 4);
    assert.match(html, /data-dashboard-summary-value class="shrink-0 text-right/);
    assert.match(dashboardSource, /xl:grid-cols-4/);
    assert.match(dashboardSource, /col-span-2 row-start-2 min-w-0 flex-1/);
    assert.match(html, /student-badge student-badge-attention/);
    assert.doesNotMatch(dashboardSource, /truncate|Ready for applications/);
    assert.match(dashboardSource, /Required details complete/);
});

test('recommendations retain logos, full benefit summaries, deadlines and a separate scholarship action', async () => {
    const html = await render(Dashboard, { dashboard });
    for (const value of [benefits, '/recommended-logo.png', dashboard.recommendation.deadline_label, '95% match', 'View scholarship']) assert.ok(html.includes(value));
    assert.match(html, /data-dashboard-recommendation/);
    assert.match(html, /data-applicant-text-summary/);
    assert.match(html, /student-button student-button-primary student-button-compact/);
    assert.ok(html.includes(`href="${dashboard.recommendation.href}"`));
});

test('all dashboard modes retain the existing Philippine imagery and handle absent optional sections', async () => {
    for (const [mode, image] of [['onboarding', 'science-class'], ['applying', 'group-study'], ['recipient', 'science-experiment'], ['discovery', 'reading-program']]) {
        const html = await render(Dashboard, { dashboard: { mode } });
        assert.ok(html.includes(`/images/filipino-students-${image}.jpg`));
        assert.ok(html.includes('Explore scholarship opportunities'));
        assert.doesNotMatch(html, /data-dashboard-recommendation|data-applicant-text-summary|undefined|NaN/);
    }
});

test('provider programs retain covers, complete contact records, match context and read-only preview guards', () => {
    assert.match(providerSource, /:text="program.benefit_summary \|\| 'Support listed in scholarship details\.'/);
    for (const value of ['providerHero.image', 'provider.description', 'provider.contact_email', 'provider.address', '<LocationMapLink', 'program.eligibility_match?.score', 'data-provider-programs', 'data-provider-program', 'programTiming(program)', 'blockPreviewAction']) assert.ok(providerSource.includes(value), value);
    assert.match(providerSource, /:aria-disabled="isPreview \|\| undefined" @click="blockPreviewAction"/);
    assert.match(providerSource, /<strong class="text-slate-950">Varies<\/strong> by applicant/);
    assert.match(providerSource, /target="_blank" rel="noopener noreferrer"/);
});
