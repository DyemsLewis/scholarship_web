import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const base = new URL('../../resources/js/components/monitoring-detail/', import.meta.url);
const urls = new Map();
async function component(name) {
    if (urls.has(name)) return urls.get(name);
    const source = readFileSync(new URL(name + '.vue', base), 'utf8');
    const { descriptor, errors } = parse(source); assert.deepEqual(errors, []);
    let code = compileScript(descriptor, { id: name, inlineTemplate: true }).content
        .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
    for (const match of [...code.matchAll(/from ['"]\.\/([^'"]+)\.vue['"]/g)]) {
        code = code.replace(match[0], `from ${JSON.stringify(await component(match[1]))}`);
    }
    const url = `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
    urls.set(name, url); return url;
}
const { default: Overview } = await import(await component('MonitoringOverviewRecord'));
const { default: Banner } = await import(await component('MonitoringDecisionBanner'));
const render = (content) => renderToString(createSSRApp(Overview, { content }));
const decision = { state: 'check_in_scheduled', tone: 'scheduled', actor: 'applicant', actor_label: 'You', title: 'Attend your document review', description: 'Bring your original school records to the provider.', due_label: 'Nov 20, 2026 09:00 AM', action: { label: 'Open check-in', href: '/check-in-record' } };
const plan = { mode: 'recurring', mode_label: 'Recurring check-ins', frequency_label: 'Semester', starts_label: 'Nov 01, 2026', ends_label: 'Apr 30, 2027', agreement_accepted: true, agreement: { label: 'Accepted', href: '/application/outcome?action=view-agreement' }, instructions: 'Attend the published appointment and bring original records.' };
const content = (extra = {}) => ({ plan, next_action: decision, closeout: null, latest_support_decision: null, schedule: [{ section: 'benefits', kind: 'Benefit release', title: 'Learning allowance', date_label: 'Nov 25, 2026 10:00 AM', context: 'In person', href: '/release-record' }], ...extra });

test('overview keeps a single authoritative next action, real dates and agreement status, with restrained imagery', async () => {
    const html = await render(content());
    for (const value of [decision.title, decision.description, decision.due_label, 'href="/check-in-record"', 'Recurring check-ins', 'Accepted', 'Nov 01, 2026 to Apr 30, 2027', '/images/filipino-students-group-study.jpg']) assert.ok(html.includes(value), value);
    assert.equal((html.match(/href="\/check-in-record"/g) || []).length, 1);
    assert.ok(html.indexOf('data-monitoring-next-action') < html.indexOf('data-overview-schedule'));
    assert.match(html, /Learning allowance/);
    assert.match(html, /Nov 25, 2026 10:00 AM/);
    assert.match(html, /href="\/release-record"/);
    assert.doesNotMatch(html, /filipino-campus\.jpg|<input|<button/);
});

test('long provider instructions remain collapsed without hiding agreement access or inventing support dates', async () => {
    const html = await render(content({ plan: { ...plan, starts_label: null, ends_label: null } }));
    assert.match(html, /<details[^>]*data-overview-plan/);
    assert.doesNotMatch(html, /<details[^>]*\bopen(?:[=>\s])/);
    assert.match(html, /Attend the published appointment/);
    assert.match(html, /application\/outcome\?action=view-agreement/);
    assert.doesNotMatch(html, /Support period|Nov 01|Apr 30/);
});

test('one-time empty schedules do not imply that recurring check-ins are required', async () => {
    const html = await render(content({ schedule: [], plan: { ...plan, mode: 'one_time', mode_label: 'One-time benefit only', frequency_label: null } }));
    assert.match(html, /No additional dates published/);
    assert.match(html, /Delivery instructions and release updates remain available in Benefit releases/);
    assert.doesNotMatch(html, /additional check-in|Review frequency|Recurring check-ins/);
});

test('pending agreements do not show a misleading empty schedule or imply acceptance', async () => {
    const html = await render(content({ schedule: [], plan: { ...plan, agreement_accepted: false, agreement: { ...plan.agreement, label: 'Awaiting response' } }, next_action: { ...decision, state: 'agreement_required', tone: 'attention', title: 'Review the recipient agreement', due_label: null } }));
    assert.match(html, /Awaiting response/);
    assert.doesNotMatch(html, /data-overview-schedule|No additional dates published|>Accepted</);
});

test('waiting on a provider is not presented as an applicant task and duplicate updates stay hidden', async () => {
    const wait = { ...decision, state: 'check_in_under_review', tone: 'waiting', actor: 'provider', actor_label: 'Scholarship provider', title: 'Wait for the provider review', action: { label: 'View check-in', href: '/check-in-record' } };
    const html = await render(content({ next_action: wait, latest_support_decision: { href: '/check-in-record', decision_label: 'Duplicate latest outcome' } }));
    assert.match(html, /What happens next/);
    assert.match(html, /With the scholarship provider/);
    assert.doesNotMatch(html, /Your next step|Duplicate latest outcome/);
});

test('ended support retains closeout totals and response links without an active hero or future schedule', async () => {
    const closeout = { kind: 'completed', title: 'Scholarship completed', description: 'The provider closed this award.', counts: { received: 1, unclaimed: 1, declined: 0, pending: 0 }, benefits_href: '/benefit-history', outcome_href: '/outcome' };
    const html = await render(content({ closeout, next_action: { ...decision, title: 'Review the support outcome', action: { label: 'Review outcome', href: '/outcome' } } }));
    assert.match(html, /data-support-closeout/);
    assert.match(html, /Receipt confirmed/);
    assert.match(html, /Unclaimed/);
    assert.match(html, /href="\/outcome"/);
    assert.doesNotMatch(html, /data-overview-schedule|data-overview-summary|filipino-students-group-study/);
});

test('illustration is opt-in so other uses of the shared next-step banner retain their compact design', async () => {
    const html = await renderToString(createSSRApp(Banner, { decision }));
    assert.match(html, /Attend your document review/);
    assert.doesNotMatch(html, /<img|bg-portal-navy|lg:min-h-56/);
});
