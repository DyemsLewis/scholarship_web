import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const base = new URL('../../resources/js/components/', import.meta.url);
const urls = new Map();
async function component(relative) {
    const file = new URL(relative, base);
    if (urls.has(file.href)) return urls.get(file.href);
    const { descriptor, errors } = parse(readFileSync(file, 'utf8')); assert.deepEqual(errors, []);
    let code = compileScript(descriptor, { id: relative, inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
    for (const match of [...code.matchAll(/from ['"]([^'"]+)\.vue['"]/g)]) {
        code = code.replace(match[0], `from ${JSON.stringify(await component(new URL(match[1] + '.vue', file).href))}`);
    }
    code = code.replace(/from ['"]([^'"]+support\/[^'"]+)['"]/g, (_, path) => `from ${JSON.stringify(new URL(path + (path.endsWith('.js') ? '' : '.js'), file).href)}`);
    const url = `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
    urls.set(file.href, url); return url;
}
const { default: ScholarshipOverview } = await import(await component('ScholarshipOverview.vue'));
const { default: ApplicationOverview } = await import(await component('application-detail/ApplicationOverviewRecord.vue'));
const { default: Progress } = await import(await component('application-detail/ApplicationProgressRecord.vue'));
const render = (component, props) => renderToString(createSSRApp(component, props));
const navigation = ['summary', 'eligibility', 'award', 'application'].map(key => ({ key, href: '/scholarship/' + key }));
const scholarship = {
    content: { description: 'Support for learners continuing their studies.', primary_benefit: { title: 'Learning allowance', amount: 9000, coverage_label: 'Fixed-value coverage', frequency_label: 'Per semester', duration: 'Two semesters' }, additional_benefit_count: 1, readiness: [{ key: 'eligibility', state: 'ready', status: 'Matched' }, { key: 'files', state: 'attention', status: '2 missing' }, { key: 'process', state: 'neutral', status: '5 steps' }] },
    navigation,
};
const progress = { current_stage: 'interview', current_stage_label: 'Interview', percent: 60, completed_steps: 3, total_steps: 5, steps: [
    { key: 'screening', label: 'Pre-screening review', position: 1, status: 'passed', result_label: 'Passed' },
    { key: 'formal_application', label: 'Formal application', position: 2, status: 'complete', result_label: 'Passed' },
    { key: 'exam', label: 'Exam', position: 3, status: 'passed', result_label: 'Passed' },
    { key: 'interview', label: 'Interview', position: 4, status: 'ongoing', current: true },
    { key: 'decision', label: 'Final decision', position: 5, status: 'upcoming' },
] };
const detail = {
    progress, decision: { state: 'waiting' }, navigation: ['submission', 'activities', 'history'].map(key => ({ key, href: '/application/' + key })),
    content: { current_step: { description: 'Meet with the provider to discuss your application.' }, latest_update: { title: 'Interview appointment confirmed', description: 'Your appointment details have been recorded.', occurred_at: 'Nov 15, 2026 09:00 AM' }, upcoming_activity: { title: 'Scholarship interview', scheduled_label: 'Nov 20, 2026 10:00 AM', mode_label: 'Online interview' }, readiness: { required_files: 4, files_needing_attention: 0, provider_questions: 2 }, record: [{ label: 'Submission version', value: 'Version 2' }], links: { scholarship: '/scholarship', provider: '/provider' } },
};

test('scholarship overview preserves the real description and benefit facts with separate readable labels', async () => {
    const html = await render(ScholarshipOverview, scholarship);
    for (const value of [scholarship.content.description, 'Learning allowance', 'Amount', '9,000', 'Coverage', 'Fixed-value coverage', 'Frequency', 'Per semester', 'Duration', 'Two semesters', '+1 more', 'href="/scholarship/award"']) assert.ok(html.includes(value), value);
    assert.doesNotMatch(html, /<img|<figure|data-overview-illustration/);
    assert.doesNotMatch(html, /Fixed-value coverage \/|Per semester \/|Start pre-screening|Apply now/);
    assert.equal((html.match(/data-overview-resource=/g) || []).length, 3);
    assert.match(html, /student-badge-attention/);
    assert.match(html, /Meeting the rules does not guarantee selection/);
});

test('already-applied and provider preview overviews do not show misleading personal readiness results', async () => {
    for (const props of [{ hasApplied: true }, { preview: true }]) {
        const html = await render(ScholarshipOverview, { ...scholarship, ...props });
        assert.doesNotMatch(html, />Matched<|>2 missing<|>5 steps<|<button/);
        assert.match(html, /Check eligibility/);
        assert.match(html, /See benefits and terms/);
    }
    assert.match(await render(ScholarshipOverview, { ...scholarship, hasApplied: true }), /Explore the program/);
    const source = readFileSync(new URL('ScholarshipOverview.vue', base), 'utf8');
    assert.match(source, /\$emit\('navigate', \$event, item.section\)/);
    assert.match(source, /\$emit\('navigate', \$event, 'award'\)/);
});

test('missing scholarship information uses honest fallbacks without invented awards, amounts or links', async () => {
    const html = await render(ScholarshipOverview, { ...scholarship, content: {}, navigation: [] });
    assert.match(html, /provider has not added a program description/);
    assert.match(html, /provider has not published the benefit details/);
    assert.doesNotMatch(html, /data-overview-benefit-facts|data-overview-resources|href=|NaN|undefined|9,000/);
});

test('application overview preserves real progress, the latest update, appointment and submission data', async () => {
    const html = await render(ApplicationOverview, { detail });
    for (const text of [detail.content.current_step.description, detail.content.latest_update.title, detail.content.latest_update.description, detail.content.latest_update.occurred_at, detail.content.upcoming_activity.title, detail.content.upcoming_activity.scheduled_label, 'Online interview', 'Version 2', '/application/history', '/application/activities', '/application/submission', 'href="/scholarship"', 'href="/provider"']) assert.ok(html.includes(text), text);
    assert.equal((html.match(/data-application-step=/g) || []).length, 5);
    assert.match(html, /aria-current="step"/);
    assert.match(html, /aria-valuenow="60"/);
    assert.match(html, /<details[^>]+data-application-record-details/);
    assert.doesNotMatch(html, /<details[^>]+\bopen(?:[=>\s])|<button|<input|dss_breakdown|rubric_scores|review_notes/);
});

test('application overview stacks its panels and keeps the update image to the right of its text', async () => {
    const html = await render(ApplicationOverview, { detail });
    assert.match(html, /class="space-y-4" data-application-overview-stack/);
    assert.doesNotMatch(html, /xl:grid-cols-|<aside/);
    assert.match(html, /sm:grid-cols-\[minmax\(0,1\.6fr\)_minmax\(10rem,1fr\)\]/);
    assert.match(html, /data-application-update-image/);
    assert.match(html, /src="\/images\/application-documents.jpg"/);
    assert.ok(html.indexOf('data-application-update-image') > html.indexOf('id="application-update-title"'));
    assert.ok(html.indexOf('data-application-latest-update') < html.indexOf('data-application-essentials'));
    assert.ok(html.indexOf('data-application-essentials') < html.indexOf('data-application-related'));
});

test('the overview does not duplicate an appointment action already prioritized by the authoritative banner', async () => {
    const html = await render(ApplicationOverview, { detail: { ...detail, decision: { state: 'action_required' } } });
    assert.doesNotMatch(html, /data-application-upcoming|Your next appointment|Nov 20, 2026/);
    assert.match(html, /Your submitted record/);
    assert.match(html, /Interview appointment confirmed/);
});

test('empty application updates and optional content never create fake appointments or records', async () => {
    const html = await render(ApplicationOverview, { detail: { ...detail, navigation: [], content: {} } });
    assert.match(html, /No updates recorded yet/);
    assert.doesNotMatch(html, /data-application-upcoming|data-application-related|data-application-record-details|data-application-submission-brief|href=|undefined|Invalid Date/);
});

test('progress keeps failed, completed, current and unstarted stages distinct without truncating labels', async () => {
    const html = await render(Progress, { progress: { ...progress, steps: [...progress.steps, { key: 'additional', label: 'An additional evaluation with a long stage name', status: 'not_passed', position: 6, current: false }] } });
    for (const text of ['Passed', 'Current stage', 'Not started', 'Not passed', 'An additional evaluation with a long stage name']) assert.ok(html.includes(text), text);
    assert.match(html, /bg-rose-50/);
    assert.doesNotMatch(html, /truncate|line-clamp/);
    const complete = await render(Progress, { progress: { ...progress, percent: 100, current_stage: 'complete', completed_steps: 5, steps: progress.steps.map(step => ({ ...step, current: false, status: 'complete', result_label: 'Passed' })) } });
    assert.match(complete, /aria-valuenow="100"/);
    assert.doesNotMatch(complete, /aria-current="step"|Current stage|Not started/);
});

test('both overview integrations preserve the existing headers, single authoritative action and scoped routes', () => {
    const scholarshipPage = readFileSync(new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url), 'utf8');
    const applicationPage = readFileSync(new URL('../../resources/js/pages/UserApplicationOverviewPage.vue', import.meta.url), 'utf8');
    assert.match(scholarshipPage, /<ScholarshipOverview\s+v-if="isOverview"/);
    assert.match(scholarshipPage, /:preview="isPreview"[\s\S]+@navigate="openPreviewSection"/);
    assert.match(scholarshipPage, /<\/header>\s+<ScholarshipDetailAction v-if="!isApplicationView"/);
    assert.match(applicationPage, /view: 'summary'/);
    assert.match(applicationPage, /<ApplicationOverviewRecord :detail="detail"/);
    assert.match(applicationPage, /<ApplicantApplicationShell v-else-if="detail" :detail="detail">/);
});
