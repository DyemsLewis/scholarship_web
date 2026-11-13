import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const tabsSource = read('components/ApplicantRecordTabs.vue');
const applicationSource = read('components/application-detail/ApplicantApplicationShell.vue');
const monitoringSource = read('components/monitoring-detail/ApplicantMonitoringShell.vue');
const compile = (source, id, tabsUrl) => {
    const { descriptor, errors } = parse(source, { filename: `${id}.vue` });
    assert.deepEqual(errors, []);
    let code = compileScript(descriptor, { id, inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
    code = code.replace(/import (\w+) from ['"][^'"]+\.vue['"];?/g, (_, name) => name === 'ApplicantRecordTabs'
        ? `import ${name} from ${JSON.stringify(tabsUrl)};`
        : `const ${name} = { render: () => null };`);
    return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
};
const tabsUrl = compile(tabsSource, 'record-tabs');
const { default: Tabs } = await import(tabsUrl);
const { default: ApplicationShell } = await import(compile(applicationSource, 'application-shell', tabsUrl));
const { default: MonitoringShell } = await import(compile(monitoringSource, 'monitoring-shell', tabsUrl));
const render = (component, props) => renderToString(createSSRApp({
    render: () => h(component, props, { default: () => h('div', { 'data-record-content': '' }, 'Record content') }),
}));
const navigation = ['Overview', 'Requirements', 'Schedule', 'Decision', 'History'].map((label, index) => ({
    key: label.toLowerCase(), label, href: `/record/${index}`, icon: 'fa-solid fa-folder', active: index === 4,
    count: index === 2 ? 2 : 0,
}));
const identity = {
    program: { title: 'Education scholarship', image_url: '/program-logo.png' },
    provider: { name: 'Education foundation', href: '/provider/1', verified: true },
    status: { tone: 'attention', label: 'Waiting for review' },
    application_href: '/dashboard/applications/1', submitted_at: 'Oct 30, 2026',
};
const page = { title: 'Focused section', description: 'Useful section instructions.' };

test('application tabs retain every native route, label, badge and current-page indicator', async () => {
    const html = await render(Tabs, { items: navigation, label: 'Application record' });
    for (const item of navigation) {
        assert.ok(html.includes(`href="${item.href}"`));
        assert.ok(html.includes(item.label));
    }
    assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
    assert.match(html, /min-w-\[42rem\] grid-cols-5/);
    assert.match(html, /overflow-x-auto/);
    assert.match(html, />2<\/span>/);
    assert.doesNotMatch(html, /data-record-tab-cue/);
});

test('monitoring tabs keep the existing two-by-two mobile grid and full labels', async () => {
    const items = navigation.slice(0, 4).map((item, index) => ({ ...item, active: index === 0 }));
    const html = await render(Tabs, { items, label: 'Monitoring record', layout: 'grid' });
    assert.match(html, /grid-cols-2 sm:grid-cols-4/);
    assert.match(html, /min-w-0 break-words/);
    assert.doesNotMatch(html, /min-w-\[42rem\]|overflow-x-auto|data-record-tab-cue/);
});

test('overflow cues are measured, accessible and do not scroll the background page', () => {
    assert.match(tabsSource, /overflowing.value = maximum > 1/);
    assert.match(tabsSource, /v-if="overflowing"/);
    for (const attribute of ['aria-controls', 'aria-describedby', 'data-record-scroll-back', 'data-record-scroll-forward']) assert.ok(tabsSource.includes(attribute));
    assert.match(tabsSource, /:disabled="!canScrollBack"/);
    assert.match(tabsSource, /:disabled="!canScrollForward"/);
    assert.match(tabsSource, /prefers-reduced-motion: reduce/);
    assert.match(tabsSource, /element.scrollBy\(/);
    assert.match(tabsSource, /element.scrollLeft/);
    assert.match(tabsSource, /parseFloat\(padding.paddingRight\)/);
    assert.match(tabsSource, /observer\?\.disconnect\(\)/);
    assert.match(tabsSource, /window.removeEventListener\('resize', revealActive\)/);
    assert.doesNotMatch(tabsSource, /scrollIntoView|window.scrollTo|axios|fetch\(|localStorage/);
});

test('application refinements retain identity, imagery and every summary fact', async () => {
    const snapshot = ['Current stage', 'Deadline', 'Files', 'Application method'].map((label, index) => ({ key: index, label, value: `Fact ${index}` }));
    const html = await render(ApplicationShell, { detail: { identity, page, navigation, snapshot, view: 'history' } });
    for (const value of ['Education scholarship', 'Education foundation', 'Waiting for review', 'Submitted Oct 30, 2026', '/program-logo.png', '/images/filipino-campus.jpg', ...snapshot.map((item) => item.value)]) assert.ok(html.includes(value));
    assert.equal((html.match(/<dt\b/g) ?? []).length, 4);
    assert.match(html, /grid-cols-2[^>]*xl:grid-cols-4/);
    assert.match(html, /lg:min-h-\[12rem\]/);
    assert.ok(html.indexOf('data-application-detail-navigation') < html.indexOf('data-record-section-intro'));
    assert.ok(html.indexOf('data-record-section-intro') < html.indexOf('data-record-content'));
    assert.equal((html.match(/Useful section instructions\./g) ?? []).length, 1);
});

const monitoringDetail = (selected = null) => ({
    identity, page, navigation: navigation.slice(0, 4), view: 'check-ins', content: { selected },
    status: { tone: 'positive', label: 'Support active', mode_label: 'Continuing support' },
    summary: { check_ins: { open: 1, next_due_label: 'Jan 15, 2027' }, benefits: { total: 2, next_release_label: 'Dec 11, 2026' } },
});

test('the applicant sidebar and directory use Scholarship Support at the existing URL', () => {
    assert.match(read('components/ApplicantSidebar.vue'), /href: '\/dashboard\/monitoring',\s+label: 'Scholarship Support'/);
    assert.match(read('pages/UserMonitoringPage.vue'), /title="Scholarship Support"/);
});

test('monitoring directories retain their introduction and application link with compact program branding', async () => {
    const html = await render(MonitoringShell, { detail: monitoringDetail() });
    for (const text of ['Back to Scholarship Support', 'Support record sections', 'Education scholarship', 'Education foundation', 'Support active', 'Application record', '/program-logo.png']) assert.ok(html.includes(text));
    assert.match(html, /href="\/dashboard\/monitoring"/);
    assert.match(html, /href="\/dashboard\/applications\/1"/);
    assert.equal((html.match(/Support active/g) ?? []).length, 1);
    assert.match(html, /data-record-section-intro/);
    assert.ok(html.indexOf('data-monitoring-detail-navigation') < html.indexOf('data-record-section-intro'));
    assert.doesNotMatch(html, /data-record-snapshot|filipino-students-studying\.jpg|Jan 15, 2027|Dec 11, 2026/);
});

test('a selected monitoring record omits only the generic directory introduction', async () => {
    for (const view of ['check-ins', 'benefits', 'history']) {
        const html = await render(MonitoringShell, { detail: { ...monitoringDetail({ record_key: 'record-1' }), view } });
        assert.doesNotMatch(html, /data-record-section-intro|Useful section instructions/);
        for (const hook of ['data-monitoring-detail-header', 'data-monitoring-detail-navigation', 'data-record-content']) assert.ok(html.includes(hook));
    }
});

test('focused monitoring records retain instructions, due dates, release facts and action flows', () => {
    const checkIn = read('components/monitoring-detail/MonitoringCheckInRecord.vue');
    const release = read('components/monitoring-detail/BenefitReleaseRecord.vue');
    for (const value of ['record.instructions', 'record.due_label', 'record.effective_due_label', 'record.requirements', 'acceptedTerms', 'saveManualGrade', 'MonitoringAdjustmentModal', 'MonitoringCaseHistoryModal']) assert.ok(checkIn.includes(value), value);
    for (const value of ['release.instructions', 'release.amount_label', 'delivery.date_label', 'release.release_method_label', 'release.location', 'BenefitReceiptResponseModal', 'LocationMapLink', 'FilePreviewModal']) assert.ok(release.includes(value), value);
    assert.match(checkIn, /data-check-in-appointment/);
    assert.match(checkIn, /sm:grid-cols-\[minmax\(0,1fr\)_minmax\(0,1fr\)\]/);
    assert.match(release, /data-benefit-next-step/);
    assert.match(release, /md:order-1/);
});
